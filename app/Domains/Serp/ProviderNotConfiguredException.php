<?php

declare(strict_types=1);

namespace App\Domains\Serp;

use App\Domains\Serp\DataForSeo\DataForSeoClient;
use RuntimeException;

/**
 * A keyword, ranking or backlink lookup needs a data provider whose
 * credentials aren't set on this server. The message is shown to users, so
 * it doesn't name the provider; the provider is kept for logs.
 */
final class ProviderNotConfiguredException extends RuntimeException
{
    public const string USER_MESSAGE = 'Keyword, ranking and backlink data isn\'t set up on this server yet. An administrator needs to add the SEO data provider credentials.';

    public function __construct(public readonly string $provider)
    {
        parent::__construct(self::USER_MESSAGE);
    }

    /**
     * For actions whose services hide provider errors and return empty data:
     * stop before the action reports a refresh or saves an empty result.
     */
    public static function throwUnlessSeoDataConfigured(): void
    {
        if (! app(DataForSeoClient::class)->isConfigured()) {
            throw new self('dataforseo');
        }
    }
}
