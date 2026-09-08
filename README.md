# Ядро магазина цифровых товаров

Backend-ядро площадки типа GGSel: заказы из нескольких товаров, платёжные
вебхуки, устойчивая к сбоям (и к нечестности) выдача ключей через двух
поставщиков, лимит на поставщика, сверка и восстановление на любой момент
времени.

**Стек:** PHP 8.3 (без фреймворка), PostgreSQL 16, очередь на самой БД
(`FOR UPDATE SKIP LOCKED`), Docker Compose. Внешних брокеров и зависимостей
времени выполнения нет — вся конкурентная логика опирается на транзакции и
ограничения Postgres, и её видно в коде, а не в чужой библиотеке.

Что покрыто: первое тестовое задание (этапы 1–5, включая бонусные 4 и 5)
плюс второе тестовое целиком — задачи 1 и 2 и обе бонусные (3 и 4). Второй
этап **строится поверх первого**, не переписывая его: миграции `004`/`005`
только добавляют колонки/таблицы, `deliveries.code` и `webhook_events.event_id`
остаются теми же тремя инвариантами БД, на которых стоит вся система.

---

## Быстрый старт

```bash
make up          # или: docker compose build && docker compose up -d
make demo        # заказ -> вебхук -> автовыдача -> аудит
```

Поднимается пять контейнеров:

| сервис       | что это                                   | порт |
|--------------|-------------------------------------------|------|
| `api`        | REST API ядра                             | 8080 |
| `worker`     | фоновые задачи: выдача, sweeper, сток      | —    |
| `supplier-a` | заглушка поставщика A (пул 300 ключей)     | 8081 |
| `supplier-b` | заглушка поставщика B, резерв (200 ключей) | 8082 |
| `db`         | PostgreSQL                                 | 55432 |

Миграции и сиды применяются автоматически (сервис `migrate`).
Полный сброс состояния — `make reset`.

Если `make` не установлен (типично для Windows), всё то же самое напрямую:

| make | docker compose |
|------|----------------|
| `make up` | `docker compose build && docker compose up -d` |
| `make reset` | `docker compose down -v && docker compose up -d --build` |
| `make test` | `docker compose --profile tools run --rm tools ./vendor/bin/phpunit` |
| `make scenarios` | `docker compose --profile tools run --rm tools php bin/scenarios.php` |
| `make chaos` | `docker compose --profile tools run --rm tools php bin/chaos.php` |
| `make explain` | `docker compose --profile tools run --rm tools php bin/explain_showcase.php` |
| `make reconcile` | `docker compose --profile tools run --rm tools php bin/reconcile.php --verbose` |
| `make demo` | `docker compose --profile tools run --rm tools php bin/demo.php` |

> После изменения кода образ `tools` нужно пересобрать:
> `docker compose build tools` (цели `make` делают это сами).

Первыми выдаются **50 ключей из задания**, дальше — синтетические
(`TEST-0001-XXXX`), чтобы тесты можно было прогонять многократно
(`SEED_EXTRA_KEYS=0` оставит ровно 50).

---

## Прогон тестов

```bash
make test              # PHPUnit: 10 unit + 29 интеграционных тестов
make test-unit
make test-integration
```

Интеграционные тесты работают против поднятого стека и **проверяют состояние
в БД**, а не ответы API: тест не может «пройти», потому что сервис красиво
соврал в JSON.

```
OK (10 tests, 78 assertions)      # unit
OK (29 tests, 337 assertions)     # integration
```

Пять из интеграционных тестов — второе тестовое (`BasketPartialFulfillmentTest`,
`UntrustedSupplierTest`): частичная выдача корзины с возвратом денег за
недостижимую позицию, ошибка поставщика после реальной выдачи, дублирующий
код от поставщика.

---

## Как воспроизвести проверки приёмки

Один сценарный скрипт закрывает все критерии первого тестового (1–6) и
второго (7–10 — задачи 1, 2 и обе бонусные):

```bash
make scenarios                 # все 10
make race                      # только #1: 50 параллельных вебхуков
docker compose --profile tools run --rm tools php bin/scenarios.php --only=4,5
docker compose --profile tools run --rm tools php bin/scenarios.php --only=7,8,9,10   # только второе тестовое
```

Вывод первых шести (первое тестовое, реальный прогон, 52 проверки):

