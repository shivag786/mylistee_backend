<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled tasks (Milestone 18). Requires one cron entry on the server:
//   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('app:maintenance')->dailyAt('03:00')->withoutOverlapping();
