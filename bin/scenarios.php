<?php

declare(strict_types=1);

/**
 * The six acceptance scenarios from the task, end to end, against the running
 * stack. Assertions are made against the DATABASE, not against API responses,
 * so a scenario cannot pass by being told a comfortable story.
 *
 *   php bin/scenarios.php              # all of them
 *   php bin/scenarios.php --only=1,4   # a subset
 */

use App\Infra\Db;
use App\Support\ApiClient;
use App\Support\Cli;
use App\Support\WebhookSender;

require __DIR__ . '/../vendor/autoload.php';

$args = Cli::args($argv);
$only = isset($args['only']) ? array_map('intval', explode(',', $args['only'])) : [1, 2, 3, 4, 5, 6];

$api      = ApiClient::api();
$supplier = ['A' => ApiClient::supplier('A'), 'B' => ApiClient::supplier('B')];
$hooks    = WebhookSender::default();

$api->waitForHealth(60);
$supplier['A']->waitForHealth(60);
$supplier['B']->waitForHealth(60);

$passed = 0;
$failed = 0;

// ---------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------
$check = function (bool $ok, string $what) use (&$passed, &$failed): bool {
    if ($ok) {
        $passed++;
        Cli::ok($what);
    } else {
        $failed++;
        Cli::fail($what);
    }

    return $ok;
};

$healthy = function () use ($supplier): void {
    foreach ($supplier as $client) {
        $client->post('/_control', [
            'down' => false, 'out_of_stock' => false, 'fail_rate' => 0,
            'timeout_rate' => 0, 'latency_ms' => 0, 'hang_ms' => 5000,
            'hang_only_first' => true, 'issue_before_hang' => true,
        ]);
    }
};

$newOrder = function (string $sku, ?string $orderId = null) use ($api): array {
    $body = ['sku' => $sku];
    if ($orderId !== null) {
        $body['order_id'] = $orderId;
    }
    $order = $api->post('/orders', $body);
    if (!isset($order['id'])) {
        throw new RuntimeException('order creation failed: ' . json_encode($order));
    }

    return $order;
};

$issued = fn (string $s): int => (int) Db::value(
    "SELECT count(*) FROM stub.keys WHERE supplier = :s AND status = 'issued'",
    ['s' => $s]
);

$countDeliveries = fn (string $orderId): int => (int) Db::value(
    'SELECT count(*) FROM deliveries WHERE order_id = :o',
    ['o' => $orderId]
);

$status = fn (string $orderId): string => (string) (Db::value(
    'SELECT status FROM orders WHERE id = :o',
    ['o' => $orderId]
) ?? 'missing');

$waitForStatus = function (string $orderId, array $wanted, float $seconds) use ($status): string {
    $deadline = microtime(true) + $seconds;
    do {
        $s = $status($orderId);
        if (in_array($s, $wanted, true)) {
            return $s;
        }
        usleep(200_000);
    } while (microtime(true) < $deadline);

    return $status($orderId);
};

// ---------------------------------------------------------------------
// 1. 50 parallel "paid" webhooks -> exactly one delivery
// ---------------------------------------------------------------------
if (in_array(1, $only, true)) {
    Cli::head('1) 50 parallel "paid" webhooks for one order');
    $healthy();

    $order    = $newOrder('KEY-GTA5');
    $orderId  = (string) $order['id'];
    $beforeA  = $issued('A');
    $beforeB  = $issued('B');

    $stats = $hooks->sendConcurrently(WebhookSender::payloads(
        $orderId,
        (float) $order['amount'],
        50,
        'distinct'
    ));
    Cli::info(sprintf(
        '50 distinct events in %d ms; results: %s',
        $stats['elapsed_ms'],
        json_encode($stats['results'])
    ));

    $final = $waitForStatus($orderId, ['delivered'], 40);

    $check($stats['errors'] === 0, 'every webhook got an answer (no transport errors)');
    $check(($stats['http'][200] ?? 0) === 50, 'all 50 webhooks answered 200');
    $check($final === 'delivered', "order reached delivered (got {$final})");
    $check($countDeliveries($orderId) === 1, 'exactly one delivery row');
    $check(
        (int) Db::value("SELECT count(*) FROM webhook_events WHERE order_id = :o AND outcome = 'applied'", ['o' => $orderId]) === 1,
        'exactly one webhook was applied, the other 49 were no-ops'
    );
    $check(
        (int) Db::value('SELECT count(*) FROM webhook_events WHERE order_id = :o', ['o' => $orderId]) === 50,
        'all 50 events were stored (nothing was lost)'
    );
    $check($issued('A') + $issued('B') - $beforeA - $beforeB === 1, 'exactly one key left the supplier pools');
    $check(
        (int) Db::value("SELECT count(*) FROM ledger_entries WHERE order_id = :o", ['o' => $orderId]) === 4,
        'ledger booked exactly one capture and one recognition'
    );
    $check(
        (int) Db::value('SELECT COALESCE(sum(amount_minor),0) FROM ledger_entries WHERE order_id = :o', ['o' => $orderId]) === 0,
        'the order is balanced in the money journal'
    );
}

