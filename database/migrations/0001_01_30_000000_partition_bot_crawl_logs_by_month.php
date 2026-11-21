<?php

declare(strict_types=1);

use App\Domains\Integrations\Services\BotCrawlLogPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * On Postgres, rebuild `bot_crawl_logs` as a table partitioned by month on
 * bucket_hour, so old months can be dropped cheaply and queries over a
 * date range only touch those months. Other drivers keep the plain table.
 * `telemetry:ensure-partitions` creates upcoming months daily.
 */
return new class extends Migration
{
    private const COLUMNS = 'id, project_id, bot_family, status_bucket, hit_count, bytes_served, parameter_hits, batch_id, top_paths, bucket_hour, created_at, updated_at';

    public function up(): void
    {
        if (! BotCrawlLogPartitions::supported()) {
            return;
        }

        DB::transaction(function (): void {
            // Lock out ingestion first: taking the sequence lock before the
            // table lock could deadlock with a concurrent INSERT.
            DB::statement('LOCK TABLE bot_crawl_logs IN ACCESS EXCLUSIVE MODE');
            // Keep the id sequence when the old table is dropped.
            DB::statement('ALTER SEQUENCE bot_crawl_logs_id_seq OWNED BY NONE');

            DB::statement(<<<'SQL'
                CREATE TABLE bot_crawl_logs_partitioned (
                    id bigint NOT NULL DEFAULT nextval('bot_crawl_logs_id_seq'),
                    project_id bigint NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                    bot_family varchar(48) NOT NULL,
                    status_bucket smallint NOT NULL,
                    hit_count bigint NOT NULL DEFAULT 0,
                    bytes_served bigint NOT NULL DEFAULT 0,
                    parameter_hits bigint NOT NULL DEFAULT 0,
                    batch_id varchar(64) NOT NULL DEFAULT '',
                    top_paths jsonb NULL,
                    bucket_hour timestamp(0) without time zone NOT NULL,
                    created_at timestamp(0) without time zone NULL,
                    updated_at timestamp(0) without time zone NULL,
                    CONSTRAINT bot_crawl_logs_part_pkey PRIMARY KEY (id, bucket_hour)
                ) PARTITION BY RANGE (bucket_hour)
                SQL);
            DB::statement('CREATE TABLE bot_crawl_logs_partitioned_default PARTITION OF bot_crawl_logs_partitioned DEFAULT');

            $earliest = DB::table('bot_crawl_logs')->min('bucket_hour');
            $this->createMonths('bot_crawl_logs_partitioned', $earliest !== null ? CarbonImmutable::parse((string) $earliest) : now()->toImmutable());

            $columns = self::COLUMNS;
            DB::statement("INSERT INTO bot_crawl_logs_partitioned ({$columns}) SELECT {$columns} FROM bot_crawl_logs");
            DB::statement('DROP TABLE bot_crawl_logs');

            DB::statement('ALTER TABLE bot_crawl_logs_partitioned RENAME TO bot_crawl_logs');
            DB::statement('ALTER TABLE bot_crawl_logs_partitioned_default RENAME TO bot_crawl_logs_default');
            DB::statement('ALTER SEQUENCE bot_crawl_logs_id_seq OWNED BY bot_crawl_logs.id');

            DB::statement('ALTER TABLE bot_crawl_logs ADD CONSTRAINT bot_log_batch_unique UNIQUE (project_id, bot_family, status_bucket, bucket_hour, batch_id)');
            DB::statement('CREATE INDEX bot_log_project_batch_index ON bot_crawl_logs (project_id, batch_id)');
            DB::statement('CREATE INDEX bot_log_project_hour_index ON bot_crawl_logs (project_id, bucket_hour)');
            DB::statement('CREATE INDEX bot_log_bot_family_index ON bot_crawl_logs (bot_family)');
        });
    }

    public function down(): void
    {
        if (! BotCrawlLogPartitions::supported()) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE bot_crawl_logs IN ACCESS EXCLUSIVE MODE');
            DB::statement('ALTER SEQUENCE bot_crawl_logs_id_seq OWNED BY NONE');
            DB::statement(<<<'SQL'
                CREATE TABLE bot_crawl_logs_plain (
                    id bigint NOT NULL DEFAULT nextval('bot_crawl_logs_id_seq') PRIMARY KEY,
                    project_id bigint NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                    bot_family varchar(48) NOT NULL,
                    status_bucket smallint NOT NULL,
                    hit_count bigint NOT NULL DEFAULT 0,
                    bytes_served bigint NOT NULL DEFAULT 0,
                    parameter_hits bigint NOT NULL DEFAULT 0,
                    batch_id varchar(64) NOT NULL DEFAULT '',
                    top_paths jsonb NULL,
                    bucket_hour timestamp(0) without time zone NOT NULL,
                    created_at timestamp(0) without time zone NULL,
                    updated_at timestamp(0) without time zone NULL
                )
                SQL);
            $columns = self::COLUMNS;
            DB::statement("INSERT INTO bot_crawl_logs_plain ({$columns}) SELECT {$columns} FROM bot_crawl_logs");
            DB::statement('DROP TABLE bot_crawl_logs');
            DB::statement('ALTER TABLE bot_crawl_logs_plain RENAME TO bot_crawl_logs');
            DB::statement('ALTER SEQUENCE bot_crawl_logs_id_seq OWNED BY bot_crawl_logs.id');
            // A constraint, as the earlier migration's rollback expects.
            DB::statement('ALTER TABLE bot_crawl_logs ADD CONSTRAINT bot_log_batch_unique UNIQUE (project_id, bot_family, status_bucket, bucket_hour, batch_id)');
            DB::statement('CREATE INDEX bot_log_project_batch_index ON bot_crawl_logs (project_id, batch_id)');
            DB::statement('CREATE INDEX bot_crawl_logs_bot_family_index ON bot_crawl_logs (bot_family)');
            DB::statement('CREATE INDEX bot_crawl_logs_bucket_hour_index ON bot_crawl_logs (bucket_hour)');
        });
    }

    /**
     * Monthly partitions from the earliest stored month through three
     * months ahead. Created here directly, because the table being built
     * isn't yet named bot_crawl_logs.
     */
    private function createMonths(string $table, CarbonImmutable $earliest): void
    {
        $end = now()->toImmutable()->startOfMonth()->addMonths(3);
        // Anything older than two years stays in the default partition.
        $start = $earliest->startOfMonth()->max(now()->toImmutable()->startOfMonth()->subMonths(24));
        for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            $from = $month->format('Y-m-d 00:00:00');
            $to = $month->addMonth()->format('Y-m-d 00:00:00');
            $name = BotCrawlLogPartitions::partitionName($month);
            DB::statement("CREATE TABLE {$name} PARTITION OF {$table} FOR VALUES FROM ('{$from}') TO ('{$to}')");
        }
    }
};
