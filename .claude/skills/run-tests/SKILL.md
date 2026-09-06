---
name: run-tests
description: Run the PHPUnit unit/integration suites, the acceptance scenarios, the race/chaos generators, and reconciliation checks for gamestore-core. Use when asked to run tests, run a single test, reproduce an acceptance scenario, run the race condition test, or run chaos testing.
---

All tests run **inside the `tools` Docker Compose profile**, not on the
host — there is no host PHP/Composer install to run them with. All paths
below are relative to the repo root.

## Prerequisites

The stack must be built and, for integration tests, running:

```bash
docker compose build tools     # rebuild after any code change
docker compose up -d           # integration tests need api/worker/suppliers/db reachable
```

`tests/bootstrap.php` waits up to 60s for health on api + both suppliers +
db before letting integration tests run — if the stack isn't up yet it'll
just wait, then time out.

## Run all tests

```bash
docker compose --profile tools run --rm tools ./vendor/bin/phpunit
# = make test
```

Unit only (no network, no running stack needed): `make test-unit` /
`docker compose --profile tools run --rm tools ./vendor/bin/phpunit --testsuite unit`

Integration only (needs the full stack up): `make test-integration` /
`docker compose --profile tools run --rm tools ./vendor/bin/phpunit --testsuite integration`

Suite membership is defined in [phpunit.xml](../../../phpunit.xml):
`tests/Unit` = unit, `tests/Integration` = integration.

## Run a single test file or method

```bash
docker compose --profile tools run --rm tools ./vendor/bin/phpunit tests/Unit/OrderStatusTest.php

docker compose --profile tools run --rm tools ./vendor/bin/phpunit \
  --filter testReplayAfterDeliveryIsANoOp tests/Integration/ConcurrentWebhookTest.php
```

## Acceptance scenarios / race / chaos

These reproduce the task's 6 acceptance scenarios and load/fault-injection
behavior, and are separate from PHPUnit:

```bash
docker compose --profile tools run --rm tools php bin/scenarios.php            # all 6, = make scenarios
docker compose --profile tools run --rm tools php bin/scenarios.php --only=1   # just #1, = make race (50 parallel webhooks)
docker compose --profile tools run --rm tools php bin/scenarios.php --only=4,5
docker compose --profile tools run --rm tools php bin/chaos.php --orders=40 --webhooks=5   # = make chaos
docker compose --profile tools run --rm tools php bin/reconcile.php --verbose              # = make reconcile; exits 1 on discrepancy
docker compose --profile tools run --rm tools php bin/explain_showcase.php                 # = make explain: query plans for the storefront query
```

## Test data hygiene

Every integration test's `setUp`/`tearDown` resets both supplier stubs to
healthy via `POST /_control` (see
[tests/IntegrationTestCase.php](../../../tests/IntegrationTestCase.php)) —
fault injection from one test (timeouts, out-of-stock, failure rates) must
not leak into the next. If you hand-write a new integration test, follow
the same pattern rather than assuming stubs start clean.

## What integration tests actually assert

They check **database state** (`waitForStatus`, `issuedKeys`,
`deliveryCount`, `ledgerSum` helpers in `IntegrationTestCase`), not API
response bodies — a 200 with a nice JSON body isn't proof the order was
actually delivered or the ledger balanced.

## Gotchas

- Forgetting `docker compose build tools` after an edit means the test
  run uses stale code baked into the image — silent false negatives (or
  false positives).
- Integration tests will hang waiting on `tests/bootstrap.php`'s health
  check if the stack isn't up (`docker compose up -d` first), rather than
  failing fast.
- `bin/chaos.php` and `bin/scenarios.php` are not part of the PHPUnit run
  and won't show up in `make test` output — run them separately if the
  task calls for reproducing an acceptance scenario or chaos/race
  behavior specifically.
