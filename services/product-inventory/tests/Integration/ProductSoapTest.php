<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration: SOAP + реальна БД + Redis (перевіряємо і кеш, і його інвалідацію).
 * Запуск: docker compose exec product-inventory php vendor/bin/phpunit --testsuite integration
 */
final class ProductSoapTest extends TestCase
{
    private const WSDL = '/contracts/product-inventory-service.wsdl';
    private const TSHIRT = '11111111-1111-1111-1111-111111111111';

    private \SoapClient $client;

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180');
    }

    protected function setUp(): void
    {
        $this->client = new \SoapClient(self::WSDL, [
            'location' => getenv('SERVICE_ENDPOINT') ?: 'http://product-inventory/soap',
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
        ]);
    }

    private function stockOf(string $productId): int
    {
        return (int) $this->client->GetProduct(['productId' => $productId])->product->stockAvailable;
    }

    /**
     * SoapClient віддає один елемент об'єктом, а не масивом з одного елемента.
     *
     * @return object[]
     */
    private function search(?string $query = null): array
    {
        $products = $this->client->SearchProducts($query === null ? [] : ['query' => $query])->product ?? [];

        return \is_array($products) ? $products : [$products];
    }

    public function testSeededCatalogIsSearchable(): void
    {
        $products = $this->search();

        self::assertGreaterThanOrEqual(3, \count($products));
        self::assertContains('PRINT-TSHIRT', array_column($products, 'sku'));
    }

    public function testSearchFiltersByQuery(): void
    {
        $products = $this->search('mug');

        self::assertNotEmpty($products);
        foreach ($products as $product) {
            self::assertStringContainsStringIgnoringCase('mug', $product->sku.$product->name);
        }
    }

    public function testCheckStockReportsAvailability(): void
    {
        $response = $this->client->CheckStock(['productId' => self::TSHIRT, 'quantity' => 1]);

        self::assertTrue($response->available);
        self::assertGreaterThan(0, $response->stockAvailable);
    }

    /** Резерв змінює залишок у БД і скидає кеш читань — наступний GetProduct не має бути стале. */
    public function testReserveAndReleaseRoundTripInvalidatesCache(): void
    {
        $before = $this->stockOf(self::TSHIRT);

        $reserved = $this->client->ReserveStock(['productId' => self::TSHIRT, 'quantity' => 2]);
        self::assertTrue($reserved->reserved);
        self::assertSame($before - 2, (int) $reserved->stockAvailable);
        self::assertSame($before - 2, $this->stockOf(self::TSHIRT), 'GetProduct віддав стале значення з кешу');

        $released = $this->client->ReleaseStock(['productId' => self::TSHIRT, 'quantity' => 2]);
        self::assertTrue($released->released);
        self::assertSame($before, $this->stockOf(self::TSHIRT));
    }

    public function testReservingMoreThanAvailableFaults(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->ReserveStock(['productId' => self::TSHIRT, 'quantity' => 1_000_000]);
    }

    public function testUnknownProductFaults(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->GetProduct(['productId' => '00000000-0000-0000-0000-000000000000']);
    }
}
