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
- [x] Комунікація **через ESB**, а не сервіс→сервіс напряму.
- [x] **Канонічна модель** (XSD) на дроті; ESB трансформує канон ↔ внутрішній формат.
- [x] **Оркестрація** checkout у ESB (центральний процес тримає стан), не хореографія.
- [x] **Контракт-first** SOAP/WSDL (document/literal) для всіх 5 сервісів.
- [x] **Централізований IAM** — єдина видача/перевірка ідентичності для всіх сервісів.
- [x] Governance-артефакт: простий **service registry** (каталог WSDL усіх 5).

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

## Фаза 4 — Fulfillment (оплата + доставка) ✅
- [x] DDD-домен: `Payment`, `Shipment`, `TrackingEvent` (event-sourced трекінг, append-only).
- [x] Своя схема БД (`fulfillment`), міграція (`Version20260730000004`), `schema:validate` зелений.
- [x] SOAP-endpoint: `AuthorizePayment`, `CapturePayment`, `CreateShipment`, `TrackShipment`
      (у WSDL з Task-27 було лише 2 операції-зразки — розширено, + локальні типи
      `PaymentStatus`/`ShipmentStatus`/`TrackingEvent`).
- [x] Провайдери за seam: `PaymentProvider` (fake/Stripe), `ShippingProvider` (fake/Нова Пошта);
      вибір через `PAYMENT_PROVIDER`/`SHIPPING_PROVIDER`, default = fake.
- [x] Публікація подій `payment.captured`, `shipment.dispatched` у topic-exchange
      `printzone.events` (AMQP, ext-amqp).
- [x] Unit-тести (21/21): переходи платежу, ідемпотентність, відмова провайдера, порядок
      tracking-подій, публікація подій, вибір провайдера за env.
- **Verify ✅:** SOAP e2e — AuthorizePayment → повторний повертає той самий `paymentId` →
  сума понад ліміт fake-провайдера → Fault (у БД лишається FAILED-платіж) → CapturePayment
  (CAPTURED) → повторний → Fault → CreateShipment (tracking `FAKEUA…`) → повторний той самий
  `shipmentId` → TrackShipment: DISPATCHED + історія CREATED→DISPATCHED → трекінг неіснуючого → Fault.
  Черга-зонд на `printzone.events` реально отримала `payment.captured` і `shipment.dispatched`.

## Фаза 5 — Notification (stateless утиліта) ✅
- [x] `SendNotification` (SOAP) + async-споживач `app:consume-events` (черга `notification.events`,
      ключі `order.placed`, `payment.captured`, `shipment.dispatched`); окремий контейнер
      `notification-worker` на тому самому образі.
- [x] Email-шлюз за seam (`MailGateway`: fake-лог / SES-скелет, вибір через `NOTIFICATION_GATEWAY`);
      шаблони `order_confirmation`, `payment_receipt`, `shipment_dispatched` + `plain` для вільного тексту.
- [x] Без власної БД (stateless) — за дизайном; дедуп оброблених подій — Redis-кеш із TTL 24 год.
- [x] Unit-тести (15/15): рендер шаблонів, подія→шаблон, fallback-адреса, дедуп, відмова на канал SMS.
- **Verify ✅:** SOAP — `SendNotification` за шаблоном і вільним текстом, невідомий шаблон / канал SMS → Fault.
  Асинхрон наскрізно: `CapturePayment` + `CreateShipment` у Fulfillment → події в `printzone.events` →
  воркер сформував і «відправив» обидва листи (лог `var/mail/sent.log`, у темі — правильний `orderId`).
  Дедуп перевірено на живому воркері: та сама подія двічі → `sent` + `skipped`, лист один.

## Фаза 6 — ESB (серце SOA) ✅
- [x] SOAP-шлюз: єдина точка входу `POST /soap` на `:8080`; сервіси лишаються всередині мережі.
      `GET /soap?wsdl=<service>` віддає контракт із **підміненою на ESB адресою**, `?xsd=…` — канонічну модель.
