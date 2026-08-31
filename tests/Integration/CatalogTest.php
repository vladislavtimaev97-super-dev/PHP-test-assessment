<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infra\Db;
use Tests\IntegrationTestCase;

/**
 * Stage 5 — the storefront query.
 */
final class CatalogTest extends IntegrationTestCase
{
    public function testShowcaseOnlyReturnsSellableItems(): void
    {
        $page = $this->api->get('/catalog?limit=50');

        self::assertNotEmpty($page['items']);
        foreach ($page['items'] as $item) {
            self::assertGreaterThan(0, $item['available_qty'], 'the rail must not show out-of-stock SKUs');
        }
    }

    public function testKeysetPaginationWalksTheCatalogWithoutGapsOrRepeats(): void
    {
        $seen   = [];
        $cursor = null;

        for ($page = 0; $page < 5; $page++) {
            $url  = '/catalog?limit=20' . ($cursor === null ? '' : '&cursor=' . urlencode($cursor));
            $data = $this->api->get($url);

            foreach ($data['items'] as $item) {
                self::assertArrayNotHasKey($item['sku'], $seen, 'keyset pagination must not repeat a SKU');
                $seen[$item['sku']] = true;
            }

            $cursor = $data['next_cursor'];
            if ($cursor === null) {
                break;
            }
        }

        self::assertGreaterThan(50, count($seen));
    }

    public function testTypeFilter(): void
    {
        $page = $this->api->get('/catalog?type=key&limit=25');

        self::assertNotEmpty($page['items']);
        foreach ($page['items'] as $item) {
            self::assertSame('key', $item['type']);
        }
    }

    public function testShowcaseQueryUsesTheCoveringIndex(): void
    {
        $plan = implode("\n", array_column(Db::all(
            'EXPLAIN SELECT sku, name, type, price_minor, currency, image, available_qty, popularity
             FROM products WHERE active AND available_qty > 0
             ORDER BY popularity DESC, sku DESC LIMIT 20'
        ), 'QUERY PLAN'));

        self::assertStringContainsString('Index Only Scan', $plan, "plan was:\n" . $plan);
        self::assertStringContainsString('products_showcase_idx', $plan);
        self::assertStringNotContainsString('Sort', $plan, 'the index order must remove the sort step');
        self::assertStringNotContainsString('Seq Scan', $plan);
    }

    public function testSearchFindsARealSku(): void
    {
        $result = $this->api->get('/catalog/search?q=' . urlencode('Steam'));

        self::assertNotEmpty($result['items']);
        self::assertContains(
            'STEAM-TOPUP-500',
            array_column($result['items'], 'sku')
        );
    }
}
