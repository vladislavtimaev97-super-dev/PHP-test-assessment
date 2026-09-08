-- =====================================================================
-- 004_baskets.sql — multi-item orders with partial fulfillment (stage 2, #1)
--
-- A basket is N `order_items`, each delivered by its own supplier call. The
-- exactly-once anchor from stage 1 (`deliveries`) moves from "one row per
-- order" to "one row per item": UNIQUE(item_id) says this unit was handed
-- out at most once, UNIQUE(code) still says a code was attached to at most
-- one unit ever, across the whole catalog and every order.
--
-- Money: `payment_captured` still books once per order (the customer pays
-- once for the whole basket). Delivery/refund books once per ITEM
-- (`delivery_recognized` / `item_refunded`), so partial fulfillment is just
-- "some of the per-item legs went to revenue, some went to refunds" — the
-- order-level total never has to be split by hand, it falls out of the sum.
-- =====================================================================

CREATE SEQUENCE order_item_seq START 1;

CREATE TABLE order_items (
    id             text        PRIMARY KEY DEFAULT ('oi_' || lpad(nextval('order_item_seq')::text, 6, '0')),
    order_id       text        NOT NULL REFERENCES orders(id),
    line_no        integer     NOT NULL,
    sku            text        NOT NULL REFERENCES products(sku),
    amount_minor   bigint      NOT NULL CHECK (amount_minor > 0),
    status         text        NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending','delivering','delivered',
                                     'out_of_stock','delivery_failed','refunded')),
    -- First time this item entered a recoverable-but-undeliverable state.
    -- Drives the give-up-and-refund timer in the sweeper: an item does not
    -- retry forever, it eventually resolves one way or the other.
    first_undeliverable_at timestamptz,
    delivered_at   timestamptz,
    refunded_at    timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (order_id, line_no)
);
CREATE INDEX order_items_order_idx ON order_items (order_id, line_no);
CREATE INDEX order_items_undeliverable_idx ON order_items (first_undeliverable_at)
    WHERE status IN ('out_of_stock','delivery_failed');

-- orders.sku/amount_minor become a denormalized summary: amount_minor is
-- always the sum of order_items, sku is kept (for display/back-compat) only
-- when the order has exactly one item — which is still the common case, and
-- behaves byte for byte like stage 1 did.
ALTER TABLE orders ALTER COLUMN sku DROP NOT NULL;
ALTER TABLE orders DROP CONSTRAINT orders_sku_fkey;
ALTER TABLE orders DROP CONSTRAINT orders_status_check;
ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN
    ('created','paid','delivering','delivered','payment_failed',
     'out_of_stock','delivery_failed','partially_delivered','refunded'));

-- Item-level transitions are logged into the same append-only history table
-- as order-level ones. item_id = '' marks an order-level row. A real NULL
-- would also work for storage, but two NULLs are never equal to each other
-- in a comparison, which breaks the point-in-time replay query in stage 2's
-- bonus #4 ("last row with item_id = :i"); an explicit sentinel is simpler
-- than writing every query with IS NOT DISTINCT FROM.
ALTER TABLE order_status_history ADD COLUMN item_id text NOT NULL DEFAULT '';
CREATE INDEX order_status_history_item_idx ON order_status_history (order_id, item_id, id);

-- ---- deliveries: the anchor moves from "one per order" to "one per item" --
ALTER TABLE deliveries ADD COLUMN item_id text REFERENCES order_items(id);
ALTER TABLE deliveries DROP CONSTRAINT deliveries_order_id_key;
ALTER TABLE deliveries ALTER COLUMN item_id SET NOT NULL;
ALTER TABLE deliveries ADD CONSTRAINT deliveries_item_id_key UNIQUE (item_id);
CREATE INDEX deliveries_order_idx ON deliveries (order_id);

-- ---- supplier_requests: one idempotency lineage per ITEM+supplier --------
ALTER TABLE supplier_requests ADD COLUMN item_id text REFERENCES order_items(id);
ALTER TABLE supplier_requests DROP CONSTRAINT supplier_requests_order_id_supplier_epoch_key;
ALTER TABLE supplier_requests ALTER COLUMN item_id SET NOT NULL;
ALTER TABLE supplier_requests ADD CONSTRAINT supplier_requests_item_supplier_epoch_key
    UNIQUE (item_id, supplier, epoch);
CREATE INDEX supplier_requests_item_idx ON supplier_requests (item_id, supplier, epoch DESC);

ALTER TABLE delivery_attempts ADD COLUMN item_id text REFERENCES order_items(id);
ALTER TABLE orphan_codes ADD COLUMN item_id text REFERENCES order_items(id);

-- ---- ledger: idempotency now keyed per item too ---------------------------
-- '' marks an order-level leg (payment_captured); a real order_items.id
-- marks a per-item leg (delivery_recognized / item_refunded). Same
-- NULL-is-never-equal-to-NULL reasoning as order_status_history above.
ALTER TABLE ledger_entries ADD COLUMN item_id text NOT NULL DEFAULT '';
DROP INDEX ledger_idempotency_idx;
CREATE UNIQUE INDEX ledger_idempotency_idx
    ON ledger_entries (order_id, item_id, entry_type, account) WHERE order_id IS NOT NULL;
CREATE INDEX ledger_order_item_idx ON ledger_entries (order_id, item_id);

-- ---- jobs: priority so freshly-paid customer-facing work outranks
--      background sweeper upkeep (stage 2, #3) -----------------------------
ALTER TABLE jobs ADD COLUMN priority integer NOT NULL DEFAULT 100;
-- Rate-limit back-offs (stage 2, #3) must not exhaust the retry budget of an
-- order stuck behind a busy supplier; a plain "supplier said no" failure
-- should still get buried reasonably fast. 200 attempts at a capped 30s
-- backoff is a multi-hour safety net either way.
ALTER TABLE jobs ALTER COLUMN max_attempts SET DEFAULT 200;
DROP INDEX jobs_pickup_idx;
CREATE INDEX jobs_pickup_idx ON jobs (priority, run_after, id) WHERE status = 'queued';
