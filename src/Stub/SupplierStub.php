<?php

declare(strict_types=1);

namespace App\Stub;

use App\Http\Request;
use App\Http\Response;
use App\Infra\Db;
use App\Infra\Log;
use App\Support\Env;
use PDO;

/**
 * Fake supplier.
 *
 * Contract:
 *   POST /issue              {request_id, sku, order_id} -> {status, request_id, code}
 *   GET  /issue/{request_id} reconcile probe:
 *                            200 -> a code exists for this request_id
 *                            404 -> AUTHORITATIVE "no code exists" (never seen,
 *                                   or the attempt definitively failed)
 *                            409 -> still being processed, ask again
 *   GET  /stock              {supplier, free, issued}
 *   POST /_control           failure injection knobs (see migrations/002)
 *   POST /_reset             restock + forget requests (test helper)
 *
 * The important behaviour: a repeated request_id ALWAYS returns the same code.
 * A "timeout" is implemented as *issue the key, then hang* — the code exists,
 * the answer just never reaches the caller. That is the trap stage 3 is about.
 */
final class SupplierStub
{
    public function __construct(private readonly string $supplier)
    {
    }

    public static function fromEnv(): self
    {
        return new self((string) Env::get('SUPPLIER_NAME', 'A'));
    }

    public function issue(Request $req): Response
    {
        $requestId = is_string($req->input('request_id')) ? trim((string) $req->input('request_id')) : '';
        if ($requestId === '') {
            return Response::json(['status' => 'error', 'reason' => 'request_id_required'], 400);
        }
        $sku     = (string) ($req->input('sku') ?? '');
        $orderId = (string) ($req->input('order_id') ?? '');

        $cfg = $this->config();

        // ---- 1. idempotency wins over everything, including failure injection
        $existing = $this->findRequest($requestId);
        if ($existing !== null && $existing['outcome'] !== 'pending') {
            return $this->replay($existing, $cfg);
        }
        if ($existing !== null) {
            return Response::json(['status' => 'pending', 'request_id' => $requestId], 409);
        }

        // ---- stage 2, #3: the supplier's own rate limit. Only genuinely NEW
        // demand counts — an idempotent replay above never reaches here.
        if ($cfg['rate_limit_per_min'] > 0 && !$this->consumeRateLimitSlot($cfg['rate_limit_per_min'])) {
            $this->log('stub_rate_limited', $requestId, ['order_id' => $orderId]);

            return Response::json(['status' => 'error', 'reason' => 'rate_limited'], 429);
        }

        if ($cfg['down']) {
            $this->log('stub_down', $requestId, ['order_id' => $orderId]);

            return Response::json(['status' => 'error', 'reason' => 'supplier_down'], 503);
        }

        if ($cfg['latency_ms'] > 0) {
            usleep($cfg['latency_ms'] * 1000);
        }

        // ---- 2. random hard failure: nothing is recorded, nothing was issued
        if ($this->roll($cfg['fail_rate'])) {
            $this->log('stub_random_failure', $requestId, ['order_id' => $orderId]);

            return Response::json(['status' => 'error', 'reason' => 'internal_error'], 500);
        }

        // ---- 3. the trap: hang, having (or not having) issued the code ------
        if ($this->roll($cfg['timeout_rate'])) {
            if ($cfg['issue_before_hang']) {
                $claimed = $this->claim($requestId, $sku, $orderId);
                if ($claimed) {
                    $result = $this->reserveKey($requestId, $sku, $orderId, $cfg['out_of_stock']);
                    $this->log('stub_hang_after_issue', $requestId, [
                        'order_id' => $orderId,
                        'outcome'  => $result['outcome'],
                        'note'     => 'code committed, answer will not arrive in time',
                    ]);
                    // Committed BEFORE sleeping: a probe or a retry with the same
                    // request_id resolves it while this request is still hanging.
                    $this->sleepMs($cfg['hang_ms']);

                    return $this->replay($this->findRequest($requestId) ?? [], $cfg);
                }
            }

            $this->log('stub_hang_without_issue', $requestId, ['order_id' => $orderId]);
            $this->sleepMs($cfg['hang_ms']);
            // Record the definitive failure only now, so that a retry arriving
            // *during* the hang stays unknown, while one arriving after it can
            // be resolved.
            $this->recordFailure($requestId, $sku, $orderId, 'timeout_no_issue');

            return Response::json(['status' => 'error', 'reason' => 'timeout_no_issue'], 504);
        }

        // ---- 4. normal path -------------------------------------------------
        if (!$this->claim($requestId, $sku, $orderId)) {
            $again = $this->findRequest($requestId);

            return $again === null || $again['outcome'] === 'pending'
                ? Response::json(['status' => 'pending', 'request_id' => $requestId], 409)
                : $this->replay($again, $cfg);
        }

        $result = $this->reserveKey($requestId, $sku, $orderId, $cfg['out_of_stock'], $cfg['duplicate_code_rate']);

        if ($result['outcome'] === 'ok') {
            // ---- stage 2, #2: lie about the error AFTER really issuing ----
            // The code is genuinely committed in stub.requests/stub.keys; we
            // just answer as if we had not issued it. An idempotent retry or
            // a probe on this same request_id reveals the truth — the same
            // mechanism the caller already needs for a real timeout.
            if ($this->roll($cfg['lie_about_error_rate'])) {
                $this->log('stub_lie_about_error', $requestId, [
                    'order_id' => $orderId, 'code' => $result['code'],
                    'note'     => 'code committed, answering with an error anyway',
                ]);

                return Response::json(['status' => 'error', 'reason' => 'injected_lie'], 500);
            }

            $this->log('stub_issued', $requestId, ['order_id' => $orderId, 'code' => $result['code']]);

            return Response::json([
                'status'     => 'ok',
                'request_id' => $requestId,
                'code'       => $result['code'],
            ]);
        }

        $this->log('stub_out_of_stock', $requestId, ['order_id' => $orderId]);

        return Response::json(['status' => 'error', 'reason' => $result['reason']], 409);
    }

