<?php

declare(strict_types=1);

namespace App\Domains\Serp\DataForSeo;

/**
 * A paid provider request was refused because the caller may only read cached data.
 *
 * Extends the budget exception so existing "could not spend" handling still
 * applies; controllers can catch this subtype first to return 403.
 */
class DataForSeoReadOnlyException extends DataForSeoBudgetException
{
    /**
     * The message explains the role limit and is already safe to show.
     */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
