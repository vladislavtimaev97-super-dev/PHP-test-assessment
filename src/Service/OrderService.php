<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ItemStatus;
use App\Domain\OrderStatus;
use App\Infra\Db;
use App\Infra\Log;
use PDO;

/**
 * Stage 2 — an order is a basket of one or more items, each fulfilled
 * independently by its own supplier call (App\Service\DeliveryService). This
 * class owns order/item creation, presentation, and the two flavours of
 * guarded state transition: `transition()` for the order row, `transitionItem()`
 * for one basket line. `recomputeStatus()` is what ties them together — the
 * order's own status is never set directly by delivery code, it is always
 * re-derived from the current mix of item statuses.
 */
final class OrderService
{
    /**
     * @param  array<string,mixed> $input
     * @return array{0:int,1:array<string,mixed>}  [http status, body]
     */
    public function create(array $input, ?string $idempotencyKey): array
    {
        $skus  = $this->extractSkus($input);
        $email = is_string($input['customer_email'] ?? null) ? $input['customer_email'] : null;

        // Optional caller-supplied id. Real integrations need this (the payment
        // page is often created before us), and it is what makes the
        // "webhook arrives before the order" scenario reproducible.
        $orderId = is_string($input['order_id'] ?? null) ? trim($input['order_id']) : null;
        if ($orderId !== null && preg_match('/^ord_[A-Za-z0-9_-]{1,40}$/', $orderId) !== 1) {
            throw new \InvalidArgumentException('Field "order_id" must match ord_[A-Za-z0-9_-]{1,40}');
        }

        $hash = hash('sha256', json_encode(['skus' => $skus, 'email' => $email, 'order_id' => $orderId]) ?: '');

        // --- API-level idempotency: a retried POST must not create a 2nd order.
        if ($idempotencyKey !== null) {
            $existing = Db::one('SELECT * FROM idempotency_keys WHERE key = :k', ['k' => $idempotencyKey]);
            if ($existing !== null) {
                if ($existing['request_hash'] !== $hash) {
                    return [409, ['error' => [
                        'code'    => 'idempotency_key_reuse',
                        'message' => 'This Idempotency-Key was used with a different payload',
                    ]]];
                }

                return [(int) $existing['response_code'], json_decode((string) $existing['response_body'], true)];
            }
        }

        $result = Db::transaction(function (PDO $pdo) use ($skus, $email, $orderId) {
            $st = $pdo->prepare('SELECT * FROM products WHERE sku = ANY(:skus::text[]) AND active');
            $st->execute(['skus' => '{' . implode(',', array_map(
                static fn (string $s): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"',
                array_unique($skus)
            )) . '}']);

            $bySku = [];
            foreach ($st->fetchAll() as $row) {
                $bySku[(string) $row['sku']] = $row;
            }

            foreach ($skus as $sku) {
                if (!isset($bySku[$sku])) {
                    return [404, ['error' => ['code' => 'sku_not_found', 'message' => "Unknown or inactive SKU {$sku}"]]];
                }
            }

            $total    = 0;
            $currency = (string) $bySku[$skus[0]]['currency'];
            foreach ($skus as $sku) {
                $total += (int) $bySku[$sku]['price_minor'];
            }

            $params = [
                'sku'      => count($skus) === 1 ? $skus[0] : null,
                'amount'   => $total,
                'currency' => $currency,
                'email'    => $email,
            ];

            if ($orderId === null) {
                $st = $pdo->prepare(
                    'INSERT INTO orders (sku, amount_minor, currency, customer_email)
                     VALUES (:sku, :amount, :currency, :email)
                     RETURNING *'
                );
            } else {
                $params['id'] = $orderId;
                $st = $pdo->prepare(
                    'INSERT INTO orders (id, sku, amount_minor, currency, customer_email)
                     VALUES (:id, :sku, :amount, :currency, :email)
                     ON CONFLICT (id) DO NOTHING
                     RETURNING *'
                );
            }

            $st->execute($params);
            $order = $st->fetch();

            if ($order === false) {
                return [409, ['error' => [
                    'code' => 'order_exists', 'message' => "Order {$orderId} already exists",
                ]]];
            }

            $pdo->prepare(
                'INSERT INTO order_status_history (order_id, from_status, to_status, reason, actor, trace_id)
                 VALUES (:id, NULL, :to, :reason, :actor, :trace)'
            )->execute([
                'id'     => $order['id'],
                'to'     => OrderStatus::CREATED,
                'reason' => 'order_created',
                'actor'  => 'api',
                'trace'  => Log::traceId(),
            ]);

            $itemSt = $pdo->prepare(
                'INSERT INTO order_items (order_id, line_no, sku, amount_minor)
                 VALUES (:oid, :line, :sku, :amount)
                 RETURNING *'
            );
            $itemHistSt = $pdo->prepare(
                'INSERT INTO order_status_history (order_id, item_id, from_status, to_status, reason, actor, trace_id)
                 VALUES (:oid, :iid, NULL, :to, :reason, :actor, :trace)'
            );

            $items = [];
            foreach ($skus as $i => $sku) {
                $itemSt->execute([
                    'oid'    => $order['id'],
                    'line'   => $i + 1,
                    'sku'    => $sku,
                    'amount' => (int) $bySku[$sku]['price_minor'],
                ]);
                $item = $itemSt->fetch();

                $itemHistSt->execute([
                    'oid'    => $order['id'],
                    'iid'    => $item['id'],
                    'to'     => ItemStatus::PENDING,
                    'reason' => 'item_created',
                    'actor'  => 'api',
                    'trace'  => Log::traceId(),
                ]);

                $items[] = $item;
            }

            return [201, $this->present($order, $items)];
        });

        [$status, $body] = $result;

        if ($idempotencyKey !== null && $status < 500) {
            Db::run(
                'INSERT INTO idempotency_keys (key, endpoint, request_hash, response_code, response_body)
                 VALUES (:k, :e, :h, :c, :b) ON CONFLICT (key) DO NOTHING',
                [
                    'k' => $idempotencyKey,
                    'e' => 'POST /orders',
                    'h' => $hash,
                    'c' => $status,
                    'b' => json_encode($body, JSON_UNESCAPED_UNICODE),
                ]
            );
        }

        if ($status === 201) {
            Log::info('order_created', [
                'order_id'     => $body['id'],
                'skus'         => $skus,
                'amount_minor' => $body['amount_minor'],
            ]);

            // A webhook may have arrived before the order existed (out of order
            // delivery). Apply anything that was parked for this order id.
            (new PaymentWebhookService())->applyDeferredFor((string) $body['id']);
            $fresh = $this->find((string) $body['id']);
            if ($fresh !== null) {
                $body = $fresh;
            }
        }

        return [$status, $body];
    }

