<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Periodically sync every enabled provider. Interval is config-driven
// (SYNC_INTERVAL_MINUTES, 5–10); withoutOverlapping guards the dispatcher itself.
Schedule::command('sync:dispatch')
    ->cron('*/'.max(1, (int) config('sync.interval_minutes', 5)).' * * * *')
    ->withoutOverlapping();
