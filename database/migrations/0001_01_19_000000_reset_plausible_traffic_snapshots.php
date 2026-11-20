<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plausible syncs used to store a 30-day total for every page under the date
 * of the sync, so the traffic report added those totals up once per sync day.
 * Every stored Plausible row is such a total. Remove them and clear the last
 * sync time, so the next sync fetches the full window again as daily rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('page_traffic_snapshots')->where('provider', 'plausible')->delete();

        DB::table('project_integrations')
            ->where('provider', 'plausible')
            ->update(['last_synced_at' => null]);
    }

    public function down(): void
    {
        // The removed rows were wrong; there is nothing to restore.
    }
};
