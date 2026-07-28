<?php

namespace App\Tests\Domain;

use App\Domain\Money;
use App\Domain\Product;
use App\Domain\ProductException;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    private function product(int $stock = 10): Product
    {
        return Product::create('SKU-1', 'Widget', new Money(1000, 'UAH'), $stock);
    }

    public function testReserveReducesStock(): void
    {
        $product = $this->product(10);
        $product->reserve(3);

        self::assertSame(7, $product->stockAvailable());
    }

    public function testReserveBeyondStockThrows(): void
    {
        $product = $this->product(2);

        $this->expectException(ProductException::class);
        $product->reserve(5);
    }

    public function testReserveNonPositiveThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->product()->reserve(0);
    }

    public function testHasStock(): void
    {
        $product = $this->product(5);

        self::assertTrue($product->hasStock(5));
        self::assertFalse($product->hasStock(6));
        self::assertFalse($product->hasStock(0));
    }

    public function testCanonicalShape(): void
    {
        $canonical = $this->product(4)->toCanonical();

        self::assertSame('SKU-1', $canonical['sku']);
        self::assertSame(1000, $canonical['price']['amountMinor']);
        self::assertSame('UAH', $canonical['price']['currency']);
        self::assertSame(4, $canonical['stockAvailable']);
    }
}
