# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A GGSel-style digital-goods store core: orders, payment webhooks, automatic key
delivery through two supplier stubs, reconciliation, and recovery. PHP 8.3, no
framework, PostgreSQL 16, a queue implemented on the database itself
(`FOR UPDATE SKIP LOCKED`). No external brokers or runtime dependencies — all
concurrency correctness lives in Postgres transactions/constraints and is
visible in this repo's code, not hidden in a library.

README.md and NOTES.md (both in Russian) are the authoritative design
documents — read NOTES.md before touching concurrency, delivery, or
reconciliation logic; it explains *why*, not just *what*. NOTES.md also has
sections on future scaling ("Как масштабировал бы дальше": read replicas,
PgBouncer, sharding by `order_id`, a transactional-outbox move to
Kafka/SQS) and on deliberate trade-offs made for this task's scope
("Осознанные компромиссы").

## Commands

Everything runs through Docker Compose; there is a `Makefile` wrapper but
`make` may be unavailable (e.g. plain Windows) — the `docker compose`
equivalents work identically:

```bash
make up                 # docker compose build && docker compose up -d
make reset              # docker compose down -v && docker compose up -d --build (wipes DB)
make down               # stop the stack
make logs               # follow structured logs from api/worker/supplier-a/supplier-b

make test               # docker compose --profile tools run --rm tools ./vendor/bin/phpunit
make test-unit          #   ... --testsuite unit
make test-integration   #   ... --testsuite integration

make scenarios          # php bin/scenarios.php   — all 6 acceptance scenarios
make race               # php bin/scenarios.php --only=1   — 50 parallel webhooks
docker compose --profile tools run --rm tools php bin/scenarios.php --only=4,5

make chaos              # php bin/chaos.php --orders=40 --webhooks=5
make explain            # php bin/explain_showcase.php — query plans for the storefront query
make reconcile          # php bin/reconcile.php --verbose (exit 1 on discrepancy — cron/CI-friendly)
make demo               # php bin/demo.php — create + pay one order, print result
make psql               # docker compose exec db psql -U gamestore -d gamestore
make shell              # shell inside the tools container
```

**After changing code, rebuild the `tools` image** before running tests/scripts
against it: `docker compose build tools` (the `make` targets do this
automatically via their `build` dependency).

Single test / single test method (inside the tools container, or via
`docker compose --profile tools run --rm tools`):

```bash
./vendor/bin/phpunit tests/Unit/OrderStatusTest.php
./vendor/bin/phpunit --filter testReplayAfterDeliveryIsANoOp tests/Integration/ConcurrentWebhookTest.php
```

Unit tests (`tests/Unit`) don't touch the network. Integration tests
(`tests/Integration`) require the full stack up (api + workers + both supplier
stubs + db reachable) — `tests/bootstrap.php` waits up to 60s for health on
each before running.

Five containers when the stack is up: `api` (:8080), `worker` (background:
delivery jobs, sweeper, stock sync), `supplier-a` (:8081, 300-key pool),
`supplier-b` (:8082, 200-key pool, fallback), `db` (Postgres, :55432 on host).
Migrations and seeds run automatically via the one-shot `migrate` service.

## Architecture

**No framework, ~80-line router.** [src/Http/Router.php](src/Http/Router.php)
does regex path matching + method dispatch; [src/Api/Api.php](src/Api/Api.php)
registers every route inline as a closure calling into a `Service`. Entry
points: [public/index.php](public/index.php) (core API) and
[public-supplier/index.php](public-supplier/index.php) (the supplier-stub
API, [src/Stub/SupplierStub.php](src/Stub/SupplierStub.php) — a separate app
sharing the same image, distinguished by `SUPPLIER_NAME` env and mounted on
its own Postgres schema `stub`; the core never reads that schema, only calls
it over HTTP).

**The queue lives in Postgres, not a broker.** [src/Service/JobQueue.php](src/Service/JobQueue.php)
enqueues a delivery job in the *same transaction* that flips an order to
`paid` — avoiding the dual-write problem an external broker would introduce.
Workers claim jobs with `FOR UPDATE SKIP LOCKED` so any number of workers
scale without contention; a partial unique index `(type, order_id) WHERE
status IN ('queued','running')` guarantees one live delivery job per order no
matter how many webhooks fire.

**Three unique constraints are the actual correctness guarantee** (everything
else is optimization on top of them):

```sql
webhook_events.event_id  PRIMARY KEY   -- a webhook replay cannot be processed twice
deliveries.order_id      UNIQUE        -- an order cannot be delivered twice
deliveries.code          UNIQUE        -- a key cannot be attached to two orders
```

**Delivery timeout handling is the core design problem**
([src/Service/DeliveryService.php](src/Service/DeliveryService.php)). A
supplier call can end in three states, not two:

| outcome | meaning | safe to fail over to the other supplier? |
|---|---|---|
| `error` (4xx/5xx) | supplier answered, issued nothing | yes |
| `unavailable` (refused/DNS/connect-timeout) | never reached the supplier | yes |
| `timeout` (connected, no response) | **unknown** | **no** |

