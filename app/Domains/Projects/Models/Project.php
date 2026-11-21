<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Crawls\Models\CrawlDiffReport;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Models\BingCrawlStat;
use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Integrations\Models\PageTrafficSnapshot;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Integrations\Models\SearchPerformancePage;
use App\Domains\Integrations\Models\SearchPerformanceQuery;
use App\Domains\Serp\Models\ContentBrief;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $name
 * @property string $domain
 * @property string $canonical_url
 * @property int $location_code
 * @property string $language_code
 * @property string $crawl_cadence
 * @property string|null $runnr_site_id
 * @property string|null $deploy_badge_token
 * @property CarbonInterface|null $last_crawled_at
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, ProjectActivity> $activities
 */
class Project extends Model
{
    use HasPublicUuid;
    use SoftDeletes;

    /**
     * Unguessable token used in the public deploy-smoke badge URL (generated on first use).
     */
    public function deployBadgeToken(): string
    {
        if ($this->deploy_badge_token === null || $this->deploy_badge_token === '') {
            $this->forceFill(['deploy_badge_token' => Str::random(40)])->save();
        }

        return (string) $this->deploy_badge_token;
    }

    /**
     * Private broadcast channel for a project, keyed by its public UUID so the
     * browser never needs the sequential project ID to subscribe.
     */
    public static function broadcastChannelName(int $projectId): string
    {
        $uuid = static::query()->withTrashed()->whereKey($projectId)->value('uuid');

        return 'project.'.(is_string($uuid) ? $uuid : 'unknown');
    }

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'name',
        'domain',
        'canonical_url',
        'location_code',
        'language_code',
        'crawl_cadence',
        'runnr_site_id',
        'last_crawled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'location_code' => 'integer',
            'last_crawled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<ProjectCompetitor, $this>
     */
    public function competitors(): HasMany
    {
        return $this->hasMany(ProjectCompetitor::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<TrackedKeyword, $this>
     */
    public function trackedKeywords(): HasMany
    {
        return $this->hasMany(TrackedKeyword::class);
    }

    /**
     * @return HasMany<CrawlRun, $this>
     */
    public function crawlRuns(): HasMany
    {
        return $this->hasMany(CrawlRun::class);
    }

    /**
     * @return HasMany<MonitoredBacklink, $this>
     */
    public function monitoredBacklinks(): HasMany
    {
        return $this->hasMany(MonitoredBacklink::class);
    }

    /**
     * @return HasMany<ProjectIntegration, $this>
     */
    public function integrations(): HasMany
    {
        return $this->hasMany(ProjectIntegration::class);
    }

    /**
     * @return HasMany<SearchPerformanceQuery, $this>
     */
    public function searchPerformanceQueries(): HasMany
    {
        return $this->hasMany(SearchPerformanceQuery::class);
    }

    /**
     * @return HasMany<BingCrawlStat, $this>
     */
    public function bingCrawlStats(): HasMany
    {
        return $this->hasMany(BingCrawlStat::class);
    }

    /**
     * @return HasMany<SearchPerformancePage, $this>
     */
    public function searchPerformancePages(): HasMany
    {
        return $this->hasMany(SearchPerformancePage::class);
    }

    /**
     * @return HasMany<CrawlDiffReport, $this>
     */
    public function diffReports(): HasMany
    {
        return $this->hasMany(CrawlDiffReport::class);
    }

    /**
     * @return HasMany<ContentBrief, $this>
     */
    public function contentBriefs(): HasMany
    {
        return $this->hasMany(ContentBrief::class);
    }

    /**
     * @return HasMany<BacklinkHistorySnapshot, $this>
     */
    public function backlinkHistorySnapshots(): HasMany
    {
        return $this->hasMany(BacklinkHistorySnapshot::class);
    }

    /**
     * @return HasMany<BotCrawlLog, $this>
     */
    public function botCrawlLogs(): HasMany
    {
        return $this->hasMany(BotCrawlLog::class);
    }

    /**
     * @return HasMany<PageTrafficSnapshot, $this>
     */
    public function pageTrafficSnapshots(): HasMany
    {
        return $this->hasMany(PageTrafficSnapshot::class);
    }

    /**
     * @return HasMany<ProjectActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class)->latest('id');
    }

    public static function isValidDomain(string $domain): bool
    {
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && str_contains($domain, '.')
            && ! str_starts_with($domain, '.')
            && ! str_ends_with($domain, '.')
            && ! str_contains($domain, ' ');
    }

    /**
     * Check if the project has Runnr hosting bundle entitlement.
     *
     * Comes from the workspace's billing alone; a linked Runnr site ID is
     * unverified and grants nothing (see Workspace::hasRunnrEntitlement()).
     */
    public function hasRunnrEntitlement(): bool
    {
        return $this->workspace?->hasRunnrEntitlement() ?? false;
    }

    public static function normalizeDomain(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '' || $trimmed === 'https://' || $trimmed === 'http://') {
            return '';
        }

        if (! str_contains($trimmed, '://')) {
            $trimmed = 'https://'.$trimmed;
        }

        $host = parse_url($trimmed, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '';
        }

        $domain = strtolower(preg_replace('/^www\./i', '', $host) ?? $host);

        return static::isValidDomain($domain) ? $domain : '';
    }

    public static function normalizeCanonicalUrl(string $input): string
    {
        $domain = static::normalizeDomain($input);
        if ($domain === '') {
            return '';
        }

        $scheme = parse_url(trim($input), PHP_URL_SCHEME);
        $protocol = is_string($scheme) && strtolower($scheme) === 'http' ? 'http' : 'https';

        return "{$protocol}://{$domain}/";
    }
}
