<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infra\Db;
use Tests\IntegrationTestCase;

/**
 * Stage 1 — the API, the status machine, and the out-of-order webhook cases.
 */
final class OrderLifecycleTest extends IntegrationTestCase
{
    public function testHappyPath(): void
    {
        $order   = $this->createOrder('STEAM-TOPUP-1000');
        $orderId = (string) $order['id'];

        self::assertSame('created', $order['status']);
        self::assertSame(100000, $order['amount_minor']);

        $this->pay($order);
        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered']));

        $view = $this->api->get('/orders/' . $orderId);
        self::assertNotNull($view['delivery']['code'] ?? null, 'the customer must receive the code');
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $view['delivery']['code']);
        self::assertSame(0, $this->ledgerSum($orderId));

        $history = array_column(
            Db::all('SELECT to_status FROM order_status_history WHERE order_id = :o ORDER BY id', ['o' => $orderId]),
            'to_status'
        );
        self::assertSame(['created', 'paid', 'delivering', 'delivered'], $history);
    }

    public function testUnknownSkuIsRejected(): void
    {
        $response = $this->api->request('POST', '/orders', ['sku' => 'NOPE-404']);
        self::assertSame(404, $response['status']);
        self::assertSame('sku_not_found', $response['json']['error']['code'] ?? null);
    }

    public function testFailedPaymentDoesNotDeliver(): void
    {
        $order   = $this->createOrder('SUB-SPOTIFY-1M');
        $orderId = (string) $order['id'];

        $this->pay($order, 1, 'same', 'failed');
        self::assertSame('payment_failed', $this->waitForStatus($orderId, ['payment_failed'], 15));
        self::assertSame(0, $this->deliveryCount($orderId));
        self::assertSame(
            0,
            (int) Db::value('SELECT count(*) FROM ledger_entries WHERE order_id = :o', ['o' => $orderId]),
            'a failed payment books no money'
        );
    }

    public function testWebhookForAnUnknownOrderIsParkedAndAppliedLater(): void
    {
        $orderId = 'ord_early_' . bin2hex(random_bytes(4));

        $stats = $this->hooks->sendConcurrently(
            \App\Support\WebhookSender::payloads($orderId, 1490.0, 1, 'same')
        );

        self::assertSame(1, $stats['http'][200] ?? 0, '5xx would make the PSP retry forever');
        self::assertSame(1, $stats['results']['deferred_no_order'] ?? 0);
        self::assertSame(404, $this->api->request('GET', '/orders/' . $orderId)['status']);

        $this->createOrder('SUB-YT-3M', $orderId);

        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered']));
        self::assertSame(1, $this->deliveryCount($orderId));
    }

    public function testStaleFailedEventCannotUndoAPaidOrder(): void
    {
        $order   = $this->createOrder('SUB-SPOTIFY-1M');
        $orderId = (string) $order['id'];

        $this->pay($order);
        $this->waitForStatus($orderId, ['delivered']);

        $eventId = 'evt_stale_' . bin2hex(random_bytes(4));
        $this->pay($order, 1, 'same', 'failed', $eventId, gmdate('Y-m-d\TH:i:s\Z', time() - 3600));
        usleep(400_000);

        self::assertSame(
            'ignored_stale',
            Db::value('SELECT outcome FROM webhook_events WHERE event_id = :e', ['e' => $eventId])
        );
        self::assertSame('delivered', $this->orderStatus($orderId));
        self::assertSame(1, $this->deliveryCount($orderId));
    }

    public function testAmountMismatchIsRefusedAndReported(): void
    {
        $order   = $this->createOrder('KEY-GTA5');
        $orderId = (string) $order['id'];
        $eventId = 'evt_mismatch_' . bin2hex(random_bytes(4));

        $this->hooks->sendConcurrently([[
            'event_id'   => $eventId,
            'order_id'   => $orderId,
            'status'     => 'paid',
            'amount'     => 1,                    // 1 ₽ for a 1990 ₽ order
            'currency'   => 'RUB',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]]);
        usleep(400_000);

        self::assertSame(
            'ignored_amount_mismatch',
            Db::value('SELECT outcome FROM webhook_events WHERE event_id = :e', ['e' => $eventId])
        );
        self::assertSame('created', $this->orderStatus($orderId), 'an underpaid order stays unpaid');
        self::assertSame(0, $this->deliveryCount($orderId));
    }

    public function testIdempotencyKeyPreventsDuplicateOrders(): void
    {
        $key = 'idem_' . bin2hex(random_bytes(6));

        $first  = $this->api->request('POST', '/orders', ['sku' => 'STEAM-TOPUP-500'], ['Idempotency-Key' => $key]);
        $second = $this->api->request('POST', '/orders', ['sku' => 'STEAM-TOPUP-500'], ['Idempotency-Key' => $key]);

        self::assertSame(201, $first['status']);
        self::assertSame($first['json']['id'], $second['json']['id'], 'a retried POST must not create a second order');

        $conflict = $this->api->request('POST', '/orders', ['sku' => 'KEY-GTA5'], ['Idempotency-Key' => $key]);
        self::assertSame(409, $conflict['status'], 'the same key with a different body is a client bug');
    }

    public function testMalformedWebhookIsRejectedWith400(): void
    {
        $response = $this->api->request('POST', '/webhooks/payment', ['order_id' => 'ord_1']);
        self::assertSame(400, $response['status']);
    }
}
