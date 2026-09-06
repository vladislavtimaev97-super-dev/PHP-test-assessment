---
name: code-review-checklist
description: Review a gamestore-core diff against this repo's concurrency and money-correctness invariants (idempotency, delivery timeout handling, ledger balance, lock ordering). Use when asked to review a PR/diff/change here, or before merging anything touching orders, webhooks, delivery, or the ledger. Complements (does not replace) the built-in /code-review command.
---

This project's correctness lives in a handful of specific invariants, not
general PHP style — a diff can be clean PHP and still break exactly-once
delivery or double-entry accounting. This checklist is what to actually
check; for a full multi-agent automated review use the built-in
`/code-review` command (levels low/medium/high/xhigh/max, or `ultra` for a
cloud multi-agent pass) — this skill is the fast, repo-specific pass to run
first or alongside it.

Background reading if the diff touches any of the areas below: `CLAUDE.md`
(architecture summary) and [NOTES.md](../../../NOTES.md) (the *why*,
Russian) — specifically "Этап 2 — exactly-once под гонками" for
idempotency, "Этап 3 — таймаут ≠ отказ" for delivery/timeout handling,
"Этап 4" for reconciliation/recovery.

## The three unique constraints — never work around these

```
webhook_events.event_id  PRIMARY KEY   -- webhook replay must not double-process
deliveries.order_id      UNIQUE        -- an order must not be delivered twice
deliveries.code          UNIQUE        -- a key must not attach to two orders
```

Red flag: any new code path that inserts into `deliveries` or
`webhook_events` with an `ON CONFLICT DO NOTHING`/catch-and-ignore that
*doesn't* end up on the same code path as the existing dedupe logic in
[PaymentWebhookService.php](../../../src/Service/PaymentWebhookService.php)
/ [DeliveryService.php](../../../src/Service/DeliveryService.php). A second,
parallel dedupe mechanism is itself a bug.

## Delivery outcome handling — three states, not two

A supplier call result must be classified as `error` (4xx/5xx — safe to
fail over), `unavailable` (never reached — safe to fail over), or
`timeout` (connected, no response — **unknown, must not fail over**). Check:

- Is `unavailable` vs `timeout` still distinguished via
  `CURLINFO_CONNECT_TIME` in [HttpClient.php](../../../src/Infra/HttpClient.php),
  or did new code collapse them into one bucket (e.g. catching all cURL
  exceptions the same way)?
- Does anything increment the `epoch` in `req_<order>_<supplier>_<epoch>`
  on a non-definite failure? Only a *definite* failure should bump epoch —
  bumping it after `unknown`/timeout would abandon a request that might
  still resolve; bumping it after `out_of_stock` too eagerly would replay
  a cached supplier failure forever instead of retrying the same
  idempotency key.
- Does any new delivery path call a supplier from inside an open DB
  transaction? It shouldn't — that holds a row lock for the call's
  duration. Cross-worker exclusion for one order's delivery should go
  through the advisory lock (`Db::tryAdvisoryLock`/`advisoryUnlock`), not
  a DB transaction held open across an HTTP call.

## Lock ordering

Every path that touches both must lock `webhook_events` before `orders` —
that ordering is *why* concurrent webhook handlers don't deadlock on each
other. A new code path acquiring them in the other order is a latent
deadlock, not a style nit.

## Order state transitions

`orders.status` must only change via
[OrderService::transition()](../../../src/Service/OrderService.php), using
`UPDATE ... WHERE status = :from` and treating a 0-row result as a lost
race (re-read, don't assume success) — plus an append-only
`order_status_history` row. Flag any direct `UPDATE orders SET status =
...` outside that method, and any transition that skips the history
insert.

## Db::transaction closures

[Db.php](../../../src/Infra/Db.php)'s `transaction()` retries the whole
closure on `40001`/`40P01` (serialization failure/deadlock). A closure
with a side effect outside the database (an HTTP call, a log line meant to
happen once, mutating a shared PHP variable) will run that side effect
more than once on retry — that's a bug, not a hypothetical.

## Ledger balance

[Ledger.php](../../../src/Service/Ledger.php) writes balanced pairs
(`payment_captured`, `delivery_recognized`, `payment_reversed`); posting is
idempotent via a unique index on `(order_id, entry_type, account)`. Any
new ledger-writing code that doesn't produce a balanced pair, or that
could double-post on retry/replay, will show up as a discrepancy in
`GET /admin/reconciliation` / `bin/reconcile.php` — run that (see
`run-tests` skill) against the change if it touches money at all.

## Recovery must stay business-rule-free

[RecoveryService.php](../../../src/Service/RecoveryService.php) (run by
the worker sweeper) should only *restore queue state* — re-enqueue,
reclaim, nudge — never decide what's safe to deliver. If a diff adds a
business decision (e.g. "if stuck for X, mark failed") into the sweeper
instead of `DeliveryService`, that's a layering violation even if it
"works."

## Tests: assert DB state, not response bodies

Per this repo's testing convention, a new integration test that only
checks the HTTP response JSON isn't verifying anything — it should use
`waitForStatus`/`issuedKeys`/`deliveryCount`/`ledgerSum` from
[tests/IntegrationTestCase.php](../../../tests/IntegrationTestCase.php) to
check what actually landed in Postgres.

## Out of scope by design — don't flag these as missing

No webhook signature verification, no automatic refund on `out_of_stock`
(money sits in `deferred_revenue` by design, refund is a manual
`Ledger::paymentReversed` call), no real payment gateway or suppliers
(stubs only), no frontend — see `README.md`'s "Чего здесь нет" section.
These are documented deliberate omissions, not gaps to raise in review.
