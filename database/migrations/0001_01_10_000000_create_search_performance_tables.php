<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_performance_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('provider', 32); // gsc | bing
            $table->string('query', 255);
            $table->date('date');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'provider', 'query', 'date'], 'search_perf_queries_unique');
            $table->index(['project_id', 'date'], 'search_perf_queries_date_idx');
            $table->index(['project_id', 'query'], 'search_perf_queries_query_idx');
        });

        Schema::create('search_performance_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('provider', 32); // gsc | bing
            $table->text('url');
            $table->string('url_hash', 64);
            $table->date('date');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0);
            $table->decimal('position', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'provider', 'url_hash', 'date'], 'search_perf_pages_unique');
            $table->index(['project_id', 'date'], 'search_perf_pages_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_performance_pages');
        Schema::dropIfExists('search_performance_queries');
    }
};
