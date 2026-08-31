<?php

declare(strict_types=1);

/**
 * Stage 5 — show the execution plan of the hot storefront query.
 *
 *   php bin/explain_showcase.php
 *
 * Prints, for the same query:
 *   1. the plan we actually get (covering partial index, index-only scan)
 *   2. the plan without any index (what a naive schema would do)
 *   3. deep pagination: keyset cursor vs OFFSET
 */

use App\Infra\Db;
use App\Support\Cli;

require __DIR__ . '/../vendor/autoload.php';

$SHOWCASE = 'SELECT sku, name, type, price_minor, currency, image, available_qty, popularity
             FROM products
             WHERE active AND available_qty > 0
             ORDER BY popularity DESC, sku DESC
             LIMIT 20';

function explain(string $sql, array $params = []): string
{
    $rows = Db::all('EXPLAIN (ANALYZE, BUFFERS, COSTS) ' . $sql, $params);

    return implode("\n", array_map(
        static fn (array $r): string => '    ' . $r['QUERY PLAN'],
        $rows
    ));
}

// index-only scans rely on the visibility map, so make it current first
Db::run('VACUUM (ANALYZE) products');

$total    = (int) Db::value('SELECT count(*) FROM products');
$sellable = (int) Db::value('SELECT count(*) FROM products WHERE active AND available_qty > 0');
$size     = (string) Db::value("SELECT pg_size_pretty(pg_total_relation_size('products'))");
$idxSize  = (string) Db::value("SELECT pg_size_pretty(pg_relation_size('products_showcase_idx'))");

Cli::head('catalog');
Cli::line("  products:            {$total}");
Cli::line("  sellable (indexed):  {$sellable}");
Cli::line("  table size:          {$size}");
Cli::line("  showcase index size: {$idxSize}");

Cli::head('1) storefront rail — what we actually run');
Cli::line(explain($SHOWCASE));

Cli::head('2) the same query with indexes disabled (a naive schema)');
Db::run('SET enable_indexscan = off');
Db::run('SET enable_indexonlyscan = off');
Db::run('SET enable_bitmapscan = off');
Cli::line(explain($SHOWCASE));
Db::run('RESET enable_indexscan');
Db::run('RESET enable_indexonlyscan');
Db::run('RESET enable_bitmapscan');

Cli::head('3) filtered rail (type = key)');
Cli::line(explain(
    "SELECT sku, name, price_minor, available_qty
     FROM products
     WHERE active AND available_qty > 0 AND type = 'key'
     ORDER BY popularity DESC, sku DESC
     LIMIT 20"
));

// deep page: pick a cursor ~3000 rows in
$cursor = Db::one(
    'SELECT popularity, sku FROM products
     WHERE active AND available_qty > 0
     ORDER BY popularity DESC, sku DESC
     OFFSET 3000 LIMIT 1'
);

if ($cursor !== null) {
    Cli::head('4) deep pagination — keyset cursor (row 3000+)');
    Cli::line(explain(
        'SELECT sku, name, price_minor
         FROM products
         WHERE active AND available_qty > 0
           AND (popularity, sku) < (CAST(:pop AS integer), CAST(:sku AS text))
         ORDER BY popularity DESC, sku DESC
         LIMIT 20',
        ['pop' => $cursor['popularity'], 'sku' => $cursor['sku']]
    ));

    Cli::head('5) deep pagination — OFFSET 3000 (what we avoid)');
    Cli::line(explain(
        'SELECT sku, name, price_minor
         FROM products
         WHERE active AND available_qty > 0
         ORDER BY popularity DESC, sku DESC
         OFFSET 3000 LIMIT 20'
    ));
}

Cli::line();
