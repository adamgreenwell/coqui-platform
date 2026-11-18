<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataforseo_cache', function (Blueprint $table): void {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->string('endpoint', 120)->index();
            $table->jsonb('request_signature');
            $table->jsonb('payload');
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('dataforseo_spend_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('endpoint', 120)->index();
            $table->string('mode', 24)->default('standard');
            $table->string('external_task_id', 120)->nullable()->index();
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->decimal('actual_cost_usd', 12, 6)->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('is_sandbox')->default(false);
            $table->jsonb('meta')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });

        Schema::create('dataforseo_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('external_task_id', 120)->unique();
            $table->string('endpoint', 120);
            $table->string('task_type', 48)->index();
            $table->string('status', 24)->default('pending')->index();
            $table->string('postback_token', 64);
            $table->jsonb('request_context')->nullable();
            $table->jsonb('result_payload')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('collected_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataforseo_tasks');
        Schema::dropIfExists('dataforseo_spend_ledger');
        Schema::dropIfExists('dataforseo_cache');
    }
};
