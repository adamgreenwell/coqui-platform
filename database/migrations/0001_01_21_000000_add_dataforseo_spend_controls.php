<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // reserved: written before the request, at the estimated cost.
        // settled: the response came back; actual cost recorded.
        // unknown: no response (timeout, connection error); the estimate
        //          stays because the call may still have been billed.
        // settled_at: when the row stopped being a reservation. Reconciliation
        // buckets spend by it, since a reservation can settle after the
        // balance snapshot that followed its request.
        Schema::table('dataforseo_spend_ledger', function (Blueprint $table): void {
            $table->string('status', 16)->default('settled')->index();
            $table->timestamp('settled_at')->nullable()->index();
        });

        Schema::create('dataforseo_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_id')->nullable()->constrained('dataforseo_spend_ledger')->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('endpoint', 120)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->jsonb('request_payload');
            $table->jsonb('response_payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });

        Schema::create('dataforseo_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->decimal('balance_usd', 12, 4);
            $table->decimal('total_deposited_usd', 12, 4);
            // Lifetime vendor spend (deposits minus balance), so top-ups don't
            // read as negative spend.
            $table->decimal('vendor_spent_usd', 12, 4);
            $table->decimal('interval_vendor_usd', 12, 4)->nullable();
            $table->decimal('interval_ledger_usd', 12, 4)->nullable();
            $table->decimal('drift_usd', 12, 4)->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });

        Schema::create('dataforseo_cache_stats', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('endpoint', 120);
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('misses')->default(0);
            $table->timestamps();

            $table->unique(['date', 'endpoint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataforseo_cache_stats');
        Schema::dropIfExists('dataforseo_balance_snapshots');
        Schema::dropIfExists('dataforseo_responses');

        Schema::table('dataforseo_spend_ledger', function (Blueprint $table): void {
            $table->dropIndex(['settled_at']);
            $table->dropIndex(['status']);
            $table->dropColumn(['settled_at', 'status']);
        });
    }
};
