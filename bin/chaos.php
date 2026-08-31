<?php

declare(strict_types=1);

/**
 * Adversarial load: many orders at once, both suppliers misbehaving, every
 * payment webhook delivered several times in parallel.
 *
 *   php bin/chaos.php --orders=40 --webhooks=5 --fail-rate=0.4 --timeout-rate=0.25
 *
 * Then it checks the invariants that must hold no matter what:
 *   - every paid order ends delivered
 *   - exactly one delivery per order
 *   - no key issued twice
 *   - the money journal sums to zero
 */

use App\Infra\Db;
use App\Support\ApiClient;
use App\Support\Cli;
use App\Support\WebhookSender;

require __DIR__ . '/../vendor/autoload.php';

$args        = Cli::args($argv);
$orderCount  = (int) ($args['orders'] ?? 40);
$webhooks    = (int) ($args['webhooks'] ?? 5);
$failRate    = (float) ($args['fail-rate'] ?? 0.4);
$timeoutRate = (float) ($args['timeout-rate'] ?? 0.25);
$waitSeconds = (float) ($args['wait'] ?? 180);

$api      = ApiClient::api();
$hooks    = WebhookSender::default();
$suppliers = ['A' => ApiClient::supplier('A'), 'B' => ApiClient::supplier('B')];

$api->waitForHealth(60);

foreach ($suppliers as $client) {
    $client->post('/_control', [
        'down'              => false,
        'out_of_stock'      => false,
        'fail_rate'         => $failRate,
        'timeout_rate'      => $timeoutRate,
        'latency_ms'        => 0,
        'hang_ms'           => 2500,
        'hang_only_first'   => true,
        'issue_before_hang' => true,
    ]);
}

Cli::head(sprintf(
    'chaos: %d orders, %d webhooks each, fail_rate=%.2f timeout_rate=%.2f on BOTH suppliers',
    $orderCount,
    $webhooks,
    $failRate,
    $timeoutRate
));

$skus   = ['KEY-CS2-PRIME', 'KEY-GTA5', 'STEAM-TOPUP-500', 'SUB-DISCORD-1M', 'GIFT-XBOX-1500'];
$orders = [];

$started = microtime(true);
for ($i = 0; $i < $orderCount; $i++) {
    $order = $api->post('/orders', ['sku' => $skus[$i % count($skus)]]);
    if (isset($order['id'])) {
        $orders[] = $order;
    }
}
Cli::info(sprintf('%d orders created in %d ms', count($orders), (int) ((microtime(true) - $started) * 1000)));

// Pay everything at once, each order redelivered `--webhooks` times.
$payloads = [];
foreach ($orders as $order) {
    foreach (WebhookSender::payloads((string) $order['id'], (float) $order['amount'], $webhooks, 'same') as $p) {
        $payloads[] = $p;
    }
}
shuffle($payloads);

$stats = $hooks->sendConcurrently($payloads);
Cli::info(sprintf(
    '%d webhooks in %d ms; http=%s results=%s',
    count($payloads),
    $stats['elapsed_ms'],
    json_encode($stats['http']),
    json_encode($stats['results'])
));

// ---- wait for the system to settle ----------------------------------
$ids      = array_column($orders, 'id');
$deadline = microtime(true) + $waitSeconds;
$delivered = 0;

do {
    $delivered = (int) Db::value(
        "SELECT count(*) FROM orders WHERE id = ANY(string_to_array(:ids, ',')) AND status = 'delivered'",
        ['ids' => implode(',', $ids)]
    );
    if ($delivered === count($ids)) {
        break;
    }
    usleep(500_000);
} while (microtime(true) < $deadline);

Cli::info(sprintf('%d/%d delivered after %d s', $delivered, count($ids), (int) (microtime(true) - $started)));

// ---- invariants ------------------------------------------------------
$failures = 0;
$check = function (bool $ok, string $what) use (&$failures): void {
    if ($ok) {
        Cli::ok($what);
    } else {
        $failures++;
        Cli::fail($what);
    }
};

Cli::head('invariants');

$byStatus = Db::all(
    "SELECT status, count(*) AS n FROM orders WHERE id = ANY(string_to_array(:ids, ','))
     GROUP BY status ORDER BY status",
    ['ids' => implode(',', $ids)]
);
Cli::info('statuses: ' . json_encode(array_combine(
    array_column($byStatus, 'status'),
    array_map('intval', array_column($byStatus, 'n'))
)));

$check($delivered === count($ids), 'every paid order was delivered');
$check(
    (int) Db::value(
        "SELECT count(*) FROM (SELECT order_id FROM deliveries GROUP BY order_id HAVING count(*) > 1) x"
    ) === 0,
    'no order has more than one delivery'
);
$check(
    (int) Db::value('SELECT count(*) FROM (SELECT code FROM deliveries GROUP BY code HAVING count(*) > 1) x') === 0,
    'no key was issued to two orders'
);
$check(
    (int) Db::value('SELECT COALESCE(sum(amount_minor), 0) FROM ledger_entries') === 0,
    'the money journal sums to zero'
);
$check((int) Db::value('SELECT count(*) FROM ledger_imbalance') === 0, 'no unbalanced transaction');
$check(
    (int) Db::value("SELECT count(*) FROM supplier_requests WHERE state = 'unknown'") === 0,
    'no supplier request left in an unknown state'
);

$attempts = Db::all(
    'SELECT supplier, outcome, count(*) AS n FROM delivery_attempts GROUP BY supplier, outcome ORDER BY supplier, outcome'
);
Cli::head('supplier attempts (the chaos that was survived)');
foreach ($attempts as $row) {
    Cli::line(sprintf('    %s / %-13s %s', $row['supplier'], $row['outcome'], $row['n']));
}

foreach ($suppliers as $client) {
    $client->post('/_control', ['fail_rate' => 0, 'timeout_rate' => 0]);
}

Cli::line();
exit($failures === 0 ? 0 : 1);
