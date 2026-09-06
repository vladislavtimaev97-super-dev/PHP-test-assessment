---
name: run-project
description: Build, start, stop, and smoke-test the gamestore-core Docker Compose stack (api, worker, supplier-a, supplier-b, db). Use when asked to run the project, start/stop the app, bring the stack up, check service health, or try the create-order-and-pay flow.
---

Digital-goods store core (PHP 8.3, no framework, PostgreSQL 16). The whole
system runs as 5 Docker Compose services; there is no way to run it outside
Docker because migrations, seeding, and inter-service URLs all assume the
compose network. All paths below are relative to the repo root.

## Prerequisites

Docker + Docker Compose v2 (`docker compose version`). Nothing else — PHP
and Postgres only exist inside the containers.

## Build & start

```bash
docker compose build          # equivalent to `make up`'s build step
docker compose up -d          # starts db -> migrate (one-shot) -> api/worker/supplier-a/supplier-b
```

`make up` does both in one step and prints the URLs. `make reset` is the
destructive variant — `docker compose down -v && docker compose build &&
docker compose up -d` — it wipes the Postgres volume, so only use it when a
clean DB is actually wanted.

Startup order is enforced by `depends_on: condition: service_healthy /
service_completed_successfully` in [docker-compose.yml](../../../docker-compose.yml):
`db` must pass its `pg_isready` healthcheck, then the one-shot `migrate`
service runs `bin/migrate.php && bin/seed.php` and must exit 0, before
`api`/`worker`/`supplier-a`/`supplier-b` start.

Check everything is up:

```bash
docker compose ps
```

All of `api`, `db`, `supplier-a`, `supplier-b`, `worker` should show `Up`
(`db` additionally shows `healthy`); `migrate` shows `Exited (0)` — that's
expected, it's one-shot.

## Run (agent path)

Once the stack is up, drive it with plain `curl` — every route is listed in
[src/Api/Api.php](../../../src/Api/Api.php) and
[public-supplier/index.php](../../../public-supplier/index.php):

```bash
# health
curl -s http://localhost:8080/health   # api
curl -s http://localhost:8081/health   # supplier-a
curl -s http://localhost:8082/health   # supplier-b

# create an order
curl -s -X POST http://localhost:8080/orders -H 'Content-Type: application/json' \
  -d '{"sku":"KEY-CS2-PRIME"}'
# -> {"id": "...", "status": "pending_payment", "amount": ..., ...}

# simulate the payment gateway's webhook (order_id and amount from the response above)
curl -s -X POST http://localhost:8080/webhooks/payment -H 'Content-Type: application/json' \
  -d '{"event_id":"evt-1","order_id":"<id>","amount":<amount>}'

# poll until delivered, then read the full trace
curl -s http://localhost:8080/orders/<id>
curl -s http://localhost:8080/orders/<id>/audit
```

Other useful routes: `GET /catalog`, `GET /catalog/{sku}`, `GET
/admin/reconciliation`, `GET /admin/jobs`, `POST /admin/sweep`, `GET
/metrics`.

For the full guided version of the same flow (create → pay → wait for
delivery → print audit trail), just run the project's own demo script,
which already wraps this in a polling loop:

```bash
docker compose --profile tools run --rm tools php bin/demo.php
# or: docker compose --profile tools run --rm tools php bin/demo.php --sku=KEY-CS2-PRIME
```

Ports: api `:8080`, supplier-a `:8081`, supplier-b `:8082`, Postgres
`:55432` on the host (`make psql` / `docker compose exec db psql -U
gamestore -d gamestore`).

## Logs

```bash
docker compose logs -f api worker supplier-a supplier-b   # = make logs
```

Logs are structured JSON lines (see [src/Infra/Log.php](../../../src/Infra/Log.php));
every entry carries a `trace_id` — pass `-H 'X-Trace-Id: <id>'` on a curl
call to correlate it across api → worker in the logs.

## Stop

```bash
docker compose down       # = make down: stop containers, keep the DB volume
docker compose down -v    # wipe the DB volume too
```

## Gotchas

- There's no host-installed PHP/Composer expected — `vendor/` only exists
  inside the built images. Don't try to run `bin/*.php` scripts with a
  host PHP; use `docker compose --profile tools run --rm tools php bin/...`.
- If you rebuild after editing code, rebuild the `tools` image too before
  running scripts/tests against it: `docker compose build tools`.
- `migrate` exiting non-zero blocks `api`/`worker`/suppliers from starting
  at all (compose `service_completed_successfully` dependency) — check
  `docker compose logs migrate` first if the stack seems stuck.
