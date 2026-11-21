<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sources an AI Overview cited for a tracked keyword on a given day, in
 * the order they appeared. A re-check on the same day replaces them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_ai_overview_citations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tracked_keyword_id')->constrained('tracked_keywords')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->date('checked_on');
            $table->unsignedSmallInteger('position');
            $table->string('domain');
            $table->text('url')->nullable();
            $table->string('title', 500)->nullable();
            $table->timestamps();

            $table->unique(['tracked_keyword_id', 'checked_on', 'position'], 'ai_overview_citations_unique');
            $table->index(['project_id', 'checked_on']);
            $table->index(['project_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_ai_overview_citations');
    }
};
