<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\OrderStatus;
use App\Infra\Db;
use App\Infra\HttpResult;
use App\Infra\Log;
use App\Support\Env;
use PDO;

/**
 * Stage 3 — resilient issuing.
 *
 * The rules this class exists to enforce:
 *
 *  * request_id is DERIVED, not random: `req_<order>_<supplier>`. Every retry
 *    of the same (order, supplier) reuses it, so the supplier's own
 *    idempotency returns the same code instead of burning a second key.
 *
 *  * TIMEOUT IS NOT A FAILURE. A read timeout leaves the request in state
 *    `unknown`. While any request for an order is `unknown` we NEVER fail over
 *    to another supplier — that is exactly how you deliver twice. We retry the
 *    same request_id (which doubles as a probe), then ask the supplier's
 *    reconcile endpoint. Only an authoritative "no such request" (404) or an
 *    explicit error response downgrades `unknown` to `error` and unlocks the
 *    fail-over.
 *
 *  * Even if all of the above failed, `deliveries` has UNIQUE(order_id) and
 *    UNIQUE(code): the database physically cannot record two deliveries for an
 *    order, and a code physically cannot be attached to two orders. Anything
 *    that loses that race is written to `orphan_codes` — never dropped.
 *
 *  * No database transaction is held open across an HTTP call. Mutual
 *    exclusion between workers uses a session-level advisory lock instead.
 */
final class DeliveryService
{
    public const DELIVERED    = 'delivered';
    public const ALREADY      = 'already_delivered';
    public const LOCKED       = 'locked_by_other_worker';
    public const NOT_PAYABLE  = 'not_payable';
    public const RETRY_LATER  = 'retry_later';
    public const OUT_OF_STOCK = 'out_of_stock';
    public const FAILED       = 'delivery_failed';

    private int $maxAttempts;
    private int $backoffBaseMs;
    private int $backoffJitterMs;

    public function __construct()
    {
        $this->maxAttempts     = max(1, Env::int('SUPPLIER_MAX_ATTEMPTS', 3));
        $this->backoffBaseMs   = Env::int('SUPPLIER_BACKOFF_BASE_MS', 200);
        $this->backoffJitterMs = Env::int('SUPPLIER_BACKOFF_JITTER_MS', 150);
    }

    public static function requestId(string $orderId, string $supplier, int $epoch): string
    {
        return sprintf('req_%s_%s_%d', $orderId, $supplier, $epoch);
    }

    /** @return array{result:string,detail?:string,code?:string,supplier?:string} */
    public function deliver(string $orderId): array
    {
        $lock = 'deliver:' . $orderId;

        // Another worker (or a manual retry) is already issuing this order.
        if (!Db::tryAdvisoryLock($lock)) {
            Log::info('delivery_skipped_locked', ['order_id' => $orderId]);

            return ['result' => self::LOCKED];
        }

        try {
            return $this->deliverLocked($orderId);
        } finally {
            Db::advisoryUnlock($lock);
        }
    }

