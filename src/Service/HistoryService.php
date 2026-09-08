<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ItemStatus;
use App\Infra\Db;

/**
 * Stage 2, bonus #4 — point-in-time reconstruction.
 *
 * Nothing here needs its own storage: `order_status_history` (order- and
 * item-level transitions) and `ledger_entries` (money) are already
 * append-only with real timestamps — that is what stage 4 built them for.
 * This class only ever reads "as of" a cutoff by taking the latest row with
 * created_at <= the requested instant, so it is impossible for it to see
 * something that had not happened yet at that moment, and correctness here
 * is really just correctness of never UPDATEing/DELETEing those two tables
 * — which the rest of the codebase already guarantees.
 */
final class HistoryService
{
    /** @return array<string,mixed>|null */
    public function orderAsOf(string $orderId, \DateTimeImmutable $at): array|null
    {
        $order = Db::one('SELECT id FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order === null) {
            return null;
        }

        $atIso = self::iso($at);

        $status = Db::value(
            "SELECT to_status FROM order_status_history
             WHERE order_id = :id AND item_id = '' AND created_at <= :at
             ORDER BY id DESC LIMIT 1",
            ['id' => $orderId, 'at' => $atIso]
        );

        if ($status === null) {
            // No order-level transition (not even "created") had happened
            // yet at this instant: the order did not exist.
            return [
                'order_id' => $orderId,
                'as_of'    => $atIso,
                'existed'  => false,
            ];
        }

        $items = Db::all(
            'SELECT id, line_no, sku, amount_minor FROM order_items WHERE order_id = :id ORDER BY line_no',
            ['id' => $orderId]
        );

        $itemStates = [];
        foreach ($items as $it) {
            $itemStatus = Db::value(
                "SELECT to_status FROM order_status_history
                 WHERE order_id = :o AND item_id = :i AND created_at <= :at
                 ORDER BY id DESC LIMIT 1",
                ['o' => $orderId, 'i' => $it['id'], 'at' => $atIso]
            );
            // No row yet at this instant = the item did not exist yet either
            // (it is created in the same transaction as the order).
            $itemStates[] = [
                'id'           => $it['id'],
                'line_no'      => (int) $it['line_no'],
                'sku'          => $it['sku'],
                'amount_minor' => (int) $it['amount_minor'],
                'status'       => $itemStatus === null ? null : (string) $itemStatus,
                'existed'      => $itemStatus !== null,
            ];
        }

        $ledger = Db::all(
            "SELECT account, sum(amount_minor) AS balance_minor
             FROM ledger_entries WHERE order_id = :id AND created_at <= :at
             GROUP BY account ORDER BY account",
            ['id' => $orderId, 'at' => $atIso]
        );

        $delivered = count(array_filter($itemStates, static fn ($it) => $it['status'] === ItemStatus::DELIVERED));
        $refunded  = count(array_filter($itemStates, static fn ($it) => $it['status'] === ItemStatus::REFUNDED));

        return [
            'order_id'      => $orderId,
            'as_of'         => $atIso,
            'existed'       => true,
            'status'        => (string) $status,
            'items'         => $itemStates,
            'items_summary' => [
                'total'     => count($itemStates),
                'delivered' => $delivered,
                'refunded'  => $refunded,
            ],
            'money' => $ledger,
        ];
    }

    /**
     * Ledger totals for a period, straight from the append-only journal.
     * `balances_to_zero` re-proves the same double-entry invariant the live
     * report checks, but scoped to only what happened in [from, to) — the
     * literal "итоги за период считаются из этой истории и сходятся".
     *
     * @return array<string,mixed>
     */
    public function periodTotals(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromIso = self::iso($from);
        $toIso   = self::iso($to);

        $rows = Db::all(
            'SELECT entry_type, account, sum(amount_minor) AS total_minor, count(*) AS entries
             FROM ledger_entries
             WHERE created_at > :from AND created_at <= :to
             GROUP BY entry_type, account
             ORDER BY entry_type, account',
            ['from' => $fromIso, 'to' => $toIso]
        );

        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) $row['total_minor'];
        }

        return [
            'from'             => $fromIso,
            'to'               => $toIso,
            'entries'          => $rows,
            'sum_minor'        => $sum,
            'balances_to_zero' => $sum === 0,
        ];
    }

    /**
     * PHP's 'c' format constant (used almost everywhere else in this
     * codebase for timestamps) deliberately omits microseconds. That is
     * fine for logging, but here it would silently round a cutoff DOWN to
     * the start of its second — and several transitions in a fast test run
     * land in the very same second, so losing sub-second precision here
     * would misclassify them as "not yet happened". Keep it explicit.
     */
    private static function iso(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s.uP');
    }
}
