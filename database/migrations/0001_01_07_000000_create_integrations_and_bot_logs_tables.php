<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('provider', 32); // gsc | bing | ga4 | plausible | runnr
            $table->string('external_identifier')->nullable();
            $table->text('credentials_encrypted')->nullable();
            $table->string('status', 24)->default('connected');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'provider']);
        });

        Schema::create('bot_crawl_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('bot_family', 48)->index(); // googlebot | bingbot | gptbot | claudebot | perplexitybot
            $table->unsignedSmallInteger('status_bucket'); // 200, 300, 400, 500
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->unsignedBigInteger('bytes_served')->default(0);
            $table->jsonb('top_error_paths')->nullable();
            $table->timestamp('bucket_hour')->index();
            $table->timestamps();

            $table->unique(['project_id', 'bot_family', 'status_bucket', 'bucket_hour'], 'bot_log_bucket_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_crawl_logs');
        Schema::dropIfExists('project_integrations');
    }
};
