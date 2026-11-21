<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of Bing Webmaster Tools crawl stats for a project.
 *
 * @property int $id
 * @property int $project_id
 * @property CarbonInterface $date
 * @property int $crawled_pages
 * @property int $crawl_errors
 * @property int $in_index
 * @property int $in_links
 * @property int $code_2xx
 * @property int $code_301
 * @property int $code_302
 * @property int $code_4xx
 * @property int $code_5xx
 * @property int $blocked_by_robots
 * @property int $dns_failures
 * @property int $connection_timeouts
 */
class BingCrawlStat extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'date',
        'crawled_pages',
        'crawl_errors',
        'in_index',
        'in_links',
        'code_2xx',
        'code_301',
        'code_302',
        'code_4xx',
        'code_5xx',
        'blocked_by_robots',
        'dns_failures',
        'connection_timeouts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'crawled_pages' => 'integer',
            'crawl_errors' => 'integer',
            'in_index' => 'integer',
            'in_links' => 'integer',
            'code_2xx' => 'integer',
            'code_301' => 'integer',
            'code_302' => 'integer',
            'code_4xx' => 'integer',
            'code_5xx' => 'integer',
            'blocked_by_robots' => 'integer',
            'dns_failures' => 'integer',
            'connection_timeouts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
