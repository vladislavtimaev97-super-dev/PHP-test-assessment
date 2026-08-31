<?php

declare(strict_types=1);

namespace App\Service;

use App\Infra\Db;

/**
 * Stage 5 — the storefront rails.
 *
 * Everything here is keyset ("seek") pagination against a covering partial
 * index; there is no OFFSET and no COUNT(*), so page 500 costs the same as
 * page 1 and the plan stays an Index Only Scan regardless of catalog size.
 */
final class CatalogService
{
    private const MAX_LIMIT = 100;

    /**
     * @return array{items:list<array<string,mixed>>,next_cursor:?string}
     */
    public function showcase(?string $type, ?string $cursor, int $limit): array
    {
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $params = [];
        $where  = ['active', 'available_qty > 0'];

        if ($type !== null && $type !== '') {
            $where[]        = 'type = :type';
            $params['type'] = $type;
        }

        // (popularity DESC, sku DESC) matches the index order exactly, so the
        // seek predicate is a range start, not a filter.
        $seek = self::decodeCursor($cursor);
        if ($seek !== null) {
            $where[]       = '(popularity, sku) < (CAST(:pop AS integer), CAST(:sku AS text))';
            $params['pop'] = $seek[0];
            $params['sku'] = $seek[1];
        }

        $sql = 'SELECT sku, name, type, price_minor, currency, image, available_qty, popularity
                FROM products
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY popularity DESC, sku DESC
                LIMIT ' . ($limit + 1);

        $rows = Db::all($sql, $params);

        $next = null;
        if (count($rows) > $limit) {
            $last = $rows[$limit - 1];
            $next = self::encodeCursor((int) $last['popularity'], (string) $last['sku']);
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'items' => array_map(static fn (array $r): array => [
                'sku'           => $r['sku'],
                'name'          => $r['name'],
                'type'          => $r['type'],
                'price'         => (int) $r['price_minor'] / 100,
                'price_minor'   => (int) $r['price_minor'],
                'currency'      => $r['currency'],
                'image'         => $r['image'],
                'available_qty' => (int) $r['available_qty'],
            ], $rows),
            'next_cursor' => $next,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function search(string $q, int $limit = 20): array
    {
        return Db::all(
            'SELECT sku, name, type, price_minor, currency, available_qty
             FROM products
             WHERE active AND lower(name) LIKE :q
             ORDER BY popularity DESC
             LIMIT ' . max(1, min(self::MAX_LIMIT, $limit)),
            ['q' => '%' . mb_strtolower($q) . '%']
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $sku): ?array
    {
        return Db::one('SELECT * FROM products WHERE sku = :sku', ['sku' => $sku]);
    }

    /**
     * Refresh the denormalised availability projection from the suppliers.
     * Called periodically by the worker — never on the storefront path.
     *
     * The stub suppliers share one key pool across all SKUs, so the projected
     * availability of every supplier-backed SKU is the total number of free
     * keys. A real integration would sync per-SKU quantities the same way.
     */
    public function syncStock(): int
    {
        $total = 0;
        foreach (SupplierGateway::registry() as $gateway) {
            $res = $gateway->stock();
            if (!$res->isOk() || !isset($res->json['free'])) {
                continue;
            }
            $total += (int) $res->json['free'];
        }

        Db::run(
            'UPDATE products SET available_qty = :qty, stock_synced_at = now() WHERE supplier_backed',
            ['qty' => $total]
        );

        return $total;
    }

    private static function encodeCursor(int $popularity, string $sku): string
    {
        return rtrim(strtr(base64_encode($popularity . '|' . $sku), '+/', '-_'), '=');
    }

    /** @return array{0:int,1:string}|null */
    private static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), false);
        if ($raw === false || !str_contains($raw, '|')) {
            return null;
        }
        [$pop, $sku] = explode('|', $raw, 2);

        return [(int) $pop, $sku];
    }
}