- [x] **Content-based routing**: маршрут визначає namespace/операція із самого конверта
      (`MessageInspector` + таблиця в `ServiceRegistry`), а не URL.
- [x] **Канонічна трансформація** (`CanonicalTransformer`): канонічний кошик → окремі
      `ReserveStock(productId, quantity)`; `cdm:Money`/`cdm:Address` → виклики сервісів;
      збагачення події `order.placed` поштою клієнта, якої немає в жодного сервісу нижче.
- [x] **Оркестрація Checkout** (стан процесу в ESB) за §05: `GetCart`(OM) → `ReserveStock`(PI, по рядках)
      → `Checkout`(OM) → `AuthorizePayment`+`CapturePayment`(FF, sync) → `MarkPaid`(OM) →
      async AMQP: `shipment.requested`(FF-воркер) + `order.placed`(NT). Компенсація: `ReleaseStock` + `CancelOrder`.
- [x] **WS-Security / централізована перевірка токена**: ESB читає `<wsse:Security>`
      (BinarySecurityToken із IAM-JWT + `wsu:Timestamp`), перевіряє підпис HS256, строк і свіжість,
      після чого **знімає заголовок** (роль WS-Security intermediary). Публічні операції — без токена.
- [x] **Service registry:** `GET /registry` — JSON-каталог 6 контрактів (5 сервісів + оркестрація).
- [x] Передумови в сервісах: `ReleaseStock` (PI) для компенсації і `GetCart` (OM) для оркестрації.
- **Verify ✅:** клієнт ходить ЛИШЕ в ESB і тягне контракти з нього ж по HTTP:
  `SearchProducts` без токена → 3 товари; `RegisterCustomer`+`Authenticate` → токен;
  `CreateCart` без токена → Fault; з токеном → кошик; **один `Checkout` до ESB** → order PAID,
  paymentId, залишок −2; async: воркер створив `FAKEUA3956`, лист `order_confirmation` пішов на
  **реальну пошту клієнта** (збагачення в ESB). Компенсація: сума 1 016 600 > ліміту → Fault
  «released 1 reservation(s), order … cancelled», залишок 90 → 90. Unit: esb 16, PI 10, OM 20, FF 21, NT 15.

## Фаза 7 — Асинхрон (AMQP) + кеш (наскрізне) ✅
- [x] RabbitMQ, exchange `printzone.events` (topic, durable) — повний набір подій:
      `order.placed` (публікує **ESB**, збагачуючи поштою клієнта), `stock.level.changed` (PI),
      `payment.captured` і `shipment.dispatched` (FF), плюс команда `shipment.requested` (ESB→FF).
- [x] Споживачі: `notification-worker` (листи), `fulfillment-worker` (створення відправлення),
      **`product-inventory-worker` — проекція залишків** (`app:project-stock`).
- [x] Проекція залишків як read-model у Redis (`projection.cache`): поточний рівень, ознака
      `lowStock`, остання операція; будується ВИКЛЮЧНО з подій. Діагностичний перегляд —
      `GET /stock-projection` (внутрішній, як `/health`).
- [x] Redis під читання каталогу + інвалідація тегу `products` після `ReserveStock`/`ReleaseStock`.
- **Verify ✅:** `rabbitmqctl list_queues` — `catalog.projection`, `fulfillment.commands`,
  `notification.events`, у кожної 1 споживач і 0 необроблених. Після Checkout через ESB:
  `GetProduct` віддав свіжий залишок 5 → 2 (кеш інвалідовано), проекція асинхронно оновилась до
  того ж значення з `lowStock=true`, `reserved ×3`. Після відкоту оплати в проекції видно
  `released ×34` — компенсація теж лишає слід у read-моделі. Unit PI: 19/19.

## Фаза 8 — Тестування ✅
- [x] **Unit** (98) — домен кожного сервісу + ESB ізольовано, без БД, брокера й мережі:
      CM 7, PI 19, OM 20, FF 21, NT 15, ESB 16. Запуск: `docker compose exec <svc> php vendor/bin/phpunit`.
