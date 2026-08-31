<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Infra\Log;
use App\Stub\SupplierStub;

require __DIR__ . '/../vendor/autoload.php';

$request = Request::fromGlobals();
$stub    = SupplierStub::fromEnv();

Log::init('supplier-' . (getenv('SUPPLIER_NAME') ?: 'A'), $request->header('x-trace-id') ?? Log::newTraceId());

$router = new Router();
$router->get('/health', static fn (): Response => Response::json(['status' => 'ok']));
$router->post('/issue', static fn (Request $r): Response => $stub->issue($r));
$router->get('/issue/{request_id}', static fn (Request $r): Response => $stub->probe($r));
$router->get('/stock', static fn (Request $r): Response => $stub->stock($r));
$router->get('/_control', static fn (Request $r): Response => $stub->getControl($r));
$router->post('/_control', static fn (Request $r): Response => $stub->setControl($r));
$router->post('/_reset', static fn (Request $r): Response => $stub->reset($r));

$router->dispatch($request)->send();
