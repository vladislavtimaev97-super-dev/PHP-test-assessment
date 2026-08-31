<?php

declare(strict_types=1);

/**
 * Payment-gateway stub.
 *
 * The same script sends a single webhook and fires a concurrent storm of them,
 * exactly as the task asks. Requests are started with curl_multi, so they hit
 * the API in the same instant rather than one after another.
 *
 * Examples:
 *   php bin/pay.php --order=ord_00123 --amount=1290
 *   php bin/pay.php --order=ord_00123 --amount=1290 --concurrency=50 --mode=distinct
 *   php bin/pay.php --order=ord_00123 --amount=1290 --concurrency=50 --mode=same
 *   php bin/pay.php --order=ord_00999 --amount=500  --status=failed
 *
 * --mode=same      N copies of ONE event_id       (at-least-once redelivery)
 * --mode=distinct  N different events, one order  (parallel racing events)
 */

use App\Support\Cli;
use App\Support\Env;
use App\Support\WebhookSender;

require __DIR__ . '/../vendor/autoload.php';

$args = Cli::args($argv);

$orderId = $args['order'] ?? null;
$amount  = (float) ($args['amount'] ?? 0);

if ($orderId === null || $amount <= 0) {
    Cli::line('usage: php bin/pay.php --order=ord_00123 --amount=1290 [--status=paid|failed]');
    Cli::line('                       [--concurrency=50] [--mode=same|distinct] [--event-id=evt_x]');
    Cli::line('                       [--created-at=2025-01-01T12:00:00Z] [--url=...]');
    exit(2);
}

$url = $args['url'] ?? (Env::get('API_URL', 'http://api:8080') . '/webhooks/payment');

$payloads = WebhookSender::payloads(
    $orderId,
    $amount,
    (int) ($args['concurrency'] ?? 1),
    $args['mode']       ?? 'same',
    $args['status']     ?? 'paid',
    $args['event-id']   ?? null,
    $args['created-at'] ?? null,
    $args['currency']   ?? 'RUB',
);

$stats = (new WebhookSender($url))->sendConcurrently($payloads);

Cli::line(sprintf(
    'sent %d webhook(s) status=%s mode=%s to %s in %d ms',
    count($payloads),
    $args['status'] ?? 'paid',
    $args['mode'] ?? 'same',
    $url,
    $stats['elapsed_ms']
));
foreach ($stats['http'] as $code => $n) {
    Cli::line("  HTTP {$code}: {$n}");
}
foreach ($stats['results'] as $result => $n) {
    Cli::line("  result {$result}: {$n}");
}
if ($stats['errors'] > 0) {
    Cli::line("  transport errors: {$stats['errors']}");
}

exit($stats['errors'] > 0 || ($stats['http'][200] ?? 0) !== count($payloads) ? 1 : 0);