- [x] **Integration** (37) — справжній SOAP до піднятого сервісу + реальна БД:
      CM 6, PI 6, OM 7, FF 6, NT 5, ESB 7. Окрема сюїта: `--testsuite integration`
      (за замовчуванням `phpunit` ганяє лише unit, тож прогін без стека не падає).
- [x] **E2E** (8) — окремий пакет `tests/` + контейнер `e2e` (профіль `test`):
      `docker compose run --rm e2e`. Клієнт знає ЛИШЕ адресу ESB і тягне контракти звідти ж.
- **Verify ✅:** усі три рівні зелені. E2E покриває повний потік завдання
  (каталог → реєстрація → вхід → кошик → Checkout → PAID-замовлення → сповіщення),
  компенсацію після відмови оплати та асинхронне доганяння проекції залишків;
  окремо — governance-перевірки: реєстр із 6 контрактів, контракти без внутрішніх адрес,
  бізнес-операція без токена й із підробленим підписом → Fault на шині.

## Фаза 9 — Документація, CI, push
- [x] Інфраструктурні борги з Фаз 6–8 (мали лягти ДО CI):
      спільний `docker/entrypoint.sh` тепер **завжди** робить `composer install` (кінець
      крешлупу воркерів) і прогріває кеш на старті (перший запит 60–90 с → ~16 с);
      воркери й `e2e` перейшли з перекритого `entrypoint` на `command`;
      HTTP-сервіси отримали `healthcheck` (таймаут 30 с — 5 с не встигало на холодний старт).
- [x] `README.md`: таблиці «SOA-ознака → де в коді» і «дизайн Task-27 → код», запуск,
      як подивитись роботу (registry, WSDL із ESB, e2e), карта подій шини, три рівні тестів,
      структура репо і **свідомі спрощення**.
- [x] `.gitlab-ci.yml`: `lint` (PHP-синтаксис + валідність WSDL/XSD як XML) → `unit`
      (matrix по 6 компонентах) → `integration` (піднятий стек, міграції, seed, integration-сюїти
      кожного сервісу + `docker compose run --rm e2e`).
- [x] E2E зроблено повторюваним: сценарій обирає товар за наявним залишком, а
      `app:seed-products --reset-stock` повертає демо-каталог у початковий стан.
- [x] Раннер: до проєкту призначено спільний із Task-25 раннер #303, джоби отримали його
      тег `Веб-разработка` (без тегу тегований раннер їх не бачить → пайплайн `stuck`),
      а `workflow:` обмежив прогони до MR / `develop` / `main`.
- [x] `integration` переведено в `when: manual`: раннер стоїть на прод-дроплеті
      **1 vCPU / 1 GB**, де вже працює магазин Task-25, — автоматичний підйом ще 13
      контейнерів означав би OOM живого прода. Автоматично бігають `lint` і `unit`.
- [ ] Мердж `develop` → `main` як фінальний реліз.
- **Verify ✅:** усі 6 сервісів `healthy`; unit 98 і integration 37 зелені на оновленій
  інфраструктурі; e2e 8/8 (6:01 — вдвічі швидше, ніж із частими health-пробами).
  ⚠️ Рівні integration/e2e підтверджені **локально**: єдиний доступний раннер ділить
  1 ГБ RAM із продом, тому в CI вони лишаються ручною джобою, а не доказом.

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
- **Фаза 4 ✅** (2026-07-30, гілка `feat/phase4-fulfillment`). Fulfillment: оплата + доставка.
  - **Події AMQP зробили вже тут** (не відкладали у Фазу 7): `EventPublisher` (порт) +
    `AmqpEventPublisher` — topic-exchange `printzone.events`, durable, ключі
    `payment.captured` / `shipment.dispatched`. З'єднання лениве, щоб `/health` не залежав
    від брокера. Фазі 5 (Notification) вже є що споживати.
  - **Ідемпотентність за `orderId`** (унікальний індекс) і для платежу, і для відправлення —
    оркестрація ESB зможе безпечно повторити крок. Виняток: платіж у статусі FAILED
    дозволяє повторну авторизацію (`reauthorize`).
  - Провайдери за seam через фабрику (`ProviderFactory::payment/shipping` + env), домен бачить
    лише порт. Реальні Stripe/Нова Пошта — скелети, що чесно падають без кредів, а не вдають успіх.
    Нові env: `STRIPE_SECRET_KEY`, `NOVAPOSHTA_API_KEY` (порожні).
  - Трекінг event-sourced: у `TrackingEvent` є `sequence_no` — без нього події однієї секунди
    не мали б детермінованого порядку (`created_at` з точністю до секунди).
  - `fulfillment` у compose так само не монтував `./contracts` — додано (та сама граблина, що у Фазі 3).
