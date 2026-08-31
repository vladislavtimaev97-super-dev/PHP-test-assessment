<?php

declare(strict_types=1);

namespace App\Service;

use App\Infra\Db;
use App\Infra\Log;
use PDO;

/**
 * Minimal Postgres-backed queue.
 *
 * Why not Redis/RabbitMQ: the job must be enqueued in the SAME transaction
 * that flips the order to `paid`. With an external broker you get the classic
 * dual-write problem (committed payment, lost job, or job for a rolled-back
 * payment). One database, one transaction, no outbox relay needed.
 *
 * Claiming uses FOR UPDATE SKIP LOCKED, so N workers scale linearly without
 * ever handing the same job to two of them.
 */
final class JobQueue
{
    public const DELIVER_ORDER = 'deliver_order';

    /**
     * Enqueue at most one live job per (type, order). Enforced by a partial
     * unique index, so 50 concurrent webhooks produce exactly one job.
     */
    public static function enqueue(
        PDO $pdo,
        string $type,
        string $orderId,
        float $delaySeconds = 0.0,
        ?string $traceId = null,
    ): bool {
        $st = $pdo->prepare(
            "INSERT INTO jobs (type, order_id, run_after, trace_id)
             VALUES (:type, :order_id, now() + make_interval(secs => CAST(:delay AS double precision)), :trace)
             ON CONFLICT (type, order_id) WHERE status IN ('queued','running') DO NOTHING
             RETURNING id"
        );
        $st->execute([
            'type'     => $type,
            'order_id' => $orderId,
            'delay'    => $delaySeconds,
            'trace'    => $traceId ?? Log::traceId(),
        ]);

        return $st->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public static function claim(string $workerId): ?array
    {
        return Db::one(
            "UPDATE jobs SET status = 'running',
                             locked_by = :w,
                             locked_at = now(),
                             attempts = attempts + 1,
                             updated_at = now()
             WHERE id = (
                 SELECT id FROM jobs
                 WHERE status = 'queued' AND run_after <= now()
                 ORDER BY run_after, id
                 FOR UPDATE SKIP LOCKED
                 LIMIT 1
             )
             RETURNING *",
            ['w' => $workerId]
        );
    }

    public static function complete(int $jobId): void
    {
        Db::run("UPDATE jobs SET status = 'done', locked_by = NULL, updated_at = now() WHERE id = :id",
            ['id' => $jobId]);
    }

    /** Reschedule with exponential backoff (capped), or bury after max_attempts. */
    public static function retryLater(int $jobId, string $reason, ?float $delaySeconds = null): void
    {
        $job = Db::one('SELECT attempts, max_attempts FROM jobs WHERE id = :id', ['id' => $jobId]);
        if ($job === null) {
            return;
        }

        if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
            Db::run(
                "UPDATE jobs SET status = 'failed', locked_by = NULL, last_error = :e, updated_at = now()
                 WHERE id = :id",
                ['id' => $jobId, 'e' => $reason]
            );
            Log::error('job_buried', ['job_id' => $jobId, 'reason' => $reason]);

            return;
        }

        $delay = $delaySeconds ?? self::backoffSeconds((int) $job['attempts']);
        Db::run(
            "UPDATE jobs SET status = 'queued', locked_by = NULL, last_error = :e,
                             run_after = now() + make_interval(secs => CAST(:d AS double precision)), updated_at = now()
             WHERE id = :id",
            ['id' => $jobId, 'e' => $reason, 'd' => $delay]
        );
    }

    /** Exponential backoff with full jitter, capped at 30s. */
    public static function backoffSeconds(int $attempt): float
    {
        $base = min(30.0, 0.5 * (2 ** max(0, $attempt - 1)));

        return round($base / 2 + (random_int(0, 1000) / 1000) * ($base / 2), 3);
    }

    /** Requeue jobs whose worker died mid-flight. */
    public static function reclaimStale(int $olderThanSeconds = 120): int
    {
        $st = Db::run(
            "UPDATE jobs SET status = 'queued', locked_by = NULL, updated_at = now()
             WHERE status = 'running'
               AND locked_at < now() - make_interval(secs => CAST(:s AS double precision))
             RETURNING id",
            ['s' => $olderThanSeconds]
        );

        return $st->rowCount();
    }
}
