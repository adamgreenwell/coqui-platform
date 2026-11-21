<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Serp\DataForSeo\DataForSeoResponseArchive;
use App\Domains\Serp\DataForSeo\DataForSeoSpendGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DataForSeoStatusCommand extends Command
{
    protected $signature = 'dataforseo:status
        {--days=7 : Days of cache statistics to show}
        {--prune : Delete archived responses past the retention window}';

    protected $description = 'Show DataForSEO spend against caps, ledger health, balance reconciliation and cache hit rates.';

    public function handle(DataForSeoSpendGuard $guard, DataForSeoResponseArchive $archive): int
    {
        if ((bool) $this->option('prune')) {
            $this->info("Pruned {$archive->prune()} archived response(s).");

            return self::SUCCESS;
        }

        $daily = (float) config('coqui.dataforseo.daily_cap_usd', 5.0);
        $monthly = (float) config('coqui.dataforseo.monthly_cap_usd', 100.0);
        if ($guard->isSandbox()) {
            $this->comment('Sandbox mode: spend is not counted against caps.');
        }
        $this->table(['Window', 'Spend (USD)', 'Cap (USD)'], [
            ['Today', sprintf('%.4f', $guard->spendSince(now()->startOfDay())), sprintf('%.2f', $daily)],
            ['This month', sprintf('%.4f', $guard->spendSince(now()->startOfMonth())), sprintf('%.2f', $monthly)],
        ]);

        $statuses = DB::table('dataforseo_spend_ledger')
            ->where('recorded_at', '>=', now()->startOfMonth())
            ->selectRaw('status, count(*) as calls, sum(actual_cost_usd) as cost')
            ->groupBy('status')
            ->get();
        $this->table(['Ledger status (this month)', 'Calls', 'Cost (USD)'], $statuses->map(fn ($row): array => [
            (string) $row->status,
            (int) $row->calls,
            sprintf('%.4f', (float) $row->cost),
        ])->all());

        $snapshots = DB::table('dataforseo_balance_snapshots')->orderByDesc('captured_at')->limit(5)->get();
        $this->table(['Checked at', 'Balance', 'Charged', 'Ledger', 'Drift'], $snapshots->map(fn ($row): array => [
            (string) $row->captured_at,
            sprintf('%.2f', (float) $row->balance_usd),
            $row->interval_vendor_usd === null ? '-' : sprintf('%.4f', (float) $row->interval_vendor_usd),
            $row->interval_ledger_usd === null ? '-' : sprintf('%.4f', (float) $row->interval_ledger_usd),
            $row->drift_usd === null ? '-' : sprintf('%.4f', (float) $row->drift_usd),
        ])->all());

        $days = max(1, (int) $this->option('days'));
        $stats = DB::table('dataforseo_cache_stats')
            ->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->selectRaw('endpoint, sum(hits) as hits, sum(misses) as misses')
            ->groupBy('endpoint')
            ->orderBy('endpoint')
            ->get();
        $this->table(["Cache (last {$days} days)", 'Hits', 'Misses', 'Hit rate'], $stats->map(function ($row): array {
            $hits = (int) $row->hits;
            $total = $hits + (int) $row->misses;

            return [
                (string) $row->endpoint,
                $hits,
                (int) $row->misses,
                $total === 0 ? '-' : sprintf('%.0f%%', 100 * $hits / $total),
            ];
        })->all());

        return self::SUCCESS;
    }
}
