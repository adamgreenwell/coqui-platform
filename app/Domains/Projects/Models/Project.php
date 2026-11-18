<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Serp\Models\TrackedKeyword;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $domain
 * @property string $canonical_url
 * @property int $location_code
 * @property string $language_code
 * @property string $crawl_cadence
 * @property string|null $runnr_site_id
 * @property CarbonInterface|null $last_crawled_at
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, ProjectActivity> $activities
 */
class Project extends Model
{
    use SoftDeletes;

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
