<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ItemStatus;
use App\Domain\OrderStatus;
use App\Infra\Db;
use App\Infra\HttpResult;
use App\Infra\Log;
use App\Support\Env;
use PDO;

/**
 * Stage 3 (resilient issuing) + stage 2 (baskets, an untrusted supplier, a
 * rate-limited one).
 *
 * The rules this class exists to enforce:
 *
 *  * request_id is DERIVED, not random: `req_<order>_<item>_<supplier>_<epoch>`.
 *    Every retry of the same (order, item, supplier) reuses it, so the
 *    supplier's own idempotency returns the same code instead of burning a
 *    second key.
 *
 *  * TIMEOUT IS NOT A FAILURE. A read timeout leaves the request in state
 *    `unknown`. While any request for an item is `unknown` we NEVER fail over
 *    to another supplier for THAT item — that is exactly how you deliver
 *    twice. We retry the same request_id (which doubles as a probe), then ask
 *    the supplier's reconcile endpoint.
 *
 *  * AN ERROR RESPONSE IS NOT TRUSTED EITHER (stage 2, #2). A supplier may
 *    answer 4xx/5xx/409 having actually committed a code. Before treating any
 *    error as definitive, we run the exact same probe-then-idempotent-retry
 *    confirmation used for timeouts. Only a confirmed "nothing was issued"
 *    downgrades to a real failure.
 *
 *  * Even if all of the above failed, `deliveries` has UNIQUE(item_id) and
 *    UNIQUE(code): the database physically cannot record two deliveries for
 *    one item, and a code physically cannot be attached to two different
 *    items — including a code an untrusted supplier handed us twice, or one
 *    that belongs to somebody else's order. Anything that loses that race is
 *    written to `orphan_codes` — never dropped, never handed to a buyer.
 *
 *  * One item's supplier trouble never blocks its neighbours: each basket
 *    line is delivered independently, by its own supplier call, under its
 *    own idempotency lineage. The order's own status is always re-derived
 *    from the mix of item outcomes (OrderService::recomputeStatus), never set
 *    directly here.
 *
 *  * No database transaction is held open across an HTTP call. Mutual
 *    exclusion between workers uses a session-level advisory lock instead,
 *    scoped to the whole order (one job per order, items are processed in
 *    sequence within it).
 */
final class DeliveryService
{
    public const DELIVERED           = 'delivered';
    public const PARTIALLY_DELIVERED = 'partially_delivered';
    public const REFUNDED            = 'refunded';
    public const ALREADY             = 'already_delivered';
    public const LOCKED              = 'locked_by_other_worker';
    public const NOT_PAYABLE         = 'not_payable';
    public const RETRY_LATER         = 'retry_later';
    public const RATE_LIMITED        = 'rate_limited';
    public const OUT_OF_STOCK        = 'out_of_stock';
    public const FAILED              = 'delivery_failed';

    private int $maxAttempts;
    private int $backoffBaseMs;
    private int $backoffJitterMs;

    public function __construct()
    {
        $this->maxAttempts     = max(1, Env::int('SUPPLIER_MAX_ATTEMPTS', 3));
        $this->backoffBaseMs   = Env::int('SUPPLIER_BACKOFF_BASE_MS', 200);
        $this->backoffJitterMs = Env::int('SUPPLIER_BACKOFF_JITTER_MS', 150);
    }

    public static function requestId(string $orderId, string $itemId, string $supplier, int $epoch): string
    {
        return sprintf('req_%s_%s_%s_%d', $orderId, $itemId, $supplier, $epoch);
    }

    /** @return array{result:string,detail?:string} */
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

