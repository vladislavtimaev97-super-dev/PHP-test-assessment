-- =====================================================================
-- 005_untrusted_supplier_and_rate_limit.sql — stage 2, tasks #2 and #3
-- =====================================================================

-- ---- task 2: a supplier that cannot be trusted ---------------------------
-- duplicate_code_rate  — instead of reserving a fresh key, hand back a code
--                         that was already issued to a DIFFERENT request_id
--                         (simulates "same code twice" and "someone else's
--                         code": from the core's point of view both are the
--                         same fault — a code that is not exclusively ours).
-- lie_about_error_rate — reserve a real key (it truly leaves the pool), then
--                         answer with an HTTP error anyway. The truth is
--                         still recorded in stub.requests, so an idempotent
--                         retry/probe recovers it — that is exactly the
--                         mechanism stage 3 already built for timeouts.
ALTER TABLE stub.config
    ADD COLUMN duplicate_code_rate  numeric NOT NULL DEFAULT 0 CHECK (duplicate_code_rate BETWEEN 0 AND 1),
    ADD COLUMN lie_about_error_rate numeric NOT NULL DEFAULT 0 CHECK (lie_about_error_rate BETWEEN 0 AND 1),
    ADD COLUMN rate_limit_per_min   integer NOT NULL DEFAULT 0;   -- 0 = unlimited

-- Sliding-window call log, used only to enforce rate_limit_per_min. Exactly
-- one row per genuinely NEW attempt (idempotent replays of an existing
-- request_id do not count — they are not new demand on the supplier).
CREATE TABLE stub.call_log (
    id        bigserial   PRIMARY KEY,
    supplier  text        NOT NULL,
    called_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX stub_call_log_supplier_idx ON stub.call_log (supplier, called_at);

-- ---- task 3: the core's own outbound throttle -----------------------------
-- Token bucket per supplier. The core must never send more than `capacity`
-- as a burst and `refill_per_sec` sustained to a supplier, no matter how
-- many orders/items are queued behind it. Continuous refill (not a fixed
-- per-minute window) avoids a thundering herd at window boundaries.
CREATE TABLE supplier_rate_limits (
    supplier       text        PRIMARY KEY,
    capacity       numeric     NOT NULL,
    refill_per_sec numeric     NOT NULL,
    tokens         numeric     NOT NULL,
    updated_at     timestamptz NOT NULL DEFAULT now()
);
