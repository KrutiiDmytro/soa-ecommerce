# E-Commerce SOA — Implementation (Task 28)

Реалізація SOA-дизайну з **Task 27** у працюючий код: 5 автономних грубозернистих сервісів
(своя схема БД + DDD), централізований IAM, комунікація **SOAP/WS-\*** (sync) + **AMQP**
(async) через **ESB** з оркестрацією checkout і **канонічною моделлю даних** на дроті.

> Дизайн, декомпозиція та контракти: репозиторій **Task 27**. Тут — виконання.
> План, хід робіт, рішення та уроки: [`docs/todo.md`](docs/todo.md).

## Де в коді видно, що це SOA, а не мікросервіси

| Ознака SOA | Де це в коді |
|---|---|
| Уся взаємодія — **через ESB**, сервіси не кличуть одне одного | [`esb/src/Gateway/EsbGatewayController.php`](esb/src/Gateway/EsbGatewayController.php); назовні відкрито лише `:8080`, у compose сервіси не мають публічних портів |
| **Content-based routing** за змістом повідомлення, а не за URL | [`esb/src/Gateway/MessageInspector.php`](esb/src/Gateway/MessageInspector.php) + [`esb/src/Registry/ServiceRegistry.php`](esb/src/Registry/ServiceRegistry.php) |
| **Канонічна модель даних** на дроті + трансформація канон ↔ сервіс | [`contracts/canonical-data-model.xsd`](contracts/canonical-data-model.xsd), [`esb/src/Orchestration/CanonicalTransformer.php`](esb/src/Orchestration/CanonicalTransformer.php) |
| **Оркестрація** процесу (стан у шині), а не хореографія | [`esb/src/Orchestration/CheckoutOrchestrator.php`](esb/src/Orchestration/CheckoutOrchestrator.php) — разом із компенсацією |
| **Контракт-first** SOAP/WSDL, document/literal | [`contracts/*.wsdl`](contracts/) — код пишеться під контракт, не навпаки |
| **Централізований IAM** + ESB як Policy Enforcement Point | видача: [`services/customer-management/src/Iam/TokenIssuer.php`](services/customer-management/src/Iam/TokenIssuer.php); перевірка: [`esb/src/Security/SecurityPolicy.php`](esb/src/Security/SecurityPolicy.php) |
| **Service registry** (governance) | `GET /registry` → [`esb/src/Registry/RegistryController.php`](esb/src/Registry/RegistryController.php) |

## Мапінг «дизайн Task 27 → код Task 28»

| Документ дизайну | Що з нього втілено |
|---|---|
| §01 Аналіз компонентів | межі 5 грубозернистих сервісів (див. таблицю нижче) |
| §02 SOA vs мікросервіси | грубша зернистість (кошик **усередині** Order Management), ESB замість «dumb pipes», канонічна модель |
| §03 Дизайн сервісів | DDD-домен у кожному сервісі, схема БД на сервіс, ESB, service registry |
| §04 Пріоритизація | порядок розробки фаз: PI → CM → OM → FF → NT → ESB |
| §05 Комунікація | SOAP (sync) + AMQP (async) + **sequence-діаграма checkout** = `CheckoutOrchestrator` |
| §06 Безпека і версіювання | `<wsse:Security>` (BinarySecurityToken + Timestamp) на вході в ESB; версія у namespace `…:v1` |

## Сервіси

| Сервіс | Роль | БД |
|---|---|---|
| **customer-management** | Ідентичність + централізований **IAM** (видача токена), профіль, адреси | `customer` |
| **product-inventory** | Каталог, наявність, резервування/звільнення, Redis-кеш, проекція залишків | `product` |
| **order-management** | Кошик + checkout + життєвий цикл замовлення | `orders` |
| **fulfillment** | Оплата + доставка (провайдери за seam), event-sourced трекінг | `fulfillment` |
| **notification** | Сповіщення: SOAP на вимогу + споживач подій (stateless) | — |
| **esb** | Routing + канон-трансформація + **оркестрація checkout** + WS-Security + registry | — |

