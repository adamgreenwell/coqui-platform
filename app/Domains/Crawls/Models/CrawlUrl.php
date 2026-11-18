<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlUrl extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'crawl_run_id',
        'client_row_id',
        'url',
        'url_hash',
        'status',
        'content_type',
        'title',
        'meta_description',
        'h1',
        'h1_count',
        'canonical',
        'meta_robots',
        'redirect_target',
        'redirect_chain',
        'word_count',
        'depth',
        'response_time_ms',
        'size_bytes',
        'error',
        'discovered_at_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'h1_count' => 'integer',
            'redirect_chain' => 'array',
            'word_count' => 'integer',
            'depth' => 'integer',
            'response_time_ms' => 'integer',
            'size_bytes' => 'integer',
            'discovered_at_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CrawlRun, $this>
     */
    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }
}
