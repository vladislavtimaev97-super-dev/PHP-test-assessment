<?php

declare(strict_types=1);

/**
 * A guided tour of one order: create -> pay -> auto delivery -> audit.
 *
 *   php bin/demo.php [--sku=KEY-CS2-PRIME]
 */

use App\Support\ApiClient;
use App\Support\Cli;
use App\Support\WebhookSender;

require __DIR__ . '/../vendor/autoload.php';

$args = Cli::args($argv);
$sku  = $args['sku'] ?? 'KEY-CS2-PRIME';

$api = ApiClient::api();
$api->waitForHealth(60);

$pretty = static fn (array $data): string => (string) json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

Cli::head("1. POST /orders  {\"sku\": \"{$sku}\"}");
$order = $api->post('/orders', ['sku' => $sku]);
Cli::line($pretty($order));

$orderId = (string) $order['id'];

Cli::head('2. the payment provider calls our webhook');
$stats = (new WebhookSender((string) getenv('API_URL') . '/webhooks/payment'))
    ->sendConcurrently(WebhookSender::payloads($orderId, (float) $order['amount'], 1));
Cli::line('   ' . json_encode($stats['results']));

Cli::head('3. GET /orders/' . $orderId . '  (after the background worker delivered it)');
$deadline = microtime(true) + 30;
do {
    usleep(300_000);
    $view = $api->get('/orders/' . $orderId);
} while (($view['status'] ?? '') !== 'delivered' && microtime(true) < $deadline);
Cli::line($pretty($view));

Cli::head('4. GET /orders/' . $orderId . '/audit  (what actually happened)');
$audit = $api->get('/orders/' . $orderId . '/audit');
Cli::line('   status history:');
foreach ($audit['history'] as $row) {
    Cli::line(sprintf('     %s -> %-12s %s', $row['from_status'] ?? 'null', $row['to_status'], $row['reason']));
}
Cli::line('   supplier calls:');
foreach ($audit['attempts'] as $row) {
    Cli::line(sprintf(
        '     %s attempt=%s outcome=%-12s %sms  %s',
        $row['supplier'],
        $row['attempt_no'],
        $row['outcome'],
        $row['duration_ms'],
        $row['request_id']
    ));
}
Cli::line('   money journal:');
foreach ($audit['ledger'] as $row) {
    Cli::line(sprintf('     %-22s %-18s %s', $row['entry_type'], $row['account'], $row['amount_minor']));
}
Cli::line();
