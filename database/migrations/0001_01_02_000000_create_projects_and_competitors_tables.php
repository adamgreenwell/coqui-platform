<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('domain');
            $table->string('canonical_url');
            $table->unsignedInteger('location_code')->default(2840);
            $table->string('language_code', 16)->default('en');
            $table->string('crawl_cadence', 24)->default('weekly');
            $table->string('runnr_site_id')->nullable()->index();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'domain']);
        });

        Schema::create('project_competitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('domain');
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_competitors');
        Schema::dropIfExists('projects');
    }
};
