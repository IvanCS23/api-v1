<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(User $user, AuditAction $action, Request $request, array $metadata = []): AuditLog
    {
        $actor = $request->user('api');

        return AuditLog::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'action' => $action->value,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