```
1) 50 parallel "paid" webhooks for one order
        50 distinct events in 287 ms; results: {"applied":1,"ignored_terminal":49}
  PASS  all 50 webhooks answered 200
  PASS  exactly one delivery row
  PASS  exactly one webhook was applied, the other 49 were no-ops
  PASS  all 50 events were stored (nothing was lost)
  PASS  exactly one key left the supplier pools
...
  52 passed, 0 failed
```

### 1. Гонка: 50 параллельных вебхуков

Заглушка платёжки — `bin/pay.php`, она же генератор гонок (все запросы
стартуют через `curl_multi`, то есть действительно одновременно):

```bash
# 50 РАЗНЫХ событий по одному заказу
docker compose --profile tools run --rm tools \
  php bin/pay.php --order=ord_00123 --amount=1290 --concurrency=50 --mode=distinct

# 50 копий ОДНОГО события (at-least-once редоставка)
docker compose --profile tools run --rm tools \
  php bin/pay.php --order=ord_00123 --amount=1290 --concurrency=50 --mode=same
```

Ожидаемо: `HTTP 200: 50`, из них `applied: 1` и `ignored_terminal: 49`
(или `duplicate: 49` в режиме `same`), одна строка в `deliveries`, один ключ
из пула.

### 2. Повторный вебхук с тем же `event_id`

`--mode=same` выше. `event_id` — первичный ключ `webhook_events`, вставка идёт
`ON CONFLICT DO NOTHING`, дубликат не доходит до бизнес-логики. Тест
`ConcurrentWebhookTest::testReplayAfterDeliveryIsANoOp` снимает слепок
(выдача, история, проводки, `updated_at`) и сверяет его после 20 повторов.

### 3. Вебхук вне порядка

```bash
# вебхук раньше заказа
curl -X POST localhost:8080/webhooks/payment -H 'Content-Type: application/json' \
  -d '{"event_id":"evt_early","order_id":"ord_early_1","status":"paid","amount":1490,"currency":"RUB","created_at":"2025-01-01T12:00:00Z"}'
# -> 200 {"result":"deferred_no_order"}   (не 5xx: платёжка не должна ретраить)

curl -X POST localhost:8080/orders -H 'Content-Type: application/json' \
  -d '{"sku":"SUB-YT-3M","order_id":"ord_early_1"}'
# -> заказ создан, отложенный платёж применён, заказ уходит в выдачу
```

Второй случай — «`failed`, созданный раньше уже применённого `paid`»: события
упорядочиваются по часам эмитента (`created_at`), устаревшее помечается
`ignored_stale` и не трогает оплаченный заказ.

### 4. Таймаут поставщика, который на самом деле выдал код

```bash
# A всегда выдаёт ключ, а потом «зависает» дольше нашего таймаута
curl -X POST localhost:8081/_control -H 'Content-Type: application/json' \
  -d '{"timeout_rate":1,"issue_before_hang":true,"hang_ms":4000}'

docker compose --profile tools run --rm tools php bin/scenarios.php --only=4
```

Проверяется: заказ выдан, `deliveries` = 1, у A ушёл **ровно один** ключ,
B не трогали вообще, все попытки использовали **один и тот же `request_id`**.

### 5. Поставщик A недоступен → фолбэк на B

```bash
curl -X POST localhost:8081/_control -H 'Content-Type: application/json' -d '{"down":true}'
docker compose --profile tools run --rm tools php bin/scenarios.php --only=5
# вернуть A в строй:
curl -X POST localhost:8081/_control -H 'Content-Type: application/json' -d '{"down":false}'
```

### 6. Пустой остаток → восстановление

```bash
curl -X POST localhost:8081/_control -d '{"out_of_stock":true}' -H 'Content-Type: application/json'
curl -X POST localhost:8082/_control -d '{"out_of_stock":true}' -H 'Content-Type: application/json'
docker compose --profile tools run --rm tools php bin/scenarios.php --only=6
```

Заказ уходит в `out_of_stock` (восстановимое состояние, не падение), деньги
остаются в `deferred_revenue`, выручка не признаётся. После снятия флага
фоновая задача сама доводит заказ до `delivered` — ровно один раз.

### Дополнительно: нагрузка + хаос

```bash
make chaos    # 40 заказов, по 5 вебхуков на каждый, оба поставщика: 40% ошибок + 25% таймаутов
```

Реальный прогон: 40/40 доставлено, 40 ключей на 40 заказов (**ноль сожжённых
ключей**), журнал сходится, ни одного `unknown` запроса в остатке.

