<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_backlinks', function (Blueprint $table): void {
            // Visible text of the link as last seen, to flag a changed anchor.
            $table->string('last_observed_anchor')->nullable()->after('last_observed_rel');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_backlinks', function (Blueprint $table): void {
            $table->dropColumn('last_observed_anchor');
        });
    }
};