- **Фаза 5 ✅** (2026-07-31, гілка `feat/phase5-notification`). Notification: SOAP + споживач подій.
  - **Адреса отримувача.** Події Fulfillment несуть лише `orderId` — пошти клієнта Fulfillment
    не знає й дізнаватися не має права (сервіси не спілкуються напряму). Тому споживач бере
    `payload.recipient` / `payload.customerEmail`, а якщо їх нема — шле на службову
    `NOTIFICATION_FALLBACK_EMAIL`. Правильне рішення — **збагачення події адресою в ESB**
    (Фаза 6: оркестрація публікує `order.placed` з поштою клієнта, яку має з checkout-запиту).
  - **Контракт розширено:** у `SendNotificationRequest` додано `recipient` і повторюваний
    `parameter` (name/value), `body` став опційним — усі дані для листа дає викликач/ESB,
    бо сервіс stateless і нічого не дочитує.
  - **Дедуп без БД:** відмітка `seen_<eventId>` у Redis-пулі `notification.cache` (TTL 24 год) —
    at-least-once доставка більше не дублює листи. Перевірено на живому воркері.
  - Воркер у compose перекриває `entrypoint` (базовий піднімає `php -S`), тому composer-install
    із базового entrypoint не спрацював би — у команді воркера це продубльовано явно.
- **Фаза 6 ✅** (2026-07-31, гілка `feat/phase6-esb`). ESB: routing + медіація + оркестрація + registry.
  - **`mustUnderstand` ламає проксі.** PHP `SoapServer` намагається викликати метод із назвою
    заголовка → `Function "Security" is not a valid method`. Правильна поведінка й водночас фікс:
    ESB як WS-Security **intermediary** знімає `<wsse:Security>` після перевірки політики
    (`MessageInspector::withoutSecurityHeader`) і далі сервісу йде чистий конверт.
  - **Адреса в WSDL береться із запиту**, а не з env: клієнт з хоста бачить `localhost:8080`,
    клієнт усередині мережі — `http://esb`. Інакше відносний `schemaLocation` канонічної XSD
    ззовні не резолвиться (та сама граблина, що у Фазі 1).
  - **Проксі через `__call`** — один клас замість 20+ підписів операцій п'яти контрактів;
    `SoapServer::setObject` це приймає, бо `is_callable` враховує `__call`.
  - **Компенсація вимагала нових операцій**: `ReleaseStock` у PI (без неї резерв нічим не
    відкотити) і `GetCart` в OM (ESB має прочитати позиції, щоб їх зарезервувати).
  - **Async-гілка стала справді асинхронною**: доданий `fulfillment-worker` споживає
    `shipment.requested`, тож ESB не тримає клієнта на створенні відправлення (§05.4).
  - Dev-режим: перший запит до сервісу прогріває кеш до ~90 с → e2e спершу «стукає» в `/health`
    кожного сервісу і піднімає `default_socket_timeout`.