    /** The reconcile probe. 404 is an authoritative "nothing was issued". */
    public function probe(Request $req): Response
    {
        $requestId = $req->params['request_id'] ?? '';
        $row       = $this->findRequest($requestId);

        if ($row === null) {
            return Response::json([
                'status'     => 'not_found',
                'request_id' => $requestId,
                'reason'     => 'no code was issued for this request_id',
            ], 404);
        }
        if ($row['outcome'] === 'pending') {
            return Response::json(['status' => 'pending', 'request_id' => $requestId], 409);
        }
        if ($row['outcome'] === 'ok') {
            return Response::json([
                'status'     => 'ok',
                'request_id' => $requestId,
                'code'       => $row['code'],
                'issued_at'  => $row['created_at'],
            ]);
        }

        return Response::json([
            'status'     => 'not_found',
            'request_id' => $requestId,
            'reason'     => $row['reason'] ?? 'failed',
        ], 404);
    }

    public function stock(Request $req): Response
    {
        $free = (int) Db::value(
            "SELECT count(*) FROM stub.keys WHERE supplier = :s AND status = 'free'",
            ['s' => $this->supplier]
        );
        $issued = (int) Db::value(
            "SELECT count(*) FROM stub.keys WHERE supplier = :s AND status = 'issued'",
            ['s' => $this->supplier]
        );

        return Response::json([
            'supplier' => $this->supplier,
            'free'     => $this->config()['out_of_stock'] ? 0 : $free,
            'issued'   => $issued,
        ]);
    }

    public function getControl(Request $req): Response
    {
        return Response::json($this->config());
    }

