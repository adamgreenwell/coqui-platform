<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlink_history_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->date('snapshot_date')->index();
            $table->unsignedBigInteger('backlinks_count')->default(0);
            $table->unsignedInteger('referring_domains_count')->default(0);
            $table->integer('new_backlinks')->default(0);
            $table->integer('lost_backlinks')->default(0);
            $table->integer('new_referring_domains')->default(0);
            $table->integer('lost_referring_domains')->default(0);
            $table->unsignedSmallInteger('rank')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlink_history_snapshots');
    }
};
