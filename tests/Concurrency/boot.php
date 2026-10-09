<?php

/**
 * Shared bootstrap for the MySQL concurrency harness.
 *
 * It boots the real Laravel application but forces the database connection to a
 * DEDICATED concurrency database. It never touches the development database:
 *
 *  - the database name is provided through CONCURRENCY_DB (default
 *    `saas_pos_concurrency_test`);
 *  - the connection is re-pointed at runtime (config + purge), so it cannot be
 *    overridden back to the dev database by .env loading;
 *  - the resolved database name is asserted before any query runs.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$database = getenv('CONCURRENCY_DB') ?: 'saas_pos_concurrency_test';

if ($database === 'saas_pos_db' || ! preg_match('/^saas_pos_concurrency_test$/', $database)) {
    fwrite(STDERR, "REFUSING to run concurrency harness against [{$database}].\n");
    exit(1);
}

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => $database,
]);

DB::purge('mysql');
DB::reconnect('mysql');

if (DB::connection('mysql')->getDatabaseName() !== $database) {
    fwrite(STDERR, "FATAL: connection is not pointing at the concurrency database.\n");
    exit(1);
}

return $app;
