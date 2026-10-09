<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TestEnvironment;
use Tests\TestCase;

/**
 * Proves the fail-closed guard runs BEFORE RefreshDatabase/migrate.
 *
 * `beforeRefreshingDatabase()` is invoked by the RefreshDatabase trait at the
 * very start of `refreshDatabase()`, before `migrate:fresh`. If the guard had
 * not already run (in `createApplication()`), this hook would throw and the
 * test would abort before any migration.
 */
class TestDatabaseGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase()
    {
        if (! TestEnvironment::$guardRan) {
            throw new RuntimeException(
                'The database guard did not run before RefreshDatabase.'
            );
        }
    }

    public function test_it_runs_on_the_isolated_sqlite_memory_database(): void
    {
        $connection = DB::connection();

        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
    }

    public function test_the_real_mysql_connection_is_blocked(): void
    {
        // The mysql connection must be neutralized so it can never reach the
        // development database from a test.
        $this->assertSame(
            TestEnvironment::BLOCKED_DATABASE,
            config('database.connections.mysql.database'),
        );
    }
}
