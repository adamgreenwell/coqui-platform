<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Backlinks\Services\BacklinkVerificationService;
use App\Domains\Crawls\Exceptions\SsrfProtectionException;
use App\Domains\Crawls\Services\SafeHttpClient;
use App\Domains\Crawls\Services\SsrfProtectionGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeHttpClientTest extends TestCase
{
    private SafeHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new SafeHttpClient(new FixedDnsGuard([
            'public.example' => ['93.184.216.34'],
            'other.example' => ['93.184.216.35'],
            'rebind.example' => ['10.0.0.5'],
        ]));
    }

    public function test_redirect_to_cloud_metadata_is_blocked_before_it_is_requested(): void
    {
        Http::fake([
            'http://public.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response('SECRET', 200),
        ]);

        try {
            $this->client->get('http://public.example/start');
            $this->fail('Expected the metadata redirect to be blocked.');
        } catch (SsrfProtectionException $e) {
            $this->assertStringContainsString('169.254.169.254', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_redirect_to_hostname_resolving_to_private_ip_is_blocked(): void
    {
        Http::fake([
            'http://public.example/*' => Http::response('', 301, ['Location' => 'http://rebind.example/admin']),
            '*' => Http::response('internal', 200),
        ]);

        $this->expectException(SsrfProtectionException::class);

        $this->client->get('http://public.example/start');
    }

    public function test_safe_relative_and_cross_host_redirects_are_followed(): void
    {
        Http::fake([
            'http://public.example/start' => Http::response('', 302, ['Location' => '/next']),
            'http://public.example/next' => Http::response('', 307, ['Location' => 'https://other.example/final']),
            'https://other.example/final' => Http::response('<html>ok</html>', 200),
        ]);

        $response = $this->client->get('http://public.example/start');

        $this->assertSame(200, $response->status());
        $this->assertSame('<html>ok</html>', $response->body());
        Http::assertSentCount(3);
    }

    public function test_get_many_fetches_together_with_the_same_per_hop_checks(): void
    {
        Http::fake([
            'http://public.example/start' => Http::response('', 302, ['Location' => 'https://other.example/final']),
            'https://other.example/final' => Http::response('<html>followed</html>', 200),
            'http://public.example/plain' => Http::response('<html>plain</html>', 200),
            'http://public.example/escape' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            'http://public.example/missing' => Http::response('gone', 404),
            '*' => Http::response('SECRET', 200),
        ]);

        $results = $this->client->getMany([
            'a' => 'http://public.example/start',
            'b' => 'http://public.example/plain',
            'c' => 'http://public.example/escape',
            'd' => 'http://rebind.example/admin',
            'e' => 'http://public.example/missing',
        ]);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_keys($results));
        $this->assertInstanceOf(Response::class, $results['a']);
        $this->assertSame('<html>followed</html>', $results['a']->body());
        $this->assertInstanceOf(Response::class, $results['b']);
        $this->assertSame('<html>plain</html>', $results['b']->body());
        $this->assertInstanceOf(SsrfProtectionException::class, $results['c']);
        $this->assertInstanceOf(SsrfProtectionException::class, $results['d']);
        $this->assertInstanceOf(Response::class, $results['e']);
        $this->assertSame(404, $results['e']->status());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254')
            || str_contains($request->url(), 'rebind.example'));
    }

    public function test_get_many_caps_redirects_and_size_per_url(): void
    {
        Http::fake([
            'http://public.example/loop' => Http::response('', 302, ['Location' => 'http://public.example/loop']),
            'http://public.example/big' => Http::response(str_repeat('a', 2048), 200),
            'http://public.example/small' => Http::response('ok', 200),
        ]);

        $results = $this->client->getMany([
            'http://public.example/loop',
            'http://public.example/big',
            'http://public.example/small',
        ], maxRedirects: 2, maxBytes: 1024);

        $this->assertInstanceOf(SsrfProtectionException::class, $results[0]);
        $this->assertStringContainsString('Exceeded 2 redirects', $results[0]->getMessage());
        $this->assertInstanceOf(SsrfProtectionException::class, $results[1]);
        $this->assertInstanceOf(Response::class, $results[2]);
    }

    public function test_redirect_loops_are_capped(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://public.example/loop'])]);

        $this->expectException(SsrfProtectionException::class);
        $this->expectExceptionMessage('Exceeded 2 redirects');

        $this->client->get('http://public.example/loop', maxRedirects: 2);
    }

    public function test_oversized_responses_are_rejected(): void
    {
        Http::fake(['*' => Http::response(str_repeat('a', 2048), 200)]);

        $this->expectException(SsrfProtectionException::class);

        $this->client->get('http://public.example/big', maxBytes: 1024);
    }

    public function test_direct_private_targets_are_blocked(): void
    {
        Http::fake();

        foreach (['http://127.0.0.1/', 'http://[::1]/', 'http://10.1.2.3/', 'file:///etc/passwd', 'http://rebind.example/'] as $url) {
            try {
                $this->client->get($url);
                $this->fail("Expected [{$url}] to be blocked.");
            } catch (SsrfProtectionException) {
                // expected
            }
        }

        Http::assertNothingSent();
    }

    public function test_post_callbacks_do_not_follow_redirects(): void
    {
        Http::fake([
            'https://public.example/*' => Http::response('', 307, ['Location' => 'http://169.254.169.254/']),
            '*' => Http::response('SECRET', 200),
        ]);

        $response = $this->client->postJson('https://public.example/callback', ['ok' => true]);

        $this->assertSame(307, $response->status());
        Http::assertSentCount(1);
    }

    public function test_post_to_private_callback_is_blocked(): void
    {
        Http::fake();

        $this->expectException(SsrfProtectionException::class);

        try {
            $this->client->postJson('http://169.254.169.254/latest', ['ok' => true]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_guard_returns_the_ips_to_pin(): void
    {
        $guard = new FixedDnsGuard(['public.example' => ['93.184.216.34']]);

        $this->assertSame(['93.184.216.34'], $guard->resolveSafeIps('https://public.example/page'));
        $this->assertSame(['8.8.8.8'], $guard->resolveSafeIps('http://8.8.8.8/'));
    }

    public function test_backlink_verification_does_not_follow_redirects_into_private_networks(): void
    {
        Http::fake([
            'http://public.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response('<a href="https://target.example/">x</a>', 200),
        ]);
        $this->app->instance(SafeHttpClient::class, $this->client);

        $result = $this->app->make(BacklinkVerificationService::class)
            ->inspectDirectly('http://public.example/post', 'https://target.example/');

        $this->assertFalse($result['hasTargetLink']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
    }
}

/**
 * Guard with deterministic DNS answers so tests never depend on the network.
 */
class FixedDnsGuard extends SsrfProtectionGuard
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(private readonly array $records) {}

    protected function resolveHost(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}
