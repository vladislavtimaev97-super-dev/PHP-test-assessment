-- =====================================================================
-- 002_supplier_stub.sql — state of the two fake suppliers
--
-- The stubs live in their own schema and are only ever reached over HTTP by
-- the core. Sharing one Postgres instance is a packaging simplification, not
-- a coupling: nothing in src/App ever reads the "stub" schema.
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS stub;

-- The key pool. Pools of A and B are disjoint, so a code seen twice in
-- deliveries would prove a real double issuance.
CREATE TABLE stub.keys (
    id         bigserial   PRIMARY KEY,
    supplier   text        NOT NULL,
    code       text        NOT NULL,
    status     text        NOT NULL DEFAULT 'free' CHECK (status IN ('free','issued')),
    request_id text,
    issued_at  timestamptz,
    UNIQUE (supplier, code)
);
CREATE INDEX stub_keys_free_idx ON stub.keys (supplier, id) WHERE status = 'free';

-- The supplier-side idempotency store. THIS is what makes
-- "same request_id -> same code" true, which in turn makes a retry after a
-- timeout safe.
CREATE TABLE stub.requests (
    supplier    text        NOT NULL,
    request_id  text        NOT NULL,
    sku         text,
    order_id    text,
    -- pending = claimed, still being processed; makes concurrent duplicates of
    -- the same request_id resolve to one code instead of two.
    outcome     text        NOT NULL CHECK (outcome IN ('pending','ok','error')),
    code        text,
    reason      text,
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (supplier, request_id)
);

-- Runtime-tunable failure injection, so scenarios are reproducible.
CREATE TABLE stub.config (
    supplier        text        PRIMARY KEY,
    down            boolean     NOT NULL DEFAULT false,  -- refuse everything with 503
    out_of_stock    boolean     NOT NULL DEFAULT false,  -- pretend the pool is empty
    fail_rate       numeric     NOT NULL DEFAULT 0 CHECK (fail_rate BETWEEN 0 AND 1),
    timeout_rate    numeric     NOT NULL DEFAULT 0 CHECK (timeout_rate BETWEEN 0 AND 1),
    latency_ms      integer     NOT NULL DEFAULT 0,      -- normal response latency
    hang_ms         integer     NOT NULL DEFAULT 5000,   -- how long a "hang" lasts
    -- true  = a hung request_id answers instantly on retry (issued, answer lost)
    -- false = it hangs forever, the supplier is a black hole
    hang_only_first boolean     NOT NULL DEFAULT true,
    -- when a request "times out", did it still issue the code? (the trap)
    issue_before_hang boolean   NOT NULL DEFAULT true,
    updated_at      timestamptz NOT NULL DEFAULT now()
);
