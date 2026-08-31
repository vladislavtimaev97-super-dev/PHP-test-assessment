<?php

declare(strict_types=1);

use App\Infra\Db;
use App\Infra\Log;
use App\Support\Env;

require __DIR__ . '/../vendor/autoload.php';

Log::init('seed');

// ---------------------------------------------------------------------
// 1. the 12 real SKUs from the task
// ---------------------------------------------------------------------
$catalog = json_decode((string) file_get_contents(__DIR__ . '/../data/catalog.json'), true);
$products = $catalog['products'] ?? [];

Db::transaction(function (PDO $pdo) use ($products) {
    $st = $pdo->prepare(
        'INSERT INTO products (sku, name, type, price_minor, currency, image, popularity, supplier_backed, available_qty)
         VALUES (:sku, :name, :type, :price, :currency, :image, :pop, true, 0)
         ON CONFLICT (sku) DO UPDATE
             SET name = EXCLUDED.name, price_minor = EXCLUDED.price_minor,
                 type = EXCLUDED.type, image = EXCLUDED.image'
    );

    $pop = 2_000_000;
    foreach ($products as $p) {
        $st->execute([
            'sku'      => $p['sku'],
            'name'     => $p['name'],
            'type'     => $p['type'],
            'price'    => (int) round(((float) $p['price']) * 100),
            'currency' => $p['currency'],
            'image'    => $p['image'],
            'pop'      => $pop -= 1000,
        ]);
    }
});

Log::info('catalog_seeded', ['products' => count($products)]);

// ---------------------------------------------------------------------
// 2. the supplier key pools (disjoint: A = 30 keys, B = 20 keys)
//
//    Disjoint pools make a double issuance impossible to hide: if the same
//    code ever showed up twice, it could only come from one supplier.
// ---------------------------------------------------------------------
$keys  = json_decode((string) file_get_contents(__DIR__ . '/../data/keys.json'), true)['keys'] ?? [];
$split = (int) round(count($keys) * 0.6);

$pools = [
    'A' => array_slice($keys, 0, $split),
    'B' => array_slice($keys, $split),
];

Db::transaction(function (PDO $pdo) use ($pools) {
    $st = $pdo->prepare(
        "INSERT INTO stub.keys (supplier, code) VALUES (:s, :c)
         ON CONFLICT (supplier, code) DO NOTHING"
    );
    foreach ($pools as $supplier => $codes) {
        foreach ($codes as $code) {
            $st->execute(['s' => $supplier, 'c' => $code]);
        }
        $pdo->prepare('INSERT INTO stub.config (supplier) VALUES (:s) ON CONFLICT (supplier) DO NOTHING')
            ->execute(['s' => $supplier]);
    }
});

// The 50 keys from the task are handed out first (lowest ids). Synthetic keys
// are appended so the test suite can be run repeatedly without draining the
// pool; set SEED_EXTRA_KEYS=0 to work with the 50 provided keys only.
$extraKeys = Env::int('SEED_EXTRA_KEYS', 450);
if ($extraKeys > 0) {
    Db::run(
        "INSERT INTO stub.keys (supplier, code)
         SELECT CASE WHEN i % 5 < 3 THEN 'A' ELSE 'B' END,
                'TEST-' || lpad(i::text, 4, '0') || '-' || upper(substr(md5(i::text), 1, 4))
         FROM generate_series(1, CAST(:n AS integer)) AS i
         ON CONFLICT (supplier, code) DO NOTHING",
        ['n' => $extraKeys]
    );
}

Log::info('key_pools_seeded', [
    'A' => (int) Db::value("SELECT count(*) FROM stub.keys WHERE supplier = 'A'"),
    'B' => (int) Db::value("SELECT count(*) FROM stub.keys WHERE supplier = 'B'"),
    'from_task' => count($keys),
]);

// initial availability projection for the storefront
Db::run(
    "UPDATE products SET available_qty = (SELECT count(*) FROM stub.keys WHERE status = 'free'),
                         stock_synced_at = now()
     WHERE supplier_backed"
);

// ---------------------------------------------------------------------
// 3. stage 5: bulk catalog so the showcase query is measured on real volume
// ---------------------------------------------------------------------
$extra = Env::int('SEED_EXTRA_SKUS', 5000);

if ($extra > 0) {
    $have = (int) Db::value('SELECT count(*) FROM products');

    if ($have < $extra) {
        // generate_series is orders of magnitude faster than a PHP loop here
        Db::run(
            "INSERT INTO products (sku, name, type, price_minor, currency, image, popularity, active, available_qty)
             SELECT
                 'GEN-' || lpad(i::text, 7, '0'),
                 'Товар ' || i,
                 (ARRAY['topup','key','subscription','giftcard'])[1 + (i % 4)],
                 ((50 + (i * 7) % 5000) * 100)::bigint,
                 'RUB',
                 'assets/generic.png',
                 (i * 2654435761) % 1000000,
                 (i % 20) <> 0,                       -- 5% inactive
                 CASE WHEN (i % 10) < 3 THEN 0 ELSE 1 + (i % 50) END   -- 30% out of stock
             FROM generate_series(1, CAST(:n AS integer)) AS i
             ON CONFLICT (sku) DO NOTHING",
            ['n' => $extra]
        );
        Db::run('VACUUM (ANALYZE) products');
    }

    Log::info('bulk_catalog_seeded', [
        'products'  => (int) Db::value('SELECT count(*) FROM products'),
        'sellable'  => (int) Db::value('SELECT count(*) FROM products WHERE active AND available_qty > 0'),
    ]);
}
