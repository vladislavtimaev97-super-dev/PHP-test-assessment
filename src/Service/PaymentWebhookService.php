<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\OrderStatus;
use App\Infra\Db;
use App\Infra\Log;
use PDO;

/**
 * Ingestion and application of payment webhooks.
 *
 * Three independent guarantees stack up here:
 *
 *  1. DEDUPE — `webhook_events.event_id` is the primary key and the insert is
 *     `ON CONFLICT DO NOTHING`. A replayed event never even reaches the
 *     business logic. (acceptance #2)
 *
 *  2. MUTUAL EXCLUSION — application takes `SELECT ... FROM orders FOR UPDATE`,
 *     so 50 distinct events for one order are applied strictly one after
 *     another; only the first sees status = 'created'. (acceptance #1)
 *
 *  3. ORDERING — events are compared by the issuer clock (`created_at`), so a
 *     `failed` that was generated before an already-applied `paid` is ignored
 *     as stale instead of corrupting a paid order. (acceptance #3)
 *
 * An event whose order does not exist yet is stored, answered 200 and applied
 * later (`applyDeferredFor` on order creation + the sweeper). (acceptance #3)
 */
final class PaymentWebhookService
{
    public const APPLIED           = 'applied';
    public const DUPLICATE         = 'duplicate';
    public const IGNORED_TERMINAL  = 'ignored_terminal';
    public const IGNORED_STALE     = 'ignored_stale';
    public const IGNORED_MISMATCH  = 'ignored_amount_mismatch';
    public const DEFERRED_NO_ORDER = 'deferred_no_order';

