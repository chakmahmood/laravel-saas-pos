<?php

/**
 * MySQL concurrency harness.
 *
 * Proves (against a DEDICATED MySQL database, never the development database)
 * that:
 *   1. Concurrent order creation yields unique order numbers.
 *   2. Concurrent payments cannot overpay an order.
 *   3. Concurrent voids of the same payment produce exactly one void.
 *
 * Usage:
 *   php tests/Concurrency/run.php
 *   CONCURRENCY_DB=saas_pos_concurrency_test php tests/Concurrency/run.php
 *
 * It never drops, truncates, or resets any database. It only creates the
 * dedicated database if missing and runs migrations into it (idempotent).
 */

use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Item;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/boot.php';

$database = getenv('CONCURRENCY_DB') ?: 'saas_pos_concurrency_test';

/* ---------------------------------------------------------------- setup DB */

$mysql = config('database.connections.mysql');
$dsnHost = sprintf('mysql:host=%s;port=%s', $mysql['host'], $mysql['port']);
$server = new PDO($dsnHost, $mysql['username'], $mysql['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$server->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $database,
));

echo "Concurrency DB: {$database}\n";
Artisan::call('migrate', ['--force' => true]);
echo trim(Artisan::output())."\n\n";

/* --------------------------------------------------------------- fixtures */

function makeStore(string $label): array
{
    $suffix = $label.'-'.bin2hex(random_bytes(4));

    $owner = User::create([
        'name' => 'Conc '.$suffix,
        'email' => $suffix.'@example.com',
        'password' => bcrypt('password'),
    ]);

    $store = Store::create([
        'owner_id' => $owner->id,
        'name' => 'Store '.$suffix,
        'slug' => 'store-'.$suffix,
        'is_active' => true,
    ]);

    $owner->stores()->attach($store->id, ['role' => 'owner', 'is_active' => true]);

    return [$store, $owner];
}

/**
 * @return array{0: array<int, array{out: string, err: string}>, 1: array<int, array<string, mixed>>}
 */
function runConcurrent(int $count, string $scenario, array $payloads, string $database): array
{
    $php = PHP_BINARY;
    $worker = __DIR__.'/worker.php';
    $readyDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'conc_'.bin2hex(random_bytes(6));
    mkdir($readyDir);
    $goFile = $readyDir.DIRECTORY_SEPARATOR.'GO';

    $baseEnv = getenv();
    $baseEnv['CONCURRENCY_DB'] = $database;
    $baseEnv['CONCURRENCY_READY_DIR'] = $readyDir;
    $baseEnv['CONCURRENCY_GO_FILE'] = $goFile;

    $processes = [];

    for ($i = 0; $i < $count; $i++) {
        $payload = $payloads[$i] ?? $payloads[0];
        $cmd = [$php, $worker, $scenario, json_encode($payload)];

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $baseEnv);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to spawn worker.');
        }

        $processes[] = [$process, $pipes];
    }

    // Wait until every worker reached the barrier.
    $deadline = microtime(true) + 60;
    while (count(glob($readyDir.DIRECTORY_SEPARATOR.'*')) < $count) {
        if (microtime(true) > $deadline) {
            break;
        }
        usleep(5000);
    }

    // Release them together.
    touch($goFile);

    $raw = [];
    $decoded = [];

    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        $out = trim($out);
        $raw[] = ['out' => $out, 'err' => trim($err)];
        $decoded[] = json_decode($out, true) ?: ['ok' => false, 'kind' => 'unparsable', 'message' => $out];
    }

    array_map('unlink', glob($readyDir.DIRECTORY_SEPARATOR.'*') ?: []);
    @rmdir($readyDir);

    return [$raw, $decoded];
}

$failures = [];

/* --------------------------------------------- scenario 1: order numbers */

echo "== Scenario 1: concurrent order numbers ==\n";
[$store, $owner] = makeStore('orders');
$item = Item::create([
    'store_id' => $store->id,
    'name' => 'Item',
    'type' => 'product',
    'selling_price' => 1000,
    'unit' => 'pcs',
    'is_active' => true,
]);

$n = 10;
$payload = ['store_id' => $store->id, 'user_id' => $owner->id, 'item_id' => $item->id];
[, $results] = runConcurrent($n, 'make_order', array_fill(0, $n, $payload), $database);

$okNumbers = [];
foreach ($results as $r) {
    if (($r['ok'] ?? false) === true) {
        $okNumbers[] = $r['result']['order_number'];
    }
}
$dbCount = Order::query()->where('store_id', $store->id)->count();
$uniqueCount = count(array_unique($okNumbers));

printf("  successes=%d distinct_numbers=%d db_orders=%d\n", count($okNumbers), $uniqueCount, $dbCount);
if (count($okNumbers) !== $n || $uniqueCount !== $n || $dbCount !== $n) {
    $failures[] = 'order numbers: expected '.$n.' unique orders';
}

/* --------------------------------------------- scenario 2: overpayment */

echo "\n== Scenario 2: concurrent overpayment attempt ==\n";
[$store2, $owner2] = makeStore('pay');
$item2 = Item::create([
    'store_id' => $store2->id,
    'name' => 'Item',
    'type' => 'product',
    'selling_price' => 10000,
    'unit' => 'pcs',
    'is_active' => true,
]);

