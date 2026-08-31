<?php

declare(strict_types=1);

namespace Tests;

use App\Infra\Db;
use App\Support\ApiClient;
use App\Support\WebhookSender;
use PHPUnit\Framework\TestCase;

/**
 * Shared machinery for the tests that exercise the running stack.
 * Assertions are made against the database, not against API responses.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ApiClient $api;
    /** @var array<string,ApiClient> */
    protected array $suppliers;
    protected WebhookSender $hooks;

    protected function setUp(): void
    {
        $this->api       = ApiClient::api();
        $this->suppliers = ['A' => ApiClient::supplier('A'), 'B' => ApiClient::supplier('B')];
        $this->hooks     = WebhookSender::default();
        $this->makeSuppliersHealthy();
    }

    protected function tearDown(): void
    {
        $this->makeSuppliersHealthy();
    }

    protected function makeSuppliersHealthy(): void
    {
        foreach ($this->suppliers as $client) {
            $client->post('/_control', [
                'down' => false, 'out_of_stock' => false, 'fail_rate' => 0,
                'timeout_rate' => 0, 'latency_ms' => 0, 'hang_ms' => 5000,
                'hang_only_first' => true, 'issue_before_hang' => true,
            ]);
        }
    }

    /** @return array<string,mixed> */
    protected function createOrder(string $sku, ?string $orderId = null): array
    {
        $body = ['sku' => $sku];
        if ($orderId !== null) {
            $body['order_id'] = $orderId;
        }

        $order = $this->api->post('/orders', $body);
        self::assertArrayHasKey('id', $order, 'order creation failed: ' . json_encode($order));

        return $order;
    }

    /**
     * @param  array<string,mixed> $order
     * @return array{http:array<int,int>,results:array<string,int>,errors:int,elapsed_ms:int}
     */
    protected function pay(
        array $order,
        int $count = 1,
        string $mode = 'same',
        string $status = 'paid',
        ?string $eventId = null,
        ?string $createdAt = null,
    ): array {
        return $this->hooks->sendConcurrently(WebhookSender::payloads(
            (string) $order['id'],
            (float) $order['amount'],
            $count,
            $mode,
            $status,
            $eventId,
            $createdAt,
        ));
    }

    protected function orderStatus(string $orderId): string
    {
        return (string) (Db::value('SELECT status FROM orders WHERE id = :o', ['o' => $orderId]) ?? 'missing');
    }

    /** @param list<string> $wanted */
    protected function waitForStatus(string $orderId, array $wanted, float $seconds = 45.0): string
    {
        $deadline = microtime(true) + $seconds;
        do {
            $status = $this->orderStatus($orderId);
            if (in_array($status, $wanted, true)) {
                return $status;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return $this->orderStatus($orderId);
    }

    protected function issuedKeys(string $supplier): int
    {
        return (int) Db::value(
            "SELECT count(*) FROM stub.keys WHERE supplier = :s AND status = 'issued'",
            ['s' => $supplier]
        );
    }

    protected function deliveryCount(string $orderId): int
    {
        return (int) Db::value('SELECT count(*) FROM deliveries WHERE order_id = :o', ['o' => $orderId]);
    }

    /** @return array<string,mixed>|null */
    protected function delivery(string $orderId): ?array
    {
        return Db::one('SELECT * FROM deliveries WHERE order_id = :o', ['o' => $orderId]);
    }

    protected function ledgerSum(string $orderId): int
    {
        return (int) Db::value(
            'SELECT COALESCE(sum(amount_minor), 0) FROM ledger_entries WHERE order_id = :o',
            ['o' => $orderId]
        );
    }
}
