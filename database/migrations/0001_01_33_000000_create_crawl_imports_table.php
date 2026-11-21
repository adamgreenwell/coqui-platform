<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploads of crawl exports. The row is the record of what a client sent and
 * where it lives in object storage; the bytes are never on the app disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // The desktop app's own id for the file, so a retried request
            // updates the same row instead of queueing a second copy.
            $table->uuid('external_import_id');
            $table->string('original_name', 255);
            $table->string('disk', 32)->default('r2');
            $table->string('object_key', 512);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('crawl_run_id')->nullable()->constrained('crawl_runs')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'external_import_id']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_imports');
    }
};