    /** @return array{result:string,detail?:string,code?:string,supplier?:string} */
    private function deliverLocked(string $orderId): array
    {
        $order = Db::one('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            return ['result' => self::NOT_PAYABLE, 'detail' => 'order not found'];
        }

        // Already delivered? Idempotent no-op. (acceptance #1, #2, #4)
        $existing = Db::one('SELECT * FROM deliveries WHERE order_id = :id', ['id' => $orderId]);
        if ($existing !== null) {
            $this->ensureDeliveredStatus($orderId);

            return [
                'result'   => self::ALREADY,
                'code'     => (string) $existing['code'],
                'supplier' => (string) $existing['supplier'],
            ];
        }

        if (!in_array((string) $order['status'], OrderStatus::RECOVERABLE, true)) {
            return ['result' => self::NOT_PAYABLE, 'detail' => 'order status ' . $order['status']];
        }

        Db::transaction(fn (PDO $pdo) => OrderService::transition(
            $pdo,
            $orderId,
            (string) $order['status'],
            OrderStatus::DELIVERING,
            'delivery started',
            'delivery'
        ));

        // ---- phase 1: resolve anything whose fate we do not know ---------
        $open = Db::all(
            "SELECT * FROM supplier_requests
             WHERE order_id = :id AND state IN ('unknown','in_flight')
             ORDER BY created_at",
            ['id' => $orderId]
        );

        foreach ($open as $sr) {
            $gateway = SupplierGateway::byName((string) $sr['supplier']);
            if ($gateway === null) {
                continue;
            }

            $resolution = $this->resolveUnknown($order, $gateway, (string) $sr['request_id']);

            if ($resolution['state'] === 'ok') {
                return $this->attach($order, $gateway->name, (string) $sr['request_id'], (string) $resolution['code']);
            }
            if ($resolution['state'] === 'unknown') {
                // Still in the dark. Failing over now risks a second key.
                Log::warn('delivery_unknown_state_hold', [
                    'order_id'   => $orderId,
                    'supplier'   => $gateway->name,
                    'request_id' => $sr['request_id'],
                ]);

                return ['result' => self::RETRY_LATER, 'detail' => 'supplier state unknown, holding fail-over'];
            }
            // 'error' — authoritative "nothing was issued": fail-over is safe.
        }

        // ---- phase 2: try suppliers in preference order ------------------
        $sawOutOfStock = false;

        foreach (SupplierGateway::registry() as $gateway) {
            $sr = $this->openRequest($orderId, $gateway->name);

            if ($sr['state'] === 'ok' && $sr['code'] !== null) {
                return $this->attach($order, $gateway->name, (string) $sr['request_id'], (string) $sr['code']);
            }

            $requestId = (string) $sr['request_id'];
            $outcome   = $this->callSupplier($order, $gateway, $requestId);

            if ($outcome['state'] === 'ok') {
                return $this->attach($order, $gateway->name, $requestId, (string) $outcome['code']);
            }
            if ($outcome['state'] === 'unknown') {
                return ['result' => self::RETRY_LATER, 'detail' => 'supplier timed out, state unknown'];
            }
            $sawOutOfStock = $sawOutOfStock || ($outcome['reason'] ?? null) === 'out_of_stock';
        }

        // ---- phase 3: every supplier said no -----------------------------
        $final = $sawOutOfStock ? OrderStatus::OUT_OF_STOCK : OrderStatus::DELIVERY_FAILED;

        Db::transaction(function (PDO $pdo) use ($orderId, $final, $sawOutOfStock) {
            $st = $pdo->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
            $st->execute(['id' => $orderId]);
            $current = (string) $st->fetchColumn();
            OrderService::transition(
                $pdo,
                $orderId,
                $current,
                $final,
                $sawOutOfStock ? 'no stock at any supplier' : 'all suppliers failed',
                'delivery'
            );
        });

        Log::warn('delivery_unsuccessful', ['order_id' => $orderId, 'status' => $final]);

        // Recoverable, not a dead end: the sweeper will pick it up again.
        return ['result' => $sawOutOfStock ? self::OUT_OF_STOCK : self::FAILED];
    }

