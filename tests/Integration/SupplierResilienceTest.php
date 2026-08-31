<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infra\Db;
use Tests\IntegrationTestCase;

/**
 * Stage 3 — timeouts, retries, fail-over, and the trap.
 */
final class SupplierResilienceTest extends IntegrationTestCase
{
    /**
     * The trap: A hangs *after* committing the code. Retrying with the same
     * request_id must recover that very code, and B must never be touched.
     */
    public function testTimeoutThatActuallyIssuedDoesNotDoubleIssue(): void
    {
        $this->suppliers['A']->post('/_control', [
            'timeout_rate'      => 1,
            'issue_before_hang' => true,
            'hang_ms'           => 4000,
            'hang_only_first'   => true,
        ]);

        $order   = $this->createOrder('KEY-EFT');
        $orderId = (string) $order['id'];
        $beforeA = $this->issuedKeys('A');
        $beforeB = $this->issuedKeys('B');

        $this->pay($order);

        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered'], 60));
        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame(1, $this->issuedKeys('A') - $beforeA, 'A must give out exactly one key');
        self::assertSame(0, $this->issuedKeys('B') - $beforeB, 'no fail-over while the state is unknown');

        $requestIds = array_column(Db::all(
            'SELECT DISTINCT request_id FROM delivery_attempts WHERE order_id = :o',
            ['o' => $orderId]
        ), 'request_id');

        self::assertCount(1, $requestIds, 'every retry must reuse one request_id');
        self::assertSame('A', $this->delivery($orderId)['supplier'] ?? null);
        self::assertGreaterThanOrEqual(
            1,
            (int) Db::value(
                "SELECT count(*) FROM delivery_attempts WHERE order_id = :o AND outcome = 'timeout'",
                ['o' => $orderId]
            ),
            'the scenario is only meaningful if a call really timed out'
        );
        self::assertSame(
            0,
            (int) Db::value('SELECT count(*) FROM orphan_codes WHERE order_id = :o', ['o' => $orderId])
        );
    }

    /**
     * A hangs WITHOUT issuing anything. Once it answers definitively, the
     * fail-over to B is safe and must happen.
     */
    public function testTimeoutWithoutIssueEventuallyFailsOver(): void
    {
        $this->suppliers['A']->post('/_control', [
            'timeout_rate'      => 1,
            'issue_before_hang' => false,
            'hang_ms'           => 2500,
            'hang_only_first'   => true,
        ]);

        $order   = $this->createOrder('GIFT-XBOX-1500');
        $orderId = (string) $order['id'];
        $beforeA = $this->issuedKeys('A');
        $beforeB = $this->issuedKeys('B');

        $this->pay($order);

        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered'], 90));
        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame(0, $this->issuedKeys('A') - $beforeA, 'A issued nothing');
        self::assertSame(1, $this->issuedKeys('B') - $beforeB, 'B issued exactly one key');
    }

    public function testFailoverWhenPrimarySupplierIsDown(): void
    {
        $this->suppliers['A']->post('/_control', ['down' => true]);

        $order   = $this->createOrder('GIFT-ROBLOX-800');
        $orderId = (string) $order['id'];
        $beforeA = $this->issuedKeys('A');
        $beforeB = $this->issuedKeys('B');

        $this->pay($order);

        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered'], 60));
        self::assertSame('B', $this->delivery($orderId)['supplier'] ?? null);
        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame(0, $this->issuedKeys('A') - $beforeA);
        self::assertSame(1, $this->issuedKeys('B') - $beforeB);
        self::assertGreaterThanOrEqual(
            1,
            (int) Db::value(
                "SELECT count(*) FROM delivery_attempts WHERE order_id = :o AND supplier = 'A'",
                ['o' => $orderId]
            ),
            'A must be tried before B'
        );
    }

    /** Flaky (but honest) suppliers must still deliver exactly once. */
    public function testFlakySuppliersStillDeliverExactlyOnce(): void
    {
        $this->suppliers['A']->post('/_control', ['fail_rate' => 0.6, 'timeout_rate' => 0.3, 'hang_ms' => 2500]);
        $this->suppliers['B']->post('/_control', ['fail_rate' => 0.3]);

        $order   = $this->createOrder('STEAM-TOPUP-500');
        $orderId = (string) $order['id'];
        $before  = $this->issuedKeys('A') + $this->issuedKeys('B');

        $this->pay($order);

        self::assertSame('delivered', $this->waitForStatus($orderId, ['delivered'], 120));
        self::assertSame(1, $this->deliveryCount($orderId));
        self::assertSame(1, $this->issuedKeys('A') + $this->issuedKeys('B') - $before, 'one key total');
        self::assertSame(
            0,
            (int) Db::value('SELECT count(*) FROM orphan_codes WHERE order_id = :o', ['o' => $orderId])
        );
    }
}
