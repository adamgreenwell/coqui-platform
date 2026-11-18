<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Serp\DataForSeo\DataForSeoClient;
use Illuminate\Console\Command;
use Throwable;

class SmokeDataForSeoCommand extends Command
{
    protected $signature = 'dataforseo:smoke';

    protected $description = 'Verify DataForSEO API credentials, connectivity, and account balance.';

    public function handle(DataForSeoClient $client): int
    {
        $baseUrl = (string) config('coqui.dataforseo.base_url');
        $isSandbox = str_contains($baseUrl, 'sandbox.dataforseo.com');

        $this->info("Checking DataForSEO connection [{$baseUrl}]...");
        if ($isSandbox) {
            $this->comment('Sandbox environment active ($0 cost testing).');
        }

        try {
            $userData = $client->userData();
        } catch (Throwable $e) {
            $this->error("DataForSEO API connection failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $statusCode = (int) ($userData['status_code'] ?? 0);
        if ($statusCode !== 20000) {
            $msg = (string) ($userData['status_message'] ?? 'Unknown error');
            $this->error("DataForSEO returned error [{$statusCode}]: {$msg}");

            return self::FAILURE;
        }

        /** @var array<string, mixed>|null $user */
        $user = $userData['tasks'][0]['result'][0] ?? null;

        $login = (string) ($user['login'] ?? config('coqui.dataforseo.login'));
        $balance = isset($user['money']['balance']) ? (float) $user['money']['balance'] : 0.0;
        $total = isset($user['money']['total']) ? (float) $user['money']['total'] : 0.0;

        $this->info("Authenticated as: {$login}");
        $this->info(sprintf('Account Balance: $%.2f (Total Deposited: $%.2f)', $balance, $total));

        return self::SUCCESS;
    }
}
