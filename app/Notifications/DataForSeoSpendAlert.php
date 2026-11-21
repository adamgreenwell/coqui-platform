<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operator-only alert from the hourly DataForSEO reconciliation.
 */
class DataForSeoSpendAlert extends Notification
{
    use Queueable;

    private const SUBJECTS = [
        'drift' => 'DataForSEO charges don\'t match the spend ledger',
        'low_balance' => 'DataForSEO balance is low',
        'daily_cap' => 'DataForSEO spend is near the daily cap',
        'monthly_cap' => 'DataForSEO spend is near the monthly cap',
    ];

    public function __construct(
        public readonly string $type,
        public readonly string $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECTS[$this->type] ?? 'DataForSEO spend alert')
            ->line($this->message)
            ->line('Run `php artisan dataforseo:status` on the server for the ledger, balance history and cache hit rates.');
    }
}
