<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function log(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null): void
    {
        // Never let recording the audit trail break the action being recorded.
        try {
            $type = $subject?->getMorphClass();
        } catch (\Throwable) {
            $type = $subject ? \Illuminate\Support\Str::snake(class_basename($subject)) : null;
        }
        DB::table('audit_logs')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ? json_encode($old) : null,
            'new_values' => $new ? json_encode($new) : null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
