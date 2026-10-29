<?php

use Illuminate\Support\Facades\Schedule;

/*
 * One cron line on the server runs all of this:
 *   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
 */

// Background jobs (large report exports). Shared hosting has no supervisor, so the
// scheduler starts a short-lived worker every minute instead of a permanent daemon.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')->everyMinute()->withoutOverlapping();

// Online-payment orders that were never paid release their reserved stock.
Schedule::command('shop:expire-unpaid-orders')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('shop:backup --keep=14')->dailyAt('02:30')->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();

// Generated export files are temporary.
Schedule::call(function () {
    foreach (glob(storage_path('app/private/exports/*')) ?: [] as $f) {
        if (filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }
    \Illuminate\Support\Facades\DB::table('report_exports')->where('created_at', '<', now()->subDays(2))->delete();
})->daily();
