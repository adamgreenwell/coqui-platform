<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    public function test_all_expected_console_commands_are_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->assertSuccessful()
            ->expectsOutputToContain('dataforseo:collect-tasks')
            ->expectsOutputToContain('dataforseo:run-rank-batches')
            ->expectsOutputToContain('coqui:verify-backlinks');
    }
}
