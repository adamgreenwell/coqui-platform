<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // The workspace a token was created for; it's refused everywhere
            // else. Null for tokens created before tokens were scoped.
            $table->foreignId('workspace_id')->nullable()->after('tokenable_id')
                ->constrained('workspaces')->cascadeOnDelete();
        });

        // Workspace-level audit trail (membership changes), shown alongside
        // each project's activity feed.
        Schema::create('workspace_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_activities');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
