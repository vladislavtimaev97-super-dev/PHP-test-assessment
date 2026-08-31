<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testHappyPath(): void
    {
        self::assertTrue(OrderStatus::canMove(OrderStatus::CREATED, OrderStatus::PAID));
        self::assertTrue(OrderStatus::canMove(OrderStatus::PAID, OrderStatus::DELIVERING));
        self::assertTrue(OrderStatus::canMove(OrderStatus::DELIVERING, OrderStatus::DELIVERED));
    }

    public function testDeliveredIsFinal(): void
    {
        foreach ([OrderStatus::PAID, OrderStatus::DELIVERING, OrderStatus::PAYMENT_FAILED, OrderStatus::OUT_OF_STOCK] as $to) {
            self::assertFalse(
                OrderStatus::canMove(OrderStatus::DELIVERED, $to),
                "delivered must not move to {$to}"
            );
        }
        self::assertTrue(OrderStatus::isFinal(OrderStatus::DELIVERED));
    }

    public function testRecoverableStatesCanRetryDelivery(): void
    {
        self::assertTrue(OrderStatus::canMove(OrderStatus::OUT_OF_STOCK, OrderStatus::DELIVERING));
        self::assertTrue(OrderStatus::canMove(OrderStatus::DELIVERY_FAILED, OrderStatus::DELIVERING));
    }

    /** A late "paid" after a "failed" is allowed: the money really did arrive. */
    public function testPaymentFailedCanStillBecomePaid(): void
    {
        self::assertTrue(OrderStatus::canMove(OrderStatus::PAYMENT_FAILED, OrderStatus::PAID));
        self::assertFalse(OrderStatus::canMove(OrderStatus::PAYMENT_FAILED, OrderStatus::DELIVERED));
    }

    public function testPaidOrderCannotSilentlyFail(): void
    {
        self::assertFalse(OrderStatus::canMove(OrderStatus::PAID, OrderStatus::PAYMENT_FAILED));
        self::assertFalse(OrderStatus::canMove(OrderStatus::DELIVERED, OrderStatus::PAYMENT_FAILED));
    }
}
