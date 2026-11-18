<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seranking_spend_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('endpoint', 120)->index();
            $table->unsignedInteger('estimated_credits')->default(0);
            $table->unsignedInteger('actual_credits')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });

        Schema::create('seranking_archive', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('endpoint', 120)->index();
            $table->string('source_region', 16)->default('us');
            $table->string('target_domain')->nullable()->index();
            $table->string('payload_hash', 64)->index();
            $table->jsonb('payload');
            $table->timestamp('first_captured_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seranking_archive');
        Schema::dropIfExists('seranking_spend_ledger');
    }
};
