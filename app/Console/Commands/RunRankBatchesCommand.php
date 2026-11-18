<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RunRankBatchesCommand extends Command
{
    protected $signature = 'dataforseo:run-rank-batches';

    protected $description = 'Submit due rank tracking batches to DataForSEO standard queue.';

    public function handle(): int
    {
        if (! config('coqui.dataforseo.scheduled_spend')) {
            $this->info('Skipped: DATAFORSEO_SCHEDULED_SPEND is disabled.');

            return self::SUCCESS;
        }

        $this->info('Rank batch dispatch ready.');

        return self::SUCCESS;
    }
}
