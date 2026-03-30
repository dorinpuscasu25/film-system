<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class RoleController extends ApiController
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $permissions = Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get();

        return response()->json([
            'roles' => $roles->map(fn (Role $role) => $this->roleData($role)),
            'permissions' => $permissions->map(fn (Permission $permission) => $this->permissionData($permission)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'admin_panel_access' => ['sometimes', 'boolean'],
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        $role = DB::transaction(function () use ($validated): Role {
            $role = Role::query()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'admin_panel_access' => $validated['admin_panel_access'] ?? false,
                'is_system' => false,
                'is_default' => false,
            ]);

            $role->permissions()->sync($validated['permission_ids']);

            return $role;
        });

        return response()->json([
            'role' => $this->roleData($role->fresh()),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'admin_panel_access' => ['sometimes', 'boolean'],
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ]);

        DB::transaction(function () use ($role, $validated): void {
            $role->fill([
                'name' => $role->is_system ? $role->name : $validated['name'],
                'description' => $validated['description'] ?? null,
                'admin_panel_access' => $validated['admin_panel_access'] ?? $role->admin_panel_access,
            ])->save();

            $role->permissions()->sync($validated['permission_ids']);
        });

        return response()->json([
            'role' => $this->roleData($role->fresh()),
        ]);
    }
}