    /**
     * Call one supplier, retrying the SAME request_id with backoff.
     *
     * @param  array<string,mixed> $order
     * @return array{state:string,code?:string,reason?:string}
     */
    private function callSupplier(array $order, SupplierGateway $gateway, string $requestId): array
    {
        $orderId = (string) $order['id'];

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->touchRequest($requestId, 'in_flight', null, null);

            $res = $gateway->issue($requestId, (string) $order['sku'], $orderId);
            $this->recordAttempt($orderId, $gateway->name, $requestId, $attempt, $res);

            // --- success -------------------------------------------------
            if ($res->isOk() && is_string($res->json['code'] ?? null)) {
                $this->touchRequest($requestId, 'ok', (string) $res->json['code'], null);

                return ['state' => 'ok', 'code' => (string) $res->json['code']];
            }

            // --- explicit error: the supplier answered, so nothing is in flight
            if ($res->kind === HttpResult::HTTP_ERROR) {
                $reason = $res->reason() ?? ('http_' . $res->status);

                if ($reason === 'out_of_stock' || $res->status === 409) {
                    $this->touchRequest($requestId, 'error', null, 'out_of_stock');

                    return ['state' => 'error', 'reason' => 'out_of_stock'];
                }
                if ($attempt === $this->maxAttempts) {
                    $this->touchRequest($requestId, 'error', null, $reason);

                    return ['state' => 'error', 'reason' => $reason];
                }
                $this->sleepBackoff($attempt);
                continue;
            }

            // --- never reached the supplier: safe, retry then fail over ---
            if ($res->kind === HttpResult::UNAVAILABLE) {
                if ($attempt === $this->maxAttempts) {
                    $this->touchRequest($requestId, 'error', null, 'unavailable');

                    return ['state' => 'error', 'reason' => 'unavailable'];
                }
                $this->sleepBackoff($attempt);
                continue;
            }

            // --- TIMEOUT: the request was delivered, the answer was not ---
            $this->touchRequest($requestId, 'unknown', null, 'timeout');
            Log::warn('supplier_timeout', [
                'order_id'    => $orderId,
                'supplier'    => $gateway->name,
                'request_id'  => $requestId,
                'attempt'     => $attempt,
                'duration_ms' => $res->durationMs,
                'note'        => 'state UNKNOWN — retrying the same request_id, no fail-over',
            ]);

            if ($attempt < $this->maxAttempts) {
                $this->sleepBackoff($attempt);
                continue;
            }

            // Last resort before giving up: ask the supplier what happened.
            return $this->resolveUnknown($order, $gateway, $requestId);
        }

