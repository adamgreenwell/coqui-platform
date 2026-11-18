<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_backlinks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->text('source_url');
            $table->string('source_url_hash', 64);
            $table->text('target_url');
            $table->string('expected_anchor')->nullable();
            $table->string('expected_rel', 64)->nullable();
            $table->string('state', 24)->default('active')->index(); // active | lost | rel_changed | error
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_observed_rel', 64)->nullable();
            $table->boolean('last_page_noindex')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'source_url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_backlinks');
    }
};
