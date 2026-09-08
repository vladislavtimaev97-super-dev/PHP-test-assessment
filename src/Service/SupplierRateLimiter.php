<?php

declare(strict_types=1);

namespace App\Service;

use App\Infra\Db;

/**
 * Stage 2, #3 — the core's own outbound throttle.
 *
 * A continuously-refilled token bucket per supplier, stored in Postgres so
 * every API/worker process shares the same budget (no per-process counter
 * that resets when a container restarts, no coordination service needed).
 *
 * `tryAcquire` is a single atomic UPDATE: it refills based on elapsed wall
 * time since the last touch, then takes one token if — and only if — at
 * least one is available. Two workers racing for the last token cannot both
 * win: the row lock inherent in UPDATE serialises them.
 */
final class SupplierRateLimiter
{
    /** Consume one token for a call to $supplier. False = back off, no call was made. */
    public static function tryAcquire(string $supplier): bool
    {
        $row = Db::one(
            "UPDATE supplier_rate_limits
             SET tokens = LEAST(capacity, tokens
                     + GREATEST(0, EXTRACT(EPOCH FROM (now() - updated_at))) * refill_per_sec) - 1,
                 updated_at = now()
             WHERE supplier = :s
               AND LEAST(capacity, tokens
                     + GREATEST(0, EXTRACT(EPOCH FROM (now() - updated_at))) * refill_per_sec) >= 1
             RETURNING tokens",
            ['s' => $supplier]
        );

        // No row at all = no limit configured for this supplier: unlimited.
        return $row !== null || !self::isConfigured($supplier);
    }

    /** Current bucket state (refilled, not consumed) — for /metrics visibility. */
    public static function peek(string $supplier): ?array
    {
        return Db::one(
            'SELECT supplier, capacity, refill_per_sec,
                    LEAST(capacity, tokens
                        + GREATEST(0, EXTRACT(EPOCH FROM (now() - updated_at))) * refill_per_sec) AS tokens
             FROM supplier_rate_limits WHERE supplier = :s',
            ['s' => $supplier]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function peekAll(): array
    {
        return Db::all(
            'SELECT supplier, capacity, refill_per_sec,
                    round(LEAST(capacity, tokens
                        + GREATEST(0, EXTRACT(EPOCH FROM (now() - updated_at))) * refill_per_sec), 2) AS tokens
             FROM supplier_rate_limits ORDER BY supplier'
        );
    }

    public static function configure(string $supplier, float $capacity, float $refillPerSec): void
    {
        Db::run(
            'INSERT INTO supplier_rate_limits (supplier, capacity, refill_per_sec, tokens)
             VALUES (:s, :c, :r, :c)
             ON CONFLICT (supplier) DO UPDATE
                 SET capacity = EXCLUDED.capacity, refill_per_sec = EXCLUDED.refill_per_sec,
                     tokens = LEAST(EXCLUDED.capacity, supplier_rate_limits.tokens), updated_at = now()',
            ['s' => $supplier, 'c' => $capacity, 'r' => $refillPerSec]
        );
    }

    private static function isConfigured(string $supplier): bool
    {
        return Db::value('SELECT 1 FROM supplier_rate_limits WHERE supplier = :s', ['s' => $supplier]) !== null;
    }
}
