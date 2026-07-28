# Task 28 — Реалізація SOA (E-Commerce PrintZone)

**Мета:** втілити SOA-дизайн з **Task-27** у працюючий код: автономні грубозернисті
сервіси (своя схема БД + DDD), централізований IAM, комунікація SOAP/WS-* + AMQP через
**ESB** з оркестрацією checkout, канонічна модель даних на дроті. Повне тестування
(unit / integration / e2e).

**Джерело дизайну:** `C:\Foxminded\Task-27\` (docs 01–06 + `contracts/`).
**Репозиторій-мішень:** `git@git.foxminded.ua:foxmidedteam/task-28.git` (порожній).

## Рамки (scope) — ВСІ 5 сервісів

Реалізуємо **повний набір з 5 грубозернистих сервісів** дизайну Task-27:
**Product & Inventory (PI)**, **Customer Management (CM/IAM)**, **Order Management (OM)**,
**Fulfillment (FF)**, **Notification (NT)** + **ESB**. Порядок розробки — за матрицею
пріоритизації §04 (PI → CM → OM спершу, далі FF → NT).

Наскрізний сценарій (повний): *переглянув каталог → зареєструвався/увійшов → checkout →
оплата (FF) → відправлення (FF) → сповіщення (NT)*.

**Стек:** PHP 8.2 / Symfony 7.4, вбудований SoapServer/SoapClient, Postgres-per-service,
RabbitMQ (async + ESB-медіація), Redis (кеш), Docker Compose. Зовнішні інтеграції для FF/NT
(Stripe / Нова Пошта / SES) — за дизайном, у скелеті через fake-провайдери з реальним seam.

**SOA-ознаки, які код МУСИТЬ показати** (інакше = мікросервіси, ризик відхилення):
- [ ] Комунікація **через ESB**, а не сервіс→сервіс напряму.
- [ ] **Канонічна модель** (XSD) на дроті; ESB трансформує канон ↔ внутрішній формат.
- [ ] **Оркестрація** checkout у ESB (центральний процес тримає стан), не хореографія.
- [ ] **Контракт-first** SOAP/WSDL (document/literal) для всіх 5 сервісів.
- [ ] **Централізований IAM** — єдина видача/перевірка ідентичності для всіх сервісів.
- [ ] Governance-артефакт: простий **service registry** (каталог WSDL усіх 5).

---

## Фаза 0 — Каркас та інфраструктура ✅
- [x] Структура репо: `contracts/`, `services/{customer-management,product-inventory,order-management,fulfillment,notification}/`, `esb/`, `tests/`, `docs/`.
- [x] Перенести з Task-27 усі контракти: `canonical-data-model.xsd` + 5 WSDL (order/customer/product/fulfillment/notification) у `contracts/`.
- [x] `docker-compose.yml`: 4× Postgres (customer/product/order/fulfillment — NT stateless), RabbitMQ, Redis, 5 php-сервісів, esb. (nginx не потрібен — вбудований `php -S`; назовні лише ESB:8080.)
- [x] `.env` / `.env.example` (DSN per service, RabbitMQ, Redis, зовнішні креди — заглушки).
- [x] Скелет Symfony (MicroKernelTrait) у кожному сервісі — спільний код, `SERVICE_NAME` через env; health-endpoint.
- [x] README-заготовка + `docs/todo.md` (цей файл).
- **Verify ✅:** усі 12 контейнерів up; `/health` кожного з 6 сервісів → `200 {"status":"ok"}`; ESB доступний з хоста на `:8080`, сервіси — лише внутрішньо.

## Фаза 1 — Customer Management (централізований IAM)
- [ ] DDD-домен: агрегати `Customer`, `Address`, `Credential`; VO `Email`, спільний `Money`.
- [ ] Своя схема БД (`customer`), Doctrine-міграція.
- [ ] SOAP-endpoint за WSDL: `RegisterCustomer`, `Authenticate`, `GetCustomer`, `UpdateAddress`.
- [ ] **IAM:** видача токена ідентичності (JWT/WS-Security UsernameToken) при `Authenticate`; ключі підпису — централізовано.
- [ ] Мапінг канонічний `Customer` ↔ внутрішня доменна модель.
- [ ] Unit-тести домену.
- **Verify:** SOAP `RegisterCustomer` → `Authenticate` повертає валідний токен; збережено в БД.

## Фаза 2 — Product & Inventory
- [ ] DDD-домен: `Product`, `Category`, `StockItem` (резервування як доменна операція).
- [ ] Своя схема БД (`catalog`), міграція + фікстури-сідери (каталог для e2e).
- [ ] SOAP-endpoint: `SearchProducts`, `GetProduct`, `CheckStock`, `ReserveStock`.
- [ ] **Redis-кеш** для читання каталогу (`SearchProducts`/`GetProduct`).
- [ ] Unit-тести (резервування, брак залишку → fault).
- **Verify:** `ReserveStock` зменшує доступний залишок атомарно; понад залишок → SOAP Fault.

## Фаза 3 — Order Management
- [ ] DDD-домен: `Cart`, `Order`, `OrderLine` (Cart всередині OM — грубіша зернистість SOA).
- [ ] Своя схема БД (`orders`), міграція.
- [ ] SOAP-endpoint: `CreateCart`, `AddCartItem`, `Checkout`, `GetOrder`, `CancelOrder`.
- [ ] Order lifecycle: PENDING → PAID → PROCESSING → SHIPPED → DELIVERED → CANCELLED.
- [ ] Unit-тести (сума кошика, переходи статусів).
- **Verify:** `Checkout` створює Order у стані PENDING з коректним total.

## Фаза 4 — Fulfillment (оплата + доставка)
- [ ] DDD-домен: `Payment`, `Shipment`, `TrackingEvent` (event-sourced трекінг).
- [ ] Своя схема БД (`fulfillment`), міграція.
- [ ] SOAP-endpoint: `AuthorizePayment`, `CapturePayment`, `CreateShipment`, `TrackShipment`.
- [ ] Провайдери за seam: `PaymentProvider` (Stripe/fake), `ShippingProvider` (НоваПошта/fake); default = fake.
- [ ] Публікація подій `PaymentCaptured`, `ShipmentDispatched` (AMQP).
- [ ] Unit-тести (авторизація→capture, ідемпотентність shipment).
- **Verify:** `AuthorizePayment` (fake) → `CapturePayment` → подія; `CreateShipment` → трекінг.

## Фаза 5 — Notification (stateless утиліта)
- [ ] `SendNotification` (SOAP) + async-споживач подій (`OrderPlaced`, `PaymentCaptured`, `ShipmentDispatched`).
- [ ] Email-шлюз за seam (SES/fake); шаблони листів (order confirmation, shipment dispatched).
- [ ] Без власної БД (stateless) — за дизайном.
- [ ] Unit-тести (вибір шаблону за подією).
- **Verify:** подія `OrderPlaced` у черзі → NT формує і «відправляє» лист (fake-gateway лог).

## Фаза 6 — ESB (серце SOA)
- [ ] SOAP-шлюз (єдина точка входу клієнта); клієнти не звертаються до сервісів напряму.
- [ ] **Content-based routing** до CM/PI/OM/FF за операцією/namespace.
- [ ] **Канонічна трансформація** (канон ↔ внутрішній формат сервісу).
- [ ] **Оркестрація Checkout** (BPEL-стиль, стан процесу в ESB) за §05:
      `ReserveStock`(PI) → `CreateOrder`(OM) → `AuthorizePayment`(FF, sync) → `MarkPaid`(OM) →
      `CreateShipment`(FF, async AMQP) → `SendOrderConfirmation`(NT, async); компенсація при збої (звільнити резерв / скасувати).
- [ ] **WS-Security / централізована перевірка токена** IAM на вході в ESB.
- [ ] **Service registry:** статичний каталог WSDL усіх 5 (JSON/endpoint) для discovery.
- **Verify:** один SOAP `Checkout` до ESB проганяє весь ланцюг PI→OM→FF→NT; при падінні PI резерв звільнено.

## Фаза 7 — Асинхрон (AMQP) + кеш (наскрізне)
- [ ] RabbitMQ: `OrderPlaced`, `StockLevelChanged`, `PaymentCaptured`, `ShipmentDispatched` (медіація ESB).
- [ ] Споживачі подій (NT + проекція залишків PI) — демонстрація async-стилю.
- [ ] Redis під читання каталогу; інвалідація після `ReserveStock`.
- **Verify:** після Checkout події лягають у черги; споживачі їх обробляють.

## Фаза 8 — Тестування
- [ ] **Unit** — домен кожного з 5 сервісів (ізольовано).
- [ ] **Integration** — SOAP-виклик + реальна БД per service.
- [ ] **E2E (повний потік)** — через ESB: SearchProducts → Register → Authenticate → CreateCart → AddCartItem → Checkout → (оплата) → GetOrder → (сповіщення).
- **Verify:** зелений прогін усіх трьох рівнів.

## Фаза 9 — Документація, CI, push
- [ ] `README.md`: як реалізація лягає на дизайн Task-27 (таблиця «дизайн → код»), як запускати, де видно SOA-ознаки.
- [ ] `.gitlab-ci.yml`: lint + unit + integration.
- [ ] Перший коміт + push у `origin/main`.
- **Verify:** CI зелений; репо на GitLab містить робочий код + docs.

---

## Ключові рішення (щоб не з'їхати в мікросервіси)
| Рішення | Чому |
|---|---|
| Уся клієнтська взаємодія — через **ESB** | «smart pipes» SOA; сервіси не знають одне про одного |
| **Канонічна XSD-модель** на дроті | єдине трактування сутностей; ESB трансформує |
| **Оркестрація** checkout у ESB | контраст із хореографією мікросервісів |
| **Централізований IAM** (CM видає токен) | вимога завдання; єдина точка ідентичності |
| **SOAP/WSDL** контракт-first (5 сервісів) | enterprise-дисципліна контрактів (WS-*) |

## Ризики / відкриті питання
- Обсяг великий (5 сервісів + ESB) → беремо **пофазно**, з check-in і verify перед наступною фазою.
- WS-Security повноцінно (підпис/шифрування XML) складний у PHP → для реалізації: UsernameToken + підписаний токен на рівні ESB (задокументувати спрощення).
- Зовнішні інтеграції FF/NT (Stripe/НоваПошта/SES) → fake-провайдери за seam, реальні опційно через env.
- e2e потребує піднятого docker-стека → передбачити `make e2e` / скрипт.

## Прогрес
- **Фаза 0 ✅** (2026-07-28). Каркас піднято: 5 сервісів + ESB на спільному образі
  `printzone-soa-php:8.2` (PHP 8.2 + soap/pdo_pgsql/redis/amqp), 4 Postgres + RabbitMQ + Redis.
  Усі `/health` зелені. Знахідки:
  - `symfony/framework-bundle` у мінімальному наборі не тягне `symfony/string` → додано явно
    (інакше `LazyString not found` на першому запиті).
  - Паралельний `docker compose build` конфліктує на однаковому imagе-імені → образ будувати
    один раз, далі reuse; конфлікт нешкідливий (образ створюється).
  - **dev-режим прогріває кеш ~40 с на першому запиті** (mounted volume). Не блокує, але для
    e2e/CI варто прогрівати кеш або перейти на `APP_ENV=prod` у контейнерах.
  - RabbitMQ management-порт 15672 зайнятий іншим стеком → перемапив на 15673.

## Review
_(заповнюється по завершенню)_

## Lessons
_(оновлюється після коригувань від користувача)_
