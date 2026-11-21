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

// Re-checks rank tasks that failed or stalled with the backup SERP provider.
Schedule::command('rank:fallback')
    ->hourly()
    ->withoutOverlapping();

// Weekly verification of starred customer backlinks using coqui-worker.
Schedule::command('coqui:verify-backlinks')
    ->weeklyOn(1, '03:30')
    ->withoutOverlapping();

// Free: records backlink drop and spam alerts once each from stored data.
// With DATAFORSEO_SCHEDULED_SPEND it first refreshes week-old histories.
Schedule::command('backlinks:evaluate-alerts')
    ->dailyAt('05:40')
    ->withoutOverlapping();

// Weekly backlink digest by email and Slack.
Schedule::command('backlinks:send-digests')
    ->weeklyOn(1, '07:00')
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
