<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CollectDataForSeoTasksCommand extends Command
{
    protected $signature = 'dataforseo:collect-tasks';

    protected $description = 'Collect completed DataForSEO standard-queue tasks missed by webhooks.';

    public function handle(): int
    {
        $pendingCount = DB::table('dataforseo_tasks')
            ->where('status', 'pending')
            ->count();

        $this->info("Pending DataForSEO tasks: {$pendingCount}");

        return self::SUCCESS;
    }
}