    /**
     * @param  array<string,mixed> $payload
     * @return array{0:int,1:array<string,mixed>}
     */
    public function ingest(array $payload): array
    {
        $event = $this->validate($payload);

        // ---- 1. dedupe -------------------------------------------------
        $st = Db::run(
            'INSERT INTO webhook_events
                 (event_id, order_id, status, amount_minor, currency, event_created_at, payload)
             VALUES (:event_id, :order_id, :status, :amount, :currency, :created_at, :payload)
             ON CONFLICT (event_id) DO NOTHING
             RETURNING event_id',
            [
                'event_id'   => $event['event_id'],
                'order_id'   => $event['order_id'],
                'status'     => $event['status'],
                'amount'     => $event['amount_minor'],
                'currency'   => $event['currency'],
                'created_at' => $event['created_at'],
                'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );

        if ($st->fetchColumn() === false) {
            Log::info('webhook_duplicate', [
                'event_id' => $event['event_id'],
                'order_id' => $event['order_id'],
            ]);

            return [200, ['received' => true, 'result' => self::DUPLICATE, 'event_id' => $event['event_id']]];
        }

        Log::info('webhook_received', [
            'event_id'     => $event['event_id'],
            'order_id'     => $event['order_id'],
            'status'       => $event['status'],
            'amount_minor' => $event['amount_minor'],
        ]);

        // ---- 2. apply --------------------------------------------------
        $outcome = $this->apply((string) $event['event_id']);

        return [200, [
            'received' => true,
            'result'   => $outcome,
            'event_id' => $event['event_id'],
            'order_id' => $event['order_id'],
        ]];
    }

    /**
     * Apply a single stored event. Safe to call repeatedly and concurrently.
     */
    public function apply(string $eventId): string
    {
        return Db::transaction(function (PDO $pdo) use ($eventId): string {
            // Lock order is always: webhook_events -> orders. Never the reverse,
            // so two concurrent applications cannot deadlock.
            $st = $pdo->prepare('SELECT * FROM webhook_events WHERE event_id = :id FOR UPDATE');
            $st->execute(['id' => $eventId]);
            $ev = $st->fetch();

            if ($ev === false) {
                return 'unknown_event';
            }
            if ($ev['processed_at'] !== null) {
                return (string) $ev['outcome'];          // already applied, idempotent
            }

            $st = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $st->execute(['id' => $ev['order_id']]);
            $order = $st->fetch();

            // ---- out of order: the event beat its own order ------------
            if ($order === false) {
                Log::warn('webhook_deferred_no_order', [
                    'event_id' => $eventId, 'order_id' => $ev['order_id'],
                ]);
                // processed_at stays NULL on purpose: the sweeper retries it.
                $this->mark($pdo, $eventId, self::DEFERRED_NO_ORDER, 'order not found yet', false);

                return self::DEFERRED_NO_ORDER;
            }

            // ---- money must match the order ----------------------------
            if ((int) $ev['amount_minor'] !== (int) $order['amount_minor']
                || $ev['currency'] !== $order['currency']) {
                Log::error('webhook_amount_mismatch', [
                    'event_id'      => $eventId,
                    'order_id'      => $order['id'],
                    'event_amount'  => (int) $ev['amount_minor'],
                    'order_amount'  => (int) $order['amount_minor'],
                ]);
                $this->mark($pdo, $eventId, self::IGNORED_MISMATCH, 'amount/currency differs from order');

                return self::IGNORED_MISMATCH;
            }

            // ---- ordering by issuer clock ------------------------------
            $newerApplied = $pdo->prepare(
                "SELECT 1 FROM webhook_events
                 WHERE order_id = :oid AND outcome = 'applied' AND event_id <> :eid
                   AND event_created_at > :created
                 LIMIT 1"
            );
            $newerApplied->execute([
                'oid' => $order['id'], 'eid' => $eventId, 'created' => $ev['event_created_at'],
            ]);
            if ($newerApplied->fetchColumn() !== false) {
                $this->mark($pdo, $eventId, self::IGNORED_STALE, 'a newer event was already applied');

                return self::IGNORED_STALE;
            }

            $status = (string) $order['status'];

            if ($ev['status'] === 'paid') {
                if ($status === OrderStatus::CREATED || $status === OrderStatus::PAYMENT_FAILED) {
                    OrderService::transition(
                        $pdo,
                        (string) $order['id'],
                        $status,
                        OrderStatus::PAID,
                        $status === OrderStatus::PAYMENT_FAILED
                            ? 'paid webhook arrived after a failed one (money did arrive)'
                            : 'payment confirmed',
                        'payment_webhook'
                    );

                    Ledger::paymentCaptured(
                        $pdo,
                        (string) $order['id'],
                        (int) $order['amount_minor'],
                        $eventId
                    );

                    JobQueue::enqueue($pdo, JobQueue::DELIVER_ORDER, (string) $order['id']);
                    $this->mark($pdo, $eventId, self::APPLIED, 'order marked paid, delivery enqueued');

                    Log::info('payment_captured', [
                        'event_id'     => $eventId,
                        'order_id'     => $order['id'],
                        'amount_minor' => (int) $order['amount_minor'],
                    ]);

                    return self::APPLIED;
                }

                // Already paid / delivering / delivered: nothing to do, but make
                // sure a delivery job exists (self-healing if one was lost).
                if ($status !== OrderStatus::DELIVERED) {
                    JobQueue::enqueue($pdo, JobQueue::DELIVER_ORDER, (string) $order['id']);
                }
                $this->mark($pdo, $eventId, self::IGNORED_TERMINAL, "order already in status {$status}");

                return self::IGNORED_TERMINAL;
            }

            // ---- status = failed ---------------------------------------
            if ($status === OrderStatus::CREATED) {
                OrderService::transition(
                    $pdo,
                    (string) $order['id'],
                    $status,
                    OrderStatus::PAYMENT_FAILED,
                    'payment failed',
                    'payment_webhook'
                );
                $this->mark($pdo, $eventId, self::APPLIED, 'order marked payment_failed');
                Log::info('payment_failed', ['event_id' => $eventId, 'order_id' => $order['id']]);

                return self::APPLIED;
            }

            // A `failed` for an order whose money we already captured is never
            // applied automatically — it is a reconciliation case, not a
            // silent rollback of a delivered good.
            Log::warn('payment_failed_after_capture', [
                'event_id' => $eventId, 'order_id' => $order['id'], 'order_status' => $status,
            ]);
            $this->mark($pdo, $eventId, self::IGNORED_TERMINAL, "failed event for {$status} order; needs review");

            return self::IGNORED_TERMINAL;
        });
    }

    /** Apply everything that was parked because the order did not exist yet. */
    public function applyDeferredFor(string $orderId): int
    {
        $pending = Db::all(
            'SELECT event_id FROM webhook_events
             WHERE order_id = :id AND processed_at IS NULL
             ORDER BY event_created_at, received_at',
            ['id' => $orderId]
        );

        $applied = 0;
        foreach ($pending as $row) {
            $outcome = $this->apply((string) $row['event_id']);
            if ($outcome !== self::DEFERRED_NO_ORDER) {
                $applied++;
            }
        }

        return $applied;
    }

    private function mark(PDO $pdo, string $eventId, string $outcome, string $detail, bool $processed = true): void
    {
        $processedAt = $processed ? 'now()' : 'NULL';
        $pdo->prepare(
            "UPDATE webhook_events
             SET outcome = :o, detail = :d, processed_at = {$processedAt}
             WHERE event_id = :id"
        )->execute(['o' => $outcome, 'd' => $detail, 'id' => $eventId]);
    }

    /**
     * @param  array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function validate(array $p): array
    {
        foreach (['event_id', 'order_id', 'status'] as $required) {
            if (!is_string($p[$required] ?? null) || $p[$required] === '') {
                throw new \InvalidArgumentException("Field \"{$required}\" is required");
            }
        }
        if (!in_array($p['status'], ['paid', 'failed'], true)) {
            throw new \InvalidArgumentException('Field "status" must be "paid" or "failed"');
        }
        if (!is_numeric($p['amount'] ?? null)) {
            throw new \InvalidArgumentException('Field "amount" must be numeric');
        }

        $createdAt = is_string($p['created_at'] ?? null) ? $p['created_at'] : null;
        if ($createdAt !== null && strtotime($createdAt) === false) {
            throw new \InvalidArgumentException('Field "created_at" must be an ISO-8601 timestamp');
        }

        return [
            'event_id'     => $p['event_id'],
            'order_id'     => $p['order_id'],
            'status'       => $p['status'],
            // The contract sends major units ("amount": 500 for a 500 ₽ order);
            // internally everything is kopecks.
            'amount_minor' => (int) round(((float) $p['amount']) * 100),
            'currency'     => is_string($p['currency'] ?? null) ? $p['currency'] : 'RUB',
            'created_at'   => $createdAt ?? gmdate('c'),
        ];
    }
}
