<?php

declare(strict_types=1);

namespace App\Service;

use App\Infra\HttpClient;
use App\Infra\HttpResult;
use App\Support\Env;

final class SupplierGateway
{
    public function __construct(
        public readonly string $name,
        private readonly string $baseUrl,
        private readonly HttpClient $http,
    ) {
    }

    /** POST /issue — idempotent on request_id by contract. */
    public function issue(string $requestId, string $sku, string $orderId): HttpResult
    {
        return $this->http->postJson($this->baseUrl . '/issue', [
            'request_id' => $requestId,
            'sku'        => $sku,
            'order_id'   => $orderId,
        ]);
    }

    /**
     * GET /issue/{request_id} — the reconciliation probe.
     * 200 => the supplier did issue (returns the code);
     * 404 => the supplier has no record of this request_id, authoritative
     *        "nothing was issued", which is what makes a fail-over safe.
     */
    public function probe(string $requestId): HttpResult
    {
        return $this->http->getJson($this->baseUrl . '/issue/' . rawurlencode($requestId));
    }

    public function stock(): HttpResult
    {
        return $this->http->getJson($this->baseUrl . '/stock');
    }

    /** @return list<self> in preference order */
    public static function registry(): array
    {
        $timeout = Env::float('SUPPLIER_TIMEOUT', 2.0);
        $http    = new HttpClient($timeout, min(0.5, $timeout));

        $urls = [
            'A' => (string) Env::get('SUPPLIER_A_URL', 'http://supplier-a:8080'),
            'B' => (string) Env::get('SUPPLIER_B_URL', 'http://supplier-b:8080'),
        ];

        $order = explode(',', (string) Env::get('SUPPLIER_ORDER', 'A,B'));

        $out = [];
        foreach ($order as $name) {
            $name = trim($name);
            if (isset($urls[$name])) {
                $out[] = new self($name, $urls[$name], $http);
            }
        }

        return $out;
    }

    public static function byName(string $name): ?self
    {
        foreach (self::registry() as $g) {
            if ($g->name === $name) {
                return $g;
            }
        }

        return null;
    }
}
