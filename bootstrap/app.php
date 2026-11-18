<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Free: collect completed DataForSEO standard-queue tasks missed by postbacks.
        $schedule
            ->command('dataforseo:collect-tasks')
            ->everyTenMinutes()
            ->withoutOverlapping();

        // Submits daily/weekly rank-tracking batches when DATAFORSEO_SCHEDULED_SPEND is enabled.
        $schedule
            ->command('dataforseo:run-rank-batches')
            ->dailyAt('05:10')
            ->withoutOverlapping();

        // Weekly verification of starred customer backlinks using coqui-worker.
        $schedule
            ->command('coqui:verify-backlinks')
            ->weeklyOn(1, '03:30')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