        return ['state' => 'unknown'];
    }

    /**
     * Find out whether an unknown request actually produced a code.
     *
     * @param  array<string,mixed> $order
     * @return array{state:string,code?:string,reason?:string}
     */
    private function resolveUnknown(array $order, SupplierGateway $gateway, string $requestId): array
    {
        $orderId = (string) $order['id'];

        // 1) reconcile endpoint
        $probe = $gateway->probe($requestId);
        $this->recordAttempt($orderId, $gateway->name, $requestId, 0, $probe, 'probe');

        if ($probe->isOk() && is_string($probe->json['code'] ?? null)) {
            Log::info('supplier_probe_resolved_issued', [
                'order_id' => $orderId, 'supplier' => $gateway->name, 'request_id' => $requestId,
            ]);
            $this->touchRequest($requestId, 'ok', (string) $probe->json['code'], 'resolved_by_probe');

            return ['state' => 'ok', 'code' => (string) $probe->json['code']];
        }

        if ($probe->kind === HttpResult::HTTP_ERROR && $probe->status === 404) {
            // Authoritative: the supplier never recorded this request_id.
            Log::info('supplier_probe_resolved_not_issued', [
                'order_id' => $orderId, 'supplier' => $gateway->name, 'request_id' => $requestId,
            ]);
            $this->touchRequest($requestId, 'error', null, 'not_issued');

            return ['state' => 'error', 'reason' => 'not_issued'];
        }

        // 2) the idempotent POST is itself a probe: if the code exists, we get
        //    the very same one back.
        $retry = $gateway->issue($requestId, (string) $order['sku'], $orderId);
        $this->recordAttempt($orderId, $gateway->name, $requestId, 0, $retry, 'resolve');

        if ($retry->isOk() && is_string($retry->json['code'] ?? null)) {
            $this->touchRequest($requestId, 'ok', (string) $retry->json['code'], 'resolved_by_retry');

            return ['state' => 'ok', 'code' => (string) $retry->json['code']];
        }
        if ($retry->kind === HttpResult::HTTP_ERROR && $retry->reason() === 'out_of_stock') {
            $this->touchRequest($requestId, 'error', null, 'out_of_stock');

            return ['state' => 'error', 'reason' => 'out_of_stock'];
        }

        $this->touchRequest($requestId, 'unknown', null, 'unresolved');

        return ['state' => 'unknown'];
    }

    /**
     * The single place where a delivery becomes a fact.
     *
     * @param  array<string,mixed> $order
     * @return array{result:string,detail?:string,code?:string,supplier?:string}
     */
    private function attach(array $order, string $supplier, string $requestId, string $code): array
    {
        $orderId = (string) $order['id'];

        $inserted = Db::transaction(function (PDO $pdo) use ($orderId, $supplier, $requestId, $code, $order) {
            $st = $pdo->prepare(
                'INSERT INTO deliveries (order_id, supplier, request_id, code)
                 VALUES (:o, :s, :r, :c)
                 ON CONFLICT (order_id) DO NOTHING
                 RETURNING id'
            );

            try {
                $st->execute(['o' => $orderId, 's' => $supplier, 'r' => $requestId, 'c' => $code]);
            } catch (\PDOException $e) {
                // UNIQUE(code): this code already belongs to another order.
                if (Db::isUniqueViolation($e)) {
                    return false;
                }
                throw $e;
            }

            if ($st->fetchColumn() === false) {
                return false;                   // another worker delivered first
            }

            $st = $pdo->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
            $st->execute(['id' => $orderId]);
            $current = (string) $st->fetchColumn();

            OrderService::transition($pdo, $orderId, $current, OrderStatus::DELIVERED, 'code issued', 'delivery');
            Ledger::deliveryRecognized($pdo, $orderId, (int) $order['amount_minor'], $requestId);

            // keep the storefront projection honest
            $pdo->prepare(
                'UPDATE products SET available_qty = GREATEST(0, available_qty - 1)
                 WHERE sku = :sku AND supplier_backed'
            )->execute(['sku' => $order['sku']]);

            return true;
        });

        if ($inserted === false) {
            // We hold a code that cannot be attached. It is money: record it.
            Db::run(
                'INSERT INTO orphan_codes (order_id, supplier, request_id, code, reason)
                 VALUES (:o, :s, :r, :c, :reason)
                 ON CONFLICT (supplier, request_id, code) DO NOTHING',
                [
                    'o' => $orderId, 's' => $supplier, 'r' => $requestId, 'c' => $code,
                    'reason' => 'order already delivered or code already used',
                ]
            );
            Log::error('orphan_code', [
                'order_id' => $orderId, 'supplier' => $supplier, 'request_id' => $requestId,
            ]);

            $existing = Db::one('SELECT * FROM deliveries WHERE order_id = :id', ['id' => $orderId]);

            return $existing !== null
                ? ['result' => self::ALREADY, 'code' => (string) $existing['code'], 'supplier' => (string) $existing['supplier']]
                : ['result' => self::RETRY_LATER, 'detail' => 'code collision'];
        }

        Log::info('order_delivered', [
            'order_id'   => $orderId,
            'supplier'   => $supplier,
            'request_id' => $requestId,
        ]);

        return ['result' => self::DELIVERED, 'code' => $code, 'supplier' => $supplier];
    }

    private function ensureDeliveredStatus(string $orderId): void
    {
        Db::transaction(function (PDO $pdo) use ($orderId) {
            $st = $pdo->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
            $st->execute(['id' => $orderId]);
            $current = (string) $st->fetchColumn();
            if ($current !== OrderStatus::DELIVERED) {
                OrderService::transition($pdo, $orderId, $current, OrderStatus::DELIVERED, 'delivery row exists', 'delivery');
            }
        });
    }

    /**
     * Decide which request_id this delivery run should use for a supplier.
     *
     *   no previous attempt        -> epoch 1
     *   previous attempt succeeded -> reuse it (we already hold a code)
     *   previous attempt UNKNOWN   -> reuse it; a new key here could double-issue
     *   previous attempt ERRORED   -> open the next epoch; the supplier has
     *                                 cached that failure under the old key and
     *                                 would replay it forever
     *
     * @return array<string,mixed>
     */
    private function openRequest(string $orderId, string $supplier): array
    {
        $last = Db::one(
            'SELECT * FROM supplier_requests
             WHERE order_id = :o AND supplier = :s
             ORDER BY epoch DESC LIMIT 1',
            ['o' => $orderId, 's' => $supplier]
        );

        if ($last !== null && $last['state'] !== 'error') {
            return $last;
        }

        $epoch     = $last === null ? 1 : ((int) $last['epoch'] + 1);
        $requestId = self::requestId($orderId, $supplier, $epoch);

        Db::run(
            "INSERT INTO supplier_requests (request_id, order_id, supplier, epoch, state)
             VALUES (:r, :o, :s, :e, 'in_flight')
             ON CONFLICT (request_id) DO NOTHING",
            ['r' => $requestId, 'o' => $orderId, 's' => $supplier, 'e' => $epoch]
        );

        /** @var array<string,mixed> $row */
        $row = Db::one('SELECT * FROM supplier_requests WHERE request_id = :r', ['r' => $requestId]);

        return $row;
    }

    private function touchRequest(string $requestId, string $state, ?string $code, ?string $reason): void
    {
        Db::run(
            'UPDATE supplier_requests
             SET state = CAST(:state AS text),
                 code = COALESCE(CAST(:code AS text), code),
                 reason = CAST(:reason AS text),
                 attempts = attempts + CASE WHEN CAST(:state2 AS text) = \'in_flight\' THEN 1 ELSE 0 END,
                 updated_at = now()
             WHERE request_id = :r',
            ['state' => $state, 'state2' => $state, 'code' => $code, 'reason' => $reason, 'r' => $requestId]
        );
    }

    private function recordAttempt(
        string $orderId,
        string $supplier,
        string $requestId,
        int $attemptNo,
        HttpResult $res,
        string $kind = 'issue',
    ): void {
        $outcome = match (true) {
            $res->isOk()                                   => 'ok',
            $res->kind === HttpResult::TIMEOUT             => 'timeout',
            $res->kind === HttpResult::UNAVAILABLE         => 'unavailable',
            $res->reason() === 'out_of_stock'              => 'out_of_stock',
            default                                        => 'error',
        };

        Db::run(
            'INSERT INTO delivery_attempts
                 (order_id, supplier, request_id, attempt_no, outcome, http_status, duration_ms, reason, trace_id)
             VALUES (:o, :s, :r, :n, :outcome, :status, :ms, :reason, :trace)',
            [
                'o' => $orderId, 's' => $supplier, 'r' => $requestId, 'n' => $attemptNo,
                'outcome' => $outcome, 'status' => $res->status, 'ms' => $res->durationMs,
                'reason' => $kind . ':' . ($res->reason() ?? '-'), 'trace' => Log::traceId(),
            ]
        );

        Log::info('supplier_call', [
            'order_id'    => $orderId,
            'supplier'    => $supplier,
            'request_id'  => $requestId,
            'attempt'     => $attemptNo,
            'kind'        => $kind,
            'outcome'     => $outcome,
            'http_status' => $res->status,
            'duration_ms' => $res->durationMs,
        ]);
    }

    private function sleepBackoff(int $attempt): void
    {
        $ms = $this->backoffBaseMs * (2 ** ($attempt - 1)) + random_int(0, max(1, $this->backoffJitterMs));
        usleep((int) min($ms, 5000) * 1000);
    }
}
