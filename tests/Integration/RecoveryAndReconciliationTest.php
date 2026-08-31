<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infra\Db;
use Tests\IntegrationTestCase;

/**
 * Stage 4 — out-of-stock recovery, reconciliation, and the money journal.
 */
final class RecoveryAndReconciliationTest extends IntegrationTestCase
{
    public function testOutOfStockIsRecoverableNotACrash(): void
    {
        $this->suppliers['A']->post('/_control', ['out_of_stock' => true]);
        $this->suppliers['B']->post('/_control', ['out_of_stock' => true]);

        $order   = $this->createOrder('GIFT-PSN-1000');
        $orderId = (string) $order['id'];

        $this->pay($order);

        self::assertSame('out_of_stock', $this->waitForStatus($orderId, ['out_of_stock'], 45));
        self::assertSame(200, $this->api->request('GET', '/orders/' . $orderId)['status']);
        self::assertSame(0, $this->deliveryCount($orderId));
        self::assertSame(
            2,
            (int) Db::value(
                "SELECT count(*) FROM ledger_entries WHERE order_id = :o AND entry_type = 'payment_captured'",
                ['o' => $orderId]
            ),
            'the captured money stays booked'
        );
        self::assertSame(
            0,
            (int) Db::value(
                "SELECT count(*) FROM ledger_entries WHERE order_id = :o AND entry_type = 'delivery_recognized'",
                ['o' => $orderId]
            ),
            'revenue must not be recognised for an undelivered order'
        );

        // ...and the report knows about it
        $report = $this->api->get('/admin/reconciliation?grace_seconds=0');
        self::assertContains(
            $orderId,
            array_column($report['sections']['paid_not_delivered'], 'id'),
            'a paid, undelivered order must show up in reconciliation'
        );

        // restock -> the background job finishes the order on its own
        $this->suppliers['A']->post('/_control', ['out_of_stock' => false]);

        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered'], 90));
        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame(0, $this->ledgerSum($orderId));
    }

    public function testManualRetryEndpointIsIdempotent(): void
    {
        $order   = $this->createOrder('STEAM-TOPUP-2500');
        $orderId = (string) $order['id'];

        $this->pay($order);
        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered']));

        $code = $this->delivery($orderId)['code'];

        for ($i = 0; $i < 5; $i++) {
            $result = $this->api->post('/orders/' . $orderId . '/deliver', ['mode' => 'sync']);
            self::assertSame('already_delivered', $result['result'] ?? null);
        }

        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame($code, $this->delivery($orderId)['code'], 'the customer keeps the same code');
    }

    public function testGlobalInvariantsHold(): void
    {
        $report = $this->api->get('/admin/reconciliation?grace_seconds=180');

        self::assertSame(0, (int) $report['totals']['ledger_sum_minor'], 'the journal must always sum to zero');
        self::assertSame(0, $report['counts']['duplicate_deliveries'], 'no order may have two deliveries');
        self::assertSame(0, $report['counts']['reused_codes'], 'no key may reach two orders');
        self::assertSame(0, $report['counts']['ledger_imbalanced_txns']);
        self::assertSame(0, $report['counts']['delivered_not_paid']);
        self::assertSame(
            (int) $report['totals']['deferred_revenue_minor'],
            (int) $report['totals']['undelivered_value_minor'],
            'money held for undelivered orders must equal their value'
        );
    }

    public function testAuditTrailIsComplete(): void
    {
        $order   = $this->createOrder('SUB-YT-3M');
        $orderId = (string) $order['id'];

        $this->pay($order);
        $this->waitForStatus($orderId, ['delivered']);

        $audit = $this->api->get('/orders/' . $orderId . '/audit');

        self::assertNotEmpty($audit['history']);
        self::assertNotEmpty($audit['webhooks']);
        self::assertNotEmpty($audit['supplier_requests']);
        self::assertNotEmpty($audit['attempts']);
        self::assertCount(4, $audit['ledger'], 'capture + recognition, two legs each');
    }
}
