<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes activity log rows. The actor is the authenticated user (web session
 * or API token) unless one is given; the IP comes from the current request.
 */
class ActivityLogger
{
    private static int $quietDepth = 0;

    /**
     * Run the callback without the automatic model-event entries. Use it for
     * bulk work (e.g. scheduling the days of a daily session) and log one
     * summary entry yourself instead.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function quietly(callable $callback): mixed
    {
        self::$quietDepth++;

        try {
            return $callback();
        } finally {
            self::$quietDepth--;
        }
    }

    /**
     * Whether model events are currently not being logged.
     */
    public static function isQuiet(): bool
    {
        return self::$quietDepth > 0;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     * @param  array<string, mixed>  $context
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?int $clinicId = null,
        ?string $label = null,
        ?array $changes = null,
        array $context = [],
        ?User $actor = null,
    ): ActivityLog {
        $actor ??= Auth::user();

        return ActivityLog::query()->create([
            'clinic_id' => $clinicId,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'changes' => $changes ?: null,
            'context' => $context ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
