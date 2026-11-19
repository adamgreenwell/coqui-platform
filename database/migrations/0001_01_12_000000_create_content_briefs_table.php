<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_briefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('keyword', 255)->index();
            $table->unsignedInteger('location_code')->default(2840);
            $table->string('language_code', 12)->default('en');
            $table->unsignedInteger('search_volume')->default(0);
            $table->decimal('cpc', 8, 2)->default(0.00);
            $table->unsignedInteger('recommended_words_min')->default(1500);
            $table->unsignedInteger('recommended_words_max')->default(2500);
            $table->jsonb('data');
            $table->mediumText('markdown');
            $table->timestamps();

            $table->index(['project_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_briefs');
    }
};
