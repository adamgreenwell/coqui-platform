<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Integrations\Models\ProjectIntegration;
use App\Domains\Serp\Models\TrackedKeyword;
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

    public static function normalizeDomain(string $input): string
    {
        $trimmed = trim($input);
        if (! str_contains($trimmed, '://')) {
            $trimmed = 'https://'.$trimmed;
        }

        $host = parse_url($trimmed, PHP_URL_HOST);

        return strtolower(preg_replace('/^www\./i', '', is_string($host) ? $host : $input) ?? $input);
    }
}
