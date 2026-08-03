<?php

namespace E2E;

/** Кроки покупця, з яких складаються наскрізні сценарії. */
final class ShopScenario
{
    public string $email;
    public string $customerId;
    public string $token;

    public function __construct(private readonly EsbClient $esb)
    {
    }

    /** Реєстрація + автентифікація (обидві операції публічні). */
    public function signUp(): self
    {
        $customers = $this->esb->forContract('customer-management');
        $this->email = sprintf('e2e+%s@example.com', bin2hex(random_bytes(4)));
        $this->customerId = $customers->RegisterCustomer([
            'email' => $this->email,
            'password' => 'secret123',
            'fullName' => 'E2E Buyer',
        ])->customerId;
        $this->token = $customers->Authenticate(['email' => $this->email, 'password' => 'secret123'])->token;

        return $this;
    }

    /**
     * @return object[] канонічні товари, відсортовані за ціною
     *
     * SoapClient віддає ОДИН елемент об'єктом, а не масивом із одного елемента,
     * тож список завжди нормалізуємо — інакше каталог із одного товару зламає сценарій.
     */
    public function catalog(): array
    {
        $products = $this->esb->forContract('product-inventory')->SearchProducts([])->product ?? [];
        $products = \is_array($products) ? $products : [$products];
        usort($products, static fn ($a, $b) => $a->price->amountMinor <=> $b->price->amountMinor);

        return $products;
    }

    public function product(string $sku): object
    {
        foreach ($this->catalog() as $product) {
            if ($product->sku === $sku) {
                return $product;
            }
        }

        throw new \RuntimeException(sprintf('Товару "%s" немає в каталозі', $sku));
    }

    /**
     * Найдешевший товар, якого точно вистачить на покупку. Прив'язка до конкретного SKU
     * робила сценарій одноразовим: залишок скінчувався після кількох прогонів по тій самій БД
     * (поповнити — `app:seed-products --reset-stock`).
     */
    public function productWithStock(int $needed): object
    {
        foreach ($this->catalog() as $product) {
            if ((int) $product->stockAvailable >= $needed) {
                return $product;
            }
        }

        throw new \RuntimeException(sprintf(
            'У каталозі немає товару із залишком >= %d. Поповніть: app:seed-products --reset-stock',
            $needed,
        ));
    }

    /**
     * Товар і кількість, які гарантовано пробивають ліміт fake-провайдера оплати
     * (і при цьому фізично є на складі, щоб збій стався саме на оплаті, а не на резерві).
     *
     * @return array{0: object, 1: int}
     */
    public function purchaseExceedingPaymentLimit(int $limitMinor = 1_000_000): array
    {
        $catalog = $this->catalog();
        usort($catalog, static fn ($a, $b) => $b->price->amountMinor <=> $a->price->amountMinor);

        foreach ($catalog as $product) {
            $quantity = intdiv($limitMinor, (int) $product->price->amountMinor) + 1;

            if ((int) $product->stockAvailable >= $quantity) {
                return [$product, $quantity];
            }
        }

        throw new \RuntimeException('Немає товару із залишком, достатнім, щоб пробити ліміт оплати');
    }

    public function stockOf(string $productId): int
    {
        return (int) $this->esb->forContract('product-inventory')->GetProduct(['productId' => $productId])->product->stockAvailable;
    }

    public function cartWith(object $product, int $quantity): string
    {
        $orders = $this->esb->forContract('order-management', $this->token);
        $cartId = $orders->CreateCart(['customerId' => $this->customerId])->cartId;
        $orders->AddCartItem([
            'cartId' => $cartId,
            'productId' => $product->id,
            'sku' => $product->sku,
            'quantity' => $quantity,
            'unitPrice' => ['amountMinor' => $product->price->amountMinor, 'currency' => $product->price->currency],
        ]);

        return $cartId;
    }

    /** Один виклик до ESB, який проганяє весь бізнес-процес. */
    public function checkout(string $cartId): object
    {
        return $this->esb->forContract('checkout-orchestration', $this->token)->Checkout([
            'cartId' => $cartId,
            'customerId' => $this->customerId,
            'customerEmail' => $this->email,
            'shippingAddress' => ['line1' => 'вул. Хрещатик, 1', 'city' => 'Київ', 'postalCode' => '01001', 'country' => 'UA'],
        ]);
    }

    public function order(string $orderId): object
    {
        return $this->esb->forContract('order-management', $this->token)->GetOrder(['orderId' => $orderId])->order;
    }
}