    /** @return array{result:string,detail?:string} */
    private function deliverLocked(string $orderId): array
    {
        $order = Db::one('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            return ['result' => self::NOT_PAYABLE, 'detail' => 'order not found'];
        }

        $status = (string) $order['status'];

        // Already fully resolved, or never payable to begin with: idempotent
        // no-op either way. (acceptance #1, #2, #4)
        if (!in_array($status, OrderStatus::RECOVERABLE, true)) {
            $resolved = in_array(
                $status,
                [OrderStatus::DELIVERED, OrderStatus::PARTIALLY_DELIVERED, OrderStatus::REFUNDED],
                true
            );

            return ['result' => $resolved ? self::ALREADY : self::NOT_PAYABLE, 'detail' => 'order status ' . $status];
        }

        Db::transaction(fn (PDO $pdo) => OrderService::transition(
            $pdo,
            $orderId,
            $status,
            OrderStatus::DELIVERING,
            'delivery started',
            'delivery'
        ));

        $items = Db::all(
            "SELECT * FROM order_items
             WHERE order_id = :id AND status NOT IN ('delivered', 'refunded')
             ORDER BY line_no",
            ['id' => $orderId]
        );

        $anyRateLimited = false;

        foreach ($items as $item) {
            $outcome = $this->deliverItem($order, $item);
            if (($outcome['result'] ?? null) === self::RATE_LIMITED) {
                $anyRateLimited = true;
            }
            // One item holding (unknown state, rate-limited, out of stock)
            // never blocks its neighbours — each has its own supplier call.
        }

        $rollup = Db::transaction(fn (PDO $pdo) => OrderService::recomputeStatus($pdo, $orderId));

        return match ($rollup['result']) {
            OrderStatus::DELIVERED           => ['result' => self::DELIVERED],
            OrderStatus::PARTIALLY_DELIVERED => ['result' => self::PARTIALLY_DELIVERED],
            OrderStatus::REFUNDED            => ['result' => self::REFUNDED],
            OrderStatus::OUT_OF_STOCK        => ['result' => self::OUT_OF_STOCK],
            OrderStatus::DELIVERY_FAILED     => ['result' => self::FAILED],
            default                          => ['result' => $anyRateLimited ? self::RATE_LIMITED : self::RETRY_LATER],
        };
    }

    /**
     * Deliver ONE basket line. Never throws for a supplier-side problem: it
     * always resolves to a result and lets the caller move on to the next
     * item.
     *
     * @param  array<string,mixed> $order
     * @param  array<string,mixed> $item
     * @return array{result:string,detail?:string,code?:string,supplier?:string}
     */
    private function deliverItem(array $order, array $item): array
    {
        $orderId = (string) $order['id'];
        $itemId  = (string) $item['id'];
        $status  = (string) $item['status'];

        if (in_array($status, [ItemStatus::PENDING, ItemStatus::OUT_OF_STOCK, ItemStatus::DELIVERY_FAILED], true)) {
            Db::transaction(fn (PDO $pdo) => OrderService::transitionItem(
                $pdo,
                $itemId,
                $orderId,
                $status,
                ItemStatus::DELIVERING,
                'delivery attempt started',
                'delivery'
            ));
        }

        // ---- phase 1: resolve anything whose fate we do not know ---------
        $open = Db::all(
            "SELECT * FROM supplier_requests
             WHERE item_id = :id AND state IN ('unknown','in_flight')
             ORDER BY created_at",
            ['id' => $itemId]
        );

        foreach ($open as $sr) {
            $gateway = SupplierGateway::byName((string) $sr['supplier']);
            if ($gateway === null) {
                continue;
            }

            $resolution = $this->resolveUnknown($order, $item, $gateway, (string) $sr['request_id']);

            if ($resolution['state'] === 'ok') {
                return $this->attach($order, $item, $gateway->name, (string) $sr['request_id'], (string) $resolution['code']);
            }
            if ($resolution['state'] === 'unknown') {
                // Still in the dark. Failing over now risks a second key.
                Log::warn('delivery_unknown_state_hold', [
                    'order_id' => $orderId, 'item_id' => $itemId,
                    'supplier' => $gateway->name, 'request_id' => $sr['request_id'],
                ]);

                return ['result' => self::RETRY_LATER, 'detail' => 'supplier state unknown, holding fail-over'];
            }
            // 'error' — authoritative "nothing was issued": fail-over is safe.
        }

        // ---- phase 2: try suppliers in preference order -------------------
        $sawOutOfStock  = false;
        $sawRateLimited = false;

        foreach (SupplierGateway::registry() as $gateway) {
            if (!SupplierRateLimiter::tryAcquire($gateway->name)) {
                $sawRateLimited = true;
                Log::info('supplier_rate_limited', [
                    'order_id' => $orderId, 'item_id' => $itemId, 'supplier' => $gateway->name,
                ]);
                continue;                            // try the other supplier this pass
            }

            $sr = $this->openRequest($itemId, $orderId, $gateway->name);

            if ($sr['state'] === 'ok' && $sr['code'] !== null) {
                $result = $this->attach($order, $item, $gateway->name, (string) $sr['request_id'], (string) $sr['code']);
                if (($result['detail'] ?? null) !== 'code_conflict') {
                    return $result;
                }
                continue;
            }

            $requestId = (string) $sr['request_id'];
            $outcome   = $this->callSupplier($order, $item, $gateway, $requestId);

            if ($outcome['state'] === 'ok') {
                $result = $this->attach($order, $item, $gateway->name, $requestId, (string) $outcome['code']);
                if (($result['detail'] ?? null) === 'code_conflict') {
                    // Untrusted supplier handed us a code that is not
                    // exclusively ours. Never given to the buyer; try the
                    // NEXT supplier in this same pass instead of stalling.
                    continue;
                }

                return $result;
            }
            if ($outcome['state'] === 'unknown') {
                return ['result' => self::RETRY_LATER, 'detail' => 'supplier timed out, state unknown'];
            }
            if ($outcome['state'] === 'rate_limited') {
                $sawRateLimited = true;
                continue;
            }
            $sawOutOfStock = $sawOutOfStock || ($outcome['reason'] ?? null) === 'out_of_stock';
        }

        if ($sawRateLimited) {
            return ['result' => self::RATE_LIMITED];
        }

        // ---- phase 3: every supplier said no for THIS item ----------------
        $final = $sawOutOfStock ? ItemStatus::OUT_OF_STOCK : ItemStatus::DELIVERY_FAILED;

        Db::transaction(function (PDO $pdo) use ($itemId, $orderId, $final, $sawOutOfStock) {
            $st = $pdo->prepare('SELECT status FROM order_items WHERE id = :id FOR UPDATE');
            $st->execute(['id' => $itemId]);
            $current = (string) $st->fetchColumn();
            OrderService::transitionItem(
                $pdo,
                $itemId,
                $orderId,
                $current,
                $final,
                $sawOutOfStock ? 'no stock at any supplier' : 'all suppliers failed',
                'delivery'
            );
        });

        Log::warn('item_delivery_unsuccessful', ['order_id' => $orderId, 'item_id' => $itemId, 'status' => $final]);

        return ['result' => $sawOutOfStock ? self::OUT_OF_STOCK : self::FAILED];
    }