$order = app(OrderService::class)->create($store2, $owner2, [
    'items' => [['item_id' => $item2->id, 'quantity' => 1]],
]);

$n = 8;
$half = 5000;
$payload = ['order_id' => $order->id, 'user_id' => $owner2->id, 'amount' => $half];
[, $results] = runConcurrent($n, 'pay', array_fill(0, $n, $payload), $database);

$successes = 0;
$validationFailures = 0;
foreach ($results as $r) {
    if (($r['ok'] ?? false) === true) {
        $successes++;
    } elseif (($r['kind'] ?? '') === 'validation') {
        $validationFailures++;
    }
}

$order->refresh();
$completedSum = (int) $order->payments()
    ->where('status', PaymentRecordStatus::COMPLETED->value)
    ->sum('amount');
$completedCount = $order->payments()
    ->where('status', PaymentRecordStatus::COMPLETED->value)
    ->count();

printf(
    "  successes=%d validation_failures=%d completed_payments=%d paid_sum=%d total=%d status=%s\n",
    $successes,
    $validationFailures,
    $completedCount,
    $completedSum,
    $order->total_amount,
    $order->payment_status->value,
);

if ($successes !== 2) {
    $failures[] = "overpayment: expected exactly 2 successes, got {$successes}";
}
if ($completedSum !== $order->total_amount) {
    $failures[] = 'overpayment: paid sum does not equal order total';
}
if ($order->paid_amount > $order->total_amount) {
    $failures[] = 'overpayment: order paid_amount exceeds total';
}
if ($order->payment_status !== PaymentStatus::PAID) {
    $failures[] = 'overpayment: order status is not paid';
}

/* --------------------------------------------- scenario 3: void race */

echo "\n== Scenario 3: concurrent void of the same payment ==\n";
[$store3, $owner3] = makeStore('void');
$item3 = Item::create([
    'store_id' => $store3->id,
    'name' => 'Item',
    'type' => 'product',
    'selling_price' => 10000,
    'unit' => 'pcs',
    'is_active' => true,
]);

$order3 = app(OrderService::class)->create($store3, $owner3, [
    'items' => [['item_id' => $item3->id, 'quantity' => 1]],
]);

$payment = app(PaymentService::class)->record($order3, $owner3, [
    'payment_method' => 'cash',
    'amount' => 10000,
]);

$n = 4;
$payload = ['payment_id' => $payment->id, 'user_id' => $owner3->id];
[, $results] = runConcurrent($n, 'void', array_fill(0, $n, $payload), $database);

$successes = 0;
$conflicts = 0;
foreach ($results as $r) {
    if (($r['ok'] ?? false) === true) {
        $successes++;
    } elseif (($r['kind'] ?? '') === 'conflict') {
        $conflicts++;
    }
}

$payment->refresh();
$order3->refresh();

printf(
    "  successes=%d conflicts=%d payment_status=%s order_paid=%d order_status=%s\n",
    $successes,
    $conflicts,
    $payment->status->value,
    $order3->paid_amount,
    $order3->payment_status->value,
);

if ($successes !== 1) {
    $failures[] = "void race: expected exactly 1 success, got {$successes}";
}
if ($payment->status !== PaymentRecordStatus::VOIDED) {
    $failures[] = 'void race: payment is not voided';
}
if ($order3->paid_amount !== 0 || $order3->payment_status !== PaymentStatus::UNPAID) {
    $failures[] = 'void race: order status not recomputed';
}

/* --------------------------------------------- scenario 4: item quota */

echo "\n== Scenario 4: concurrent item quota ==\n";
[$store4, $owner4] = makeStore('quota');

$limit = 5;
$plan = Plan::create([
    'name' => 'Conc Plan',
    'slug' => 'conc-plan-'.bin2hex(random_bytes(4)),
    'price_monthly' => 0,
    'price_yearly' => 0,
    'max_stores' => 1,
    'max_users_per_store' => 5,
    'max_products' => $limit,
    'max_transactions_per_month' => null,
    'is_active' => true,
    'sort_order' => 1,
]);

Subscription::create([
    'store_id' => $store4->id,
    'plan_id' => $plan->id,
    'status' => 'active',
    'billing_cycle' => 'monthly',
    'starts_at' => now(),
]);

$n = 12;
$payloads = [];
for ($i = 1; $i <= $n; $i++) {
    $payloads[] = ['store_id' => $store4->id, 'tag' => (string) $i];
}
[, $results] = runConcurrent($n, 'make_item', $payloads, $database);

$successes = 0;
$limitFailures = 0;
foreach ($results as $r) {
    if (($r['ok'] ?? false) === true) {
        $successes++;
    } elseif (($r['kind'] ?? '') === 'limit') {
        $limitFailures++;
    }
}
$itemCount = Item::query()->where('store_id', $store4->id)->count();

printf("  successes=%d limit_failures=%d items_in_db=%d limit=%d\n", $successes, $limitFailures, $itemCount, $limit);

if ($successes !== $limit || $itemCount !== $limit) {
    $failures[] = "item quota: expected exactly {$limit} items, got successes={$successes} db={$itemCount}";
}

/* ------------------------------------------------------------- summary */
echo "\n== Summary ==\n";
if ($failures === []) {
    echo "PASS: all concurrency invariants held on MySQL.\n";
    exit(0);
}

foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "FAILED: concurrency invariants violated.\n";
exit(1);