- **Фаза 7 ✅** (2026-08-01, гілка `feat/phase7-async-cache`). Наскрізний асинхрон + кеш.
  - Бракувало лише `stock.level.changed`: PI отримав порт `EventPublisher` (той самий патерн,
    що у FF) і публікує подію після `ReserveStock`/`ReleaseStock`.
  - **Проекція = read-model, а не друга копія БД.** Живе в Redis-пулі `projection.cache`,
    будується виключно з подій, тримає `lowStock`; сервіс лишається без другої схеми БД.
  - **Граблі воркерів:** базовий entrypoint ставить залежності лише якщо `vendor/` ВІДСУТНІЙ.
    Коли до `composer.json` додається новий пакет, а `vendor/` уже є, воркер крешить
    (`Class "Symfony\Component\HttpKernel\Kernel" not found`) і рестартує по колу, доки
    вручну не виконати `composer update` у сервісі. Стосується всіх трьох воркерів.
  - Розкладка подій за власниками: домені події публікують сервіси (PI, FF), а процесні
    (`order.placed`) — ESB, бо лише він знає контекст процесу (напр. пошту клієнта).
- **Фаза 8 ✅** (2026-08-01, гілка `feat/phase8-testing`). Три рівні тестів замість ad-hoc скриптів.
  - `defaultTestSuite="unit"` у кожному сервісі: голий `phpunit` не потребує піднятого стека,
    integration запускається явно (`--testsuite integration`). Інакше CI-крок «unit» тягнув би
    за собою всю інфраструктуру.
  - **Пастка SOAP:** один результат приходить об'єктом, а не масивом з одного елемента
    (`SearchProducts('mug')`), і `(array)` перетворює його на список ВЛАСТИВОСТЕЙ. Нормалізація
    `is_array($x) ? $x : [$x]` — обов'язкова скрізь, де відповідь має `maxOccurs="unbounded"`.
  - E2E живе окремим composer-пакетом `tests/` і контейнером `e2e` під профілем `test`,
    щоб не піднімався разом зі стеком; всередині — рольовий клієнт `EsbClient`/`ShopScenario`,
    який навмисно вміє звертатися лише до шини.
  - E2E повільний (~4 хв) через dev-прогрів сервісів; у тестах підвищений `default_socket_timeout`.
- **Фаза 9** (2026-08-01, гілка `feat/phase9-docs-ci`). Документація, CI, інфраструктурні борги.
  - **`entrypoint.sh` вшитий в образ** — правка на хості нічого не змінює, доки не зробити
    `docker compose build`. Витратив на це окремий цикл: воркери мовчки піднімали `php -S`
    замість своєї команди, бо контейнер виконував СТАРИЙ entrypoint із образу.
  - Прогрів кешу перенесено в старт контейнера: перший запит 60–90 с → **~16 с**, ціною того,
    що порт відкривається пізніше. Тому HTTP-сервіси отримали `healthcheck`, і саме за ним
    CI чекає готовності, а не за `sleep`.
  - **Healthcheck із коротким таймаутом гірший за жоден**: із `timeout: 5s` сервіси вічно
    висіли в `health: starting`, бо проба помирала раніше, ніж Symfony добудовував контейнер.
  - Воркери й `e2e` більше не перекривають `entrypoint`, а передають `command` — установка
    залежностей і прогрів лишаються спільними для всіх контейнерів.
  - **Healthcheck сам себе покарав:** із `interval: 15s` e2e сповільнився вчетверо (4 хв → 12:44).
    Причина — `php -S` однопотоковий, а проба `/health` у dev-режимі коштує 2–10 с
    (`docker inspect … .State.Health.Log` показує тривалість кожної), тож перевірки з'їдали
    до третини пропускної здатності сервісу. Розрідив до `interval: 60s`.
    Радикальніший важіль, якщо знадобиться швидкість, — підняти стек із `APP_ENV=prod`.
  - **E2E був одноразовим:** сценарій купував конкретний SKU, і після кількох прогонів по тій
    самій БД залишок PRINT-MUG упав до 0 — тест «падав», хоча код був справний. Тепер сценарій
    сам обирає товар із достатнім залишком (`productWithStock`, `purchaseExceedingPaymentLimit`),
    а `app:seed-products --reset-stock` повертає демо-каталог у початковий стан. У CI база
    щоразу чиста, тож проблема стосувалась лише локальних повторних прогонів.

