<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infra\Db;
use Tests\IntegrationTestCase;

/**
 * Stage 2, task 1 — a basket where part of it cannot be fulfilled.
 */
final class BasketPartialFulfillmentTest extends IntegrationTestCase
{
    public function testAllItemsDeliveredEndsInDelivered(): void
    {
        $order   = $this->createBasket(['KEY-CS2-PRIME', 'KEY-GTA5']);
        $orderId = (string) $order['id'];

        self::assertCount(2, $order['items']);
        self::assertSame('created', $order['status']);

        $this->pay($order);
        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered', 'partially_delivered'], 45));

        $view = $this->api->get('/orders/' . $orderId);
        self::assertSame('delivered', $view['status']);
        self::assertSame(2, $view['items_summary']['delivered']);
        self::assertSame(0, $view['items_summary']['refunded']);
        foreach ($view['items'] as $item) {
            self::assertNotNull($item['delivery']['code'] ?? null, 'every item must carry exactly one code');
        }
        self::assertSame(2, (int) Db::value('SELECT count(*) FROM deliveries WHERE order_id = :o', ['o' => $orderId]));
        self::assertSame(0, $this->ledgerSum($orderId), 'paid = delivered: the order balances to zero');
    }

    /**
     * The core requirement of stage 2 task 1: one item genuinely cannot be
     * fulfilled (both suppliers are empty for it), the rest of the basket
     * stays with the customer, the unfulfillable item is refunded, and the
     * order still reaches a final state — money always reconciles.
     */
    public function testOneUnfulfillableItemIsRefundedWhileSiblingsStayDelivered(): void
    {
        $order   = $this->createBasket(['KEY-CS2-PRIME', 'KEY-GTA5', 'KEY-EFT']);
        $orderId = (string) $order['id'];

        // Deterministically leave exactly 2 free keys system-wide so two
        // items can be delivered and the third genuinely finds every
        // supplier empty (test tooling reaching into the stub's own
        // storage — the core under test never does this).
        Db::run(
            "UPDATE stub.keys SET status = 'issued', request_id = 'basket_test_drain', issued_at = now()
             WHERE status = 'free' AND id NOT IN (
                 SELECT id FROM stub.keys WHERE status = 'free' ORDER BY id LIMIT 2
             )"
        );

        try {
            $this->pay($order);
            self::assertSame('partially_delivered', $this->waitForStatus($orderId, ['partially_delivered'], 60));

            $view = $this->api->get('/orders/' . $orderId);
            self::assertSame(2, $view['items_summary']['delivered']);
            self::assertSame(1, $view['items_summary']['refunded']);

            self::assertSame(2, (int) Db::value('SELECT count(*) FROM deliveries WHERE order_id = :o', ['o' => $orderId]));
            self::assertSame(
                1,
                (int) Db::value(
                    "SELECT count(*) FROM order_items WHERE order_id = :o AND status = 'refunded'",
                    ['o' => $orderId]
                )
            );

            // The literal money invariant from the task: paid = delivered + refunded.
            self::assertSame(0, $this->ledgerSum($orderId));
            $delivered = (int) Db::value(
                "SELECT COALESCE(sum(amount_minor), 0) FROM order_items WHERE order_id = :o AND status = 'delivered'",
                ['o' => $orderId]
            );
            $refunded = (int) Db::value(
                "SELECT COALESCE(sum(amount_minor), 0) FROM order_items WHERE order_id = :o AND status = 'refunded'",
                ['o' => $orderId]
            );
            self::assertSame((int) $order['amount_minor'], $delivered + $refunded);

            // Retrying (crash-and-restart simulation) must not double-issue
            // or double-refund.
            $this->api->post('/orders/' . $orderId . '/deliver', ['mode' => 'sync']);
            self::assertSame(2, (int) Db::value('SELECT count(*) FROM deliveries WHERE order_id = :o', ['o' => $orderId]));
            self::assertSame(
                2,
                (int) Db::value(
                    "SELECT count(*) FROM ledger_entries WHERE order_id = :o AND entry_type = 'item_refunded'",
                    ['o' => $orderId]
                ),
                'still exactly one item_refunded event (two legs)'
            );

            // The system-wide cross-check must hold too, not just this order.
            $report = $this->api->get('/admin/reconciliation?grace_seconds=120');
            self::assertSame(
                (int) $report['totals']['deferred_revenue_minor'],
                (int) $report['totals']['undelivered_value_minor']
            );
        } finally {
            Db::run("UPDATE stub.keys SET status = 'free', request_id = NULL, issued_at = NULL WHERE request_id = 'basket_test_drain'");
        }
    }

    public function testLegacySingleSkuShapeStillWorks(): void
    {
        $order = $this->createOrder('STEAM-TOPUP-1000');
        self::assertSame('STEAM-TOPUP-1000', $order['sku']);
        self::assertCount(1, $order['items']);
        self::assertSame('STEAM-TOPUP-1000', $order['items'][0]['sku']);
    }
}
