<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Infra\Db;
use App\Service\CatalogService;
use App\Service\DeliveryService;
use App\Service\HistoryService;
use App\Service\JobQueue;
use App\Service\OrderService;
use App\Service\PaymentWebhookService;
use App\Service\ReconciliationService;
use App\Service\RecoveryService;
use App\Service\SupplierRateLimiter;
use PDO;

final class Api
{
    public static function router(): Router
    {
        $r = new Router();

        $r->get('/health', static function (): Response {
            Db::value('SELECT 1');

            return Response::json(['status' => 'ok', 'time' => gmdate('c')]);
        });

        // ---------------- catalog (stage 5) ----------------------------
        $r->get('/catalog', static function (Request $req): Response {
            $catalog = new CatalogService();

            return Response::json($catalog->showcase(
                $req->queryParam('type'),
                $req->queryParam('cursor'),
                (int) ($req->queryParam('limit') ?? '20')
            ));
        });

        $r->get('/catalog/search', static function (Request $req): Response {
            $q = $req->queryParam('q') ?? '';
            if (trim($q) === '') {
                throw new \InvalidArgumentException('Query parameter "q" is required');
            }

            return Response::json(['items' => (new CatalogService())->search($q)]);
        });

        $r->get('/catalog/{sku}', static function (Request $req): Response {
            $product = (new CatalogService())->find($req->params['sku']);

            return $product === null
                ? Response::error(404, 'sku_not_found', 'Unknown SKU')
                : Response::json($product);
        });

        // ---------------- orders (stage 1) ------------------------------
        $r->post('/orders', static function (Request $req): Response {
            [$status, $body] = (new OrderService())->create(
                $req->body,
                $req->header('idempotency-key')
            );

            return Response::json($body, $status);
        });

        $r->get('/orders/{id}', static function (Request $req): Response {
            $order = (new OrderService())->find($req->params['id']);

            return $order === null
                ? Response::error(404, 'order_not_found', 'Unknown order')
                : Response::json($order);
        });

        $r->get('/orders/{id}/audit', static function (Request $req): Response {
            $audit = (new OrderService())->audit($req->params['id']);

            return $audit['order'] === null
                ? Response::error(404, 'order_not_found', 'Unknown order')
                : Response::json($audit);
        });

        // Stage 2, bonus #4: exact order/money state at any past instant.
        $r->get('/orders/{id}/history', static function (Request $req): Response {
            $at = $req->queryParam('at');
            if ($at === null || trim($at) === '') {
                throw new \InvalidArgumentException('Query parameter "at" (ISO-8601) is required');
            }
            try {
                $instant = new \DateTimeImmutable($at);
            } catch (\Exception) {
                throw new \InvalidArgumentException('Query parameter "at" must be an ISO-8601 timestamp');
            }

            $snapshot = (new HistoryService())->orderAsOf($req->params['id'], $instant);

            return $snapshot === null
                ? Response::error(404, 'order_not_found', 'Unknown order')
                : Response::json($snapshot);
        });

        // Manual recovery hook: safe to call at any time, any number of times.
        $r->post('/orders/{id}/deliver', static function (Request $req): Response {
            $orderId = $req->params['id'];

            if ($req->input('mode') === 'sync') {
                return Response::json((new DeliveryService())->deliver($orderId));
            }

            $queued = Db::transaction(
                static fn (PDO $pdo) => JobQueue::enqueue($pdo, JobQueue::DELIVER_ORDER, $orderId)
            );

            return Response::json(['result' => $queued ? 'queued' : 'already_queued']);
        });

        // ---------------- payments (stage 1 + 2) ------------------------
        $r->post('/webhooks/payment', static function (Request $req): Response {
            [$status, $body] = (new PaymentWebhookService())->ingest($req->body);

            return Response::json($body, $status);
        });

        // ---------------- ops (stage 4) ---------------------------------
        $r->get('/admin/reconciliation', static function (Request $req): Response {
            $grace = $req->queryParam('grace_seconds');

            return Response::json(
                (new ReconciliationService())->report($grace === null ? null : (int) $grace)
            );
        });

        $r->post('/admin/sweep', static function (): Response {
            return Response::json((new RecoveryService())->sweep());
        });

        // Stage 2, bonus #4: ledger totals for a period, straight from the
        // append-only journal — "итоги за период считаются из этой истории".
        $r->get('/admin/ledger/period', static function (Request $req): Response {
            $from = $req->queryParam('from');
            $to   = $req->queryParam('to');
            if ($from === null || $to === null) {
                throw new \InvalidArgumentException('Query parameters "from" and "to" (ISO-8601) are required');
            }
            try {
                $fromAt = new \DateTimeImmutable($from);
                $toAt   = new \DateTimeImmutable($to);
            } catch (\Exception) {
                throw new \InvalidArgumentException('"from"/"to" must be ISO-8601 timestamps');
            }

            return Response::json((new HistoryService())->periodTotals($fromAt, $toAt));
        });

        // Stage 2, bonus #3: current queue depth/throughput and the supplier
        // rate-limit bucket state — "виден прогресс".
        $r->get('/admin/queue', static function (): Response {
            return Response::json([
                'jobs_by_status'   => Db::all(
                    "SELECT status, count(*) AS n FROM jobs WHERE type = 'deliver_order' GROUP BY status ORDER BY status"
                ),
                'jobs_by_priority' => Db::all(
                    "SELECT priority, status, count(*) AS n FROM jobs
                     WHERE type = 'deliver_order' AND status IN ('queued','running')
                     GROUP BY priority, status ORDER BY priority, status"
                ),
                'oldest_queued_seconds' => Db::value(
                    "SELECT EXTRACT(EPOCH FROM (now() - min(run_after)))
                     FROM jobs WHERE type = 'deliver_order' AND status = 'queued' AND run_after <= now()"
                ),
                'delivered_last_minute' => (int) Db::value(
                    "SELECT count(*) FROM deliveries WHERE delivered_at > now() - interval '1 minute'"
                ),
                'delivered_total'       => (int) Db::value('SELECT count(*) FROM deliveries'),
                'supplier_rate_limits'  => SupplierRateLimiter::peekAll(),
            ]);
        });

        // Test/demo helper: configure the core's own outbound throttle for a
        // supplier (stage 2, bonus #3). Not part of the customer-facing API.
        $r->post('/admin/supplier-rate-limit', static function (Request $req): Response {
            $supplier = is_string($req->body['supplier'] ?? null) ? $req->body['supplier'] : '';
            if ($supplier === '' || !is_numeric($req->body['capacity'] ?? null) || !is_numeric($req->body['refill_per_sec'] ?? null)) {
                throw new \InvalidArgumentException('Fields "supplier", "capacity", "refill_per_sec" are required');
            }
            SupplierRateLimiter::configure($supplier, (float) $req->body['capacity'], (float) $req->body['refill_per_sec']);

            return Response::json(SupplierRateLimiter::peek($supplier) ?? ['supplier' => $supplier]);
        });

        $r->get('/admin/jobs', static function (): Response {
            return Response::json([
                'jobs' => Db::all(
                    'SELECT id, type, order_id, status, attempts, run_after, last_error
                     FROM jobs ORDER BY id DESC LIMIT 50'
                ),
            ]);
        });

        $r->get('/metrics', static function (): Response {
            return Response::json([
                'orders_by_status' => Db::all(
                    'SELECT status, count(*) AS n FROM orders GROUP BY status ORDER BY status'
                ),
                'items_by_status'  => Db::all(
                    'SELECT status, count(*) AS n FROM order_items GROUP BY status ORDER BY status'
                ),
                'deliveries'       => (int) Db::value('SELECT count(*) FROM deliveries'),
                'webhooks'         => (int) Db::value('SELECT count(*) FROM webhook_events'),
                'webhook_outcomes' => Db::all(
                    'SELECT outcome, count(*) AS n FROM webhook_events GROUP BY outcome ORDER BY outcome'
                ),
                'supplier_attempts' => Db::all(
                    'SELECT supplier, outcome, count(*) AS n, round(avg(duration_ms)) AS avg_ms
                     FROM delivery_attempts GROUP BY supplier, outcome ORDER BY supplier, outcome'
                ),
                'jobs_by_status'   => Db::all(
                    'SELECT status, count(*) AS n FROM jobs GROUP BY status ORDER BY status'
                ),
            ]);
        });

        return $r;
    }
}
