<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    public function test_all_expected_console_commands_are_scheduled(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $events = collect($schedule->events());

        $hasCollect = $events->contains(function (Event $event): bool {
            return str_contains((string) $event->command, 'dataforseo:collect-tasks')
                && $event->expression === '*/10 * * * *';
        });

        $hasRankBatches = $events->contains(function (Event $event): bool {
            return str_contains((string) $event->command, 'dataforseo:run-rank-batches')
                && $event->expression === '10 5 * * *';
        });

        $hasBacklinks = $events->contains(function (Event $event): bool {
            return str_contains((string) $event->command, 'coqui:verify-backlinks')
                && $event->expression === '30 3 * * 1';
        });

        $this->assertTrue($hasCollect, 'dataforseo:collect-tasks should be scheduled every 10 minutes.');
        $this->assertTrue($hasRankBatches, 'dataforseo:run-rank-batches should be scheduled daily at 05:10.');
        $this->assertTrue($hasBacklinks, 'coqui:verify-backlinks should be scheduled weekly on Monday at 03:30.');
    }
}
