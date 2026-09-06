---
name: db-migrations
description: Add, run, and inspect PostgreSQL schema migrations for gamestore-core. Use when asked to add a migration, change the database schema, run migrations, check what's been applied, or connect to the database.
---

Migrations are plain numbered `.sql` files in
[migrations/](../../../migrations/), applied in filename order by
[bin/migrate.php](../../../bin/migrate.php) — there is no migration
framework/library involved. All paths below are relative to the repo root.

## How it works

- `bin/migrate.php` creates a `schema_migrations(filename PK, applied_at)`
  tracking table if missing, then for each `migrations/*.sql` file (sorted
  by name) not already recorded there: runs the file's SQL and inserts the
  tracking row, **in one transaction** — a migration either fully applies
  or leaves no trace.
- It runs automatically as the one-shot `migrate` Compose service on every
  `docker compose up` (before `api`/`worker`/suppliers are allowed to
  start), and again is safe to re-run any time — already-applied files are
  skipped.
- Current migrations: `001_core.sql`, `002_supplier_stub.sql`,
  `003_catalog_showcase.sql` (the last one adds the covering partial index
  the storefront keyset-pagination query relies on — see
  [src/Service/CatalogService.php](../../../src/Service/CatalogService.php)).

## Add a new migration

1. Create the next-numbered file, e.g. `migrations/004_<description>.sql`
   — numeric prefix controls apply order, so it must sort after
   everything already applied.
2. Write plain SQL (DDL/DML) — no special syntax, no `up`/`down` split,
   no rollback mechanism. If a change needs to be reversible, write a
   *new* forward migration that undoes it rather than editing an already-
   applied file.
3. Never edit a migration file that may already be applied anywhere
   (`schema_migrations` on any running/shared DB) — `bin/migrate.php`
   keys on filename, not content hash, so an edited-but-already-recorded
   file silently never re-runs.

## Run migrations

```bash
docker compose --profile tools run --rm tools php bin/migrate.php
```

Normally you don't need to run this manually — `docker compose up -d` /
`make up` runs it as part of stack startup. Use the direct form to apply a
newly added migration to an already-running stack without restarting
everything, or to see its log output directly.

To re-seed after schema changes: `docker compose --profile tools run --rm
tools php bin/seed.php` (seeding is separate from migration and controlled
by `SEED_EXTRA_SKUS` in the compose env).

## Inspect the database

```bash
make psql
# = docker compose exec db psql -U gamestore -d gamestore
```

```sql
select * from schema_migrations order by applied_at;
\d orders
\d deliveries
```

## Start from a clean database

```bash
docker compose down -v      # drops the Postgres volume entirely
docker compose up -d        # migrate + seed run fresh
# or: make reset (build + up in one step)
```

## Gotchas

- Migrations apply inside a single `Db::transaction()` per file — a
  multi-statement file that partially fails rolls back entirely, but a
  later *separate* migration file that already committed does not roll
  back with it. Keep each migration focused so partial-apply-then-fail
  scenarios are easy to reason about.
- The three correctness-critical unique constraints
  (`webhook_events.event_id`, `deliveries.order_id`,
  `deliveries.code` — see `CLAUDE.md`) live in these migration files. Any
  change touching them needs to be reviewed against
  [NOTES.md](../../../NOTES.md)'s "Этап 2 — exactly-once под гонками"
  section, not just applied as a normal schema tweak.
- `migrate` failing (non-zero exit) blocks every other service from
  starting at all, because they `depends_on: migrate: condition:
  service_completed_successfully` — check `docker compose logs migrate`
  first if the stack won't come up after adding a migration.