    /**
     * Call one supplier, retrying the SAME request_id with backoff.
     *
     * @param  array<string,mixed> $order
     * @param  array<string,mixed> $item
     * @return array{state:string,code?:string,reason?:string}
     */
    private function callSupplier(array $order, array $item, SupplierGateway $gateway, string $requestId): array
    {
        $orderId = (string) $order['id'];
        $itemId  = (string) $item['id'];

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            if (!SupplierRateLimiter::tryAcquire($gateway->name)) {
                return ['state' => 'rate_limited'];
            }

            $this->touchRequest($requestId, 'in_flight', null, null);

            $res = $gateway->issue($requestId, (string) $item['sku'], $orderId);
            $this->recordAttempt($orderId, $itemId, $gateway->name, $requestId, $attempt, $res);

            // --- success -------------------------------------------------
            if ($res->isOk() && is_string($res->json['code'] ?? null)) {
                $this->touchRequest($requestId, 'ok', (string) $res->json['code'], null);

                return ['state' => 'ok', 'code' => (string) $res->json['code']];
            }

            // --- explicit error: do NOT take it at face value (stage 2, #2)
            if ($res->kind === HttpResult::HTTP_ERROR) {
                $reason       = $res->reason() ?? ('http_' . $res->status);
                $isOutOfStock = $reason === 'out_of_stock' || $res->status === 409;

                // One confirmatory probe: the supplier may have committed a
                // code and lied about the transport. Reuses the exact same
                // mechanism stage 3 built for timeouts.
                $confirmed = $this->resolveUnknown($order, $item, $gateway, $requestId);
                if ($confirmed['state'] === 'ok') {
                    return $confirmed;                  // it lied — use the real code
                }
                if ($confirmed['state'] === 'unknown') {
                    if ($attempt < $this->maxAttempts) {
                        $this->sleepBackoff($attempt);
                        continue;
                    }

                    return ['state' => 'unknown'];
                }

                // Confirmed: nothing was issued. Classify by what WE saw,
                // not by resolveUnknown's generic "not_issued" label, so
                // out_of_stock keeps meaning out_of_stock.
                if ($isOutOfStock) {
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
                'item_id'     => $itemId,
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
            return $this->resolveUnknown($order, $item, $gateway, $requestId);
        }

        return ['state' => 'unknown'];
    }

    /**
     * Find out whether an unknown/disputed request actually produced a code.
     * Used both for real timeouts (stage 3) and for confirming a supplier's
     * error response before trusting it (stage 2, #2) — same mechanism,
     * because both boil down to "we do not actually know what happened".
     *
     * @param  array<string,mixed> $order
     * @param  array<string,mixed> $item
     * @return array{state:string,code?:string,reason?:string}
     */
    private function resolveUnknown(array $order, array $item, SupplierGateway $gateway, string $requestId): array
    {
        $orderId = (string) $order['id'];
        $itemId  = (string) $item['id'];

        // 1) reconcile endpoint
        $probe = $gateway->probe($requestId);
        $this->recordAttempt($orderId, $itemId, $gateway->name, $requestId, 0, $probe, 'probe');

        if ($probe->isOk() && is_string($probe->json['code'] ?? null)) {
            Log::info('supplier_probe_resolved_issued', [
                'order_id' => $orderId, 'item_id' => $itemId, 'supplier' => $gateway->name, 'request_id' => $requestId,
            ]);
            $this->touchRequest($requestId, 'ok', (string) $probe->json['code'], 'resolved_by_probe');

            return ['state' => 'ok', 'code' => (string) $probe->json['code']];
        }

        if ($probe->kind === HttpResult::HTTP_ERROR && $probe->status === 404) {
            // Authoritative: the supplier has no record of a code for this
            // request_id — nothing to un-trust further.
            Log::info('supplier_probe_resolved_not_issued', [
                'order_id' => $orderId, 'item_id' => $itemId, 'supplier' => $gateway->name, 'request_id' => $requestId,
            ]);
            $this->touchRequest($requestId, 'error', null, 'not_issued');

            return ['state' => 'error', 'reason' => 'not_issued'];
        }

        // 2) the idempotent POST is itself a probe: if the code exists, we get
        //    the very same one back.
        $retry = $gateway->issue($requestId, (string) $item['sku'], $orderId);
        $this->recordAttempt($orderId, $itemId, $gateway->name, $requestId, 0, $retry, 'resolve');

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
     * The single place where a delivery becomes a fact for one item.
     *
     * @param  array<string,mixed> $order
     * @param  array<string,mixed> $item
     * @return array{result:string,detail?:string,code?:string,supplier?:string}
     */
    private function attach(array $order, array $item, string $supplier, string $requestId, string $code): array
    {
        $orderId = (string) $order['id'];
        $itemId  = (string) $item['id'];

        $outcome = Db::transaction(function (PDO $pdo) use ($orderId, $itemId, $supplier, $requestId, $code, $item) {
            $st = $pdo->prepare(
                'INSERT INTO deliveries (order_id, item_id, supplier, request_id, code)
                 VALUES (:o, :i, :s, :r, :c)
                 ON CONFLICT (item_id) DO NOTHING
                 RETURNING id'
            );

            try {
                $st->execute(['o' => $orderId, 'i' => $itemId, 's' => $supplier, 'r' => $requestId, 'c' => $code]);
            } catch (\PDOException $e) {
                // UNIQUE(code): an untrusted supplier gave us a code that is
                // not exclusively ours (a duplicate, or someone else's).
                if (Db::isUniqueViolation($e)) {
                    return 'code_conflict';
                }
                throw $e;
            }

            if ($st->fetchColumn() === false) {
                return 'already';                   // another worker delivered this item first
            }

            $st = $pdo->prepare('SELECT status FROM order_items WHERE id = :id FOR UPDATE');
            $st->execute(['id' => $itemId]);
            $current = (string) $st->fetchColumn();

            OrderService::transitionItem($pdo, $itemId, $orderId, $current, ItemStatus::DELIVERED, 'code issued', 'delivery');
            Ledger::deliveryRecognized($pdo, $orderId, $itemId, (int) $item['amount_minor'], $requestId);

            // keep the storefront projection honest
            $pdo->prepare(
                'UPDATE products SET available_qty = GREATEST(0, available_qty - 1)
                 WHERE sku = :sku AND supplier_backed'
            )->execute(['sku' => $item['sku']]);

            return 'delivered';
        });

        if ($outcome === 'delivered') {
            Log::info('item_delivered', [
                'order_id' => $orderId, 'item_id' => $itemId, 'supplier' => $supplier, 'request_id' => $requestId,
            ]);

            return ['result' => self::DELIVERED, 'code' => $code, 'supplier' => $supplier];
        }

        if ($outcome === 'already') {
            $existing = Db::one('SELECT * FROM deliveries WHERE item_id = :id', ['id' => $itemId]);

            return $existing !== null
                ? ['result' => self::ALREADY, 'code' => (string) $existing['code'], 'supplier' => (string) $existing['supplier']]
                : ['result' => self::RETRY_LATER, 'detail' => 'item collision'];
        }

        // code_conflict: never hand this code to a buyer. Record it, poison
        // this request_id so the next attempt opens a fresh epoch instead of
        // retrying onto the same bad code forever — resolved automatically,
        // no human in the loop (stage 2, #2 point 4).
        Db::run(
            'INSERT INTO orphan_codes (order_id, item_id, supplier, request_id, code, reason)
             VALUES (:o, :i, :s, :r, :c, :reason)
             ON CONFLICT (supplier, request_id, code) DO NOTHING',
            [
                'o' => $orderId, 'i' => $itemId, 's' => $supplier, 'r' => $requestId, 'c' => $code,
                'reason' => 'code already attached to another item (untrusted supplier or race)',
            ]
        );
        Log::error('orphan_code', [
            'order_id' => $orderId, 'item_id' => $itemId, 'supplier' => $supplier, 'request_id' => $requestId,
        ]);
        $this->touchRequest($requestId, 'error', null, 'code_conflict_untrusted_supplier');

        return ['result' => self::RETRY_LATER, 'detail' => 'code_conflict'];
    }

    /**
     * Decide which request_id this delivery run should use for a supplier,
     * scoped to one item.
     *
     *   no previous attempt        -> epoch 1
     *   previous attempt succeeded -> reuse it (we already hold a code)
     *   previous attempt UNKNOWN   -> reuse it; a new key here could double-issue
     *   previous attempt ERRORED   -> open the next epoch; the supplier has
     *                                 cached that failure under the old key
     *                                 (or, for an untrusted supplier, handed
     *                                 out a code we cannot use again) and
     *                                 would replay it forever
     *
     * @return array<string,mixed>
     */
    private function openRequest(string $itemId, string $orderId, string $supplier): array
    {
        $last = Db::one(
            'SELECT * FROM supplier_requests
             WHERE item_id = :i AND supplier = :s
             ORDER BY epoch DESC LIMIT 1',
            ['i' => $itemId, 's' => $supplier]
        );

        if ($last !== null && $last['state'] !== 'error') {
            return $last;
        }

        $epoch     = $last === null ? 1 : ((int) $last['epoch'] + 1);
        $requestId = self::requestId($orderId, $itemId, $supplier, $epoch);

        Db::run(
            "INSERT INTO supplier_requests (request_id, order_id, item_id, supplier, epoch, state)
             VALUES (:r, :o, :i, :s, :e, 'in_flight')
             ON CONFLICT (request_id) DO NOTHING",
            ['r' => $requestId, 'o' => $orderId, 'i' => $itemId, 's' => $supplier, 'e' => $epoch]
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
        string $itemId,
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
                 (order_id, item_id, supplier, request_id, attempt_no, outcome, http_status, duration_ms, reason, trace_id)
             VALUES (:o, :i, :s, :r, :n, :outcome, :status, :ms, :reason, :trace)',
            [
                'o' => $orderId, 'i' => $itemId, 's' => $supplier, 'r' => $requestId, 'n' => $attemptNo,
                'outcome' => $outcome, 'status' => $res->status, 'ms' => $res->durationMs,
                'reason' => $kind . ':' . ($res->reason() ?? '-'), 'trace' => Log::traceId(),
            ]
        );

        Log::info('supplier_call', [
            'order_id'    => $orderId,
            'item_id'     => $itemId,
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
