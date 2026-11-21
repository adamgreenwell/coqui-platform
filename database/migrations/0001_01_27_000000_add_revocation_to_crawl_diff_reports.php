<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Share links can be revoked, and get a public UUID so editors can manage
 * them without the token appearing in management URLs. Links created
 * without an expiry get the default 30 days from now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_diff_reports', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('crawl_diff_reports')->whereNull('uuid')->orderBy('id')->each(function (object $row): void {
            DB::table('crawl_diff_reports')->where('id', $row->id)->update(['uuid' => (string) Str::uuid7()]);
        });

        DB::table('crawl_diff_reports')->whereNull('expires_at')->update(['expires_at' => now()->addDays(30)]);
    }

    public function down(): void
    {
        Schema::table('crawl_diff_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'revoked_at']);
        });
    }
};
