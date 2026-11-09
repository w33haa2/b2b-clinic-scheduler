<?php

use App\Actions\Clinics\AdvanceDailySessionDays;
use App\Actions\Clinics\ScheduleDailySessionDays;
use App\Actions\Clinics\SyncClinicPermissions;
use App\Models\ActivityLog;
use App\Models\DailySession;
use App\Support\ActivityLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clinic:sync-permissions', function (SyncClinicPermissions $sync) {
    ['added' => $added, 'removed' => $removed] = $sync->handle();

    $this->info(sprintf('Clinic permissions synced: %d added, %d removed.', count($added), count($removed)));

    foreach ($added as $name) {
        $this->line("  + {$name}");
    }

    foreach ($removed as $name) {
        $this->line("  - {$name}");
    }
})->purpose('Create a clinic permission for every permission-guarded clinic API route');

// Delete activity log rows older than config('activity.retention_days').
Schedule::command('model:prune', ['--model' => [ActivityLog::class]])->daily();

Artisan::command('clinic:schedule-daily-sessions', function (ScheduleDailySessionDays $scheduleDays, ActivityLogger $logger) {
    $created = 0;

    DailySession::query()
        ->with('clinic')
        ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->subDay()->toDateString()))
        ->each(function (DailySession $dailySession) use ($scheduleDays, $logger, &$created): void {
            $days = $scheduleDays->handle($dailySession);

            if ($days->isNotEmpty()) {
                $created += $days->count();
                $logger->log(
                    action: 'daily_session.days_scheduled',
                    subject: $dailySession,
                    clinicId: $dailySession->clinic_id,
                    label: $dailySession->activityLabel(),
                    context: ['days' => $days->count(), 'until' => $days->last()->occurs_on?->toDateString()],
                );
            }
        });

    $this->info("Scheduled {$created} daily session days.");
})->purpose('Create the upcoming days of every daily session that is still running');

// Keep every daily session scheduled ScheduleDailySessionDays::DAYS_AHEAD days ahead.
Schedule::command('clinic:schedule-daily-sessions')->hourly();

Artisan::command('clinic:advance-daily-session-days', function (AdvanceDailySessionDays $advance) {
    $this->info(sprintf('%d daily session days started or finished.', $advance->handle()));
})->purpose('Start daily session days on their date and finish them when their hours end');

// Days of daily sessions start and finish on their own.
Schedule::command('clinic:advance-daily-session-days')->everyMinute();
