<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Every 5 minutes - Auto-spy dispatch for active competitors
        $schedule->command('contentspy:dispatch-auto-spy')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Every minute - Check scheduled content
        $schedule->command('contentspy:dispatch-scheduled')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Daily at 3 AM - Refresh social tokens
        $schedule->command('contentspy:refresh-social-tokens')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/social-refresh.log'));

        // Daily at 4 AM - Expire old suggestions
        $schedule->command('contentspy:expire-suggestions')
            ->dailyAt('04:00')
            ->withoutOverlapping();

        // Daily at 5 AM - Purge old logs
        $schedule->command('contentspy:purge-logs')
            ->dailyAt('05:00')
            ->withoutOverlapping();

        // Hourly - Retry failed jobs
        $schedule->command('contentspy:retry-failed')
            ->hourly()
            ->withoutOverlapping();

        // Every 10 minutes - Queue monitoring
        $schedule->command('horizon:snapshot')
            ->everyTenMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
