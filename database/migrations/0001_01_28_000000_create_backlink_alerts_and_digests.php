<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlink alerts are evaluated on a schedule and stored once per event
 * (dedupe_key), instead of being re-detected and re-broadcast on every
 * page view. Members can opt into a weekly email digest, and editors can
 * set a Slack incoming webhook for the project. The project also records
 * when its backlink history was last fetched, so an empty history isn't
 * re-fetched on every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlink_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('severity', 16);
            $table->string('dedupe_key', 128);
            $table->text('message');
            $table->json('meta')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['project_id', 'type', 'dedupe_key']);
            $table->index(['project_id', 'detected_at']);
        });

        Schema::create('backlink_digest_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('backlink_history_checked_at')->nullable();
            // Encrypted Slack incoming-webhook URL.
            $table->text('backlink_digest_slack_webhook')->nullable();
            $table->timestamp('backlink_digest_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['backlink_history_checked_at', 'backlink_digest_slack_webhook', 'backlink_digest_sent_at']);
        });
        Schema::dropIfExists('backlink_digest_subscribers');
        Schema::dropIfExists('backlink_alerts');
    }
};
