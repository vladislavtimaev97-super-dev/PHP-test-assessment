<?php

declare(strict_types=1);

namespace App\Service;

use App\Infra\Db;
use App\Support\Env;

/**
 * Stage 4 — the report you look at when something smells. Stage 2 extends it
 * to basket items and to the untrusted-supplier fault modes: every section
 * still answers one question a finance/ops person would actually ask, and
 * every section is expected to be empty in a healthy system.
 */
final class ReconciliationService
{
    /** @return array<string,mixed> */
    public function report(?int $graceSeconds = null): array
    {
        $grace = $graceSeconds ?? Env::int('STUCK_ORDER_SECONDS', 20);

        // Paid, but at least one basket item is still unresolved (not
        // delivered, not refunded), for longer than the grace period.
        $paidNotDelivered = Db::all(
            "SELECT DISTINCT o.id, o.status, o.amount_minor, o.paid_at, o.updated_at
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id AND oi.status NOT IN ('delivered','refunded')
             WHERE o.status IN ('paid','delivering','out_of_stock','delivery_failed')
               AND o.paid_at < now() - make_interval(secs => CAST(:g AS double precision))
             ORDER BY o.paid_at
             LIMIT 200",
            ['g' => $grace]
        );

        // Delivered without a captured payment: the ledger is the source of
        // truth here, not the order status.
        $deliveredNotPaid = Db::all(
            "SELECT d.order_id, d.item_id, d.supplier, d.delivered_at, o.status, o.amount_minor
             FROM deliveries d
             JOIN orders o ON o.id = d.order_id
             WHERE o.paid_at IS NULL
                OR NOT EXISTS (
                    SELECT 1 FROM ledger_entries l
                    WHERE l.order_id = d.order_id AND l.entry_type = 'payment_captured'
                )
             ORDER BY d.delivered_at
             LIMIT 200"
        );

        $stuckDelivering = Db::all(
            "SELECT id, status, updated_at FROM orders
             WHERE status = 'delivering'
               AND updated_at < now() - make_interval(secs => CAST(:g AS double precision))
             ORDER BY updated_at LIMIT 200",
            ['g' => $grace]
        );

        // The dangerous ones: we do not know whether a code was issued.
        $unknownSupplierRequests = Db::all(
            "SELECT request_id, order_id, item_id, supplier, attempts, reason, updated_at
             FROM supplier_requests WHERE state = 'unknown'
             ORDER BY updated_at LIMIT 200"
        );

        $deferredWebhooks = Db::all(
            'SELECT event_id, order_id, status, received_at, outcome
             FROM webhook_events
             WHERE processed_at IS NULL
             ORDER BY received_at LIMIT 200'
        );

        $mismatchedWebhooks = Db::all(
            "SELECT event_id, order_id, amount_minor, outcome, detail
             FROM webhook_events
             WHERE outcome IN ('ignored_amount_mismatch')
             ORDER BY received_at DESC LIMIT 200"
        );

        $orphanCodes = Db::all(
            'SELECT id, order_id, item_id, supplier, request_id, reason, created_at
             FROM orphan_codes ORDER BY created_at DESC LIMIT 200'
        );

        // Items stuck out_of_stock/delivery_failed, heading for an automatic
        // refund once ITEM_GIVE_UP_AFTER_SECONDS elapses — visible before it
        // happens, not just after (stage 2, task 1).
        $stuckItems = Db::all(
            "SELECT id, order_id, sku, status, first_undeliverable_at
             FROM order_items
             WHERE status IN ('out_of_stock','delivery_failed')
             ORDER BY first_undeliverable_at LIMIT 200"
        );

        // These three must always be zero — the first two are enforced by
        // unique indexes (UNIQUE(item_id), UNIQUE(code) on deliveries), so a
        // non-zero value means the schema was tampered with, not just a bug.
        $duplicateDeliveries = Db::all(
            'SELECT item_id, count(*) AS n FROM deliveries GROUP BY item_id HAVING count(*) > 1'
        );
        $reusedCodes = Db::all(
            'SELECT code, count(*) AS n FROM deliveries GROUP BY code HAVING count(*) > 1'
        );
        // Not DB-enforced (it is a cross-column business rule, not a single
        // unique index): a resolved order's declared total must equal the
        // sum of what its items actually resolved to (delivered + refunded).
        // This is the literal "money always reconciles" check from the task.
        $moneyMismatchedOrders = Db::all(
            "SELECT o.id, o.status, o.amount_minor,
                    COALESCE((SELECT sum(amount_minor) FROM order_items
                              WHERE order_id = o.id AND status IN ('delivered','refunded')), 0) AS resolved_value
             FROM orders o
             WHERE o.status IN ('delivered','partially_delivered','refunded')
               AND o.amount_minor <> COALESCE((SELECT sum(amount_minor) FROM order_items
                                                WHERE order_id = o.id AND status IN ('delivered','refunded')), 0)
             LIMIT 200"
        );

        $ledgerBalance   = Db::all('SELECT account, balance_minor, entries FROM ledger_balance ORDER BY account');
        $ledgerImbalance = Db::all('SELECT txn_id, delta_minor FROM ledger_imbalance LIMIT 50');
        $ledgerTotal     = (int) (Db::value('SELECT COALESCE(sum(amount_minor), 0) FROM ledger_entries') ?? 0);

        // Money that is captured but not yet earned or refunded must equal
        // the value of unresolved basket items. This is the real cross-check
        // (the item-level generalisation of stage 1's order-level one).
        $deferredRevenue = -1 * (int) (Db::value(
            "SELECT COALESCE(sum(amount_minor), 0) FROM ledger_entries WHERE account = 'deferred_revenue'"
        ) ?? 0);
        $undeliveredValue = (int) (Db::value(
            "SELECT COALESCE(sum(oi.amount_minor), 0)
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.status NOT IN ('delivered','refunded') AND o.paid_at IS NOT NULL"
        ) ?? 0);

        $sections = [
            'paid_not_delivered'        => $paidNotDelivered,
            'delivered_not_paid'        => $deliveredNotPaid,
            'stuck_delivering'          => $stuckDelivering,
            'unknown_supplier_requests' => $unknownSupplierRequests,
            'deferred_webhooks'         => $deferredWebhooks,
            'amount_mismatch_webhooks'  => $mismatchedWebhooks,
            'orphan_codes'              => $orphanCodes,
            'stuck_items'               => $stuckItems,
            'duplicate_deliveries'      => $duplicateDeliveries,
            'reused_codes'              => $reusedCodes,
            'money_mismatched_orders'   => $moneyMismatchedOrders,
            'ledger_imbalanced_txns'    => $ledgerImbalance,
        ];

        $healthy = $ledgerTotal === 0
            && $deferredRevenue === $undeliveredValue
            && $duplicateDeliveries === []
            && $reusedCodes === []
            && $moneyMismatchedOrders === []
            && $ledgerImbalance === [];

        return [
            'generated_at'   => gmdate('c'),
            'grace_seconds'  => $grace,
            'healthy'        => $healthy,
            'totals'         => [
                'orders'              => (int) Db::value('SELECT count(*) FROM orders'),
                'order_items'         => (int) Db::value('SELECT count(*) FROM order_items'),
                'deliveries'          => (int) Db::value('SELECT count(*) FROM deliveries'),
                'webhook_events'      => (int) Db::value('SELECT count(*) FROM webhook_events'),
                'ledger_sum_minor'    => $ledgerTotal,            // must be 0
                'deferred_revenue_minor'  => $deferredRevenue,
                'undelivered_value_minor' => $undeliveredValue,   // must equal the line above
            ],
            'ledger_balance' => $ledgerBalance,
            'counts'         => array_map('count', $sections),
            'sections'       => $sections,
        ];
    }
}
