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

// Free: compare the DataForSEO ledger with the account balance; alert operators on drift or thresholds.
Schedule::command('dataforseo:reconcile')
    ->hourly()
    ->withoutOverlapping();

// Drop archived DataForSEO responses past DATAFORSEO_ARCHIVE_RETENTION_DAYS.
Schedule::command('dataforseo:status --prune')
    ->dailyAt('03:15')
    ->withoutOverlapping();

// Re-checks rank tasks that failed or stalled with the backup SERP provider.
Schedule::command('rank:fallback')
    ->hourly()
    ->withoutOverlapping();

// Weekly verification of starred customer backlinks using coqui-worker.
Schedule::command('coqui:verify-backlinks')
    ->weeklyOn(1, '03:30')
    ->withoutOverlapping();

// Nightly synchronization of Google Search Console and Bing Webmaster performance metrics.
Schedule::command('search-console:sync')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Nightly synchronization of GA4 / Plausible page traffic into the attribution report.
Schedule::command('traffic:sync')
    ->dailyAt('04:30')
    ->withoutOverlapping();

// Daily run checking project crawl cadence and dispatching cloud site audits to coqui-worker.
Schedule::command('coqui:run-scheduled-crawls')
    ->dailyAt('02:00')
    ->withoutOverlapping();
