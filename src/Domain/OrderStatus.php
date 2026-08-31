<?php

declare(strict_types=1);

namespace App\Domain;

final class OrderStatus
{
    public const CREATED         = 'created';
    public const PAID            = 'paid';
    public const DELIVERING      = 'delivering';
    public const DELIVERED       = 'delivered';
    public const PAYMENT_FAILED  = 'payment_failed';
    public const OUT_OF_STOCK    = 'out_of_stock';
    public const DELIVERY_FAILED = 'delivery_failed';

    /** Final: nothing may move an order out of these. */
    public const FINAL = [self::DELIVERED, self::PAYMENT_FAILED];

    /** Paid, not delivered yet — the sweeper keeps working on these. */
    public const RECOVERABLE = [
        self::PAID,
        self::DELIVERING,
        self::OUT_OF_STOCK,
        self::DELIVERY_FAILED,
    ];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::CREATED => [self::PAID, self::PAYMENT_FAILED],
        // A "paid" webhook that lost the race with a "failed" one still means
        // the money arrived, so this edge is allowed and audited.
        self::PAYMENT_FAILED  => [self::PAID],
        self::PAID            => [self::DELIVERING],
        self::DELIVERING      => [self::DELIVERED, self::OUT_OF_STOCK, self::DELIVERY_FAILED],
        self::OUT_OF_STOCK    => [self::DELIVERING],
        self::DELIVERY_FAILED => [self::DELIVERING],
        self::DELIVERED       => [],
    ];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL, true);
    }

    public static function isPaid(string $status): bool
    {
        return in_array($status, self::RECOVERABLE, true) || $status === self::DELIVERED;
    }
}
