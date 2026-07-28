# E-Commerce SOA — Implementation (Task 28)

Реалізація SOA-дизайну з **Task 27** у працюючий код: 5 автономних грубозернистих сервісів
(своя схема БД + DDD), централізований IAM, комунікація **SOAP/WS-\*** (sync) + **AMQP**
(async) через **ESB** з оркестрацією checkout і **канонічною моделлю даних** на дроті.

> Дизайн, декомпозиція та контракти: див. репозиторій **Task 27**. Тут — виконання.

## Сервіси

| Сервіс | Роль | БД |
|---|---|---|
| **customer-management** | Ідентичність + централізований **IAM** (видача/перевірка токена), профіль, адреси | `customer` |
| **product-inventory** | Каталог + наявність/резервування (Redis-кеш) | `product` |
| **order-management** | Кошик + checkout + lifecycle замовлення | `orders` |
| **fulfillment** | Оплата + доставка (провайдери за seam) | `fulfillment` |
| **notification** | Сповіщення (stateless, async-споживач подій) | — |
| **esb** | Маршрутизація + канон-трансформація + **оркестрація checkout** + WS-Security + registry | — |

Уся клієнтська взаємодія — **через ESB** (`http://localhost:8080`); сервіси не викликають
одне одного напряму. Це «smart pipes» SOA.

## Запуск

```bash
cp .env.example .env
docker compose build
docker compose up -d
```

Перевірка (Фаза 0 — health кожного сервісу через його контейнер):

```bash
docker compose exec esb curl -s http://customer-management/health
docker compose exec esb curl -s http://esb/health
```

## Контракти (SOA-артефакти)

`contracts/` містить канонічну модель + WSDL кожного сервісу (перенесені з Task 27):

- `canonical-data-model.xsd` — канонічна модель даних (єдине трактування сутностей).
- `*-service.wsdl` — контракт-first SOAP-інтерфейси (document/literal).

## Статус

Хід робіт і план — [`docs/todo.md`](docs/todo.md). Поточна фаза: **0 — каркас та інфраструктура**.
