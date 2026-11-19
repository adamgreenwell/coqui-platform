<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Projects\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $stripe_invoice_id
 * @property float $amount_paid_usd
 * @property string $currency
 * @property string $status
 * @property string|null $invoice_pdf_url
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Workspace|null $workspace
 */
class BillingInvoice extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'stripe_invoice_id',
        'amount_paid_usd',
        'currency',
        'status',
        'invoice_pdf_url',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_paid_usd' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
