-- =====================================================================
-- 003_catalog_showcase.sql — stage 5: the hot storefront query
--
-- Hot query (the "Популярные товары" rails on the mockup):
--
--   SELECT sku, name, type, price_minor, image, available_qty
--   FROM products
--   WHERE active AND available_qty > 0 [AND type = $1]
--   ORDER BY popularity DESC, sku DESC
--   LIMIT 20;                          -- + keyset pagination
--
-- Design choices:
--  * available_qty is DENORMALISED onto products. Counting live rows in a
--    stock table (or worse, asking the supplier) per storefront request does
--    not survive thousands of SKUs; the rail needs one index range scan.
--  * PARTIAL index on (active AND available_qty > 0): the storefront only ever
--    looks at sellable rows, so out-of-stock and disabled SKUs are not even in
--    the index. On a real catalog that is most of the table.
--  * INCLUDE (...) makes it a covering index => Index Only Scan, no heap
--    access, no sort: the plan is a bounded prefix read of the index.
--  * (popularity DESC, sku DESC) is also the keyset pagination cursor, so page N
--    costs the same as page 1 — no OFFSET.
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX products_showcase_idx
    ON products (popularity DESC, sku DESC)
    INCLUDE (name, type, price_minor, currency, image, available_qty)
    WHERE active AND available_qty > 0;

CREATE INDEX products_showcase_by_type_idx
    ON products (type, popularity DESC, sku DESC)
    INCLUDE (name, price_minor, currency, image, available_qty)
    WHERE active AND available_qty > 0;

-- Search box on the storefront ("Игра, приложение или услуга...").
CREATE INDEX products_name_trgm_idx ON products USING gin (lower(name) gin_trgm_ops)
    WHERE active;