    /**
     * Accepts either the stage-1 shape ({"sku": "..."}) or a basket
     * ({"items": [{"sku": "..."}, ...]}). Every basket line is exactly one
     * unit of a SKU (this is a digital-key marketplace: each unit is a
     * unique code, so "quantity 2" is simply two lines).
     *
     * @param  array<string,mixed> $input
     * @return list<string>
     */
    private function extractSkus(array $input): array
    {
        if (array_key_exists('items', $input)) {
            $items = $input['items'];
            if (!is_array($items) || $items === []) {
                throw new \InvalidArgumentException('Field "items" must be a non-empty array');
            }

            $skus = [];
            foreach (array_values($items) as $i => $entry) {
                $sku = is_array($entry) ? ($entry['sku'] ?? null) : $entry;
                if (!is_string($sku) || trim($sku) === '') {
                    throw new \InvalidArgumentException("Field \"items[{$i}].sku\" is required");
                }
                $skus[] = trim($sku);
            }

            return $skus;
        }

        $sku = is_string($input['sku'] ?? null) ? trim($input['sku']) : '';
        if ($sku === '') {
            throw new \InvalidArgumentException('Field "sku" or "items" is required');
        }

        return [$sku];
    }

    /** @return array<string,mixed>|null */
    public function find(string $orderId): ?array
    {
        $order = Db::one('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            return null;
        }

        $items = Db::all(
            'SELECT oi.*, d.code, d.supplier AS delivery_supplier,
                    d.request_id AS delivery_request_id, d.delivered_at AS delivery_delivered_at
             FROM order_items oi
             LEFT JOIN deliveries d ON d.item_id = oi.id
             WHERE oi.order_id = :id
             ORDER BY oi.line_no',
            ['id' => $orderId]
        );

        return $this->present($order, $items);
    }

