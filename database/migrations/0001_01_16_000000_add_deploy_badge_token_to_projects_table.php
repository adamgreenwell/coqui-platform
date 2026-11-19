<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // Unguessable token for the public deploy-smoke badge URL, so the
            // badge no longer exposes or allows enumeration of project IDs.
            $table->string('deploy_badge_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['deploy_badge_token']);
            $table->dropColumn('deploy_badge_token');
        });
    }
};
