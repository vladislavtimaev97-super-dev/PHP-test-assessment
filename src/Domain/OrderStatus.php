<?php

declare(strict_types=1);

namespace App\Domain;

final class OrderStatus
{
    public const CREATED             = 'created';
    public const PAID                = 'paid';
    public const DELIVERING          = 'delivering';
    public const DELIVERED           = 'delivered';
    public const PAYMENT_FAILED      = 'payment_failed';
    public const OUT_OF_STOCK        = 'out_of_stock';
    public const DELIVERY_FAILED     = 'delivery_failed';
    /** Stage 2: some basket items delivered, the rest were refunded. */
    public const PARTIALLY_DELIVERED = 'partially_delivered';
    /** Stage 2: every basket item ended up refunded, nothing was delivered. */
    public const REFUNDED            = 'refunded';

    /** Final: nothing may move an order out of these. */
    public const FINAL = [
        self::DELIVERED,
        self::PAYMENT_FAILED,
        self::PARTIALLY_DELIVERED,
        self::REFUNDED,
    ];

    /** Paid, not fully resolved yet — the sweeper keeps working on these. */
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
        // DELIVERING is the umbrella "still working the basket" status; the
        // order only leaves it once every item has reached a terminal state
        // (delivered or refunded) — see OrderService::recomputeStatus().
        // OUT_OF_STOCK / DELIVERY_FAILED here mean "at least one item is
        // stuck, none are unresolved" — a recoverable rollup, not a dead end.
        self::DELIVERING      => [
            self::DELIVERED, self::OUT_OF_STOCK, self::DELIVERY_FAILED,
            self::PARTIALLY_DELIVERED, self::REFUNDED,
        ],
        // These two are themselves rollups (recomputeStatus() may re-derive
        // the order status straight from OUT_OF_STOCK to DELIVERY_FAILED, or
        // straight to a terminal state, without passing back through
        // DELIVERING first — e.g. the sweeper refunding the last stuck item).
        self::OUT_OF_STOCK    => [
            self::DELIVERING, self::DELIVERY_FAILED,
            self::DELIVERED, self::PARTIALLY_DELIVERED, self::REFUNDED,
        ],
        self::DELIVERY_FAILED => [
            self::DELIVERING, self::OUT_OF_STOCK,
            self::DELIVERED, self::PARTIALLY_DELIVERED, self::REFUNDED,
        ],
        self::DELIVERED       => [],
        self::PARTIALLY_DELIVERED => [],
        self::REFUNDED         => [],
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
        return in_array($status, self::RECOVERABLE, true)
            || in_array($status, [self::DELIVERED, self::PARTIALLY_DELIVERED, self::REFUNDED], true);
    }
}