    /**
     * @param  array<string,mixed>      $order
     * @param  list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function present(array $order, array $items): array
    {
        $presented = array_map(static function (array $it): array {
            return [
                'id'           => $it['id'],
                'line_no'      => (int) $it['line_no'],
                'sku'          => $it['sku'],
                'amount_minor' => (int) $it['amount_minor'],
                'status'       => $it['status'],
                'delivered_at' => $it['delivered_at'] ?? null,
                'refunded_at'  => $it['refunded_at'] ?? null,
                'delivery'     => !array_key_exists('code', $it) || $it['code'] === null ? null : [
                    'code'         => $it['code'],
                    'supplier'     => $it['delivery_supplier'],
                    'request_id'   => $it['delivery_request_id'],
                    'delivered_at' => $it['delivery_delivered_at'],
                ],
            ];
        }, $items);

        $delivered = 0;
        $refunded  = 0;
        foreach ($items as $it) {
            if ($it['status'] === ItemStatus::DELIVERED) {
                $delivered++;
            } elseif ($it['status'] === ItemStatus::REFUNDED) {
                $refunded++;
            }
        }

        $body = [
            'id'            => $order['id'],
            'status'        => $order['status'],
            'amount_minor'  => (int) $order['amount_minor'],
            'amount'        => (int) $order['amount_minor'] / 100,
            'currency'      => $order['currency'],
            'paid_at'       => $order['paid_at'],
            'delivered_at'  => $order['delivered_at'],
            'created_at'    => $order['created_at'],
            'items'         => $presented,
            'items_summary' => [
                'total'     => count($items),
                'delivered' => $delivered,
                'refunded'  => $refunded,
                'pending'   => count($items) - $delivered - $refunded,
            ],
        ];

        // Backward-compatible single-item view (this is still the common
        // case, and it is what stage-1 clients/tests read directly).
        if (count($presented) === 1) {
            $body['sku']      = $presented[0]['sku'];
            $body['delivery'] = $presented[0]['delivery'];
        } else {
            $body['sku']      = null;
            $body['delivery'] = null;
        }

        return $body;
    }

    /**
     * Guarded order-level state transition. Returns false when the move is
     * not allowed by the state machine. Caller must already hold the row lock
     * (recomputeStatus() and the delivery path both take FOR UPDATE first).
     */
    public static function transition(
        PDO $pdo,
        string $orderId,
        string $from,
        string $to,
        string $reason,
        string $actor = 'system',
    ): bool {
        if ($from === $to) {
            return true;
        }
        if (!OrderStatus::canMove($from, $to)) {
            Log::warn('transition_rejected', [
                'order_id' => $orderId, 'from' => $from, 'to' => $to, 'reason' => $reason,
            ]);

            return false;
        }

        $extra = match ($to) {
            OrderStatus::PAID      => ', paid_at = COALESCE(paid_at, now())',
            OrderStatus::DELIVERED,
            OrderStatus::PARTIALLY_DELIVERED => ', delivered_at = COALESCE(delivered_at, now())',
            default                => '',
        };

        $st = $pdo->prepare(
            "UPDATE orders SET status = :to, version = version + 1, updated_at = now() {$extra}
             WHERE id = :id AND status = :from"
        );
        $st->execute(['id' => $orderId, 'to' => $to, 'from' => $from]);

        if ($st->rowCount() === 0) {
            return false;                      // lost a race; caller re-reads
        }

        $pdo->prepare(
            'INSERT INTO order_status_history (order_id, from_status, to_status, reason, actor, trace_id)
             VALUES (:id, :from, :to, :reason, :actor, :trace)'
        )->execute([
            'id'     => $orderId,
            'from'   => $from,
            'to'     => $to,
            'reason' => $reason,
            'actor'  => $actor,
            'trace'  => Log::traceId(),
        ]);

        Log::info('order_status_changed', [
            'order_id' => $orderId, 'from' => $from, 'to' => $to, 'reason' => $reason,
        ]);

        return true;
    }

    /** Guarded item-level state transition. Mirrors transition() above. */
    public static function transitionItem(
        PDO $pdo,
        string $itemId,
        string $orderId,
        string $from,
        string $to,
        string $reason,
        string $actor = 'system',
    ): bool {
        if ($from === $to) {
            return true;
        }
        if (!ItemStatus::canMove($from, $to)) {
            Log::warn('item_transition_rejected', [
                'item_id' => $itemId, 'order_id' => $orderId, 'from' => $from, 'to' => $to, 'reason' => $reason,
            ]);

            return false;
        }

        $extra = match ($to) {
            ItemStatus::DELIVERED => ', delivered_at = COALESCE(delivered_at, now())',
            ItemStatus::REFUNDED  => ', refunded_at = COALESCE(refunded_at, now())',
            ItemStatus::OUT_OF_STOCK,
            ItemStatus::DELIVERY_FAILED => ', first_undeliverable_at = COALESCE(first_undeliverable_at, now())',
            default => '',
        };

        $st = $pdo->prepare(
            "UPDATE order_items SET status = :to, updated_at = now() {$extra}
             WHERE id = :id AND status = :from"
        );
        $st->execute(['id' => $itemId, 'to' => $to, 'from' => $from]);

        if ($st->rowCount() === 0) {
            return false;
        }

        $pdo->prepare(
            'INSERT INTO order_status_history (order_id, item_id, from_status, to_status, reason, actor, trace_id)
             VALUES (:oid, :iid, :from, :to, :reason, :actor, :trace)'
        )->execute([
            'oid'    => $orderId,
            'iid'    => $itemId,
            'from'   => $from,
            'to'     => $to,
            'reason' => $reason,
            'actor'  => $actor,
            'trace'  => Log::traceId(),
        ]);

        Log::info('item_status_changed', [
            'item_id' => $itemId, 'order_id' => $orderId, 'from' => $from, 'to' => $to, 'reason' => $reason,
        ]);

        return true;
    }