// ---------------------------------------------------------------------
// 2. the same event_id again changes nothing
// ---------------------------------------------------------------------
if (in_array(2, $only, true)) {
    Cli::head('2) replay of the same event_id');
    $healthy();

    $order   = $newOrder('SUB-DISCORD-1M');
    $orderId = (string) $order['id'];
    $eventId = 'evt_replay_' . bin2hex(random_bytes(4));

    $hooks->sendConcurrently(WebhookSender::payloads($orderId, (float) $order['amount'], 1, 'same', 'paid', $eventId));
    $final = $waitForStatus($orderId, ['delivered'], 40);

    $snapshot = Db::one(
        'SELECT
            (SELECT count(*) FROM deliveries WHERE order_id = :o)            AS deliveries,
            (SELECT count(*) FROM order_status_history WHERE order_id = :o)  AS history,
            (SELECT count(*) FROM ledger_entries WHERE order_id = :o)        AS ledger,
            (SELECT count(*) FROM webhook_events WHERE order_id = :o)        AS events,
            (SELECT code FROM deliveries WHERE order_id = :o)                AS code,
            (SELECT updated_at FROM orders WHERE id = :o)                    AS updated_at',
        ['o' => $orderId]
    );

    $stats = $hooks->sendConcurrently(WebhookSender::payloads($orderId, (float) $order['amount'], 20, 'same', 'paid', $eventId));
    usleep(500_000);

    $after = Db::one(
        'SELECT
            (SELECT count(*) FROM deliveries WHERE order_id = :o)            AS deliveries,
            (SELECT count(*) FROM order_status_history WHERE order_id = :o)  AS history,
            (SELECT count(*) FROM ledger_entries WHERE order_id = :o)        AS ledger,
            (SELECT count(*) FROM webhook_events WHERE order_id = :o)        AS events,
            (SELECT code FROM deliveries WHERE order_id = :o)                AS code,
            (SELECT updated_at FROM orders WHERE id = :o)                    AS updated_at',
        ['o' => $orderId]
    );

    $check($final === 'delivered', 'order was delivered by the first event');
    $check(($stats['results']['duplicate'] ?? 0) === 20, 'all 20 replays were recognised as duplicates');
    $check(($stats['http'][200] ?? 0) === 20, 'all 20 replays answered 200');
    $check($snapshot == $after, 'nothing changed: deliveries, history, ledger, code, updated_at');
    $check((int) $after['events'] === 1, 'the event is stored exactly once');
}