## Review

Зроблено те, що планувалось у рамках: **5 грубозернистих сервісів + ESB**, а не walking
skeleton. Наскрізний сценарій із §01 працює цілком — каталог → реєстрація → checkout →
оплата → відправлення → сповіщення — і перевіряється e2e-тестом, який навмисно вміє
звертатися **лише до шини**.

### Чим доведено кожну SOA-ознаку

| Ознака | Де в коді | Чим перевірено |
|---|---|---|
| Уся взаємодія через ESB | `esb/` — єдиний `POST /soap` на `:8080`; сервіси не мають опублікованих портів | e2e-клієнт фізично не має адрес сервісів |
| Канонічна модель на дроті | `contracts/canonical-data-model.xsd` + `CanonicalTransformer` | integration-тести порівнюють канонічний shape |
| Оркестрація, не хореографія | `CheckoutOrchestration` в ESB: `GetCart` → `ReserveStock` → `Checkout` → `AuthorizePayment`, стан процесу в шині | тест компенсації: відмова оплати → `ReleaseStock` + `CancelOrder` |
| Контракт-first SOAP/WSDL | 5 WSDL у `contracts/`, перенесені з дизайну Task-27 без переписування | `lint:contracts` у CI + `GET /soap?wsdl=<service>` |
| Централізований IAM | `TokenIssuer` у CM видає підписаний токен; перевіряє його **ESB** у `<wsse:Security>` | governance-тести: без токена і з підробленим підписом → Fault на шині |
| Service registry | `GET /registry` — 6 контрактів | тест: у реєстрі немає внутрішніх адрес |

### Що вийшло добре

- **Дизайн Task-27 ліг у код без правок контрактів.** WSDL і XSD перенесені як є; те, що
  вони пережили реалізацію, — найкращий аргумент на користь контракт-first підходу.
- **Дисципліна «сервіси не знають одне про одного» витримана до кінця.** Найбільша спокуса
  була у Фазі 3 (OM → PI за ціною); замість прямого виклику зробили знімок ціни в
  `AddCartItem`, а зведення даних лишили оркестрації. Саме за прямі виклики відхиляли Task-27.
- **Три рівні тестів замість ad-hoc скриптів:** 98 unit, 37 integration, 8 e2e.

### Свідомі спрощення

- **WS-Security** — UsernameToken + підписаний токен на рівні ESB, без XML-підпису й
  шифрування тіла. Повноцінний WS-Security у PHP непропорційно дорогий.
- **Зовнішні інтеграції** (Stripe, Нова Пошта, SES) — fake-провайдери за реальним seam
  (`ProviderFactory` + env), бо предмет завдання — архітектура, а не інтеграції.
- **Notification без власної БД** — дедуп оброблених подій у Redis із TTL 24 год.
- **`php -S` замість nginx** — стек і так із 13 контейнерів на демо-машині.

### Чесні обмеження

- **CI підтверджує лише `lint` і `unit`.** Єдиний доступний раннер стоїть на прод-дроплеті
  (1 vCPU / 1 ГБ, без свопу), де вже працює магазин Task-25, тож `integration` лишився
  ручною джобою: автоматичний підйом ще 13 контейнерів там означав би OOM живого прода.
  Рівні integration та e2e зелені локально — це задокументовано, але не доведено пайплайном.
- **Стек піднімається в `dev`-режимі**, тому холодний старт довгий (звідси healthcheck із
  `start_period: 240s`). Для швидкості достатньо `APP_ENV=prod`, але це зайвий ризик для демо.

## Lessons
_(оновлюється після коригувань від користувача)_
