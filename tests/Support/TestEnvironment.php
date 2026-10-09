<?php

namespace Tests\Support;

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\CachedState;
use RuntimeException;

/**
 * Fail-closed guards for the PHPUnit test environment.
 *
 * Every check throws a RuntimeException instead of returning a boolean, so a
 * misconfiguration aborts the test process BEFORE Laravel can run
 * RefreshDatabase / migrate on a real database.
 *
 * This class intentionally has no Laravel dependency beyond the two framework
 * classes whose static caches it resets, so it can be unit-tested without
 * booting the application or touching any database.
 */
final class TestEnvironment
{
    public const EXPECTED_ENV = 'testing';

    public const EXPECTED_CONNECTION = 'sqlite';

    public const EXPECTED_DRIVER = 'sqlite';

    public const EXPECTED_DATABASE = ':memory:';

    /**
     * Database name written into any non-SQLite connection so it can never
     * point at a real database during tests.
     */
    public const BLOCKED_DATABASE = '__blocked_in_tests__';

    /**
     * Set to true by TestCase once the guard has run for a test. Used by a
     * dedicated test to prove the guard runs before RefreshDatabase.
     */
    public static bool $guardRan = false;

    public static function cachedConfigPath(string $basePath): string
    {
        return rtrim($basePath, '\\/')
            .DIRECTORY_SEPARATOR.'bootstrap'
            .DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.'config.php';
    }

    /**
     * Remove a stale cached configuration. Never silently ignores a failure:
     * if the file still exists afterwards, the test run is aborted.
     */
    public static function purgeCachedConfig(string $configPath): void
    {
        if (is_file($configPath)) {
            @unlink($configPath);
        }

        if (file_exists($configPath)) {
            throw new RuntimeException(
                "UNSAFE TEST ENVIRONMENT: cached config [{$configPath}] could not be removed. "
                .'A cached config makes Laravel ignore phpunit.xml and use the real .env database. '
                .'Tests aborted before any migration/RefreshDatabase could run.'
            );
        }
    }

    /**
     * Clear the framework's in-memory (static) config caches. Without this, a
     * previous test using Laravel's WithCachedConfig trait could keep the real
     * configuration alive in memory even with no cache file on disk.
     */
    public static function resetInMemoryConfigCache(): void
    {
        if (class_exists(CachedState::class)) {
            CachedState::$cachedConfig = null;
            CachedState::$cachedRoutes = null;
        }

        if (class_exists(LoadConfiguration::class)) {
            LoadConfiguration::alwaysUse(null);
        }
    }

    /**
     * Abort unless the resolved configuration is exactly the isolated
     * SQLite in-memory test database.
     *
     * @param  array<string, mixed>  $connection
     *
     * @throws RuntimeException
     */
    public static function assertSafe(string $environment, string $connection, array $connectionConfig): void
    {
        $problems = [];

        if ($environment !== self::EXPECTED_ENV) {
            $problems[] = "APP_ENV must be '".self::EXPECTED_ENV."', got '{$environment}'";
        }

        if ($connection !== self::EXPECTED_CONNECTION) {
            $problems[] = "default connection must be '".self::EXPECTED_CONNECTION."', got '{$connection}'";
        }

        if (($connectionConfig['driver'] ?? null) !== self::EXPECTED_DRIVER) {
            $problems[] = "default connection driver must be '".self::EXPECTED_DRIVER."', got '".($connectionConfig['driver'] ?? 'null')."'";
        }

        if (($connectionConfig['database'] ?? null) !== self::EXPECTED_DATABASE) {
            $problems[] = "default connection database must be '".self::EXPECTED_DATABASE."', got '".($connectionConfig['database'] ?? 'null')."'";
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "UNSAFE TEST DATABASE CONFIGURATION:\n - ".implode("\n - ", $problems)
                ."\nTests aborted BEFORE RefreshDatabase/migration could run."
            );
        }
    }
}
