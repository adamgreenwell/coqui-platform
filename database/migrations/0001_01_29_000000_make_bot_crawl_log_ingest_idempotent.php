<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bot telemetry is stored per ingest batch: each batch's rows carry its
 * batch_id, and a resent batch replaces its own rows instead of adding to
 * the hour bucket again. Rows also keep the batch's top crawled paths (all
 * statuses, not only errors) and its hits on parameterized URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_crawl_logs', function (Blueprint $table): void {
            $table->string('batch_id', 64)->default('');
            $table->unsignedBigInteger('parameter_hits')->default(0);
            $table->jsonb('top_paths')->nullable();
        });

        DB::table('bot_crawl_logs')->update(['top_paths' => DB::raw('top_error_paths')]);

        Schema::table('bot_crawl_logs', function (Blueprint $table): void {
            $table->dropUnique('bot_log_bucket_unique');
            $table->unique(['project_id', 'bot_family', 'status_bucket', 'bucket_hour', 'batch_id'], 'bot_log_batch_unique');
            $table->index(['project_id', 'batch_id'], 'bot_log_project_batch_index');
            $table->dropColumn('top_error_paths');
        });
    }

    public function down(): void
    {
        Schema::table('bot_crawl_logs', function (Blueprint $table): void {
            $table->jsonb('top_error_paths')->nullable();
        });

        // Fold each hour bucket's batches back into one row.
        $groups = DB::table('bot_crawl_logs')
            ->select('project_id', 'bot_family', 'status_bucket', 'bucket_hour')
            ->groupBy('project_id', 'bot_family', 'status_bucket', 'bucket_hour')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $g) {
            $rows = DB::table('bot_crawl_logs')
                ->where('project_id', $g->project_id)
                ->where('bot_family', $g->bot_family)
                ->where('status_bucket', $g->status_bucket)
                ->where('bucket_hour', $g->bucket_hour)
                ->orderBy('id')
                ->get();
            $keep = $rows->first();
            DB::table('bot_crawl_logs')->where('id', $keep->id)->update([
                'hit_count' => $rows->sum('hit_count'),
                'bytes_served' => $rows->sum('bytes_served'),
            ]);
            DB::table('bot_crawl_logs')->whereIn('id', $rows->pluck('id')->slice(1)->all())->delete();
        }

        DB::table('bot_crawl_logs')->update(['top_error_paths' => DB::raw('top_paths')]);

        Schema::table('bot_crawl_logs', function (Blueprint $table): void {
            $table->dropUnique('bot_log_batch_unique');
            $table->dropIndex('bot_log_project_batch_index');
            $table->unique(['project_id', 'bot_family', 'status_bucket', 'bucket_hour'], 'bot_log_bucket_unique');
            $table->dropColumn(['batch_id', 'parameter_hits', 'top_paths']);
        });
    }
};