// ---------------------------------------------------------------------
// 3. out-of-order webhooks
// ---------------------------------------------------------------------
if (in_array(3, $only, true)) {
    Cli::head('3a) webhook arrives BEFORE the order exists');
    $healthy();

    $orderId = 'ord_early_' . bin2hex(random_bytes(4));
    $stats   = $hooks->sendConcurrently(WebhookSender::payloads($orderId, 1490.0, 1, 'same'));

    $check(($stats['http'][200] ?? 0) === 1, 'the early webhook is accepted with 200 (no retry storm)');
    $check(
        ($stats['results']['deferred_no_order'] ?? 0) === 1,
        'it is parked as deferred_no_order instead of being dropped'
    );
    $check($api->request('GET', '/orders/' . $orderId)['status'] === 404, 'no order was invented for it');

    $order = $newOrder('SUB-YT-3M', $orderId);
    $final = $waitForStatus($orderId, ['delivered'], 40);

    $check($final === 'delivered', "the parked payment is applied when the order shows up (got {$final})");
    $check($countDeliveries($orderId) === 1, 'exactly one delivery row');
    $check(
        (int) Db::value("SELECT count(*) FROM webhook_events WHERE order_id = :o AND processed_at IS NOT NULL", ['o' => $orderId]) === 1,
        'the parked event is marked processed'
    );

    Cli::head('3b) a "failed" event generated BEFORE the "paid" one, delivered after it');

    $order2   = $newOrder('SUB-SPOTIFY-1M');
    $orderId2 = (string) $order2['id'];
    $late     = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);   // older issuer timestamp

    $hooks->sendConcurrently(WebhookSender::payloads($orderId2, (float) $order2['amount'], 1, 'same', 'paid'));
    $waitForStatus($orderId2, ['delivered'], 40);

    $failedEvent = 'evt_stale_' . bin2hex(random_bytes(4));
    $hooks->sendConcurrently(WebhookSender::payloads(
        $orderId2, (float) $order2['amount'], 1, 'same', 'failed', $failedEvent, $late
    ));
    usleep(500_000);

    $outcome = (string) Db::value('SELECT outcome FROM webhook_events WHERE event_id = :e', ['e' => $failedEvent]);

    $check($outcome === 'ignored_stale', "the stale 'failed' event is ignored (outcome={$outcome})");
    $check($status($orderId2) === 'delivered', 'the delivered order is untouched');
    $check($countDeliveries($orderId2) === 1, 'still exactly one delivery row');
}

// ---------------------------------------------------------------------
// 4. supplier times out but DID issue the code
// ---------------------------------------------------------------------
if (in_array(4, $only, true)) {
    Cli::head('4) supplier A times out after issuing the code');
    $healthy();

    // A always hangs, and always hangs *after* committing the code.
    $supplier['A']->post('/_control', [
        'timeout_rate'      => 1,
        'issue_before_hang' => true,
        'hang_ms'           => 4000,
        'hang_only_first'   => true,
    ]);

    $order   = $newOrder('KEY-EFT');
    $orderId = (string) $order['id'];
    $beforeA = $issued('A');
    $beforeB = $issued('B');

    $hooks->sendConcurrently(WebhookSender::payloads($orderId, (float) $order['amount'], 1, 'same'));
    $final = $waitForStatus($orderId, ['delivered'], 60);

    $requestIds = Db::all(
        'SELECT DISTINCT request_id FROM delivery_attempts WHERE order_id = :o ORDER BY request_id',
        ['o' => $orderId]
    );
    $timeouts = (int) Db::value(
        "SELECT count(*) FROM delivery_attempts WHERE order_id = :o AND outcome = 'timeout'",
        ['o' => $orderId]
    );
    $delivery = Db::one('SELECT * FROM deliveries WHERE order_id = :o', ['o' => $orderId]);

    $check($final === 'delivered', "order was delivered anyway (got {$final})");
    $check($timeouts >= 1, "the first call really did time out ({$timeouts} timeout attempts)");
    $check($countDeliveries($orderId) === 1, 'exactly one delivery row');
    $check($issued('A') - $beforeA === 1, 'supplier A gave out exactly ONE key, not two');
    $check($issued('B') - $beforeB === 0, 'no fail-over happened while the state was unknown');
    $check(count($requestIds) === 1, 'every retry reused the same request_id: ' . json_encode(array_column($requestIds, 'request_id')));
    $check(($delivery['supplier'] ?? null) === 'A', 'the code came from A, the supplier that actually issued it');
    $check(
        (int) Db::value('SELECT count(*) FROM orphan_codes WHERE order_id = :o', ['o' => $orderId]) === 0,
        'no orphaned codes'
    );

    $healthy();
}

