<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class QueueTimeoutsTest extends TestCase
{
    public function test_every_job_finishes_before_the_queue_hands_it_to_another_worker(): void
    {
        $retryAfter = min(
            (int) config('queue.connections.redis.retry_after'),
            (int) config('queue.connections.database.retry_after'),
        );

        $checked = 0;
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getRealPath(), realpath(app_path()).DIRECTORY_SEPARATOR));
            if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->hasProperty('timeout')) {
                continue;
            }
            $timeout = $reflection->getProperty('timeout')->getDefaultValue();
            if (! is_int($timeout)) {
                continue;
            }

            $checked++;
            $this->assertLessThan($retryAfter, $timeout, "{$class}::\$timeout ({$timeout}s) must be below the queue's retry_after ({$retryAfter}s).");
        }

        $this->assertGreaterThan(0, $checked);
    }
}
