<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a public UUID to every model that appears in a URL, so routes stop
 * exposing sequential primary keys. Integer IDs remain the primary and foreign
 * keys. Existing rows are backfilled before the column becomes required.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'users',
        'workspaces',
        'personal_access_tokens',
        'projects',
        'project_competitors',
        'tracked_keywords',
        'monitored_backlinks',
        'project_integrations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('uuid')->nullable()->after('id');
            });

            DB::table($table)
                ->whereNull('uuid')
                ->select('id')
                ->chunkById(500, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['uuid' => (string) Str::uuid7()]);
                    }
                });

            Schema::table($table, function (Blueprint $blueprint): void {
                // SQLite (tests only) can't alter a column in place: Laravel
                // rebuilds the table, and dropping a populated parent table
                // fires its foreign-key cascades. Postgres just sets NOT NULL.
                if (DB::getDriverName() !== 'sqlite') {
                    $blueprint->uuid('uuid')->nullable(false)->change();
                }

                $blueprint->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_uuid_unique');
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
