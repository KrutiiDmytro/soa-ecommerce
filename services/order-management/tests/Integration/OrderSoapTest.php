<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration: кошик → замовлення → життєвий цикл, усе через SOAP і реальну БД.
 * Запуск: docker compose exec order-management php vendor/bin/phpunit --testsuite integration
 */
final class OrderSoapTest extends TestCase
{
    private const WSDL = '/contracts/order-management-service.wsdl';
    private const TSHIRT = '11111111-1111-1111-1111-111111111111';
    private const MUG = '22222222-2222-2222-2222-222222222222';

    private \SoapClient $client;
    private string $customerId;

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180');
    }

    protected function setUp(): void
    {
        $this->client = new \SoapClient(self::WSDL, [
            'location' => getenv('SERVICE_ENDPOINT') ?: 'http://order-management/soap',
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
        ]);
        $this->customerId = sprintf('%s-1111-1111-1111-111111111111', substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function cartWithTwoLines(): string
    {
        $cartId = $this->client->CreateCart(['customerId' => $this->customerId])->cartId;

        $this->client->AddCartItem([
            'cartId' => $cartId, 'productId' => self::TSHIRT, 'sku' => 'PRINT-TSHIRT', 'quantity' => 2,
            'unitPrice' => ['amountMinor' => 29900, 'currency' => 'UAH'],
        ]);
        $this->client->AddCartItem([
            'cartId' => $cartId, 'productId' => self::MUG, 'sku' => 'PRINT-MUG', 'quantity' => 1,
            'unitPrice' => ['amountMinor' => 19900, 'currency' => 'UAH'],
        ]);

        return $cartId;
    }

    private function shippingAddress(): array
    {
        return ['line1' => 'вул. Хрещатик, 1', 'city' => 'Київ', 'postalCode' => '01001', 'country' => 'UA'];
    }

    private function checkout(string $cartId): object
    {
        return $this->client->Checkout([
            'cartId' => $cartId,
            'customerId' => $this->customerId,
            'shippingAddress' => $this->shippingAddress(),
        ])->order;
    }

    public function testCartIsPersistedAndReadableForOrchestration(): void
    {
        $cartId = $this->cartWithTwoLines();

        $cart = $this->client->GetCart(['cartId' => $cartId]);

        self::assertSame($this->customerId, $cart->customerId);
        self::assertCount(2, (array) $cart->line);
        self::assertSame(2 * 29900 + 19900, (int) $cart->total->amountMinor);
    }

    public function testAddingSameProductMergesTheLine(): void
    {
        $cartId = $this->client->CreateCart(['customerId' => $this->customerId])->cartId;
        $line = ['cartId' => $cartId, 'productId' => self::MUG, 'sku' => 'PRINT-MUG', 'quantity' => 1,
            'unitPrice' => ['amountMinor' => 19900, 'currency' => 'UAH']];

        $this->client->AddCartItem($line);
        $response = $this->client->AddCartItem($line);

        self::assertSame(1, (int) $response->lineCount);
    }

    public function testCheckoutCreatesPendingOrderWithSnapshotTotal(): void
    {
        $order = $this->checkout($this->cartWithTwoLines());

        self::assertSame('PENDING', $order->status);
        self::assertSame(2 * 29900 + 19900, (int) $order->total->amountMinor);
        self::assertCount(2, (array) $order->line);
        self::assertSame('Київ', $order->shippingAddress->city);
    }

    public function testOrderSurvivesReadAndFollowsLifecycle(): void
    {
        $orderId = $this->checkout($this->cartWithTwoLines())->id;

        self::assertSame('PENDING', $this->client->GetOrder(['orderId' => $orderId])->order->status);
        self::assertSame('PAID', $this->client->MarkPaid(['orderId' => $orderId])->order->status);
        self::assertSame('CANCELLED', $this->client->CancelOrder(['orderId' => $orderId])->order->status);
    }

    public function testInvalidTransitionFaults(): void
    {
        $orderId = $this->checkout($this->cartWithTwoLines())->id;
        $this->client->MarkPaid(['orderId' => $orderId]);

        $this->expectException(\SoapFault::class);
        $this->client->MarkPaid(['orderId' => $orderId]);
    }

    public function testCheckoutOfEmptyCartFaults(): void
    {
        $cartId = $this->client->CreateCart(['customerId' => $this->customerId])->cartId;

        $this->expectException(\SoapFault::class);
        $this->checkout($cartId);
    }

    public function testCartIsGoneAfterCheckout(): void
    {
        $cartId = $this->cartWithTwoLines();
        $this->checkout($cartId);

        $this->expectException(\SoapFault::class);
        $this->client->GetCart(['cartId' => $cartId]);
    }
}