Distinguishing `unavailable` from `timeout` uses `CURLINFO_CONNECT_TIME`, checked
in [src/Infra/HttpClient.php](src/Infra/HttpClient.php) (which every supplier
call goes through). [src/Service/SupplierGateway.php](src/Service/SupplierGateway.php)
holds the registry and preference order (`SUPPLIER_ORDER=A,B` env) that
`DeliveryService` fails over across. While
any `supplier_requests` row for an order is `unknown`, delivery never fails
over — it retries the same deterministic `request_id`
(`req_<order>_<supplier>_<epoch>`), which doubles as an idempotency key at the
supplier, then probes `GET /issue/{request_id}` (404 = authoritative "nothing
issued", safe to move on). `epoch` only increments after a *definite* failure
— retrying the same request_id after `out_of_stock` would replay the
supplier's cached failure forever and the order could never recover once
stock returns. No supplier HTTP call happens inside an open DB transaction
(would hold a row lock for the length of the call); cross-worker exclusion for
one order's delivery uses a session advisory lock
(`Db::tryAdvisoryLock`/`advisoryUnlock`) instead.

**Money is double-entry** ([src/Service/Ledger.php](src/Service/Ledger.php)).
Every event writes a balanced pair (`payment_captured`, `delivery_recognized`,
`payment_reversed`); a unique index `(order_id, entry_type, account)` makes
posting idempotent. The reconciliation cross-check
([src/Service/ReconciliationService.php](src/Service/ReconciliationService.php),
`GET /admin/reconciliation` / `bin/reconcile.php`, non-zero exit on
discrepancy) is: `deferred_revenue` must equal the value of all paid-but-not-
delivered orders.

**Recovery is business-rule-free**
([src/Service/RecoveryService.php](src/Service/RecoveryService.php), run by
the worker's sweeper every `WORKER_SWEEP_SECONDS`): applies webhooks that
arrived before their order existed, re-enqueues paid-but-undelivered orders
with no live job, reclaims jobs from dead workers, nudges `unknown` supplier
requests toward resolution. It only restores queue state; the delivery path
itself decides what is actually safe to do.

**Db helper** ([src/Infra/Db.php](src/Infra/Db.php)):
`Db::transaction()` retries the whole closure on `40001`/`40P01`
(serialization failure/deadlock) — closures passed to it must be free of
side effects outside the database. `Db::run/one/all/value` are thin PDO
wrappers with named params. Lock ordering across the codebase is always
`webhook_events` → `orders`, which is why concurrent webhook handlers never
deadlock on each other.

**Order state machine**: [src/Domain/OrderStatus.php](src/Domain/OrderStatus.php)
defines allowed transitions; [src/Service/OrderService.php](src/Service/OrderService.php)'s
`transition()` is the only place that changes `orders.status`, always via
`UPDATE ... WHERE status = :from` (a 0-row result means a lost race, and the
caller re-reads rather than assuming success) plus an append-only row in
`order_status_history`. `OrderService` also owns order creation (idempotency
key on `POST /orders`) and assembles `GET /orders/{id}/audit`.

**Webhook intake**: [src/Service/PaymentWebhookService.php](src/Service/PaymentWebhookService.php)
is where webhook dedupe (`webhook_events.event_id` PK), the
`webhook_events` → `orders` lock ordering, and the paid-transition +
job-enqueue all happen inside one transaction.

**Storefront query (stage 5)** ([src/Service/CatalogService.php](src/Service/CatalogService.php)):
`available_qty` is denormalized onto `products` (updated by delivery and a
periodic stock sync) specifically so the hot listing query never joins or
calls a supplier. Pagination is keyset-based (`popularity, sku` cursor), not
`OFFSET`, so deep pages cost the same as page 1 — see
[migrations/003_catalog_showcase.sql](migrations/003_catalog_showcase.sql)
for the covering partial index this relies on.

**Structured logging** ([src/Infra/Log.php](src/Infra/Log.php)): JSON lines to
stdout, every entry carries a `trace_id` propagated from the `X-Trace-Id`
request header into `jobs.trace_id` and across the async hand-off to the
worker. `GET /orders/{id}/audit` surfaces the same trail (status history,
webhooks, supplier requests/attempts, ledger entries, jobs) via the API.

## Testing conventions

Integration tests assert against **database state**, not API response bodies
— a service returning a nice-looking JSON body is not proof anything actually
happened. `tests/IntegrationTestCase.php` provides helpers (`createOrder`,
`pay` — sends N webhooks concurrently via `curl_multi` under the hood via
`WebhookSender`, `waitForStatus`, `issuedKeys`, `deliveryCount`, `ledgerSum`)
used across `tests/Integration/*`. Each test's `setUp`/`tearDown` resets both
supplier stubs to healthy via `POST /_control` — chaos/fault injection set in
one test must not leak into the next.

`bin/pay.php` is both the payment-gateway stub *and* the race-condition
generator (`--concurrency=N --mode=distinct|same`, fires via `curl_multi` for
genuine simultaneity). `bin/scenarios.php --only=N` reproduces the six
acceptance scenarios from the task individually; `bin/chaos.php` runs a larger
load with injected supplier failures/timeouts and asserts zero burned/lost
keys at the end. `bin/migrate.php` and `bin/seed.php` back the one-shot
`migrate` compose service; `bin/worker.php` is the `worker` container's
entrypoint (delivery jobs, sweeper, stock sync).

## Deliberate omissions (see README "Чего здесь нет")

No frontend, no real payment gateway/suppliers (stubs only), no webhook
signature verification (simplified per task scope), no automatic refund on
`out_of_stock` (money is held in `deferred_revenue`, visible in
reconciliation, with `Ledger::paymentReversed` as the manual hook — refund
timing is a product decision, not a core-engine one).
