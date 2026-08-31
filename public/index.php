<?php

declare(strict_types=1);

use App\Api\Api;
use App\Http\Request;
use App\Infra\Log;

require __DIR__ . '/../vendor/autoload.php';

$request = Request::fromGlobals();

Log::init('api', $request->header('x-trace-id') ?? Log::newTraceId());

$started  = microtime(true);
$response = Api::router()->dispatch($request);

Log::info('http_request', [
    'method'      => $request->method,
    'path'        => $request->path,
    'status'      => $response->status,
    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
]);

header('X-Trace-Id: ' . Log::traceId());
$response->send();
