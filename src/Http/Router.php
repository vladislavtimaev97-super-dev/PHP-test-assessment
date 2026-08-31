<?php

declare(strict_types=1);

namespace App\Http;

use App\Infra\Log;

final class Router
{
    /** @var list<array{method:string,regex:string,names:list<string>,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback(
            '#\{(\w+)\}#',
            function (array $m) use (&$names): string {
                $names[] = $m[1];

                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $req): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $req->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $req->method) {
                continue;
            }

            array_shift($m);
            $req->params = array_combine($route['names'], $m) ?: [];

            try {
                return ($route['handler'])($req);
            } catch (\InvalidArgumentException $e) {
                return Response::error(400, 'bad_request', $e->getMessage());
            } catch (\Throwable $e) {
                Log::error('unhandled_exception', [
                    'path'      => $req->path,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile() . ':' . $e->getLine(),
                ]);

                return Response::error(500, 'internal_error', $e->getMessage());
            }
        }

        return $pathMatched
            ? Response::error(405, 'method_not_allowed', 'Method not allowed')
            : Response::error(404, 'not_found', 'Route not found');
    }
}
