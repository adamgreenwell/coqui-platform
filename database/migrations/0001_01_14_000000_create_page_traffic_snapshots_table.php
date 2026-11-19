<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_traffic_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('provider', 32); // ga4 | plausible
            $table->string('path', 2048);
            $table->string('path_hash', 64);
            $table->date('date');
            $table->unsignedInteger('organic_sessions')->default(0);
            $table->unsignedInteger('ai_referral_sessions')->default(0);
            $table->unsignedInteger('direct_sessions')->default(0);
            $table->unsignedInteger('total_sessions')->default(0);
            $table->decimal('bounce_rate', 5, 2)->default(0);
            $table->unsignedInteger('avg_engagement_time_sec')->default(0);
            $table->jsonb('ai_referrals_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'provider', 'path_hash', 'date'], 'page_traffic_unique');
            $table->index(['project_id', 'date'], 'page_traffic_date_idx');
            $table->index(['project_id', 'path_hash'], 'page_traffic_path_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_traffic_snapshots');
    }
};
