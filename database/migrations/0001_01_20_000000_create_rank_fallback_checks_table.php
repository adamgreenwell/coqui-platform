<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per keyword per day that the backup rank provider was asked to
 * check, with what it cost. The cost column is also the fallback's spend
 * ledger, so it counts toward the platform caps and workspace allowance.
 * Rows outlive the keyword, project and workspace so deleting one never
 * refunds spend that was already paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_fallback_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tracked_keyword_id')->nullable()->constrained('tracked_keywords')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            // The primary rank task this replaces, when there was one.
            $table->foreignId('dataforseo_task_id')->nullable()->constrained('dataforseo_tasks')->nullOnDelete();
            $table->string('reason', 24);
            $table->string('status', 16)->default('pending')->index();
            $table->date('checked_on');
            // The primary result counts if it arrived after this time.
            $table->timestamp('reference_at');
            $table->unsignedTinyInteger('pages_fetched')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            // Worst-case cost held while the check runs, so concurrent checks
            // can't all pass the caps against the same remaining allowance.
            $table->decimal('reserved_usd', 10, 6)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tracked_keyword_id', 'checked_on']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_fallback_checks');
    }
};
