<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infra\HttpResult;
use App\Service\DeliveryService;
use App\Service\JobQueue;
use App\Support\WebhookSender;
use PHPUnit\Framework\TestCase;

final class DeliveryPrimitivesTest extends TestCase
{
    public function testRequestIdIsDerivedAndStable(): void
    {
        self::assertSame('req_ord_00123_A_1', DeliveryService::requestId('ord_00123', 'A', 1));
        self::assertSame(
            DeliveryService::requestId('ord_00123', 'A', 1),
            DeliveryService::requestId('ord_00123', 'A', 1),
            'the same order/supplier/epoch must always produce the same idempotency key'
        );
        self::assertNotSame(
            DeliveryService::requestId('ord_00123', 'A', 1),
            DeliveryService::requestId('ord_00123', 'A', 2),
            'a new attempt after a definitive failure needs a fresh key'
        );
        self::assertNotSame(
            DeliveryService::requestId('ord_00123', 'A', 1),
            DeliveryService::requestId('ord_00123', 'B', 1)
        );
    }

    public function testTimeoutAndUnavailableAreDifferentThings(): void
    {
        $timeout     = HttpResult::timeout(2000, 'curl(28)');
        $unavailable = HttpResult::unavailable(12, 'curl(7)');

        self::assertSame(HttpResult::TIMEOUT, $timeout->kind);
        self::assertSame(HttpResult::UNAVAILABLE, $unavailable->kind);
        self::assertFalse($timeout->isOk());
        self::assertFalse($unavailable->isOk());
    }

    public function testHttpResultClassification(): void
    {
        self::assertTrue(HttpResult::response(200, ['status' => 'ok'], '{}', 5)->isOk());
        self::assertFalse(HttpResult::response(503, ['reason' => 'supplier_down'], '{}', 5)->isOk());
        self::assertSame('out_of_stock', HttpResult::response(409, ['reason' => 'out_of_stock'], '{}', 5)->reason());
    }

    public function testBackoffGrowsAndIsBounded(): void
    {
        $previousMax = 0.0;
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $value = JobQueue::backoffSeconds($attempt);
            self::assertGreaterThan(0, $value);
            self::assertLessThanOrEqual(30.0, $value, 'backoff must stay capped');
            if ($attempt <= 6) {
                self::assertGreaterThanOrEqual($previousMax / 4, $value);
            }
            $previousMax = $value;
        }
    }

    public function testWebhookPayloadModes(): void
    {
        $same = WebhookSender::payloads('ord_1', 500, 5, 'same', 'paid', 'evt_x');
        self::assertCount(5, $same);
        self::assertSame(['evt_x'], array_values(array_unique(array_column($same, 'event_id'))));

        $distinct = WebhookSender::payloads('ord_1', 500, 5, 'distinct', 'paid', 'evt_x');
        self::assertCount(5, array_unique(array_column($distinct, 'event_id')));
        self::assertSame(['ord_1'], array_values(array_unique(array_column($distinct, 'order_id'))));
    }
}
