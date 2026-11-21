<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Monthly range partitions of `bot_crawl_logs` on Postgres. Rows outside
 * every monthly partition land in `bot_crawl_logs_default`; creating a
 * month moves its rows out of the default partition first, so it never
 * fails on rows that arrived early.
 */
class BotCrawlLogPartitions
{
    public const TABLE = 'bot_crawl_logs';

    public static function supported(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * Ensure partitions exist from $from's month through $months months
     * after the current month.
     *
     * @return list<string> partitions created
     */
    public function ensure(int $months = 3, ?CarbonInterface $from = null): array
    {
        if (! self::supported()) {
            return [];
        }

        $start = CarbonImmutable::parse(($from ?? now())->toDateString())->startOfMonth();
        $end = CarbonImmutable::now()->startOfMonth()->addMonths(max(0, $months));

        $created = [];
        for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            if ($this->ensureMonth($month)) {
                $created[] = self::partitionName($month);
            }
        }

        return $created;
    }

    public static function partitionName(CarbonInterface $month): string
    {
        return sprintf('%s_%s', self::TABLE, $month->format('Y_m'));
    }

    private function ensureMonth(CarbonImmutable $month): bool
    {
        $name = self::partitionName($month);
        if ($this->exists($name)) {
            return false;
        }

        $from = $month->format('Y-m-d 00:00:00');
        $to = $month->addMonth()->format('Y-m-d 00:00:00');
        $table = self::TABLE;

        DB::transaction(function () use ($name, $from, $to, $table): void {
            $hasDefault = $this->exists("{$table}_default");
            if ($hasDefault) {
                DB::statement("CREATE TEMP TABLE bot_crawl_logs_move ON COMMIT DROP AS SELECT * FROM {$table}_default WHERE bucket_hour >= ? AND bucket_hour < ?", [$from, $to]);
                DB::statement("DELETE FROM {$table}_default WHERE bucket_hour >= ? AND bucket_hour < ?", [$from, $to]);
            }

            DB::statement("CREATE TABLE {$name} PARTITION OF {$table} FOR VALUES FROM ('{$from}') TO ('{$to}')");

            if ($hasDefault) {
                DB::statement("INSERT INTO {$table} SELECT * FROM bot_crawl_logs_move");
            }
        });

        return true;
    }

    private function exists(string $name): bool
    {
        return DB::selectOne('SELECT to_regclass(?) AS oid', [$name])?->oid !== null;
    }
}
