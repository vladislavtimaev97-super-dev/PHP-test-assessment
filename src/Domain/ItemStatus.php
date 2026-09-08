<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Stage 2 — one order can hold several items, each fulfilled independently
 * by its own supplier call. This is the per-item mirror of OrderStatus; the
 * order's own status is a rollup computed from these (see
 * OrderService::recomputeStatus()).
 */
final class ItemStatus
{
    public const PENDING         = 'pending';
    public const DELIVERING      = 'delivering';
    public const DELIVERED       = 'delivered';
    public const OUT_OF_STOCK    = 'out_of_stock';
    public const DELIVERY_FAILED = 'delivery_failed';
    public const REFUNDED        = 'refunded';

    /** Final: nothing may move an item out of these. */
    public const FINAL = [self::DELIVERED, self::REFUNDED];

    /** Not yet resolved — the sweeper keeps working on these. */
    public const RECOVERABLE = [
        self::PENDING,
        self::DELIVERING,
        self::OUT_OF_STOCK,
        self::DELIVERY_FAILED,
    ];

    /**
     * Stuck, but not yet given up on. The sweeper retries these until
     * ITEM_GIVE_UP_AFTER_SECONDS elapses since first_undeliverable_at, then
     * refunds the item instead of holding the order hostage forever.
     */
    public const UNDELIVERABLE = [self::OUT_OF_STOCK, self::DELIVERY_FAILED];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::PENDING         => [self::DELIVERING],
        self::DELIVERING      => [self::DELIVERED, self::OUT_OF_STOCK, self::DELIVERY_FAILED],
        self::OUT_OF_STOCK    => [self::DELIVERING, self::REFUNDED],
        self::DELIVERY_FAILED => [self::DELIVERING, self::REFUNDED],
        self::DELIVERED       => [],
        self::REFUNDED        => [],
    ];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL, true);
    }
}
