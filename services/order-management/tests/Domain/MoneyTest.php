<?php

namespace App\Tests\Domain;

use App\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testMultiplyAndAdd(): void
    {
        $price = new Money(29900, 'UAH');

        self::assertSame(89700, $price->multiply(3)->amountMinor);
        self::assertSame(49800, $price->add(new Money(19900, 'UAH'))->amountMinor);
    }

    public function testAddDifferentCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(100, 'UAH'))->add(new Money(100, 'EUR'));
    }

    public function testNegativeAmountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(-1, 'UAH');
    }

    public function testInvalidCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(100, 'uah');
    }

    public function testCanonicalShape(): void
    {
        self::assertSame(['amountMinor' => 100, 'currency' => 'UAH'], (new Money(100, 'UAH'))->toCanonical());
    }
}
