<?php

declare(strict_types=1);

use App\Infra\Db;
use App\Infra\Log;
use App\Service\CatalogService;
use App\Service\DeliveryService;
use App\Service\JobQueue;
use App\Service\RecoveryService;
use App\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

$workerId = (string) Env::get('WORKER_ID', 'worker-' . getmypid());
Log::init('worker');
Log::info('worker_started', ['worker_id' => $workerId]);

for ($i = 1; $i <= 60; $i++) {
    try {
        Db::value('SELECT 1');
        break;
    } catch (Throwable) {
        Db::reset();
        usleep(500_000);
    }
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
}

$pollMs      = Env::int('WORKER_POLL_MS', 200);
$sweepEvery  = Env::int('WORKER_SWEEP_SECONDS', 5);
$stockEvery  = Env::int('WORKER_STOCK_SYNC_SECONDS', 15);
$lastSweep   = 0.0;
$lastStock   = 0.0;

$delivery  = new DeliveryService();
$recovery  = new RecoveryService();
$catalog   = new CatalogService();

while ($running) {
    $didWork = false;

    try {
        $job = JobQueue::claim($workerId);

        if ($job !== null) {
            $didWork = true;
            Log::setTraceId((string) ($job['trace_id'] ?: Log::newTraceId()));

            $started = microtime(true);
            try {
                $result = match ($job['type']) {
                    JobQueue::DELIVER_ORDER => $delivery->deliver((string) $job['order_id']),
                    default                 => ['result' => 'unknown_job_type'],
                };

                $terminal = in_array($result['result'], [
                    DeliveryService::DELIVERED,
                    DeliveryService::PARTIALLY_DELIVERED,
                    DeliveryService::REFUNDED,
                    DeliveryService::ALREADY,
                    DeliveryService::NOT_PAYABLE,
                ], true);

                if ($terminal) {
                    JobQueue::complete((int) $job['id']);
                } elseif ($result['result'] === DeliveryService::RATE_LIMITED) {
                    // The supplier's own limit, not a failure: reschedule
                    // quickly and do not count it against max_attempts.
                    JobQueue::retrySoon((int) $job['id'], 'rate_limited', 1.0 + (random_int(0, 500) / 1000));
                } else {
                    // RETRY_LATER / OUT_OF_STOCK / FAILED / LOCKED are all
                    // recoverable: back off and try again later.
                    JobQueue::retryLater((int) $job['id'], (string) $result['result']);
                }

                Log::info('job_finished', [
                    'job_id'      => (int) $job['id'],
                    'type'        => $job['type'],
                    'order_id'    => $job['order_id'],
                    'attempt'     => (int) $job['attempts'],
                    'result'      => $result['result'],
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
            } catch (Throwable $e) {
                Log::error('job_exception', [
                    'job_id'  => (int) $job['id'],
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile() . ':' . $e->getLine(),
                ]);
                JobQueue::retryLater((int) $job['id'], $e->getMessage());
            }
        }

        $now = microtime(true);

        if ($now - $lastSweep >= $sweepEvery) {
            $lastSweep = $now;
            Log::setTraceId(Log::newTraceId());
            $stats = $recovery->sweep();
            if (array_sum($stats) > 0) {
                Log::info('sweep', $stats);
            }
        }

        if ($now - $lastStock >= $stockEvery) {
            $lastStock = $now;
            try {
                $catalog->syncStock();
            } catch (Throwable $e) {
                Log::warn('stock_sync_failed', ['message' => $e->getMessage()]);
            }
        }
    } catch (Throwable $e) {
        Log::error('worker_loop_error', ['message' => $e->getMessage()]);
        Db::reset();
        usleep(500_000);
    }

    if (!$didWork) {
        usleep($pollMs * 1000);
    }
}

Log::info('worker_stopped', ['worker_id' => $workerId]);
