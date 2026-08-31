<?php

declare(strict_types=1);

namespace App\Service;

use App\Infra\Db;
use App\Infra\Log;
use App\Support\Env;
use PDO;

/**
 * Stage 4 — the sweeper.
 *
 * Runs on a timer inside the worker. Everything it does is idempotent and
 * safe to run concurrently with live traffic: it only ever *re-enqueues* work,
 * the delivery path itself decides what is allowed to happen.
 */
final class RecoveryService
{
    /** @return array<string,int> */
    public function sweep(): array
    {
        $stuckAfter = Env::int('STUCK_ORDER_SECONDS', 20);

        return [
            'reclaimed_jobs'      => JobQueue::reclaimStale(120),
            'deferred_webhooks'   => $this->applyDeferredWebhooks(),
            'requeued_orders'     => $this->requeueStuckOrders($stuckAfter),
            'unknown_requests'    => $this->nudgeUnknownRequests($stuckAfter),
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
     * Orders that are paid but still without a delivery, and whose delivery job
     * is not live any more (finished, buried, or never created). This is what
     * safely finishes `out_of_stock` and `delivery_failed` orders after the
     * stock or the supplier comes back. (acceptance #6)
     */
    public function requeueStuckOrders(int $olderThanSeconds, int $limit = 100): int
    {
        $orders = Db::all(
            "SELECT o.id
             FROM orders o
             LEFT JOIN deliveries d ON d.order_id = o.id
             WHERE o.status IN ('paid','delivering','out_of_stock','delivery_failed')
               AND d.id IS NULL
               AND o.updated_at < now() - make_interval(secs => CAST(:s AS double precision))
               AND NOT EXISTS (
                   SELECT 1 FROM jobs j
                   WHERE j.order_id = o.id AND j.type = :type AND j.status IN ('queued','running')
               )
             ORDER BY o.updated_at
             LIMIT " . $limit,
            ['s' => $olderThanSeconds, 'type' => JobQueue::DELIVER_ORDER]
        );

        $count = 0;
        foreach ($orders as $order) {
            $enqueued = Db::transaction(fn (PDO $pdo) => JobQueue::enqueue(
                $pdo,
                JobQueue::DELIVER_ORDER,
                (string) $order['id']
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
     * order is deliberately *not* failed over to another supplier.
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
                (string) $row['order_id']
            ));
            if ($enqueued) {
                Log::warn('unknown_request_nudged', ['order_id' => $row['order_id']]);
                $count++;
            }
        }

        return $count;
    }
}