    /**
     * The order's own status is never set directly by delivery code — it is
     * always re-derived from the current mix of item statuses. This is what
     * makes partial fulfillment safe to retry from any point: no matter how
     * many times this runs, or in what order items finished, the order ends
     * up in exactly the status its items justify.
     *
     * Caller must NOT already hold the orders row lock (this takes it).
     *
     * @return array{result:string}
     */
    public static function recomputeStatus(PDO $pdo, string $orderId): array
    {
        $st = $pdo->prepare('SELECT status FROM orders WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $orderId]);
        $current = $st->fetchColumn();
        if ($current === false) {
            return ['result' => 'missing'];
        }
        $current = (string) $current;

        $st = $pdo->prepare('SELECT status FROM order_items WHERE order_id = :id');
        $st->execute(['id' => $orderId]);
        $statuses = array_column($st->fetchAll(), 'status');

        $total          = count($statuses);
        $delivered      = count(array_filter($statuses, static fn ($s) => $s === ItemStatus::DELIVERED));
        $refunded       = count(array_filter($statuses, static fn ($s) => $s === ItemStatus::REFUNDED));
        $outOfStock     = count(array_filter($statuses, static fn ($s) => $s === ItemStatus::OUT_OF_STOCK));
        $deliveryFailed = count(array_filter($statuses, static fn ($s) => $s === ItemStatus::DELIVERY_FAILED));
        $unresolved     = $total - $delivered - $refunded - $outOfStock - $deliveryFailed;

        // Something is still actively being worked (pending/delivering, or
        // held because a supplier's fate is unknown): keep DELIVERING.
        if ($unresolved > 0) {
            self::transition($pdo, $orderId, $current, OrderStatus::DELIVERING, 'items still in progress', 'delivery');

            return ['result' => 'in_progress'];
        }

        // Every item reached a terminal state: delivered or refunded.
        if ($delivered + $refunded === $total) {
            $target = match (true) {
                $refunded === 0  => OrderStatus::DELIVERED,
                $delivered === 0 => OrderStatus::REFUNDED,
                default          => OrderStatus::PARTIALLY_DELIVERED,
            };
            self::transition($pdo, $orderId, $current, $target, 'all items resolved', 'delivery');

            return ['result' => $target];
        }

        // No item is unresolved, but some are stuck (recoverable): a rollup,
        // not a dead end — the sweeper retries them, then eventually gives up
        // and refunds whichever ones never came back in stock.
        $target = $outOfStock > 0 ? OrderStatus::OUT_OF_STOCK : OrderStatus::DELIVERY_FAILED;
        self::transition($pdo, $orderId, $current, $target, 'items stuck, recoverable', 'delivery');

        return ['result' => $target];
    }

    /** @return array<string,mixed> full audit trail for one order */
    public function audit(string $orderId): array
    {
        return [
            'order'    => $this->find($orderId),
            'history'  => Db::all('SELECT * FROM order_status_history WHERE order_id = :id ORDER BY id', ['id' => $orderId]),
            'webhooks' => Db::all('SELECT event_id, status, amount_minor, event_created_at, received_at, processed_at, outcome, detail
                                   FROM webhook_events WHERE order_id = :id ORDER BY received_at', ['id' => $orderId]),
            'supplier_requests' => Db::all('SELECT * FROM supplier_requests WHERE order_id = :id ORDER BY created_at', ['id' => $orderId]),
            'attempts' => Db::all('SELECT * FROM delivery_attempts WHERE order_id = :id ORDER BY id', ['id' => $orderId]),
            'ledger'   => Db::all('SELECT item_id, entry_type, account, amount_minor, created_at FROM ledger_entries WHERE order_id = :id ORDER BY id', ['id' => $orderId]),
            'jobs'     => Db::all('SELECT id, type, status, attempts, priority, run_after, last_error FROM jobs WHERE order_id = :id ORDER BY id', ['id' => $orderId]),
            'orphan_codes' => Db::all('SELECT id, item_id, supplier, request_id, reason, created_at FROM orphan_codes WHERE order_id = :id ORDER BY id', ['id' => $orderId]),
        ];
    }
}
