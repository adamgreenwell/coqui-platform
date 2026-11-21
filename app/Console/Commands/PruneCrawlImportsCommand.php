<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Crawls\Services\CrawlImportService;
use Illuminate\Console\Command;

class PruneCrawlImportsCommand extends Command
{
    protected $signature = 'imports:prune';

    protected $description = 'Delete expired uploads and old failed imports, objects included.';

    public function handle(CrawlImportService $imports): int
    {
        $result = $imports->prune();

        $this->info(sprintf(
            'Pruned %d expired upload(s) and %d old failure(s).',
            $result['pending'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
