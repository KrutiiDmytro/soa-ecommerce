<?php

namespace E2E\Tests;

use E2E\EsbClient;
use E2E\ShopScenario;
use PHPUnit\Framework\TestCase;

/**
 * E2E повного потоку із завдання: переглянув каталог → зареєструвався → увійшов →
 * кошик → checkout (резерв, замовлення, оплата) → замовлення → сповіщення.
 * Клієнт увесь час ходить ЛИШЕ в ESB.
 */
final class CheckoutFlowTest extends TestCase
{
    private ShopScenario $shop;

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180'); // перший запит до сервісу прогріває його кеш
    }

    protected function setUp(): void
    {
        $this->shop = new ShopScenario(new EsbClient());
    }

    public function testHappyPathFromCatalogToPaidOrder(): void
    {
        $shop = $this->shop->signUp();

        $product = $shop->productWithStock(2);
        $stockBefore = $shop->stockOf($product->id);

        $result = $shop->checkout($shop->cartWith($product, 2));
        $order = $result->order;

        self::assertSame('PAID', $order->status, 'Оркестрація має довести замовлення до PAID');
        self::assertSame(2 * (int) $product->price->amountMinor, (int) $order->total->amountMinor);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result->paymentId);
        self::assertTrue($result->shipmentRequested);

        // Замовлення справді збережене, а резерв справді знято з каталогу.
        self::assertSame('PAID', $shop->order($order->id)->status);
        self::assertSame($stockBefore - 2, $shop->stockOf($product->id), 'Кеш каталогу мав бути інвалідований');
    }

    public function testFailedPaymentIsCompensated(): void
    {
        $shop = $this->shop->signUp();

        // Кількість, що гарантовано пробиває ліміт fake-провайдера, але є на складі:
        // збій має статися саме на оплаті, інакше перевірятимемо не ту гілку компенсації.
        [$product, $quantity] = $shop->purchaseExceedingPaymentLimit();
        $stockBefore = $shop->stockOf($product->id);

        $cartId = $shop->cartWith($product, $quantity);

        try {
            $shop->checkout($cartId);
            self::fail('Оплата мала бути відхилена');
        } catch (\SoapFault $e) {
            self::assertStringContainsString('declined', $e->getMessage());
            self::assertStringContainsString('Compensation', $e->getMessage());
        }

        self::assertSame($stockBefore, $shop->stockOf($product->id), 'Компенсація мала повернути резерв');
    }

    public function testStockProjectionCatchesUpAsynchronously(): void
    {
        $shop = $this->shop->signUp();
        $product = $shop->productWithStock(1);

        $shop->checkout($shop->cartWith($product, 1));
        $expected = $shop->stockOf($product->id);

        $projected = null;
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $projected = $this->projectionFor($product->sku);
            if ($projected !== null && (int) $projected['stockAvailable'] === $expected) {
                break;
            }
            usleep(500_000);
        }

        self::assertNotNull($projected, 'Проекція залишків не отримала подію');
        self::assertSame($expected, (int) $projected['stockAvailable']);
        self::assertSame('reserved', $projected['lastOperation']);
    }

    /** @return array<string, mixed>|null */
    private function projectionFor(string $sku): ?array
    {
        $raw = @file_get_contents(getenv('STOCK_PROJECTION_URL') ?: 'http://product-inventory/stock-projection');

        foreach (json_decode((string) $raw, true)['products'] ?? [] as $product) {
            if ($product['sku'] === $sku) {
                return $product;
            }
        }

        return null;
    }
}
