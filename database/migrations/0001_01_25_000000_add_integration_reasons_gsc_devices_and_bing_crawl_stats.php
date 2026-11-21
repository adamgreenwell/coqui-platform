<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why an integration needs reconnecting, shown on the reconnect banners.
        Schema::table('project_integrations', function (Blueprint $table): void {
            $table->string('status_reason', 500)->nullable()->after('status');
        });

        // Search Console rows per device; "all" for Bing and for rows synced
        // before the device split.
        Schema::table('search_performance_queries', function (Blueprint $table): void {
            $table->string('device', 16)->default('all')->after('query');
        });
        Schema::table('search_performance_queries', function (Blueprint $table): void {
            $table->dropUnique('search_perf_queries_unique');
            $table->unique(['project_id', 'provider', 'query', 'device', 'date'], 'search_perf_queries_unique');
        });

        // Daily crawl stats from Bing Webmaster Tools.
        Schema::create('bing_crawl_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('crawled_pages')->default(0);
            $table->unsignedInteger('crawl_errors')->default(0);
            $table->unsignedInteger('in_index')->default(0);
            $table->unsignedInteger('in_links')->default(0);
            $table->unsignedInteger('code_2xx')->default(0);
            $table->unsignedInteger('code_301')->default(0);
            $table->unsignedInteger('code_302')->default(0);
            $table->unsignedInteger('code_4xx')->default(0);
            $table->unsignedInteger('code_5xx')->default(0);
            $table->unsignedInteger('blocked_by_robots')->default(0);
            $table->unsignedInteger('dns_failures')->default(0);
            $table->unsignedInteger('connection_timeouts')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_crawl_stats');

        // Fold per-device rows back into one row per query and date, so the
        // old unique key can be restored.
        $groups = DB::table('search_performance_queries')
            ->selectRaw('project_id, provider, query, date, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as weighted_position, AVG(position) as avg_position, MIN(created_at) as created_at, MAX(updated_at) as updated_at')
            ->groupBy('project_id', 'provider', 'query', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $impressions = (int) $group->impressions;
            $clicks = (int) $group->clicks;

            DB::transaction(function () use ($group, $impressions, $clicks): void {
                DB::table('search_performance_queries')
                    ->where('project_id', $group->project_id)
                    ->where('provider', $group->provider)
                    ->where('query', $group->query)
                    ->where('date', $group->date)
                    ->delete();

                DB::table('search_performance_queries')->insert([
                    'project_id' => $group->project_id,
                    'provider' => $group->provider,
                    'query' => $group->query,
                    'device' => 'all',
                    'date' => $group->date,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0,
                    'position' => $impressions > 0 ? round((float) $group->weighted_position / $impressions, 2) : round((float) $group->avg_position, 2),
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ]);
            });
        }

        Schema::table('search_performance_queries', function (Blueprint $table): void {
            $table->dropUnique('search_perf_queries_unique');
        });
        Schema::table('search_performance_queries', function (Blueprint $table): void {
            $table->dropColumn('device');
            $table->unique(['project_id', 'provider', 'query', 'date'], 'search_perf_queries_unique');
        });

        Schema::table('project_integrations', function (Blueprint $table): void {
            $table->dropColumn('status_reason');
        });
    }
};
