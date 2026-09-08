<?php

declare(strict_types=1);

namespace App\Service;

use App\Infra\Db;
use PDO;

/**
 * Double-entry money journal.
 *
 * Every business event books a balanced pair of entries, so
 * SUM(amount_minor) over the whole table is always exactly 0, and the balance
 * of each account has a meaning:
 *
 *   gateway_clearing  — money the PSP owes us for captured payments (asset)
 *   deferred_revenue  — captured, but the good is not delivered yet (liability)
 *   revenue           — recognised after the code is handed to the customer
 *   refunds           — money given back (an item could not be fulfilled)
 *
 * Writes are idempotent through a UNIQUE (order_id, item_id, entry_type,
 * account) index: replaying the same event can never double-book it.
 *
 * Stage 2: a basket pays once for the whole order (`payment_captured`, one
 * leg per order — item_id is the '' sentinel), then each ITEM books its own
 * outcome independently: `delivery_recognized` moves that item's price from
 * deferred_revenue to revenue, `item_refunded` moves it from deferred_revenue
 * to refunds instead. The order-level total is never split by hand — it
 * falls out of summing the per-item legs, which is exactly what makes
 * "paid = delivered + refunded" hold under partial fulfillment and any
 * retry/crash in between.
 */
final class Ledger
{
    public const PAYMENT_CAPTURED    = 'payment_captured';
    public const DELIVERY_RECOGNIZED = 'delivery_recognized';
    public const PAYMENT_REVERSED    = 'payment_reversed';
    public const ITEM_REFUNDED       = 'item_refunded';

    /** Order-level leg: item_id sentinel for "not about one specific item". */
    private const ORDER_LEVEL = '';

    public static function paymentCaptured(PDO $pdo, string $orderId, int $amountMinor, string $ref): void
    {
        self::book($pdo, $orderId, self::ORDER_LEVEL, self::PAYMENT_CAPTURED, $ref, [
            'gateway_clearing' => +$amountMinor,
            'deferred_revenue' => -$amountMinor,
        ]);
    }

    public static function deliveryRecognized(
        PDO $pdo,
        string $orderId,
        string $itemId,
        int $amountMinor,
        string $ref,
    ): void {
        self::book($pdo, $orderId, $itemId, self::DELIVERY_RECOGNIZED, $ref, [
            'deferred_revenue' => +$amountMinor,
            'revenue'          => -$amountMinor,
        ]);
    }

    /**
     * An item that could not be fulfilled after exhausting the retry window.
     * The payment for the whole order was already captured (gateway_clearing
     * was zeroed out into deferred_revenue right then), so what resolves
     * here is the deferred_revenue slice that was being held for this item —
     * exactly like deliveryRecognized resolves it, just into `refunds`
     * instead of `revenue`. Both legs move deferred_revenue the SAME
     * direction (toward zero); getting this backwards (subtracting instead
     * of adding) would make an item's refund look like it INCREASED the
     * amount still owed, and the deferred_revenue == undelivered_value
     * cross-check in ReconciliationService would never balance again for
     * this order once it is fully resolved.
     *
     * `refunds` is booked as a negative raw amount here, the same
     * convention `revenue` already uses (recognised value is negative-raw,
     * reported inverted) — not the positive-raw convention the older,
     * currently-unused paymentReversed() uses for a whole-order reversal
     * that never reached deferred_revenue in the first place.
     */
    public static function itemRefunded(
        PDO $pdo,
        string $orderId,
        string $itemId,
        int $amountMinor,
        string $ref,
    ): void {
        self::book($pdo, $orderId, $itemId, self::ITEM_REFUNDED, $ref, [
            'deferred_revenue' => +$amountMinor,
            'refunds'          => -$amountMinor,
        ]);
    }

    /**
     * Whole-order reversal for a payment that was captured before any item
     * was delivered (kept from stage 1; not on the basket partial-refund
     * path, which uses itemRefunded above instead).
     */
    public static function paymentReversed(PDO $pdo, string $orderId, int $amountMinor, string $ref): void
    {
        self::book($pdo, $orderId, self::ORDER_LEVEL, self::PAYMENT_REVERSED, $ref, [
            'gateway_clearing' => -$amountMinor,
            'refunds'          => +$amountMinor,
        ]);
    }

    /** @param array<string,int> $legs account => signed minor amount */
    private static function book(
        PDO $pdo,
        string $orderId,
        string $itemId,
        string $type,
        string $ref,
        array $legs,
    ): void {
        if (array_sum($legs) !== 0) {                     // guard against a coding mistake
            throw new \LogicException("Unbalanced ledger transaction {$type} for {$orderId}");
        }

        $txnId = (string) Db::value('SELECT gen_random_uuid()');

        $st = $pdo->prepare(
            'INSERT INTO ledger_entries (txn_id, order_id, item_id, account, amount_minor, entry_type, ref)
             VALUES (:txn, :order_id, :item_id, :account, :amount, :type, :ref)
             ON CONFLICT (order_id, item_id, entry_type, account) WHERE order_id IS NOT NULL DO NOTHING'
        );

        foreach ($legs as $account => $amount) {
            $st->execute([
                'txn'      => $txnId,
                'order_id' => $orderId,
                'item_id'  => $itemId,
                'account'  => $account,
                'amount'   => $amount,
                'type'     => $type,
                'ref'      => $ref,
            ]);
        }
    }
}
