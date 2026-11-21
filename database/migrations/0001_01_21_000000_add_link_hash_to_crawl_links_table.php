<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links get a hash of (from, to, anchor, rel) so a retried chunk upserts
     * instead of inserting duplicates. Existing rows keep a NULL hash, which
     * a unique index never treats as a conflict, so no backfill is needed.
     */
    public function up(): void
    {
        Schema::table('crawl_links', function (Blueprint $table): void {
            $table->string('link_hash', 64)->nullable();
            $table->unique(['crawl_run_id', 'link_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('crawl_links', function (Blueprint $table): void {
            $table->dropUnique(['crawl_run_id', 'link_hash']);
            $table->dropColumn('link_hash');
        });
    }
};
