# Ядро магазина цифровых товаров

Backend-ядро площадки типа GGSel: заказы, платёжные вебхуки, автоматическая
выдача ключей через двух поставщиков, сверка и восстановление.

**Стек:** PHP 8.3 (без фреймворка), PostgreSQL 16, очередь на самой БД
(`FOR UPDATE SKIP LOCKED`), Docker Compose. Внешних брокеров и зависимостей
времени выполнения нет — вся конкурентная логика опирается на транзакции и
ограничения Postgres, и её видно в коде, а не в чужой библиотеке.

Что покрыто: этапы 1–5 полностью, включая бонусные 4 и 5.

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
make test              # PHPUnit: 10 unit + 24 интеграционных теста
make test-unit
make test-integration
```

Интеграционные тесты работают против поднятого стека и **проверяют состояние
в БД**, а не ответы API: тест не может «пройти», потому что сервис красиво
соврал в JSON.

```
OK (10 tests, 77 assertions)      # unit
OK (24 tests, 294 assertions)     # integration
```

---

## Как воспроизвести проверки приёмки

Один сценарный скрипт закрывает все шесть пунктов критериев:

```bash
make scenarios                 # все 6
make race                      # только #1: 50 параллельных вебхуков
docker compose --profile tools run --rm tools php bin/scenarios.php --only=4,5
```

Вывод (реальный прогон, 52 проверки):

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

## API

| метод | путь | назначение |
|-------|------|-----------|
| `GET`  | `/health` | проверка живости |
| `GET`  | `/catalog?type=&cursor=&limit=` | витрина, keyset-пагинация (этап 5) |
| `GET`  | `/catalog/search?q=` | поиск по названию |
| `GET`  | `/catalog/{sku}` | карточка товара |
| `POST` | `/orders` | создать заказ по SKU (`Idempotency-Key` поддерживается) |
| `GET`  | `/orders/{id}` | заказ + выданный код |
| `GET`  | `/orders/{id}/audit` | полный след: история, вебхуки, попытки, проводки |
| `POST` | `/orders/{id}/deliver` | ручной повтор выдачи (идемпотентно) |
| `POST` | `/webhooks/payment` | вебхук платёжки |
| `GET`  | `/admin/reconciliation` | отчёт сверки |
| `POST` | `/admin/sweep` | прогнать восстановление вручную |
| `GET`  | `/metrics` | счётчики по статусам, вебхукам, поставщикам |

Заглушки поставщиков (`:8081`, `:8082`): `POST /issue`,
`GET /issue/{request_id}` (probe сверки), `GET /stock`, `POST /_control`
(инъекция отказов), `POST /_reset`.

```bash
curl -X POST localhost:8080/orders -H 'Content-Type: application/json' \
     -H 'Idempotency-Key: my-key-1' -d '{"sku":"KEY-CS2-PRIME"}'
curl localhost:8080/orders/ord_00123/audit
php bin/reconcile.php --verbose        # та же сверка в CLI, exit 1 если расхождение
```

---

## Схема данных

`migrations/001_core.sql` — с комментариями к каждому решению.

```
products              каталог + денормализованный available_qty (витрина)
orders                заказ; статус-машина; version
order_status_history  append-only аудит переходов
webhook_events        event_id PRIMARY KEY  <- точка дедупликации платежей
deliveries            order_id UNIQUE, code UNIQUE  <- точка exactly-once выдачи
supplier_requests     request_id + epoch: состояние вызова поставщика
delivery_attempts     каждый HTTP-вызов: исход, код, длительность
orphan_codes          коды, которые не удалось привязать (никогда не теряем)
jobs                  очередь (partial unique: одна живая задача на заказ)
ledger_entries        двойная запись, сумма по txn_id всегда 0
idempotency_keys      идемпотентность POST /orders
stub.keys/.requests/.config    состояние заглушек-поставщиков
```

Три independent-гарантии, которые нельзя обойти из кода:

```sql
webhook_events.event_id  PRIMARY KEY        -- повтор события физически один
deliveries.order_id      UNIQUE             -- два факта выдачи невозможны
deliveries.code          UNIQUE             -- ключ не уйдёт в два заказа
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
`order_status_changed`, `supplier_call`, `supplier_timeout`,
`supplier_probe_resolved_issued|not_issued`, `order_delivered`, `orphan_code`,
`stuck_order_requeued`, `job_finished`.

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
* автоматического возврата денег при `out_of_stock` — это продуктовое
  решение; ядро держит деньги в `deferred_revenue`, показывает заказ в сверке
  и даёт ручку для возврата (`Ledger::paymentReversed`).

Ключевые решения и заметки о масштабировании — в **[NOTES.md](NOTES.md)**.
