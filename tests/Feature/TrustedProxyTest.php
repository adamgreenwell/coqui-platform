<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * In production TLS terminates at Coolify's proxy, then nginx forwards to
 * php-fpm over plain HTTP. Laravel must honour the forwarded headers or it
 * generates http:// URLs (mixed-content assets, insecure redirects).
 */
class TrustedProxyTest extends TestCase
{
    public function test_forwarded_https_scheme_and_host_are_trusted(): void
    {
        $this->get('/up', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'app.coquiseo.com',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-For' => '203.0.113.7',
        ])->assertOk();

        $request = request();
        $this->assertTrue($request->isSecure());
        $this->assertSame('https://app.coquiseo.com', $request->getSchemeAndHttpHost());
        $this->assertSame('203.0.113.7', $request->ip());
    }
}
