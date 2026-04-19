<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with('user')
            ->latest('id');

        if ($request->filled('action')) {
            $query->where('action', (string) $request->query('action'));
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', (string) $request->query('entity_type'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        $items = $query->limit(200)->get();

        return response()->json([
            'items' => $items->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'timestamp' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->name ?? 'System',
                'user_id' => $log->user_id,
                'action' => $log->action,
                'target' => trim(sprintf('%s %s', $log->entity_type, $log->entity_id ?: '')),
                'details' => collect($log->payload ?? [])
                    ->map(fn ($value, $key) => sprintf('%s: %s', $key, is_scalar($value) ? (string) $value : json_encode($value)))
                    ->implode(' | '),
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'ip_address' => $log->ip_address,
                'payload' => $log->payload ?? [],
            ])->values(),
        ]);
    }
}
