-- =====================================================================
-- 001_core.sql — orders, payments, delivery, ledger, jobs
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---------------------------------------------------------------------
-- Catalog
-- ---------------------------------------------------------------------
CREATE TABLE products (
    sku                text        PRIMARY KEY,
    name               text        NOT NULL,
    type               text        NOT NULL CHECK (type IN ('topup','key','subscription','giftcard')),
    price_minor        bigint      NOT NULL CHECK (price_minor > 0),  -- kopecks
    currency           char(3)     NOT NULL DEFAULT 'RUB',
    image              text,
    active             boolean     NOT NULL DEFAULT true,
    popularity         integer     NOT NULL DEFAULT 0,
    -- Cached availability projection used ONLY by the storefront (stage 5).
    -- The source of truth for issuance is always the supplier response.
    available_qty      integer     NOT NULL DEFAULT 0 CHECK (available_qty >= 0),
    supplier_backed    boolean     NOT NULL DEFAULT false,
    stock_synced_at    timestamptz,
    created_at         timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Orders
-- ---------------------------------------------------------------------
CREATE SEQUENCE order_seq START 123;

CREATE TABLE orders (
    id             text        PRIMARY KEY DEFAULT ('ord_' || lpad(nextval('order_seq')::text, 5, '0')),
    sku            text        NOT NULL REFERENCES products(sku),
    amount_minor   bigint      NOT NULL CHECK (amount_minor > 0),
    currency       char(3)     NOT NULL,
    status         text        NOT NULL DEFAULT 'created'
                               CHECK (status IN ('created','paid','delivering','delivered',
                                                 'payment_failed','out_of_stock','delivery_failed')),
    customer_email text,
    paid_at        timestamptz,
    delivered_at   timestamptz,
    -- Bumped on every mutation; audit/debug aid only. The real concurrency
    -- control is SELECT ... FOR UPDATE on this row.
    version        integer     NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

-- worklist for the recovery sweeper: only non-final orders
CREATE INDEX orders_recoverable_idx ON orders (updated_at)
    WHERE status IN ('paid','delivering','out_of_stock','delivery_failed');

CREATE TABLE order_status_history (
    id          bigserial   PRIMARY KEY,
    order_id    text        NOT NULL REFERENCES orders(id),
    from_status text,
    to_status   text        NOT NULL,
    reason      text,
    actor       text        NOT NULL DEFAULT 'system',
    trace_id    text,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_status_history_order_idx ON order_status_history (order_id, id);

-- ---------------------------------------------------------------------
-- Payment webhooks
--
-- No FK to orders on purpose: a webhook may legitimately arrive BEFORE the
-- order row exists (out-of-order delivery). Such an event is stored, answered
-- 200 and applied later by the sweeper.
-- ---------------------------------------------------------------------
CREATE TABLE webhook_events (
    event_id          text        PRIMARY KEY,   -- <= dedupe anchor (at-least-once delivery)
    order_id          text        NOT NULL,
    status            text        NOT NULL CHECK (status IN ('paid','failed')),
    amount_minor      bigint      NOT NULL,
    currency          char(3)     NOT NULL,
    event_created_at  timestamptz NOT NULL,      -- issuer clock, used to order events
    payload           jsonb       NOT NULL,
    received_at       timestamptz NOT NULL DEFAULT now(),
    processed_at      timestamptz,
    outcome           text,   -- applied | ignored_terminal | ignored_stale
                              -- | ignored_amount_mismatch | deferred_no_order | error
    detail            text
);
CREATE INDEX webhook_events_deferred_idx ON webhook_events (order_id)
    WHERE processed_at IS NULL;
CREATE INDEX webhook_events_order_idx ON webhook_events (order_id, event_created_at);

-- ---------------------------------------------------------------------
-- Delivery
-- ---------------------------------------------------------------------

-- THE exactly-once anchor: one row per order (UNIQUE), one code per row
-- (UNIQUE). Even if two workers and two suppliers race, the database can only
-- record a single delivery fact per order, and a code can never be attached to
-- two different orders.
CREATE TABLE deliveries (
    id           bigserial   PRIMARY KEY,
    order_id     text        NOT NULL UNIQUE REFERENCES orders(id),
    supplier     text        NOT NULL,
    request_id   text        NOT NULL,
    code         text        NOT NULL UNIQUE,
    delivered_at timestamptz NOT NULL DEFAULT now()
);

-- Our own record of an external call. request_id is deterministic:
-- req_<order_id>_<supplier>_<epoch>.
--
-- The epoch is what makes both halves of stage 3 work:
--   * retries of an UNKNOWN or successful call keep the SAME epoch, so the
--     supplier's idempotency returns the same code instead of a second key;
--   * a new attempt is only opened (epoch + 1) after the supplier gave a
--     DEFINITIVE answer that nothing was issued — at which point a fresh
--     idempotency key is both safe and necessary (a supplier that cached
--     "out_of_stock" under the old key would replay that error forever).
CREATE TABLE supplier_requests (
    request_id  text        PRIMARY KEY,
    order_id    text        NOT NULL REFERENCES orders(id),
    supplier    text        NOT NULL,
    epoch       integer     NOT NULL DEFAULT 1,
    state       text        NOT NULL CHECK (state IN ('in_flight','ok','error','unknown')),
    code        text,
    reason      text,
    attempts    integer     NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (order_id, supplier, epoch)
);
CREATE INDEX supplier_requests_order_idx ON supplier_requests (order_id, supplier, epoch DESC);
-- "timeout != failure": these are the requests whose fate we do not know yet.
-- We must never fall back to another supplier while one of these is open.
CREATE INDEX supplier_requests_unknown_idx ON supplier_requests (updated_at)
    WHERE state = 'unknown';

CREATE TABLE delivery_attempts (
    id           bigserial   PRIMARY KEY,
    order_id     text        NOT NULL REFERENCES orders(id),
    supplier     text        NOT NULL,
    request_id   text        NOT NULL,
    attempt_no   integer     NOT NULL,
    outcome      text        NOT NULL CHECK (outcome IN
                             ('ok','error','timeout','unavailable','out_of_stock')),
    http_status  integer,
    duration_ms  integer     NOT NULL,
    reason       text,
    trace_id     text,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX delivery_attempts_order_idx ON delivery_attempts (order_id, id);

-- A code the supplier handed us that we could not attach to its order (e.g. a
-- late timeout resolution after the order was already delivered by the other
-- supplier). Never silently dropped: it is money, so it is reported.
CREATE TABLE orphan_codes (
    id         bigserial   PRIMARY KEY,
    order_id   text,
    supplier   text        NOT NULL,
    request_id text        NOT NULL,
    code       text        NOT NULL,
    reason     text        NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (supplier, request_id, code)
);

-- ---------------------------------------------------------------------
-- Background jobs (Postgres-backed queue, FOR UPDATE SKIP LOCKED)
-- ---------------------------------------------------------------------
CREATE TABLE jobs (
    id           bigserial   PRIMARY KEY,
    type         text        NOT NULL,
    order_id     text,
    payload      jsonb       NOT NULL DEFAULT '{}'::jsonb,
    status       text        NOT NULL DEFAULT 'queued'
                             CHECK (status IN ('queued','running','done','failed')),
    attempts     integer     NOT NULL DEFAULT 0,
    max_attempts integer     NOT NULL DEFAULT 25,
    run_after    timestamptz NOT NULL DEFAULT now(),
    locked_by    text,
    locked_at    timestamptz,
    last_error   text,
    trace_id     text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
-- At most one live delivery job per order: 50 concurrent webhooks enqueue once.
CREATE UNIQUE INDEX jobs_live_per_order_idx ON jobs (type, order_id)
    WHERE status IN ('queued','running');
CREATE INDEX jobs_pickup_idx ON jobs (run_after, id) WHERE status = 'queued';

-- ---------------------------------------------------------------------
-- Money ledger (double entry; every transaction sums to zero)
-- ---------------------------------------------------------------------
CREATE TABLE ledger_entries (
    id           bigserial   PRIMARY KEY,
    txn_id       uuid        NOT NULL DEFAULT gen_random_uuid(),
    order_id     text        REFERENCES orders(id),
    account      text        NOT NULL CHECK (account IN
                             ('gateway_clearing','deferred_revenue','revenue','refunds')),
    amount_minor bigint      NOT NULL,     -- signed, sum per txn_id = 0
    entry_type   text        NOT NULL,     -- payment_captured | delivery_recognized | payment_reversed
    ref          text,
    created_at   timestamptz NOT NULL DEFAULT now()
);
-- Makes ledger writes idempotent: the same business event for the same order
-- can only be booked once, no matter how often it is replayed.
CREATE UNIQUE INDEX ledger_idempotency_idx
    ON ledger_entries (order_id, entry_type, account) WHERE order_id IS NOT NULL;
CREATE INDEX ledger_order_idx ON ledger_entries (order_id);

CREATE VIEW ledger_balance AS
    SELECT account, sum(amount_minor) AS balance_minor, count(*) AS entries
    FROM ledger_entries GROUP BY account;

-- Any row here is a bug: a transaction whose debits != credits.
CREATE VIEW ledger_imbalance AS
    SELECT txn_id, sum(amount_minor) AS delta_minor
    FROM ledger_entries GROUP BY txn_id HAVING sum(amount_minor) <> 0;

-- ---------------------------------------------------------------------
-- API idempotency (POST /orders)
-- ---------------------------------------------------------------------
CREATE TABLE idempotency_keys (
    key           text        PRIMARY KEY,
    endpoint      text        NOT NULL,
    request_hash  text        NOT NULL,
    response_code integer,
    response_body jsonb,
    created_at    timestamptz NOT NULL DEFAULT now()
);
