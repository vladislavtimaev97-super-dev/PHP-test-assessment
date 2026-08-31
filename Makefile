COMPOSE ?= docker compose
TOOLS   := $(COMPOSE) --profile tools run --rm tools

.PHONY: help up down reset logs build test test-unit test-integration \
        scenarios race chaos explain reconcile demo psql shell

help:
	@echo "make up          build and start the stack (api, worker, 2 suppliers, postgres)"
	@echo "make reset       wipe the database and start clean"
	@echo "make test        unit + integration tests (PHPUnit)"
	@echo "make scenarios   the 6 acceptance scenarios from the task"
	@echo "make race        acceptance #1 only: 50 parallel webhooks"
	@echo "make chaos       40 orders against suppliers failing 40% / hanging 25%"
	@echo "make explain     execution plans for the storefront query (stage 5)"
	@echo "make reconcile   reconciliation report"
	@echo "make demo        create + pay one order and print it"
	@echo "make logs        follow the structured logs"
	@echo "make down        stop everything"

build:
	$(COMPOSE) build

up: build
	$(COMPOSE) up -d
	@echo "api        http://localhost:8080"
	@echo "supplier A http://localhost:8081"
	@echo "supplier B http://localhost:8082"

down:
	$(COMPOSE) down

reset:
	$(COMPOSE) down -v
	$(COMPOSE) build
	$(COMPOSE) up -d

logs:
	$(COMPOSE) logs -f api worker supplier-a supplier-b

test: build
	$(TOOLS) ./vendor/bin/phpunit

test-unit: build
	$(TOOLS) ./vendor/bin/phpunit --testsuite unit

test-integration: build
	$(TOOLS) ./vendor/bin/phpunit --testsuite integration

scenarios: build
	$(TOOLS) php bin/scenarios.php

race: build
	$(TOOLS) php bin/scenarios.php --only=1

chaos: build
	$(TOOLS) php bin/chaos.php --orders=40 --webhooks=5

explain: build
	$(TOOLS) php bin/explain_showcase.php

reconcile: build
	$(TOOLS) php bin/reconcile.php --verbose

demo: build
	$(TOOLS) php bin/demo.php

psql:
	$(COMPOSE) exec db psql -U gamestore -d gamestore

shell:
	$(TOOLS) bash
