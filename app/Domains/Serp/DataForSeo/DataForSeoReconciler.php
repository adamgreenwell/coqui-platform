<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

use App\Models\User;
use App\Notifications\DataForSeoSpendAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Compares the spend ledger with the account balance DataForSEO reports
 * (`appendix/user_data`, free) and alerts platform operators on drift, a low
 * balance, or spend nearing a cap.
 */
class DataForSeoReconciler
{
    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly DataForSeoSpendGuard $spendGuard,
    ) {}

    /**
     * @return array{skipped: string|null, snapshot: array<string, float|null>|null, alerts: list<string>, expired_reservations: int}
     */
    public function reconcile(): array
    {
        $expired = $this->expireStaleReservations();

        if (! $this->client->isConfigured()) {
            return ['skipped' => 'not configured', 'snapshot' => null, 'alerts' => [], 'expired_reservations' => $expired];
        }
        if ($this->spendGuard->isSandbox()) {
            return ['skipped' => 'sandbox (no real balance)', 'snapshot' => null, 'alerts' => [], 'expired_reservations' => $expired];
        }

        $userData = $this->client->userData();
        if ((int) ($userData['status_code'] ?? 0) !== 20000) {
            throw new RuntimeException('DataForSEO user_data failed: '.(string) ($userData['status_message'] ?? 'unknown error'));
        }

        /** @var array<string, mixed> $money */
        $money = $userData['tasks'][0]['result'][0]['money'] ?? [];
        if (! isset($money['balance'], $money['total'])) {
            throw new RuntimeException('DataForSEO user_data has no balance.');
        }

        $balance = (float) $money['balance'];
        $deposited = (float) $money['total'];
        $vendorSpent = $deposited - $balance;
        $now = now();

        $previous = DB::table('dataforseo_balance_snapshots')->orderByDesc('captured_at')->orderByDesc('id')->first();

        $intervalVendor = null;
        $intervalLedger = null;
        $drift = null;
        if ($previous !== null) {
            $intervalVendor = $vendorSpent - (float) $previous->vendor_spent_usd;
            // Bucketed by settlement, not reservation: a request reserved
            // before the last snapshot but settled after it is counted here,
            // once, at its final cost. Open reservations wait for the
            // interval they settle in. Rows written before settled_at existed
            // fall back to recorded_at.
            $intervalLedger = (float) DB::table('dataforseo_spend_ledger')
                ->where('is_sandbox', false)
                ->where('status', '!=', DataForSeoSpendGuard::STATUS_RESERVED)
                ->whereRaw('COALESCE(settled_at, recorded_at) > ?', [$previous->captured_at])
                ->whereRaw('COALESCE(settled_at, recorded_at) <= ?', [$now])
                ->sum('actual_cost_usd');
            $drift = $intervalVendor - $intervalLedger;
        }

        DB::table('dataforseo_balance_snapshots')->insert([
            'balance_usd' => $balance,
            'total_deposited_usd' => $deposited,
            'vendor_spent_usd' => $vendorSpent,
            'interval_vendor_usd' => $intervalVendor,
            'interval_ledger_usd' => $intervalLedger,
            'drift_usd' => $drift,
            'captured_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $alerts = [];

        $driftLimit = (float) config('coqui.dataforseo.alerts.drift_usd', 1.0);
        if ($drift !== null && abs($drift) >= $driftLimit) {
            $alerts['drift'] = sprintf(
                'The account was charged $%.4f since the last check, but the ledger recorded $%.4f (drift $%.4f).',
                $intervalVendor,
                $intervalLedger,
                $drift,
            );
        }

        $lowBalance = (float) config('coqui.dataforseo.alerts.low_balance_usd', 10.0);
        if ($balance < $lowBalance) {
            $alerts['low_balance'] = sprintf('The account balance is $%.2f (alert below $%.2f).', $balance, $lowBalance);
        }

        $ratio = (float) config('coqui.dataforseo.alerts.cap_ratio', 0.8);
        $dailyCap = (float) config('coqui.dataforseo.daily_cap_usd', 5.0);
        $today = $this->spendGuard->spendSince($now->copy()->startOfDay());
        if ($dailyCap > 0 && $today >= $dailyCap * $ratio) {
            $alerts['daily_cap'] = sprintf('Spend today is $%.4f of the $%.2f daily cap.', $today, $dailyCap);
        }

        $monthlyCap = (float) config('coqui.dataforseo.monthly_cap_usd', 100.0);
        $month = $this->spendGuard->spendSince($now->copy()->startOfMonth());
        if ($monthlyCap > 0 && $month >= $monthlyCap * $ratio) {
            $alerts['monthly_cap'] = sprintf('Spend this month is $%.4f of the $%.2f monthly cap.', $month, $monthlyCap);
        }

        foreach ($alerts as $type => $message) {
            $this->alert($type, $message);
        }

        return [
            'skipped' => null,
            'snapshot' => [
                'balance_usd' => $balance,
                'total_deposited_usd' => $deposited,
                'interval_vendor_usd' => $intervalVendor,
                'interval_ledger_usd' => $intervalLedger,
                'drift_usd' => $drift,
            ],
            'alerts' => array_values($alerts),
            'expired_reservations' => $expired,
        ];
    }

    /**
     * A reservation never settled (the process died mid-request) keeps its
     * estimate but is marked unknown, so it shows up in diagnostics.
     */
    private function expireStaleReservations(): int
    {
        return DB::table('dataforseo_spend_ledger')
            ->where('status', DataForSeoSpendGuard::STATUS_RESERVED)
            ->where('recorded_at', '<', now()->subHour())
            ->update(['status' => DataForSeoSpendGuard::STATUS_UNKNOWN, 'settled_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Log and email operators, at most once a day per alert type.
     */
    private function alert(string $type, string $message): void
    {
        Log::warning("DataForSEO spend alert [{$type}]: {$message}");

        if (! Cache::add("dataforseo:alert:{$type}:".now()->toDateString(), true, now()->addDay())) {
            return;
        }

        $operators = User::query()->where('is_operator', true)->get();
        if ($operators->isNotEmpty()) {
            Notification::send($operators, new DataForSeoSpendAlert($type, $message));
        }
    }
}
