<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\OrderStatus;
use App\Infra\Db;
use App\Infra\Log;
use PDO;

final class OrderService
{
    /**
     * @param  array<string,mixed> $input
     * @return array{0:int,1:array<string,mixed>}  [http status, body]
     */
    public function create(array $input, ?string $idempotencyKey): array
    {
        $sku = is_string($input['sku'] ?? null) ? trim($input['sku']) : '';
        if ($sku === '') {
            throw new \InvalidArgumentException('Field "sku" is required');
        }
        $email = is_string($input['customer_email'] ?? null) ? $input['customer_email'] : null;

        // Optional caller-supplied id. Real integrations need this (the payment
        // page is often created before us), and it is what makes the
        // "webhook arrives before the order" scenario reproducible.
        $orderId = is_string($input['order_id'] ?? null) ? trim($input['order_id']) : null;
        if ($orderId !== null && preg_match('/^ord_[A-Za-z0-9_-]{1,40}$/', $orderId) !== 1) {
            throw new \InvalidArgumentException('Field "order_id" must match ord_[A-Za-z0-9_-]{1,40}');
        }

        $hash = hash('sha256', json_encode(['sku' => $sku, 'email' => $email, 'order_id' => $orderId]) ?: '');

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

        $result = Db::transaction(function (PDO $pdo) use ($sku, $email, $orderId) {
            $st = $pdo->prepare('SELECT * FROM products WHERE sku = :sku AND active');
            $st->execute(['sku' => $sku]);
            $product = $st->fetch();

            if ($product === false) {
                return [404, ['error' => ['code' => 'sku_not_found', 'message' => "Unknown or inactive SKU {$sku}"]]];
            }

            $params = [
                'sku'      => $sku,
                'amount'   => (int) $product['price_minor'],
                'currency' => $product['currency'],
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

            return [201, $this->present($order, null)];
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
                'sku'          => $sku,
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

    /** @return array<string,mixed>|null */
    public function find(string $orderId): ?array
    {
        $order = Db::one('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            return null;
        }
        $delivery = Db::one('SELECT * FROM deliveries WHERE order_id = :id', ['id' => $orderId]);

        return $this->present($order, $delivery);
    }

    /**
     * @param  array<string,mixed>      $order
     * @param  array<string,mixed>|null $delivery
     * @return array<string,mixed>
     */
    public function present(array $order, ?array $delivery): array
    {
        return [
            'id'            => $order['id'],
            'sku'           => $order['sku'],
            'status'        => $order['status'],
            'amount_minor'  => (int) $order['amount_minor'],
            'amount'        => (int) $order['amount_minor'] / 100,
            'currency'      => $order['currency'],
            'paid_at'       => $order['paid_at'],
            'delivered_at'  => $order['delivered_at'],
            'created_at'    => $order['created_at'],
            'delivery'      => $delivery === null ? null : [
                'code'         => $delivery['code'],
                'supplier'     => $delivery['supplier'],
                'request_id'   => $delivery['request_id'],
                'delivered_at' => $delivery['delivered_at'],
            ],
        ];
    }

    /**
     * Guarded state transition. Returns false when the move is not allowed by
     * the state machine (e.g. anything out of a final status).
     * Caller must already hold the row lock.
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
            OrderStatus::DELIVERED => ', delivered_at = COALESCE(delivered_at, now())',
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
            'ledger'   => Db::all('SELECT entry_type, account, amount_minor, created_at FROM ledger_entries WHERE order_id = :id ORDER BY id', ['id' => $orderId]),
            'jobs'     => Db::all('SELECT id, type, status, attempts, run_after, last_error FROM jobs WHERE order_id = :id ORDER BY id', ['id' => $orderId]),
        ];
    }
}
