<?php

declare(strict_types=1);

namespace App\Domains\Integrations;

/**
 * Analytics integrations that store page traffic snapshots. Adding a provider
 * here makes it part of syncing, the traffic report and the provider labels.
 */
enum TrafficProvider: string
{
    case Ga4 = 'ga4';
    case Plausible = 'plausible';
    case Umami = 'umami';

    /**
     * Providers in the order the traffic report prefers them when a project
     * has more than one. GA4 reports sources per page and Umami counts
     * search and AI referrers per page; Plausible's are estimated from daily
     * site-wide shares.
     *
     * @return list<self>
     */
    public static function byPriority(): array
    {
        return [self::Ga4, self::Umami, self::Plausible];
    }

    /**
     * Provider strings in priority order, as stored on integrations and
     * snapshots.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $provider): string => $provider->value, self::byPriority());
    }

    public function label(): string
    {
        return match ($this) {
            self::Ga4 => 'Google Analytics 4',
            self::Plausible => 'Plausible Analytics',
            self::Umami => 'Umami Analytics',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Ga4 => 'GA4',
            self::Plausible => 'Plausible',
            self::Umami => 'Umami',
        };
    }
}