Плюс три воркери (`*-worker`): проекція залишків, створення відправлень, розсилка листів.

## Запуск

```bash
cp .env.example .env
docker compose build
docker compose up -d
```

Міграції та демо-каталог (один раз після першого підйому):

```bash
for s in customer-management product-inventory order-management fulfillment; do
  docker compose exec "$s" php bin/console doctrine:migrations:migrate --no-interaction
done
docker compose exec product-inventory php bin/console app:seed-products
```

Залежності й прогрів кешу робить спільний `docker/entrypoint.sh` при старті контейнера, тому
сервіс відкриває порт не миттєво — готовність показує `docker compose ps` (`healthy`).

Кожен прогін e2e реально списує залишки. Щоб повернути демо-каталог у початковий стан:

```bash
docker compose exec product-inventory php bin/console app:seed-products --reset-stock
```

## Як подивитися, що воно працює

```bash
# 1. Governance: каталог контрактів (єдине, що потрібно знати клієнту)
curl http://localhost:8080/registry

# 2. Контракт віддає САМ ESB, з адресою шини замість внутрішньої
curl "http://localhost:8080/soap?wsdl=order-management"

# 3. Наскрізний бізнес-процес одним викликом до шини
docker compose run --rm e2e
```

Асинхронна частина (exchange `printzone.events`, RabbitMQ UI — `http://localhost:15673`):

| Подія / команда | Публікує | Споживає |
|---|---|---|
| `stock.level.changed` | product-inventory | `product-inventory-worker` → проекція залишків (`GET /stock-projection`) |
| `order.placed` | **ESB** (збагачує поштою клієнта) | `notification-worker` → лист-підтвердження |
| `shipment.requested` | **ESB** (async-гілка оркестрації) | `fulfillment-worker` → створення відправлення |
| `payment.captured`, `shipment.dispatched` | fulfillment | `notification-worker` → листи |

## Тести

```bash
# Unit — домен ізольовано, стек не потрібен
docker compose exec order-management php vendor/bin/phpunit

# Integration — справжній SOAP + реальна БД (потрібен піднятий стек)
docker compose exec order-management php vendor/bin/phpunit --testsuite integration

# E2E — повний потік через ESB (окремий пакет tests/)
docker compose run --rm e2e
```

Три рівні відповідають стадіям CI у [`.gitlab-ci.yml`](.gitlab-ci.yml): `lint` → `unit` → `integration`.
Автоматично бігають лише `lint` і `unit`: `integration` піднімає ще 13 контейнерів, а доступний
раннер стоїть на дроплеті 1 vCPU / 1 ГБ разом із чужим продом — тому це джоба `when: manual`.
Рівні integration та e2e зелені локально, але пайплайном не доведені.

## Структура репозиторію

```
contracts/     канонічна XSD + WSDL 5 сервісів + контракт оркестрації checkout
esb/           шина: routing, трансформація, оркестрація, безпека, registry
services/      п'ять автономних сервісів (DDD-домен + Doctrine + native SOAP)
tests/         наскрізні тести: клієнт знає лише адресу ESB
docs/todo.md   план, прогрес, ухвалені рішення та уроки
```

## Свідомі спрощення

- **WS-Security без XML-DSig**: перевіряються `BinarySecurityToken` (JWT від IAM) і `wsu:Timestamp`;
  повноцінний підпис XML у PHP невиправдано дорогий. ESB знімає заголовок після перевірки — як і
  належить intermediary.
- **Оркестрація — код, а не BPEL-рушій**: логіка процесу зосереджена в одному класі ESB, що й
  дає потрібний контраст із хореографією мікросервісів.
- **Зовнішні інтеграції за seam**: Stripe / Нова Пошта / SES — скелети, що чесно падають без
  кредів; за замовчуванням працюють fake-реалізації (`PAYMENT_PROVIDER`, `SHIPPING_PROVIDER`,
  `NOTIFICATION_GATEWAY`).
- **Dev-режим** зі змонтованим кодом: зручно для розробки, ціною повільнішого старту.
