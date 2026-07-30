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

## Фаза 1 — Customer Management (централізований IAM) ✅
- [x] DDD-домен: агрегат `Customer`; VO `Email`, `Address`, `Credential`; доменні винятки; порт `CustomerRepository`.
- [x] Своя схема БД (`customer`), Doctrine-міграція (`Version20260728000001`), `schema:validate` зелений.
- [x] SOAP-endpoint за розширеним WSDL: `RegisterCustomer`, `Authenticate`, `GetCustomer`, `UpdateAddress` (native `SoapServer`, document/literal).
- [x] **IAM:** `TokenIssuer` видає підписаний JWT (HS256, self-contained) при `Authenticate`; секрет `IAM_TOKEN_SECRET` спільний для перевірки в ESB.
- [x] Мапінг канонічний `Customer`/`Address` ↔ доменна модель (`toCanonical()`).
- [x] Unit-тести домену + IAM (7/7 зелені).
- **Verify ✅:** реальний `SoapClient` e2e: Register → Authenticate (валідний токен) → GetCustomer → UpdateAddress; невірний пароль → SOAP Fault. Дані збережено в БД.

## Фаза 2 — Product & Inventory ✅
- [x] DDD-домен: агрегат `Product` + VO `Money` (резервування як доменна операція з інваріантом).
- [x] Своя схема БД (`catalog`), міграція (`Version20260728000002`), `schema:validate` зелений.
- [x] Seed-команда `app:seed-products` з ФІКСОВАНИМИ UUID (3 товари) — для Order-фази та e2e.
- [x] SOAP-endpoint за розширеним WSDL: `SearchProducts`, `GetProduct`, `CheckStock`, `ReserveStock`.
- [x] **Redis-кеш** читань (`search`/`getCanonical`) із тегом `products`; `reserveStock` інвалідує тег.
- [x] Unit-тести (8/8): резервування, брак залишку → fault, канонічний shape, Money.
- **Verify ✅:** SOAP e2e — Search(3) → Get → CheckStock → Reserve(10)→stock 90 → повторний Get=90 (кеш інвалідовано) → Reserve(999)→SOAP Fault.

## Фаза 3 — Order Management ✅
- [x] DDD-домен: `Cart`, `CartItem`, `Order`, `OrderLine`, VO `Money`/`Address`, enum `OrderStatus`
      (Cart всередині OM — грубіша зернистість SOA).
- [x] Своя схема БД (`orders`), міграція (`Version20260730000003`), `schema:validate` зелений.
- [x] SOAP-endpoint: `CreateCart`, `AddCartItem`, `Checkout`, `GetOrder`, `MarkPaid`, `CancelOrder`.
- [x] Order lifecycle: PENDING → PAID → PROCESSING → SHIPPED → DELIVERED → CANCELLED
      (матриця дозволених переходів у `OrderStatus::allowedTransitions()`).
- [x] Unit-тести (19/19): сума кошика, злиття рядків, знімок при checkout, переходи статусів, канон.
- **Verify ✅:** SOAP e2e — CreateCart → AddCartItem ×3 (третій злився, lineCount 2) → Checkout
  (PENDING, total 99600 UAH) → GetOrder → MarkPaid (PAID) → повторний MarkPaid → SOAP Fault →
  CancelOrder (CANCELLED) → повторний Cancel → Fault; checkout неіснуючого/порожнього кошика → Fault.
  Кошик після checkout видалено разом із рядками (cascade).

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
| **Знімок ціни** в `AddCartItem` замість виклику OM → PI | сервіси не спілкуються напряму; зведення даних і резервування залишку робить оркестрація ESB (Фаза 6) |

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
- **Фаза 1 ✅** (2026-07-28). Customer Management + централізований IAM.
  Стек сервісу: Symfony 7.4 (MicroKernel) + Doctrine ORM 3 + native SOAP. Знахідки:
  - `firebase/php-jwt` заблоковано security-advisory (усі 6.x) → замінив на self-contained
    JWT HS256 у `TokenIssuer` (менше залежностей, повний контроль).
  - Контролер із конструктор-залежністю має бути **public + tag `controller.service_arguments`**
    (інакше «cannot be fetched … private»); health працював без цього лише бо без конструктора.
  - Relative-import у WSDL (`schemaLocation="canonical-data-model.xsd"`) не резолвиться при
    завантаженні WSDL по HTTP → SOAP-клієнт бере контракт **локально** (ESB монтує `/contracts`),
    endpoint — з `soap:address`. Це і є реальний SOA-патерн (клієнт/ESB тримає контракт).
  - Doctrine авто-імена індексів → зафіксував `UniqueConstraint(name:...)`, щоб `schema:validate`
    збігався з рукописною міграцією.
- **Фаза 2 ✅** (2026-07-28). Product & Inventory (каталог + наявність + Redis-кеш).
  - Redis через framework cache-пул `catalog.cache` (tag-aware); читання тегуються `products`,
    `ReserveStock` викликає `invalidateTags(['products'])` — e2e підтвердив (повторний Get=90, не стале 100).
  - Seed з фіксованими UUID (11111111…/22222222…/33333333…) — Order-фаза посилатиметься на них.
  - Патерн сервісу ідентичний Фазі 1 (Doctrine ORM3 + native SOAP + controllers public+tag).
- **Фаза 3 ✅** (2026-07-30). Order Management (кошик + checkout + життєвий цикл замовлення).
  - **SOA-рішення:** OM автономний і НЕ звертається до Product & Inventory. `AddCartItemRequest`
    розширено полями `sku` + `unitPrice` (`cdm:Money`) — знімок ціни з канонічного `Product`,
    який клієнт уже отримав із `SearchProducts`. Заготовку `CatalogPort`/`SoapCatalogClient`
    з чернетки прибрано: прямий виклик сервіс→сервіс = ознака мікросервісів.
  - У WSDL додано `MarkPaid` — її викликатиме оркестрація ESB (Фаза 6) після `AuthorizePayment`.
  - `order-management` у `docker-compose.yml` не монтував `./contracts` (на відміну від CM/PI) →
    додано, інакше `SoapServer` не читає WSDL.
  - Знову граблі Фази 1: Doctrine сам іменує індекси join-колонок (`IDX_E49A10F1…`) →
    `schema:validate` розходився з рукописною міграцією. Фікс — явний
    `#[ORM\Index(name: 'idx_cart_item_cart', columns: ['cart_id'])]` на `CartItem`/`OrderLine`.
  - Money/Address лишили простими колонками (як ціна в Фазі 2), без Doctrine embeddable —
    менше магії, той самий патерн у трьох сервісах.

## Review
_(заповнюється по завершенню)_

## Lessons
_(оновлюється після коригувань від користувача)_