// ---------------------------------------------------------------------
// 5. supplier A unavailable -> fail over to B
// ---------------------------------------------------------------------
if (in_array(5, $only, true)) {
    Cli::head('5) supplier A is down, B takes over');
    $healthy();
    $supplier['A']->post('/_control', ['down' => true]);

    $order   = $newOrder('KEY-CS2-PRIME');
    $orderId = (string) $order['id'];
    $beforeA = $issued('A');
    $beforeB = $issued('B');

    $hooks->sendConcurrently(WebhookSender::payloads($orderId, (float) $order['amount'], 1, 'same'));
    $final = $waitForStatus($orderId, ['delivered'], 60);

    $delivery = Db::one('SELECT * FROM deliveries WHERE order_id = :o', ['o' => $orderId]);

    $check($final === 'delivered', "order was delivered (got {$final})");
    $check(($delivery['supplier'] ?? null) === 'B', 'it was delivered by the fallback supplier B');
    $check($countDeliveries($orderId) === 1, 'exactly one delivery row');
    $check($issued('A') - $beforeA === 0, 'supplier A gave out nothing');
    $check($issued('B') - $beforeB === 1, 'supplier B gave out exactly one key');
    $check(
        (int) Db::value("SELECT count(*) FROM delivery_attempts WHERE order_id = :o AND supplier = 'A'", ['o' => $orderId]) >= 1,
        'A was tried first and its failure is recorded'
    );

    $healthy();
}

// ---------------------------------------------------------------------
// 6. empty stock: recoverable, not a crash
// ---------------------------------------------------------------------
if (in_array(6, $only, true)) {
    Cli::head('6) both suppliers out of stock, then restocked');
    $healthy();
    $supplier['A']->post('/_control', ['out_of_stock' => true]);
    $supplier['B']->post('/_control', ['out_of_stock' => true]);

    $order   = $newOrder('GIFT-PSN-1000');
    $orderId = (string) $order['id'];

    $hooks->sendConcurrently(WebhookSender::payloads($orderId, (float) $order['amount'], 1, 'same'));
    $final = $waitForStatus($orderId, ['out_of_stock'], 40);

    $view = $api->request('GET', '/orders/' . $orderId);

    $check($final === 'out_of_stock', "order parked in out_of_stock (got {$final})");
    $check($view['status'] === 200, 'the API still serves the order (no 500)');
    $check($countDeliveries($orderId) === 0, 'nothing was delivered');
    $check(
        (int) Db::value("SELECT count(*) FROM ledger_entries WHERE order_id = :o AND entry_type = 'payment_captured'", ['o' => $orderId]) === 2,
        'the money is still booked as captured and deferred'
    );
    $check(
        (int) Db::value("SELECT count(*) FROM ledger_entries WHERE order_id = :o AND entry_type = 'delivery_recognized'", ['o' => $orderId]) === 0,
        'no revenue was recognised for an undelivered order'
    );

    Cli::info('restocking supplier A and waiting for the background recovery...');
    $supplier['A']->post('/_control', ['out_of_stock' => false]);

    $final = $waitForStatus($orderId, ['delivered'], 90);

    $check($final === 'delivered', "the background job finished the order after restock (got {$final})");
    $check($countDeliveries($orderId) === 1, 'exactly one delivery row after recovery');
    $check(
        (int) Db::value('SELECT COALESCE(sum(amount_minor),0) FROM ledger_entries WHERE order_id = :o', ['o' => $orderId]) === 0,
        'the order is balanced in the money journal'
    );

    $healthy();
}

// ---------------------------------------------------------------------
// global invariants
// ---------------------------------------------------------------------
Cli::head('global invariants');

$report = $api->get('/admin/reconciliation?grace_seconds=120');

$check((int) ($report['totals']['ledger_sum_minor'] ?? -1) === 0, 'the whole money journal sums to zero');
$check(
    ($report['counts']['duplicate_deliveries'] ?? 1) === 0,
    'no order has more than one delivery'
);
$check(($report['counts']['reused_codes'] ?? 1) === 0, 'no key was issued to two orders');
$check(($report['counts']['ledger_imbalanced_txns'] ?? 1) === 0, 'no unbalanced ledger transaction');
$check(($report['counts']['orphan_codes'] ?? 1) === 0, 'no orphaned supplier codes');
$check(($report['counts']['delivered_not_paid'] ?? 1) === 0, 'nothing was delivered without a payment');
$check(($report['counts']['paid_not_delivered'] ?? 1) === 0, 'nothing paid is left undelivered');

Cli::line();
Cli::line(sprintf(
    "\033[1m%d passed, %d failed\033[0m",
    $passed,
    $failed
));

exit($failed === 0 ? 0 : 1);
