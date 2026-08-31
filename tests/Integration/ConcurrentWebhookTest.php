<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infra\Db;
use Tests\IntegrationTestCase;

/**
 * Stage 2 — exactly-once under races. This is the reproducible concurrency
 * test the task asks for.
 */
final class ConcurrentWebhookTest extends IntegrationTestCase
{
    /** 50 DIFFERENT events for one order, fired simultaneously. */
    public function testFiftyDistinctEventsProduceExactlyOneDelivery(): void
    {
        $order   = $this->createOrder('KEY-GTA5');
        $orderId = (string) $order['id'];
        $before  = $this->issuedKeys('A') + $this->issuedKeys('B');

        $stats = $this->pay($order, 50, 'distinct');

        self::assertSame(0, $stats['errors'], 'no webhook may be dropped on the floor');
        self::assertSame(50, $stats['http'][200] ?? 0, 'every webhook must be answered 200');

        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered']));
        self::assertSame(1, $this->deliveryCount($orderId), 'exactly one delivery');
        self::assertSame(
            1,
            (int) Db::value(
                "SELECT count(*) FROM webhook_events WHERE order_id = :o AND outcome = 'applied'",
                ['o' => $orderId]
            ),
            'only one of the 50 events may take effect'
        );
        self::assertSame(
            50,
            (int) Db::value('SELECT count(*) FROM webhook_events WHERE order_id = :o', ['o' => $orderId]),
            'all 50 events must be persisted, none lost'
        );
        self::assertSame(1, $this->issuedKeys('A') + $this->issuedKeys('B') - $before, 'one key, not 50');
        self::assertSame(0, $this->ledgerSum($orderId));
    }

    /** 50 copies of ONE event (at-least-once redelivery). */
    public function testFiftyIdenticalEventsProduceExactlyOneDelivery(): void
    {
        $order   = $this->createOrder('KEY-CS2-PRIME');
        $orderId = (string) $order['id'];
        $before  = $this->issuedKeys('A') + $this->issuedKeys('B');

        $stats = $this->pay($order, 50, 'same', 'paid', 'evt_storm_' . bin2hex(random_bytes(4)));

        self::assertSame(50, $stats['http'][200] ?? 0);
        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered']));
        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame(
            1,
            (int) Db::value('SELECT count(*) FROM webhook_events WHERE order_id = :o', ['o' => $orderId]),
            'the duplicate event must be stored once'
        );
        self::assertSame(1, $this->issuedKeys('A') + $this->issuedKeys('B') - $before);
    }

    /** A replay after the order is already delivered must change nothing. */
    public function testReplayAfterDeliveryIsANoOp(): void
    {
        $order   = $this->createOrder('SUB-DISCORD-1M');
        $orderId = (string) $order['id'];
        $eventId = 'evt_replay_' . bin2hex(random_bytes(4));

        $this->pay($order, 1, 'same', 'paid', $eventId);
        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered']));

        $snapshot = $this->snapshot($orderId);
        $stats    = $this->pay($order, 20, 'same', 'paid', $eventId);
        usleep(500_000);

        self::assertSame(20, $stats['results']['duplicate'] ?? 0, 'all replays must be recognised');
        self::assertSame($snapshot, $this->snapshot($orderId), 'a finished order must be immutable');
    }

    /** @return array<string,mixed> */
    private function snapshot(string $orderId): array
    {
        /** @var array<string,mixed> $row */
        $row = Db::one(
            'SELECT
                (SELECT count(*) FROM deliveries WHERE order_id = :o)           AS deliveries,
                (SELECT count(*) FROM order_status_history WHERE order_id = :o) AS history,
                (SELECT count(*) FROM ledger_entries WHERE order_id = :o)       AS ledger,
                (SELECT count(*) FROM webhook_events WHERE order_id = :o)       AS events,
                (SELECT code FROM deliveries WHERE order_id = :o)               AS code,
                (SELECT status FROM orders WHERE id = :o)                       AS status,
                (SELECT updated_at FROM orders WHERE id = :o)                   AS updated_at',
            ['o' => $orderId]
        );

        return $row;
    }
}
