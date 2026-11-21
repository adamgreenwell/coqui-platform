<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
