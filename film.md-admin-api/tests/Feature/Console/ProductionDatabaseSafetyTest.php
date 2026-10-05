<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ProductionDatabaseSafetyTest extends TestCase
{
    public function test_database_seeding_is_blocked_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database seeding is disabled in production.');

        event(new CommandStarting(
            'db:seed',
            new ArrayInput([]),
            new BufferedOutput,
        ));
    }

    public function test_non_seed_commands_are_not_blocked_by_the_seed_guard(): void
    {
        $this->app['env'] = 'production';

        event(new CommandStarting(
            'migrate',
            new ArrayInput([]),
            new BufferedOutput,
        ));

        $this->addToAssertionCount(1);
    }
}
