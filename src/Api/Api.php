<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Infra\Db;
use App\Service\CatalogService;
use App\Service\DeliveryService;
use App\Service\JobQueue;
use App\Service\OrderService;
use App\Service\PaymentWebhookService;
use App\Service\ReconciliationService;
use App\Service\RecoveryService;
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
