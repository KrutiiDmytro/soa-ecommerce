<?php

namespace App\Tests\Domain;

use App\Domain\Cart;
use App\Domain\Money;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testEmptyCartHasZeroTotal(): void
    {
        $cart = new Cart('customer-1');

        self::assertTrue($cart->isEmpty());
        self::assertSame(0, $cart->total()->amountMinor);
    }

    public function testTotalSumsLineTotals(): void
    {
        $cart = new Cart('customer-1');
        $cart->addItem('product-1', 'PRINT-TSHIRT', 2, new Money(29900, 'UAH'));
        $cart->addItem('product-2', 'PRINT-MUG', 3, new Money(19900, 'UAH'));

        self::assertSame(2, $cart->lineCount());
        self::assertSame(2 * 29900 + 3 * 19900, $cart->total()->amountMinor);
        self::assertSame('UAH', $cart->total()->currency);
    }

    public function testSameProductIsMergedIntoOneLine(): void
    {
        $cart = new Cart('customer-1');
        $cart->addItem('product-1', 'PRINT-TSHIRT', 2, new Money(29900, 'UAH'));
        $cart->addItem('product-1', 'PRINT-TSHIRT', 1, new Money(29900, 'UAH'));

        self::assertSame(1, $cart->lineCount());
        self::assertSame(3, $cart->items()[0]->quantity());
        self::assertSame(3 * 29900, $cart->total()->amountMinor);
    }

    public function testCanonicalShapeForOrchestration(): void
    {
        $cart = new Cart('customer-1');
        $cart->addItem('product-1', 'PRINT-TSHIRT', 2, new Money(29900, 'UAH'));

        $canonical = $cart->toCanonical();

        self::assertSame(['cartId', 'customerId', 'line', 'total'], array_keys($canonical));
        self::assertSame(['productId', 'sku', 'quantity', 'unitPrice', 'lineTotal'], array_keys($canonical['line'][0]));
        self::assertSame(2, $canonical['line'][0]['quantity']);
        self::assertSame(59800, $canonical['total']['amountMinor']);
    }

    public function testNonPositiveQuantityThrows(): void
    {
        $cart = new Cart('customer-1');

        $this->expectException(\InvalidArgumentException::class);
        $cart->addItem('product-1', 'PRINT-TSHIRT', 0, new Money(29900, 'UAH'));
    }

    public function testMixedCurrenciesThrow(): void
    {
        $cart = new Cart('customer-1');
        $cart->addItem('product-1', 'PRINT-TSHIRT', 1, new Money(29900, 'UAH'));
        $cart->addItem('product-2', 'PRINT-MUG', 1, new Money(1000, 'EUR'));

        $this->expectException(\InvalidArgumentException::class);
        $cart->total();
    }
}