    public function setControl(Request $req): Response
    {
        $allowed = [
            'down', 'out_of_stock', 'fail_rate', 'timeout_rate',
            'latency_ms', 'hang_ms', 'hang_only_first', 'issue_before_hang',
            'duplicate_code_rate', 'lie_about_error_rate', 'rate_limit_per_min',
        ];

        $sets   = [];
        $params = ['s' => $this->supplier];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $req->body)) {
                $sets[]           = "{$field} = :{$field}";
                $params[$field]   = is_bool($req->body[$field])
                    ? ($req->body[$field] ? 'true' : 'false')
                    : $req->body[$field];
            }
        }

        $this->config();                                   // make sure the row exists
        if ($sets !== []) {
            Db::run(
                'UPDATE stub.config SET ' . implode(', ', $sets) . ', updated_at = now() WHERE supplier = :s',
                $params
            );
        }

        $cfg = $this->config();
        Log::info('stub_control_updated', ['supplier' => $this->supplier] + $cfg);

        return Response::json($cfg);
    }

    /**
     * Test helper: restore the default (healthy) behaviour.
     *
     * Keys are only returned to the pool with {"keys": true} — a code that was
     * already delivered to a customer must not silently become issuable again,
     * so scenario scripts reset the behaviour but never the pool.
     */
    public function reset(Request $req): Response
    {
        $resetKeys = (bool) ($req->input('keys') ?? false);

        Db::transaction(function (PDO $pdo) use ($resetKeys) {
            if ($resetKeys) {
                $pdo->prepare('DELETE FROM stub.requests WHERE supplier = :s')
                    ->execute(['s' => $this->supplier]);
                $pdo->prepare(
                    "UPDATE stub.keys SET status = 'free', request_id = NULL, issued_at = NULL
                     WHERE supplier = :s"
                )->execute(['s' => $this->supplier]);
            }
            $pdo->prepare(
                "UPDATE stub.config SET down = false, out_of_stock = false, fail_rate = 0,
                        timeout_rate = 0, latency_ms = 0, hang_ms = 5000,
                        hang_only_first = true, issue_before_hang = true,
                        duplicate_code_rate = 0, lie_about_error_rate = 0, rate_limit_per_min = 0,
                        updated_at = now()
                 WHERE supplier = :s"
            )->execute(['s' => $this->supplier]);
        });

        Log::info('stub_reset', ['supplier' => $this->supplier]);

        return $this->stock($req);
    }

    // -----------------------------------------------------------------
    // internals
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $cfg */
    private function replay(array $row, array $cfg): Response
    {
        if (($row['outcome'] ?? null) === 'ok') {
            // hang_only_first = false => a black hole: even replays hang.
            if (!$cfg['hang_only_first'] && $cfg['timeout_rate'] > 0) {
                $this->sleepMs($cfg['hang_ms']);
            }
            $this->log('stub_replay', (string) $row['request_id'], ['code' => $row['code']]);

            return Response::json([
                'status'     => 'ok',
                'request_id' => $row['request_id'],
                'code'       => $row['code'],
                'replayed'   => true,
            ]);
        }

        return Response::json([
            'status'     => 'error',
            'request_id' => $row['request_id'] ?? null,
            'reason'     => $row['reason'] ?? 'failed',
            'replayed'   => true,
        ], ($row['reason'] ?? '') === 'out_of_stock' ? 409 : 500);
    }

    /** Reserve the request_id. Returns false if somebody else already has it. */
    private function claim(string $requestId, string $sku, string $orderId): bool
    {
        $st = Db::run(
            "INSERT INTO stub.requests (supplier, request_id, sku, order_id, outcome)
             VALUES (:s, :r, :sku, :o, 'pending')
             ON CONFLICT (supplier, request_id) DO NOTHING
             RETURNING request_id",
            ['s' => $this->supplier, 'r' => $requestId, 'sku' => $sku, 'o' => $orderId]
        );

        return $st->fetchColumn() !== false;
    }

    /**
     * Take one key out of the pool and bind it to this request_id, atomically.
     *
     * @return array{outcome:string,code:?string,reason:?string}
     */
    private function reserveKey(
        string $requestId,
        string $sku,
        string $orderId,
        bool $forceEmpty,
        float $duplicateCodeRate = 0.0,
    ): array {
        // ---- stage 2, #2: a supplier that cannot be trusted -----------------
        // Instead of reserving a fresh key, hand back one that is already
        // bound to a DIFFERENT request_id. This is a real bug on the
        // supplier's side, deliberately injected: from the core's point of
        // view it looks exactly like "same code twice" or "someone else's
        // code" — both are just a code that is not exclusively ours, and
        // both are caught the same way, by deliveries.code UNIQUE.
        if (!$forceEmpty && $this->roll($duplicateCodeRate)) {
            $stolen = Db::value(
                "SELECT code FROM stub.keys WHERE supplier = :s AND status = 'issued'
                 ORDER BY random() LIMIT 1",
                ['s' => $this->supplier]
            );
            if ($stolen !== null) {
                Db::run(
                    "UPDATE stub.requests SET outcome = 'ok', code = :c, reason = NULL
                     WHERE supplier = :s AND request_id = :r",
                    ['c' => $stolen, 's' => $this->supplier, 'r' => $requestId]
                );
                $this->log('stub_duplicate_code_injected', $requestId, ['code' => $stolen]);

                return ['outcome' => 'ok', 'code' => (string) $stolen, 'reason' => null];
            }
            // No issued key exists yet (e.g. the very first request ever):
            // fall through to a normal, honest reservation.
        }

        // `FOR UPDATE SKIP LOCKED ... LIMIT 1` can come back empty even when the
        // pool is not: the single candidate row may have been taken and
        // committed by a concurrent request, and Postgres then filters it out
        // *after* the LIMIT was satisfied. Retry a few times before believing
        // that we are out of stock, otherwise a busy supplier lies about its
        // own inventory.
        for ($try = 1; $try <= 5 && !$forceEmpty; $try++) {
            $code = Db::transaction(function (PDO $pdo) use ($requestId): ?string {
                $st = $pdo->prepare(
                    "UPDATE stub.keys k
                     SET status = 'issued', request_id = :r, issued_at = now()
                     FROM (
                         SELECT id FROM stub.keys
                         WHERE supplier = :s AND status = 'free'
                         ORDER BY id
                         FOR UPDATE SKIP LOCKED
                         LIMIT 1
                     ) picked
                     WHERE k.id = picked.id
                     RETURNING k.code"
                );
                $st->execute(['r' => $requestId, 's' => $this->supplier]);
                $found = $st->fetchColumn();

                return $found === false ? null : (string) $found;
            });

            if ($code !== null) {
                Db::run(
                    "UPDATE stub.requests SET outcome = 'ok', code = :c, reason = NULL
                     WHERE supplier = :s AND request_id = :r",
                    ['c' => $code, 's' => $this->supplier, 'r' => $requestId]
                );

                return ['outcome' => 'ok', 'code' => $code, 'reason' => null];
            }

            $free = (int) Db::value(
                "SELECT count(*) FROM stub.keys WHERE supplier = :s AND status = 'free'",
                ['s' => $this->supplier]
            );
            if ($free === 0) {
                break;                                   // genuinely empty
            }
            usleep(random_int(2_000, 15_000));           // contention, not scarcity
        }

        Db::run(
            "UPDATE stub.requests SET outcome = 'error', reason = 'out_of_stock'
             WHERE supplier = :s AND request_id = :r",
            ['s' => $this->supplier, 'r' => $requestId]
        );

        return ['outcome' => 'error', 'code' => null, 'reason' => 'out_of_stock'];
    }

    private function recordFailure(string $requestId, string $sku, string $orderId, string $reason): void
    {
        Db::run(
            "INSERT INTO stub.requests (supplier, request_id, sku, order_id, outcome, reason)
             VALUES (:s, :r, :sku, :o, 'error', :reason)
             ON CONFLICT (supplier, request_id) DO NOTHING",
            [
                's' => $this->supplier, 'r' => $requestId, 'sku' => $sku,
                'o' => $orderId, 'reason' => $reason,
            ]
        );
    }

    /** @return array<string,mixed>|null */
    private function findRequest(string $requestId): ?array
    {
        return Db::one(
            'SELECT * FROM stub.requests WHERE supplier = :s AND request_id = :r',
            ['s' => $this->supplier, 'r' => $requestId]
        );
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        Db::run(
            'INSERT INTO stub.config (supplier) VALUES (:s) ON CONFLICT (supplier) DO NOTHING',
            ['s' => $this->supplier]
        );

        /** @var array<string,mixed> $row */
        $row = Db::one('SELECT * FROM stub.config WHERE supplier = :s', ['s' => $this->supplier]);

        return [
            'supplier'             => $this->supplier,
            'down'                 => (bool) $row['down'],
            'out_of_stock'         => (bool) $row['out_of_stock'],
            'fail_rate'            => (float) $row['fail_rate'],
            'timeout_rate'         => (float) $row['timeout_rate'],
            'latency_ms'           => (int) $row['latency_ms'],
            'hang_ms'              => (int) $row['hang_ms'],
            'hang_only_first'      => (bool) $row['hang_only_first'],
            'issue_before_hang'    => (bool) $row['issue_before_hang'],
            'duplicate_code_rate'  => (float) $row['duplicate_code_rate'],
            'lie_about_error_rate' => (float) $row['lie_about_error_rate'],
            'rate_limit_per_min'   => (int) $row['rate_limit_per_min'],
        ];
    }

    /**
     * Sliding 60s window over stub.call_log. True = a slot was consumed and
     * the caller may proceed; false = the agreed rate would be exceeded.
     * Only genuinely new demand reaches this (idempotent replays never do),
     * so this is exactly "requests per minute", not "HTTP calls per minute".
     */
    private function consumeRateLimitSlot(int $perMinute): bool
    {
        return (bool) Db::value(
            "WITH recent AS (
                 SELECT count(*) AS n FROM stub.call_log
                 WHERE supplier = :s AND called_at > now() - interval '60 seconds'
             )
             INSERT INTO stub.call_log (supplier)
             SELECT :s FROM recent WHERE recent.n < :limit
             RETURNING true",
            ['s' => $this->supplier, 'limit' => $perMinute]
        );
    }

    private function roll(float $probability): bool
    {
        if ($probability <= 0) {
            return false;
        }
        if ($probability >= 1) {
            return true;
        }

        return (random_int(0, 999_999) / 1_000_000) < $probability;
    }

    private function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep(min($ms, 60_000) * 1000);
        }
    }

    /** @param array<string,mixed> $ctx */
    private function log(string $event, string $requestId, array $ctx = []): void
    {
        Log::info($event, ['supplier' => $this->supplier, 'request_id' => $requestId] + $ctx);
    }
}