---

## Второе тестовое задание (этапы 6–9)

Те же принципы, тот же скрипт:

```bash
make scenarios                                # всё — 1..10
docker compose --profile tools run --rm tools php bin/scenarios.php --only=7,8,9,10
```

### 7. Заказ из нескольких товаров, где часть не выдаётся

Корзина — это N строк `order_items`, каждая со своим поставщиком, своим
`request_id` (`req_<order>_<item>_<supplier>_<epoch>`) и своей записью в
`deliveries` (`UNIQUE(item_id)`, `code` по-прежнему уникален глобально).
Сценарий 7 намеренно оставляет в пуле обоих поставщиков ровно 2 свободных
ключа и создаёт заказ из 3 позиций:

```bash
docker compose --profile tools run --rm tools php bin/scenarios.php --only=7
```

Проверяется: 2 позиции выданы и остаются у покупателя, 3-я — честно не может
быть выдана ни у одного поставщика, попадает в `out_of_stock`, и через
`ITEM_GIVE_UP_AFTER_SECONDS` (в докер-стенде — 20 с) фоновый sweeper
возвращает за неё деньги (`Ledger::itemRefunded`) и заказ переходит в
`partially_delivered`. Повторный вызов `/orders/{id}/deliver?mode=sync`
не создаёт ни второй выдачи, ни второго возврата. По деньгам:
`amount_minor` заказа всегда равен сумме выданных плюс сумме возвращённых
позиций — это отдельно проверяется и в `GET /admin/reconciliation`
(`money_mismatched_orders`), и в `bin/reconcile.php`.

Как воспроизвести аварийную остановку прямо в процессе выдачи корзины
(этап 1, пункт 5) вручную:

```bash
curl -X POST localhost:8081/_control -d '{"latency_ms":8000}' -H 'Content-Type: application/json'
curl -X POST localhost:8082/_control -d '{"latency_ms":8000}' -H 'Content-Type: application/json'
# создать и оплатить заказ из нескольких позиций, затем через пару секунд:
docker kill -s KILL <container_worker>
# ... подождать/принудительно состарить lock (locked_at) и вызвать POST /admin/sweep,
# затем поднять воркер заново — docker compose up -d worker
```
Воркер, взявший `order_items` в статус `delivering`, погибает посреди HTTP-вызова
к поставщику; job остаётся `running` с `locked_by` мёртвого воркера.
`JobQueue::reclaimStale()` (часть `RecoveryService::sweep()`, раз в
`WORKER_SWEEP_SECONDS`) возвращает такие job в очередь через 120 с простоя.
Новый воркер дочитывает `supplier_requests` для незавершённой позиции по тому
же `request_id` (идемпотентность поставщика решает, был ли код реально
выдан), довыдаёт остальные позиции и заказ доходит до финального статуса без
дублей — это то же самое поведение, что и в акцептансе 4 первого этапа,
просто теперь на уровне одной позиции корзины, а не всего заказа.

### 8. Поставщик, которому нельзя доверять

`stub.config` умеет: `lie_about_error_rate` (ключ реально зарезервирован и
записан в `stub.requests`, но ответ на `POST /issue` — HTTP-ошибка) и
`duplicate_code_rate` (вместо свободного ключа отдаётся код, уже привязанный
к другому `request_id`, — модель одновременно и «выдал дважды», и «отдал
чужой код»: с точки зрения ядра это один и тот же дефект).

```bash
curl -X POST localhost:8081/_control -d '{"lie_about_error_rate":1}' -H 'Content-Type: application/json'
docker compose --profile tools run --rm tools php bin/scenarios.php --only=8
curl -X POST localhost:8081/_control -d '{"lie_about_error_rate":0,"duplicate_code_rate":0}' -H 'Content-Type: application/json'
```

Защита: любая ошибка/`out_of_stock` от поставщика перед тем, как считаться
окончательной, проходит ту же проверку, что раньше была только для таймаутов
(`DeliveryService::resolveUnknown` — пробник `GET /issue/{request_id}`, затем
идемпотентный повтор `POST /issue`) — если код на самом деле был выдан,
повтор не создаёт вторую выдачу, а просто её обнаруживает. От дублирующего
кода защищает физическое ограничение `deliveries.code UNIQUE`: конфликт
ловится, код никогда не уходит покупателю, `orphan_codes` фиксирует
расхождение с причиной, `request_id` помечается `error`, и в той же попытке
`deliverItem` без вмешательства человека переключается на другого поставщика
— расхождение обнаружено и разобрано автоматически (задача 2, пункт 4).

