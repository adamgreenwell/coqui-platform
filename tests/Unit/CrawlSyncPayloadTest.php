<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Crawls\DTOs\CrawlSyncPayload;
use PHPUnit\Framework\TestCase;

class CrawlSyncPayloadTest extends TestCase
{
    public function test_it_scrubs_secrets_from_coqui_core_crawl_config(): void
    {
        $config = [
            'maxDepth' => 3,
            'maxUrls' => 500,
            'authUser' => 'staging-admin',
            'authPassword' => 'super-secret-password',
            'customHeaders' => [
                'Authorization: Bearer secret-token',
                'X-Coqui-Env: staging',
                'Cookie: session=abc123',
            ],
        ];

        $scrubbed = CrawlSyncPayload::scrubConfigSecrets($config);

        $this->assertArrayNotHasKey('authPassword', $scrubbed);
        $this->assertSame('[redacted]', $scrubbed['authUser']);
        $this->assertSame([
            'Authorization: [redacted]',
            'X-Coqui-Env: staging',
            'Cookie: [redacted]',
        ], $scrubbed['customHeaders']);
    }
}
