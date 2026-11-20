<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Projects\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * Names of the plan features defined with Pennant in AppServiceProvider.
 *
 * This enum only names the features and labels them for people. Which plan
 * includes which feature lives in the Pennant definitions alone, so nothing
 * here lists plan tiers.
 */
enum PlanFeature: string
{
    case ContentBriefs = 'content-briefs';
    case BacklinkIntelligence = 'backlink-intelligence';
    case TrafficAttribution = 'traffic-attribution';
    case WhiteLabelReports = 'white-label-reports';
    case PriorityQueue = 'priority-queue';
    case RunnrSmokeTests = 'runnr-smoke-tests';
    case BotTelemetry = 'bot-telemetry';

    public function label(): string
    {
        return match ($this) {
            self::ContentBriefs => 'Content briefs',
            self::BacklinkIntelligence => 'Backlink intelligence',
            self::TrafficAttribution => 'Search performance and traffic attribution',
            self::WhiteLabelReports => 'White-label client reports',
            self::PriorityQueue => 'Priority crawl queue',
            self::RunnrSmokeTests => 'Runnr deploy smoke tests',
            self::BotTelemetry => 'Bot crawler telemetry',
        };
    }

    public function activeFor(Workspace $workspace): bool
    {
        return Feature::for($workspace)->active($this->value);
    }

    /**
     * Every plan feature and whether the workspace has it, keyed by feature name.
     *
     * @return array<string, bool>
     */
    public static function flagsFor(Workspace $workspace): array
    {
        $flags = [];
        foreach (self::cases() as $feature) {
            $flags[$feature->value] = $feature->activeFor($workspace);
        }

        return $flags;
    }
}
