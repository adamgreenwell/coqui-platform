<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Integrations\Services\BotCrawlLogPartitions;
use Illuminate\Console\Command;

class EnsureBotCrawlLogPartitionsCommand extends Command
{
    protected $signature = 'telemetry:ensure-partitions {--months=3 : Months ahead of the current one}';

    protected $description = 'Create upcoming monthly partitions of the bot telemetry table (Postgres only).';

    public function handle(BotCrawlLogPartitions $partitions): int
    {
        if (! BotCrawlLogPartitions::supported()) {
            $this->info('Bot telemetry partitions need Postgres; nothing to do.');

            return self::SUCCESS;
        }

        $created = $partitions->ensure((int) $this->option('months'));
        $this->info(sprintf('Created %d bot telemetry partition(s).', count($created)));

        return self::SUCCESS;
    }
}
