---
name: deployment
description: Build and run gamestore-core's production-shaped Docker images. Use when asked to deploy the project, build production images, or explain how this would go to production — clarifies there is no CI/CD pipeline or cloud target configured in this repo.
---

**There is no deployment pipeline in this repo.** No `.github/workflows`,
no Dockerfile targets beyond the single dev-shaped one, no Kubernetes
manifests, no cloud provider config, no `docker-compose.prod.yml`. This is
a self-contained take-home/assessment project (`README.md` /
`composer.json` name `gamestore/core`) meant to run via `docker compose`
on one machine. Don't imply a deployment process exists beyond what's
below — the honest answer to "how do I deploy this" is "the same Docker
Compose stack, run wherever Docker runs."

## What actually exists

[Dockerfile](../../../Dockerfile) builds one image (`php:8.3-cli` +
`pdo_pgsql`/`pcntl` + Composer deps) reused by every service —
`api`/`worker`/`supplier-a`/`supplier-b`/`migrate`/`tools` in
[docker-compose.yml](../../../docker-compose.yml) all build from it, only
the `command:`/`environment:` differ per service. There's no separate
build stage that strips dev dependencies or swaps the built-in `php -S`
server for a real one (php-fpm + nginx, etc.) — `php -S` is explicitly
used even for what would be prod traffic, with
`PHP_CLI_SERVER_WORKERS=16` set in the Dockerfile specifically so the
race-condition tests get genuine concurrency; it is not a production-grade
web server.

## The closest thing to "deploying" it

Build and run the same stack anywhere Docker is available, pointing
`DB_DSN`/`SUPPLIER_*_URL`/etc. (see [.env.example](../../../.env.example)
and the `x-app-env` block in `docker-compose.yml`) at real infrastructure
instead of the compose-network hostnames:

```bash
docker compose build
docker compose up -d
```

That's it — there's no separate "build for prod" step, image registry
push, or orchestration config to reference. If asked to actually stand
this up somewhere (a VM, a cloud VM group, etc.), the honest scope of work
is: provision a host with Docker, copy the repo (or just
`docker-compose.yml` + a registry-pushed image), point env vars at a real
Postgres and real supplier endpoints, run `docker compose up -d`. Anything
beyond that (CI, blue/green, secrets management, TLS termination) doesn't
exist here and would need to be built, not just documented — flag that
explicitly rather than inventing a pipeline.

## Forward-looking notes that already exist (not implemented)

[NOTES.md](../../../NOTES.md)'s "Как масштабировал бы дальше" section
(Russian) describes how the author *would* scale this further: read
replicas, PgBouncer for connection pooling, sharding by `order_id`, and
moving the DB-as-queue to a transactional-outbox pattern feeding
Kafka/SQS. These are design notes, not code — don't cite them as if
they're implemented, and don't attempt to build them speculatively unless
asked.

## Gotchas

- Don't propose adding a full CI/CD pipeline or cloud manifests as a
  "deployment fix" unless explicitly asked — it's a scope decision the
  user hasn't made, not a missing piece of an existing setup.
- The `php -S` built-in server (used for `api` and both supplier stubs) is
  single-threaded per worker process and only reasonable at the
  concurrency this task's compose setup targets — don't present it as
  production-ready without flagging that.
