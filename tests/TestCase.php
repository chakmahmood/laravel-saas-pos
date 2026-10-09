<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TestEnvironment;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application for a test with fail-closed database guards.
     *
     * Ordering is critical and verified against the framework internals:
     * `Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle::setUpTheTestEnvironment()`
     * calls `refreshApplication()` (which calls this method) and only THEN
     * `setUpTraits()`, where `RefreshDatabase` runs `migrate:fresh`.
     *
     * Therefore this method runs:
     *   - BEFORE RefreshDatabase / DatabaseMigrations / any test migration, and
     *   - BEFORE any factory or seeder.
     *
     * If the resolved database is not the isolated SQLite in-memory database,
     * it throws and no migration is ever executed.
     *
     * @return Application
     */
    public function createApplication()
    {
        $basePath = dirname(__DIR__);

        // Layer 1 (pre-boot): no stale on-disk config, no in-memory config.
        TestEnvironment::purgeCachedConfig(TestEnvironment::cachedConfigPath($basePath));
        TestEnvironment::resetInMemoryConfigCache();

        // Boot Laravel (configuration is now resolved from the test env).
        $app = parent::createApplication();

        // Layer 2 (post-boot, pre-RefreshDatabase): verify resolved config.
        $default = (string) config('database.default');
        $defaultConfig = (array) config("database.connections.{$default}", []);

        TestEnvironment::assertSafe($app->environment(), $default, $defaultConfig);

        /*
         * Neutralize every non-SQLite connection so an accidental
         * `DB::connection('mysql')` in a test cannot reach a real database.
         * The sentinel database name guarantees a connection error rather than
         * a destructive operation on development data.
         */
        foreach ((array) config('database.connections') as $name => $connection) {
            if (($connection['driver'] ?? null) !== TestEnvironment::EXPECTED_DRIVER) {
                config(["database.connections.{$name}.database" => TestEnvironment::BLOCKED_DATABASE]);
            }
        }

        // Layer 3: verify the ACTUAL connection, not only the configuration.
        $resolved = DB::connection($default);
        $driver = $resolved->getDriverName();
        $database = $resolved->getDatabaseName();

        if ($driver !== TestEnvironment::EXPECTED_DRIVER || $database !== TestEnvironment::EXPECTED_DATABASE) {
            throw new RuntimeException(
                "UNSAFE: resolved database connection is not SQLite :memory: (got {$driver} / {$database}). "
                .'Tests aborted BEFORE RefreshDatabase/migration could run.'
            );
        }

        TestEnvironment::$guardRan = true;

        return $app;
    }
}