### 9. Всплеск заказов и лимит поставщика (бонус)

Лимит на вызовы к поставщику — токен-бакет в таблице `supplier_rate_limits`
(`SupplierRateLimiter`), с непрерывным пополнением, общий на все API/worker
процессы. Проверяется на боевом сценарии: B выключен, у A — 3 токена и
пополнение 2/сек, дальше залпом 20 заказов:

```bash
curl -X POST localhost:8080/admin/supplier-rate-limit \
  -d '{"supplier":"A","capacity":3,"refill_per_sec":2}' -H 'Content-Type: application/json'
docker compose --profile tools run --rm tools php bin/scenarios.php --only=9
curl -s localhost:8080/admin/queue    # сколько в очереди, сколько уже выдано, состояние бакета
```

Ничего не теряется (все 20 заказов рано или поздно доставлены), лимит
поставщика не превышается ни разу (`delivery_attempts.http_status = 429`
всегда 0 — заглушке для симметрии тоже можно задать собственный
`rate_limit_per_min`, и она в этом сценарии его ни разу не видит нарушенным).
Оплаченные заказы обслуживаются раньше фоновых: `jobs.priority`
(10 — доставка по свежей оплате или ручной `/deliver`, 100 — задачи,
переставленные в очередь sweeper'ом), `claim()` берёт задачи
`ORDER BY priority, run_after, id`. В этом домене задача на выдачу вообще
никогда не ставится для неоплаченного заказа (её некому ставить), поэтому
приоритет применён на уровень тоньше буквального «оплаченный/неоплаченный» —
подробнее и с обоснованием в [NOTES.md](NOTES.md#этап-8--лимит-поставщика-и-приоритетная-очередь).

### 10. Восстановление картины на любой момент времени (бонус)

Ничего нового в схеме не понадобилось: `order_status_history` (теперь ещё и
на уровне позиции, `item_id`) и `ledger_entries` уже были append-only с
момента этапа 4. `HistoryService` просто берёт последнюю строку с
`created_at <= at`.

```bash
docker compose --profile tools run --rm tools php bin/scenarios.php --only=10

curl "localhost:8080/orders/ord_00123/history?at=2026-01-01T12:00:00Z"
curl "localhost:8080/admin/ledger/period?from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z"
```

Первый эндпоинт отдаёт статус заказа и каждой позиции, а также баланс по
счетам ровно на указанный момент; момент до создания заказа — `existed:false`.
Второй — суммы по типам событий за период с `balances_to_zero`, тем же
двойным инвариантом, что и у живого отчёта, но на срезе истории.

---

## API

| метод | путь | назначение |
|-------|------|-----------|
| `GET`  | `/health` | проверка живости |
| `GET`  | `/catalog?type=&cursor=&limit=` | витрина, keyset-пагинация (этап 5) |
| `GET`  | `/catalog/search?q=` | поиск по названию |
| `GET`  | `/catalog/{sku}` | карточка товара |
| `POST` | `/orders` | создать заказ: `{"sku":...}` (1 товар) или `{"items":[{"sku":...}, ...]}` (корзина), `Idempotency-Key` поддерживается |
| `GET`  | `/orders/{id}` | заказ: статус, позиции с их статусом/кодом; `sku`/`delivery` наверху — для обратной совместимости с заказом на 1 товар |
| `GET`  | `/orders/{id}/audit` | полный след: история (включая по позициям), вебхуки, попытки, проводки, orphan-коды |
| `GET`  | `/orders/{id}/history?at=` | состояние заказа и денег на произвольный момент времени (этап 9, бонус) |
| `POST` | `/orders/{id}/deliver` | ручной повтор выдачи всей корзины (идемпотентно) |
| `POST` | `/webhooks/payment` | вебхук платёжки |
| `GET`  | `/admin/reconciliation` | отчёт сверки (теперь и по позициям, и по деньгам заказа) |
| `GET`  | `/admin/ledger/period?from=&to=` | суммы по журналу за период, из истории (этап 9, бонус) |
| `GET`  | `/admin/queue` | глубина очереди выдачи, сколько уже доставлено, состояние лимитера поставщика (этап 8, бонус) |
| `POST` | `/admin/supplier-rate-limit` | задать лимит на поставщика (`{supplier,capacity,refill_per_sec}`) |
| `POST` | `/admin/sweep` | прогнать восстановление вручную |
| `GET`  | `/metrics` | счётчики по статусам заказов/позиций, вебхукам, поставщикам, задачам |

Заглушки поставщиков (`:8081`, `:8082`): `POST /issue`,
`GET /issue/{request_id}` (probe сверки), `GET /stock`, `POST /_control`
(инъекция отказов — включая `duplicate_code_rate`, `lie_about_error_rate`,
`rate_limit_per_min` из второго тестового), `POST /_reset`.

```bash
curl -X POST localhost:8080/orders -H 'Content-Type: application/json' \
     -H 'Idempotency-Key: my-key-1' -d '{"sku":"KEY-CS2-PRIME"}'

curl -X POST localhost:8080/orders -H 'Content-Type: application/json' \
     -d '{"items":[{"sku":"KEY-CS2-PRIME"},{"sku":"KEY-GTA5"},{"sku":"KEY-EFT"}]}'

curl localhost:8080/orders/ord_00123/audit
php bin/reconcile.php --verbose        # та же сверка в CLI, exit 1 если расхождение
```

---

## Схема данных

`migrations/001_core.sql` — с комментариями к каждому решению (этапы 1–5),
`004_baskets.sql` и `005_untrusted_supplier_and_rate_limit.sql` — второе
тестовое (комментарии там же, по тому же принципу).

```
products              каталог + денормализованный available_qty (витрина)
orders                заказ; статус-машина; version; amount_minor = сумма order_items
order_items            позиция корзины: свой sku, статус, first_undeliverable_at
order_status_history  append-only аудит переходов; item_id = '' — уровень заказа
webhook_events        event_id PRIMARY KEY  <- точка дедупликации платежей
deliveries            item_id UNIQUE, code UNIQUE  <- точка exactly-once выдачи
supplier_requests     item_id + supplier + epoch: состояние вызова поставщика
delivery_attempts     каждый HTTP-вызов: исход, код, длительность
orphan_codes          коды, которые не удалось привязать (никогда не теряем)
jobs                  очередь; priority; partial unique: одна живая задача на заказ
ledger_entries        двойная запись; item_id (''=заказ) в ключе идемпотентности
supplier_rate_limits  токен-бакет на поставщика (лимит со стороны ядра)
idempotency_keys      идемпотентность POST /orders
stub.keys/.requests/.config/.call_log    состояние заглушек-поставщиков
```

Три independent-гарантии, которые нельзя обойти из кода:

```sql
webhook_events.event_id  PRIMARY KEY        -- повтор события физически один
deliveries.item_id       UNIQUE             -- у позиции максимум одна выдача
deliveries.code          UNIQUE             -- ключ не уйдёт в две позиции/заказа
```

---

## Наблюдаемость

Структурированные JSON-логи в stdout, у каждой записи `trace_id`
(проброшенный из HTTP-заголовка `X-Trace-Id` и переживающий переход в фоновую
задачу):

```bash
make logs
docker compose logs worker | grep supplier_timeout
```

Ключевые события: `webhook_received`, `webhook_duplicate`, `payment_captured`,
`order_status_changed`, `item_status_changed`, `supplier_call`, `supplier_timeout`,
`supplier_probe_resolved_issued|not_issued`, `item_delivered`, `orphan_code`,
`stuck_order_requeued`, `item_refunded_after_timeout`, `supplier_rate_limited`,
`job_finished`.

---

## Этап 5: витрина под нагрузкой

```bash
make explain
```

Скрипт печатает планы на 5 012 SKU (объём настраивается `SEED_EXTRA_SKUS`).
Разбор — в [NOTES.md](NOTES.md#этап-5--витрина-под-нагрузкой).

---

## Чего здесь нет (сознательно)

* фронтенда — только backend;
* настоящего эквайринга и настоящих поставщиков — только заглушки;
* проверки подписи вебхука — по условию задания упрощено;
* реального платёжного возврата через PSP — второе тестовое просило
  корректный **учёт** денег, а не интеграцию со внешним возвратом; ядро
  доводит невыданную позицию до `refunded` и корректно проводит это по
  журналу (`Ledger::itemRefunded`), дальше это уже вызов внешнего API
  платёжки, которого в задании и на входе нет.

Ключевые решения и заметки о масштабировании — в **[NOTES.md](NOTES.md)**.
