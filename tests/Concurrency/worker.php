<?php

/**
 * Concurrency worker. Runs one operation against the dedicated concurrency
 * database and prints a JSON result.
 *
 * Usage: php worker.php <scenario> <payloadJson>
 *
 * Coordination: the worker signals readiness by writing a file into
 * CONCURRENCY_READY_DIR, then spin-waits for CONCURRENCY_GO_FILE so that all
 * workers enter their critical section at nearly the same moment.
 */

use App\Exceptions\ItemLimitReachedException;
use App\Exceptions\OrderConflictException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use App\Services\CashSessionService;
use App\Services\CatalogItemService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

require __DIR__.'/boot.php';

$scenario = $argv[1] ?? '';
$payload = json_decode($argv[2] ?? '{}', true) ?: [];

$readyDir = getenv('CONCURRENCY_READY_DIR') ?: '';
$goFile = getenv('CONCURRENCY_GO_FILE') ?: '';

$succeeded = false;

try {
    if ($readyDir !== '') {
        @file_put_contents($readyDir.DIRECTORY_SEPARATOR.getmypid(), 'ready');
    }

    if ($goFile !== '') {
        $deadline = microtime(true) + 30;

        while (! file_exists($goFile)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('go-barrier-timeout');
            }

            usleep(2000);
        }
    }

    $result = match ($scenario) {
        'make_order' => (function () use ($payload) {
            $store = Store::query()->findOrFail($payload['store_id']);
            $user = User::query()->findOrFail($payload['user_id']);

            $order = app(OrderService::class)->create($store, $user, [
                'items' => [['item_id' => $payload['item_id'], 'quantity' => 1]],
            ]);

            return ['order_id' => $order->id, 'order_number' => $order->order_number];
        })(),
        'pay' => (function () use ($payload) {
            $order = Order::query()->findOrFail($payload['order_id']);
            $user = User::query()->findOrFail($payload['user_id']);

            $payment = app(PaymentService::class)->record($order, $user, [
                'payment_method' => 'cash',
                'amount' => (int) $payload['amount'],
            ]);

            return ['payment_id' => $payment->id];
        })(),
        'void' => (function () use ($payload) {
            $payment = Payment::query()->findOrFail($payload['payment_id']);
            $user = User::query()->findOrFail($payload['user_id']);

            app(PaymentService::class)->void($payment, $user, 'race');

            return ['payment_id' => $payment->id];
        })(),
        'make_item' => (function () use ($payload) {
            $store = Store::query()->findOrFail($payload['store_id']);

            $item = app(CatalogItemService::class)->create($store, [
                'name' => 'Conc Item '.$payload['tag'],
                'type' => 'product',
                'selling_price' => 1000,
                'unit' => 'pcs',
                'is_active' => true,
            ]);

            return ['item_id' => $item->id];
        })(),
        'open_shift' => (function () use ($payload) {
            $store = Store::query()->findOrFail($payload['store_id']);
            $user = User::query()->findOrFail($payload['user_id']);

            $session = app(CashSessionService::class)->open(
                $store,
                $user,
                (int) ($payload['opening_cash'] ?? 0),
                null,
            );

            return ['session_id' => $session->id];
        })(),
        default => throw new RuntimeException('unknown-scenario:'.$scenario),
    };

    $succeeded = true;
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'validation', 'message' => $exception->getMessage()]);
} catch (ItemLimitReachedException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'limit', 'message' => $exception->getMessage()]);
} catch (OrderConflictException $exception) {
    echo json_encode(['ok' => false, 'kind' => 'conflict', 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'kind' => 'error',
        'message' => $exception->getMessage(),
    ]);
}

exit($succeeded ? 0 : 3);
