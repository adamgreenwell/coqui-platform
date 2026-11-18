<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->uuid('external_crawl_id')->unique();
            $table->string('source', 24)->default('desktop')->index(); // desktop | worker | deploy_smoke
            $table->string('seed', 2048);
            $table->string('seed_kind', 24)->default('url'); // url | sitemap | list
            $table->string('status', 24)->default('pending')->index(); // pending | running | paused | completed | failed | cancelled
            $table->jsonb('config');
            $table->unsignedBigInteger('total_urls')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->unsignedBigInteger('warning_count')->default(0);
            $table->unsignedBigInteger('opportunity_count')->default(0);
            $table->unsignedBigInteger('client_created_at_ms')->nullable();
            $table->unsignedBigInteger('client_started_at_ms')->nullable();
            $table->unsignedBigInteger('client_finished_at_ms')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crawl_urls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawl_run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('client_row_id')->nullable();
            $table->text('url');
            $table->string('url_hash', 64);
            $table->unsignedSmallInteger('status')->nullable()->index();
            $table->string('content_type', 160)->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('h1')->nullable();
            $table->unsignedInteger('h1_count')->default(0);
            $table->text('canonical')->nullable();
            $table->string('meta_robots', 255)->nullable();
            $table->text('redirect_target')->nullable();
            $table->jsonb('redirect_chain')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->unsignedInteger('depth')->default(0);
            $table->unsignedBigInteger('response_time_ms')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('discovered_at_ms')->nullable();
            $table->timestamps();

            $table->unique(['crawl_run_id', 'url_hash']);
        });

        Schema::create('crawl_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawl_run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->text('from_url');
            $table->text('to_url');
            $table->text('anchor')->nullable();
            $table->string('rel', 120)->nullable();
            $table->boolean('internal')->default(true)->index();
            $table->timestamps();

            $table->index(['crawl_run_id', 'internal']);
        });

        Schema::create('crawl_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawl_run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->text('url');
            $table->string('url_hash', 64);
            $table->string('rule', 96)->index();
            $table->string('severity', 24)->index(); // error | warning | opportunity
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->unique(['crawl_run_id', 'url_hash', 'rule']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_issues');
        Schema::dropIfExists('crawl_links');
        Schema::dropIfExists('crawl_urls');
        Schema::dropIfExists('crawl_runs');
    }
};
