<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Mail\UserInvitationMail;
use App\Models\Content;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class UserController extends ApiController
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('roles.permissions', 'contentAccesses.content')
            ->latest()
            ->get();

        $invitations = Invitation::query()
            ->latest()
            ->limit(25)
            ->get();

        $invitationRoles = Role::query()
            ->whereIn('id', $invitations->flatMap(fn (Invitation $invitation) => $invitation->role_ids ?? [])->unique())
            ->get()
            ->keyBy('id');

        return response()->json([
            'users' => $users->map(fn (User $user) => $this->userData($user)),
            'invitations' => $invitations->map(function (Invitation $invitation) use ($invitationRoles): array {
                $roles = collect($invitation->role_ids ?? [])
                    ->map(fn (mixed $roleId) => $invitationRoles->get((int) $roleId))
                    ->filter();

                return $this->invitationData($invitation, $roles);
            }),
            'content_options' => Content::query()
                ->orderBy('original_title')
                ->get(['id', 'original_title', 'slug'])
                ->map(fn (Content $content) => [
                    'id' => $content->id,
                    'title' => $content->original_title,
                    'slug' => $content->slug,
                ])
                ->values(),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $plainTextToken = Str::random(64);
        $roleIds = collect($validated['role_ids'])
            ->map(fn (mixed $roleId): int => (int) $roleId)
            ->unique()
            ->values()
            ->all();

        $roles = Role::query()
            ->whereIn('id', $roleIds)
            ->get();

        $invitation = Invitation::query()->create([
            'email' => strtolower($validated['email']),
            'name' => $validated['name'] ?? null,
            'token_hash' => hash('sha256', $plainTextToken),
            'role_ids' => $roleIds,
            'status' => 'pending',
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours($validated['expires_in_hours'] ?? 72),
        ]);

        $acceptUrl = $this->buildAcceptUrl($plainTextToken, $roles->contains('admin_panel_access', true));

        Mail::to($invitation->email)->send(new UserInvitationMail(
            appName: config('app.name'),
            inviteeName: $invitation->name,
            inviterName: $request->user()->name,
            acceptUrl: $acceptUrl,
            expiresAt: $invitation->expires_at,
            roleNames: $roles->pluck('name')->all(),
        ));

        $this->auditLog->record(
            'user.invited',
            'invitation',
            $invitation->id,
            ['email' => $invitation->email, 'role_ids' => $roleIds],
            $request->user(),
            $request,
        );

        return response()->json([
            'invitation' => $this->invitationData($invitation, $roles),
            'accept_url' => $acceptUrl,
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
            'assigned_content_ids' => ['nullable', 'array'],
            'assigned_content_ids.*' => ['integer', Rule::exists('contents', 'id')],
            'preferred_locale' => ['nullable', Rule::in(['en', 'ro', 'ru'])],
        ]);

        DB::transaction(function () use ($validated, $user): void {
            $user->fill([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'status' => $validated['status'],
                'preferred_locale' => $validated['preferred_locale'] ?? $user->preferred_locale,
            ])->save();

            $user->syncRoleIds($validated['role_ids']);
            $user->syncAssignedContentIds($validated['assigned_content_ids'] ?? []);
        });

        $this->auditLog->record(
            'user.updated',
            'user',
            $user->id,
            [
                'email' => $user->email,
                'status' => $user->status,
                'assigned_content_ids' => $validated['assigned_content_ids'] ?? [],
            ],
            $request->user(),
            $request,
        );

        return response()->json([
            'user' => $this->userData($user->fresh()),
        ]);
    }

    protected function buildAcceptUrl(string $plainTextToken, bool $adminAccess): string
    {
        $frontendUrl = $adminAccess
            ? (string) env('ADMIN_FRONTEND_URL', 'http://localhost:5174')
            : (string) env('CLIENT_FRONTEND_URL', 'http://localhost:5173');

        return rtrim($frontendUrl, '/').'/accept-invite?token='.$plainTextToken;
    }
}
