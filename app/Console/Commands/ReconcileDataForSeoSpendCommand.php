<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Serp\DataForSeo\DataForSeoReconciler;
use Illuminate\Console\Command;
use Throwable;

class ReconcileDataForSeoSpendCommand extends Command
{
    protected $signature = 'dataforseo:reconcile';

    protected $description = 'Compare the DataForSEO spend ledger with the account balance and alert operators on drift or thresholds (free call).';

    public function handle(DataForSeoReconciler $reconciler): int
    {
        try {
            $result = $reconciler->reconcile();
        } catch (Throwable $e) {
            $this->error("Reconciliation failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result['expired_reservations'] > 0) {
            $this->warn("{$result['expired_reservations']} reservation(s) never settled; marked unknown.");
        }

        if ($result['skipped'] !== null) {
            $this->info("Skipped: {$result['skipped']}.");

            return self::SUCCESS;
        }

        $snapshot = $result['snapshot'] ?? [];
        $this->info(sprintf('Balance: $%.2f', (float) ($snapshot['balance_usd'] ?? 0)));
        if (($snapshot['drift_usd'] ?? null) !== null) {
            $this->info(sprintf(
                'Since last check: charged $%.4f, ledger $%.4f, drift $%.4f',
                (float) $snapshot['interval_vendor_usd'],
                (float) $snapshot['interval_ledger_usd'],
                (float) $snapshot['drift_usd'],
            ));
        } else {
            $this->info('First snapshot; drift is measured from the next run.');
        }

        foreach ($result['alerts'] as $alert) {
            $this->warn("Alert: {$alert}");
        }

        return self::SUCCESS;
    }
}
