<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per keyword per day that the backup rank provider was asked to
 * check, and a spend ledger with one row per paid request, dated when it
 * was paid. The ledger counts toward the platform caps and the workspace
 * allowance. Both outlive the keyword, project and workspace, so deleting
 * one never refunds spend that was already paid.
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
            // Responses fetched so far, so a retry resumes at the next page
            // instead of paying for the earlier ones again.
            $table->json('pages')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            // Unpaid part of the worst-case cost, held while the check runs so
            // concurrent checks can't all pass the caps against the same
            // remaining allowance.
            $table->decimal('reserved_usd', 10, 6)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tracked_keyword_id', 'checked_on']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('rank_fallback_spend', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rank_fallback_check_id')->nullable()->constrained('rank_fallback_checks')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->decimal('cost_usd', 10, 6);
            $table->timestamp('recorded_at')->index();

            $table->index(['workspace_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_fallback_spend');
        Schema::dropIfExists('rank_fallback_checks');
    }
};
