<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_diff_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('baseline_run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->foreignId('target_run_id')->constrained('crawl_runs')->cascadeOnDelete();
            $table->string('share_token', 64)->unique();
            $table->string('title')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('view_count')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_diff_reports');
    }
};
