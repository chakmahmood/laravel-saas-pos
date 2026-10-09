<?php

/**
 * MySQL concurrency harness.
 *
 * Proves (against a DEDICATED MySQL database, never the development database)
 * that:
 *   1. Concurrent order creation yields unique order numbers.
 *   2. Concurrent payments cannot overpay an order.
 *   3. Concurrent voids of the same payment produce exactly one void.
 *   4. Item quota cannot be exceeded.
 *   5. Only one open shift per cashier/store.
 *   6. Two orders racing for the last unit: exactly one reserves it.
 *   7. Concurrent commits of one order: exactly one commit is effective.
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
use App\Models\CashSession;
use App\Models\Item;
use App\Models\Order;
use App\Models\Plan;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\StockLocationProvisioner;
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

function makeStore(string $label, bool $withShift = true, string $businessType = 'other'): array
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
        'business_type' => $businessType,
    ]);

    $owner->stores()->attach($store->id, ['role' => 'owner', 'is_active' => true]);

    if ($withShift) {
        app(CashSessionService::class)->open($store, $owner, 0, null);
    }

    return [$store, $owner];
}

/**
 * Store that supports inventory, with a default location, one stock-tracked
 * item and an on-hand balance.
 *
 * @return array{0: Store, 1: User, 2: Item, 3: StockLocation}
 */
function makeInventoryStore(string $label, string $onHand): array
{
    [$store, $owner] = makeStore($label, withShift: false, businessType: 'retail');

    $location = app(StockLocationProvisioner::class)->ensureDefaultForStore($store);

    $item = Item::create([
        'store_id' => $store->id,
        'name' => 'Tracked '.$label,
        'type' => 'product',
        'selling_price' => 10000,
        'unit' => 'pcs',
        'tracks_stock' => true,
        'is_active' => true,
    ]);

    StockBalance::create([
        'store_id' => $store->id,
        'stock_location_id' => $location->id,
        'item_id' => $item->id,
        'quantity_on_hand' => $onHand,
        'quantity_reserved' => 0,
    ]);

    return [$store, $owner, $item, $location];
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

/* --------------------------------------------- scenario 5: open shift race */

echo "\n== Scenario 5: concurrent open shift (same cashier/store) ==\n";
[$store5, $owner5] = makeStore('shift', withShift: false);

$n = 6;
$payload = ['store_id' => $store5->id, 'user_id' => $owner5->id, 'opening_cash' => 10000];
[, $results] = runConcurrent($n, 'open_shift', array_fill(0, $n, $payload), $database);

$successes = 0;
$conflicts = 0;
foreach ($results as $r) {
    if (($r['ok'] ?? false) === true) {
        $successes++;
    } elseif (($r['kind'] ?? '') === 'conflict') {
        $conflicts++;
    }
}

$openCount = CashSession::query()
    ->where('store_id', $store5->id)
    ->where('cashier_id', $owner5->id)
    ->where('status', 'open')
    ->count();

printf("  successes=%d conflicts=%d open_shifts_in_db=%d\n", $successes, $conflicts, $openCount);

if ($successes !== 1 || $openCount !== 1) {
    $failures[] = "open shift race: expected exactly 1 open shift, got successes={$successes} db={$openCount}";
}

/* ------------------------------------ scenario 6: last unit reservation */

echo "\n== Scenario 6: last unit reservation race ==\n";
[$store6, $owner6, $item6] = makeInventoryStore('lastunit', '1.000');

$n = 2;
$payload = ['store_id' => $store6->id, 'user_id' => $owner6->id, 'item_id' => $item6->id];
[, $results] = runConcurrent($n, 'make_order', array_fill(0, $n, $payload), $database);

$successes = 0;
$conflicts = 0;
foreach ($results as $r) {
    if (($r['ok'] ?? false) === true) {
        $successes++;
    } elseif (($r['kind'] ?? '') === 'conflict') {
        $conflicts++;
    }
}

$orders6 = Order::query()->where('store_id', $store6->id)->count();
$balance6 = StockBalance::query()
    ->where('store_id', $store6->id)
    ->where('item_id', $item6->id)
    ->firstOrFail();
$reservations6 = StockMovement::query()
    ->where('store_id', $store6->id)
    ->where('item_id', $item6->id)
    ->where('type', 'reservation')
    ->count();

printf(
    "  successes=%d conflicts=%d orders=%d on_hand=%s reserved=%s reservations=%d\n",
    $successes,
    $conflicts,
    $orders6,
    $balance6->quantity_on_hand,
    $balance6->quantity_reserved,
    $reservations6,
);

if ($successes !== 1 || $orders6 !== 1) {
    $failures[] = "last unit: expected exactly 1 successful order, got successes={$successes} orders={$orders6}";
}
if ($reservations6 !== 1) {
    $failures[] = "last unit: expected exactly 1 reservation movement, got {$reservations6}";
}
if ((float) $balance6->quantity_reserved > (float) $balance6->quantity_on_hand) {
    $failures[] = 'last unit: reserved exceeds on_hand';
}
if ((float) $balance6->quantity_reserved !== 1.0) {
    $failures[] = "last unit: expected reserved=1.000, got {$balance6->quantity_reserved}";
}

/* ----------------------------------------- scenario 7: double commit race */

echo "\n== Scenario 7: double commit race ==\n";
[$store7, $owner7, $item7] = makeInventoryStore('commit', '5.000');

$order7 = app(OrderService::class)->create($store7, $owner7, [
    'items' => [['item_id' => $item7->id, 'quantity' => 2]],
]);

$n = 2;
$payload = ['order_id' => $order7->id, 'user_id' => $owner7->id];
[, $results] = runConcurrent($n, 'commit_order', array_fill(0, $n, $payload), $database);

$successes = 0;
foreach ($results as $r) {
    if (($r['ok'] ?? false) === true) {
        $successes++;
    }
}

$order7->refresh();
$balance7 = StockBalance::query()
    ->where('store_id', $store7->id)
    ->where('item_id', $item7->id)
    ->firstOrFail();
$sales7 = StockMovement::query()
    ->where('store_id', $store7->id)
    ->where('order_id', $order7->id)
    ->where('type', 'sale_out')
    ->count();
$releases7 = StockMovement::query()
    ->where('store_id', $store7->id)
    ->where('order_id', $order7->id)
    ->where('type', 'reservation_release')
    ->count();

printf(
    "  successes=%d committed_at=%s on_hand=%s reserved=%s sale_out=%d release=%d\n",
    $successes,
    $order7->stock_committed_at?->toDateTimeString() ?? 'null',
    $balance7->quantity_on_hand,
    $balance7->quantity_reserved,
    $sales7,
    $releases7,
);

if ($successes !== 2) {
    $failures[] = "double commit: expected both calls to succeed idempotently, got {$successes}";
}
if ($order7->stock_committed_at === null) {
    $failures[] = 'double commit: stock_committed_at not set';
}
if ((string) $balance7->quantity_on_hand !== '3.000' || (string) $balance7->quantity_reserved !== '0.000') {
    $failures[] = "double commit: expected on_hand=3.000 reserved=0.000, got on_hand={$balance7->quantity_on_hand} reserved={$balance7->quantity_reserved}";
}
if ($sales7 !== 1 || $releases7 !== 1) {
    $failures[] = "double commit: expected exactly one sale_out and one reservation_release, got sale={$sales7} release={$releases7}";
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
