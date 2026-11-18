<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('keyword');
            $table->string('device', 16)->default('desktop');
            $table->unsignedInteger('location_code')->default(2840);
            $table->string('language_code', 16)->default('en');
            $table->string('cadence', 16)->default('weekly');
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedSmallInteger('keyword_difficulty')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'keyword', 'device', 'location_code', 'language_code'], 'tracked_kw_unique');
        });

        Schema::create('keyword_rank_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tracked_keyword_id')->constrained('tracked_keywords')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('domain');
            $table->unsignedSmallInteger('rank_group')->nullable();
            $table->unsignedSmallInteger('rank_absolute')->nullable();
            $table->text('ranking_url')->nullable();
            $table->string('ranking_title')->nullable();
            $table->boolean('has_ai_overview')->default(false);
            $table->boolean('has_featured_snippet')->default(false);
            $table->jsonb('serp_features')->nullable();
            $table->date('checked_on')->index();
            $table->timestamps();

            $table->unique(['tracked_keyword_id', 'domain', 'checked_on'], 'kw_rank_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_rank_snapshots');
        Schema::dropIfExists('tracked_keywords');
    }
};
