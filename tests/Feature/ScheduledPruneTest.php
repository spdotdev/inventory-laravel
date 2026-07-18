<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * The retention windows only exist if the prune commands actually run. The
 * package schedules them itself (the host app was documented as responsible
 * and never did it — so retention was silently dead in production).
 */
class ScheduledPruneTest extends TestCase
{
    public function test_both_prune_commands_are_registered_on_the_scheduler(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->filter();

        $this->assertTrue(
            $commands->contains(fn (string $c) => str_contains($c, 'inventory:deleted:prune')),
            'inventory:deleted:prune is not scheduled'
        );
        $this->assertTrue(
            $commands->contains(fn (string $c) => str_contains($c, 'inventory:client-errors:prune')),
            'inventory:client-errors:prune is not scheduled'
        );
    }
}
