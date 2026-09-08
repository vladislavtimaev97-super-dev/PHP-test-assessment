<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infra\Db;
use Tests\IntegrationTestCase;

/**
 * Stage 2, task 2 — a supplier whose answers cannot be trusted.
 */
final class UntrustedSupplierTest extends IntegrationTestCase
{
    public function testErrorResponseAfterARealIssueDoesNotDoubleIssue(): void
    {
        $this->suppliers['A']->post('/_control', ['lie_about_error_rate' => 1]);

        $order   = $this->createOrder('STEAM-TOPUP-500');
        $orderId = (string) $order['id'];
        $beforeA = $this->issuedKeys('A');

        $this->pay($order);
        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered'], 40));

        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame(1, $this->issuedKeys('A') - $beforeA, 'exactly one key left the pool, not two');
        self::assertSame(
            1,
            (int) Db::value(
                "SELECT count(*) FROM stub.requests WHERE supplier = 'A' AND order_id = :o AND outcome = 'ok'",
                ['o' => $orderId]
            ),
            "the supplier's own idempotency store agrees a code was issued"
        );
    }

    public function testDuplicatedCodeNeverReachesTwoItems(): void
    {
        $orderA = $this->createOrder('SUB-DISCORD-1M');
        $this->pay($orderA);
        self::assertSame('delivered', $this->waitForStatus((string) $orderA['id'], ['delivered'], 40));
        $stolenCode = $this->delivery((string) $orderA['id'])['code'] ?? null;
        self::assertNotNull($stolenCode);

        $this->suppliers['A']->post('/_control', ['duplicate_code_rate' => 1]);

        $orderB   = $this->createOrder('SUB-DISCORD-1M');
        $orderIdB = (string) $orderB['id'];
        $this->pay($orderB);

        self::assertSame('delivered', $this->waitForStatus($orderIdB, ['delivered'], 40));
        $delivery = $this->delivery($orderIdB);

        self::assertNotNull($delivery);
        self::assertNotSame($stolenCode, $delivery['code'], 'the buyer never received the stolen/duplicated code');
        self::assertSame('B', $delivery['supplier'], 'failed over to the honest supplier automatically');

        // No manual step was involved; the collision was caught and recorded.
        self::assertGreaterThanOrEqual(
            1,
            (int) Db::value('SELECT count(*) FROM orphan_codes WHERE order_id = :o', ['o' => $orderIdB]),
        );

        // The hard, DB-enforced invariant: still true.
        self::assertSame(
            0,
            (int) Db::value('SELECT count(*) FROM (SELECT code FROM deliveries GROUP BY code HAVING count(*) > 1) x'),
            'a code must never be attached to two different items'
        );
    }
}
