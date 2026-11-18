<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlIssue extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'crawl_run_id',
        'url',
        'url_hash',
        'rule',
        'severity',
        'detail',
    ];

    /**
     * @return BelongsTo<CrawlRun, $this>
     */
    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }
}
