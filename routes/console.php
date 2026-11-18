<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Free: collect completed DataForSEO standard-queue tasks missed by postbacks.
Schedule::command('dataforseo:collect-tasks')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Submits daily/weekly rank-tracking batches when DATAFORSEO_SCHEDULED_SPEND is enabled.
Schedule::command('dataforseo:run-rank-batches')
    ->dailyAt('05:10')
    ->withoutOverlapping();

// Weekly verification of starred customer backlinks using coqui-worker.
Schedule::command('coqui:verify-backlinks')
    ->weeklyOn(1, '03:30')
    ->withoutOverlapping();
