<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes immutable audit records for admin actions (document/phase/14 §Audit
 * Logs). Every sensitive admin action should call this.
 */
class AuditService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(
        ?User $actor,
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $meta = [],
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
            'ip_address' => request()?->ip(),
        ]);
    }
}
