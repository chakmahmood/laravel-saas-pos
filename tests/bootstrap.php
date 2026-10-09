<?php

/**
 * PHPUnit bootstrap.
 *
 * Runs before PHPUnit executes any test and, therefore, before Laravel is ever
 * bootstrapped. It performs the earliest, process-wide safety measures:
 *
 * 1. Removes a stale `bootstrap/cache/config.php` (fail-closed: abort if it
 *    cannot be removed). A cached config makes Laravel ignore phpunit.xml and
 *    use the real .env database.
 * 2. Clears the framework's in-memory config caches.
 * 3. Forces the process environment to the isolated SQLite in-memory database.
 *
 * This is the FIRST layer. `Tests\TestCase::createApplication()` adds a second
 * layer that verifies the resolved configuration right after boot and before
 * RefreshDatabase runs.
 */

use Tests\Support\TestEnvironment;

require __DIR__.'/../vendor/autoload.php';

$basePath = dirname(__DIR__);

TestEnvironment::purgeCachedConfig(TestEnvironment::cachedConfigPath($basePath));
TestEnvironment::resetInMemoryConfigCache();

$forcedEnvironment = [
    'APP_ENV' => TestEnvironment::EXPECTED_ENV,
    'DB_CONNECTION' => TestEnvironment::EXPECTED_CONNECTION,
    'DB_DATABASE' => TestEnvironment::EXPECTED_DATABASE,
    'DB_URL' => '',
    'DB_FOREIGN_KEYS' => 'true',
];

foreach ($forcedEnvironment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
