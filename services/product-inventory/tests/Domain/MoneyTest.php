<?php

namespace App\Tests\Domain;

use App\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testValidMoney(): void
    {
        $money = new Money(2500, 'USD');

        self::assertSame(2500, $money->amountMinor);
        self::assertSame(['amountMinor' => 2500, 'currency' => 'USD'], $money->toCanonical());
    }

    public function testInvalidCurrencyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(100, 'us');
    }

    public function testNegativeAmountRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(-1, 'USD');
    }
}
