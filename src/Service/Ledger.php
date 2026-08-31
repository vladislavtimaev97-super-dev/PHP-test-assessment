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
 *   refunds           — money given back (out of stock and we gave up)
 *
 * Writes are idempotent through a UNIQUE (order_id, entry_type, account)
 * index: replaying the same event can never double-book it.
 */
final class Ledger
{
    public const PAYMENT_CAPTURED    = 'payment_captured';
    public const DELIVERY_RECOGNIZED = 'delivery_recognized';
    public const PAYMENT_REVERSED    = 'payment_reversed';

    public static function paymentCaptured(PDO $pdo, string $orderId, int $amountMinor, string $ref): void
    {
        self::book($pdo, $orderId, self::PAYMENT_CAPTURED, $ref, [
            'gateway_clearing' => +$amountMinor,
            'deferred_revenue' => -$amountMinor,
        ]);
    }

    public static function deliveryRecognized(PDO $pdo, string $orderId, int $amountMinor, string $ref): void
    {
        self::book($pdo, $orderId, self::DELIVERY_RECOGNIZED, $ref, [
            'deferred_revenue' => +$amountMinor,
            'revenue'          => -$amountMinor,
        ]);
    }

    public static function paymentReversed(PDO $pdo, string $orderId, int $amountMinor, string $ref): void
    {
        self::book($pdo, $orderId, self::PAYMENT_REVERSED, $ref, [
            'gateway_clearing' => -$amountMinor,
            'refunds'          => +$amountMinor,
        ]);
    }

    /** @param array<string,int> $legs account => signed minor amount */
    private static function book(PDO $pdo, string $orderId, string $type, string $ref, array $legs): void
    {
        if (array_sum($legs) !== 0) {                     // guard against a coding mistake
            throw new \LogicException("Unbalanced ledger transaction {$type} for {$orderId}");
        }

        $txnId = (string) Db::value('SELECT gen_random_uuid()');

        $st = $pdo->prepare(
            'INSERT INTO ledger_entries (txn_id, order_id, account, amount_minor, entry_type, ref)
             VALUES (:txn, :order_id, :account, :amount, :type, :ref)
             ON CONFLICT (order_id, entry_type, account) WHERE order_id IS NOT NULL DO NOTHING'
        );

        foreach ($legs as $account => $amount) {
            $st->execute([
                'txn'      => $txnId,
                'order_id' => $orderId,
                'account'  => $account,
                'amount'   => $amount,
                'type'     => $type,
                'ref'      => $ref,
            ]);
        }
    }
}
