<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Services\SearchPerformanceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncGoogleSearchConsoleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly ProjectIntegration $integration
    ) {}

    public function handle(SearchPerformanceSyncService $syncService): void
    {
        $syncService->syncGoogleSearchConsole($this->integration);
    }
}
