<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogService
{
    public function record(
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        array $payload = [],
        ?User $user = null,
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'ip_address' => $request?->ip(),
            'payload' => $payload,
        ]);
    }
}
