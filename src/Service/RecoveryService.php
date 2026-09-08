<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ItemStatus;
use App\Infra\Db;
use App\Infra\Log;
use App\Support\Env;
use PDO;

/**
 * Stage 4 — the sweeper. Stage 2 adds one more job: an item that has been
 * stuck (out_of_stock / delivery_failed) for too long is refunded instead of
 * retried forever, so a basket always reaches a final state — even across a
 * crash and restart in the middle of delivery (stage 2, task 1, point 5).
 *
 * Everything here is idempotent and safe to run concurrently with live
 * traffic: it only ever *re-enqueues* work or *refunds one item exactly
 * once*; the delivery path itself decides what is allowed to happen.
 */
final class RecoveryService
{
    /** @return array<string,int> */
    public function sweep(): array
    {
        $stuckAfter  = Env::int('STUCK_ORDER_SECONDS', 20);
        $giveUpAfter = Env::int('ITEM_GIVE_UP_AFTER_SECONDS', 30);

        return [
            'reclaimed_jobs'      => JobQueue::reclaimStale(120),
            'deferred_webhooks'   => $this->applyDeferredWebhooks(),
            'requeued_orders'     => $this->requeueStuckOrders($stuckAfter),
            'unknown_requests'    => $this->nudgeUnknownRequests($stuckAfter),
            'refunded_items'      => $this->giveUpOnStaleItems($giveUpAfter),
        ];
    }

    /**
     * Webhooks that arrived before their order existed (out-of-order delivery).
     * Once the order shows up, apply them.
     */
    public function applyDeferredWebhooks(int $limit = 100): int
    {
        $rows = Db::all(
            'SELECT w.event_id
             FROM webhook_events w
             JOIN orders o ON o.id = w.order_id
             WHERE w.processed_at IS NULL
             ORDER BY w.event_created_at, w.received_at
             LIMIT ' . $limit
        );

        $service = new PaymentWebhookService();
        $applied = 0;
        foreach ($rows as $row) {
            $outcome = $service->apply((string) $row['event_id']);
            Log::info('deferred_webhook_applied', [
                'event_id' => $row['event_id'], 'outcome' => $outcome,
            ]);
            $applied++;
        }

        return $applied;
    }

    /**
     * Orders that are paid but still have at least one item pending/stuck, and
     * whose delivery job is not live any more (finished, buried, or never
     * created). This is what safely finishes `out_of_stock` and
     * `delivery_failed` orders after the stock or the supplier comes back.
     * (acceptance #6, and stage 2 basket equivalent)
     */
    public function requeueStuckOrders(int $olderThanSeconds, int $limit = 100): int
    {
        $orders = Db::all(
            "SELECT DISTINCT o.id
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id AND oi.status NOT IN ('delivered', 'refunded')
             WHERE o.status IN ('paid','delivering','out_of_stock','delivery_failed')
               AND o.updated_at < now() - make_interval(secs => CAST(:s AS double precision))
               AND NOT EXISTS (
                   SELECT 1 FROM jobs j
                   WHERE j.order_id = o.id AND j.type = :type AND j.status IN ('queued','running')
               )
             ORDER BY o.id
             LIMIT " . $limit,
            ['s' => $olderThanSeconds, 'type' => JobQueue::DELIVER_ORDER]
        );

        $count = 0;
        foreach ($orders as $order) {
            $enqueued = Db::transaction(fn (PDO $pdo) => JobQueue::enqueue(
                $pdo,
                JobQueue::DELIVER_ORDER,
                (string) $order['id'],
                0.0,
                null,
                JobQueue::PRIORITY_BACKGROUND
            ));
            if ($enqueued) {
                Log::warn('stuck_order_requeued', ['order_id' => $order['id']]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Supplier requests whose fate is still unknown after a timeout. The
     * delivery job re-runs `resolveUnknown` for them; until it succeeds the
     * item is deliberately *not* failed over to another supplier.
     */
    public function nudgeUnknownRequests(int $olderThanSeconds, int $limit = 50): int
    {
        $rows = Db::all(
            "SELECT DISTINCT order_id FROM supplier_requests
             WHERE state = 'unknown'
               AND updated_at < now() - make_interval(secs => CAST(:s AS double precision))
             LIMIT " . $limit,
            ['s' => $olderThanSeconds]
        );

        $count = 0;
        foreach ($rows as $row) {
            $enqueued = Db::transaction(fn (PDO $pdo) => JobQueue::enqueue(
                $pdo,
                JobQueue::DELIVER_ORDER,
                (string) $row['order_id'],
                0.0,
                null,
                JobQueue::PRIORITY_BACKGROUND
            ));
            if ($enqueued) {
                Log::warn('unknown_request_nudged', ['order_id' => $row['order_id']]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Stage 2, task 1 — an item cannot retry forever: once it has been stuck
     * out_of_stock/delivery_failed for longer than the give-up window, refund
     * it and let the basket move on. Runs under a row lock and re-checks the
     * status before acting, so a concurrent delivery attempt that just
     * succeeded (or a previous sweep tick) cannot be double-refunded — the
     * same idempotent-transition discipline as every other mutation here.
     */
    public function giveUpOnStaleItems(int $giveUpAfterSeconds, int $limit = 100): int
    {
        $rows = Db::all(
            "SELECT id, order_id FROM order_items
             WHERE status IN ('out_of_stock','delivery_failed')
               AND first_undeliverable_at < now() - make_interval(secs => CAST(:s AS double precision))
             ORDER BY first_undeliverable_at
             LIMIT " . $limit,
            ['s' => $giveUpAfterSeconds]
        );

        $count = 0;
        foreach ($rows as $row) {
            $itemId  = (string) $row['id'];
            $orderId = (string) $row['order_id'];

            $refunded = Db::transaction(function (PDO $pdo) use ($itemId, $orderId) {
                $st = $pdo->prepare('SELECT * FROM order_items WHERE id = :id FOR UPDATE');
                $st->execute(['id' => $itemId]);
                $item = $st->fetch();

                if ($item === false || !in_array($item['status'], ItemStatus::UNDELIVERABLE, true)) {
                    return false;                  // resolved by someone else already
                }

                $moved = OrderService::transitionItem(
                    $pdo,
                    $itemId,
                    $orderId,
                    (string) $item['status'],
                    ItemStatus::REFUNDED,
                    'gave up after retry window, refunding',
                    'sweeper'
                );
                if (!$moved) {
                    return false;
                }

                Ledger::itemRefunded($pdo, $orderId, $itemId, (int) $item['amount_minor'], 'give_up:' . $itemId);
                OrderService::recomputeStatus($pdo, $orderId);

                return true;
            });

            if ($refunded) {
                Log::warn('item_refunded_after_timeout', ['order_id' => $orderId, 'item_id' => $itemId]);
                $count++;
            }
        }

        return $count;
    }
}
