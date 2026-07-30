<?php

namespace App\Tests\Infrastructure;

use App\Infrastructure\FakePaymentProvider;
use App\Infrastructure\FakeShippingProvider;
use App\Infrastructure\NovaPoshtaShippingProvider;
use App\Infrastructure\ProviderFactory;
use App\Infrastructure\StripePaymentProvider;
use PHPUnit\Framework\TestCase;

/** Seam провайдерів: вибір реалізації за env, default — fake. */
final class ProviderFactoryTest extends TestCase
{
    public function testPaymentProviderSelection(): void
    {
        $fake = new FakePaymentProvider();
        $stripe = new StripePaymentProvider('sk_test');

        self::assertSame($fake, ProviderFactory::payment($fake, $stripe, 'fake'));
        self::assertSame($fake, ProviderFactory::payment($fake, $stripe, ''));
        self::assertSame($stripe, ProviderFactory::payment($fake, $stripe, 'Stripe'));
    }

    public function testShippingProviderSelection(): void
    {
        $fake = new FakeShippingProvider();
        $novaPoshta = new NovaPoshtaShippingProvider('key');

        self::assertSame($fake, ProviderFactory::shipping($fake, $novaPoshta, 'fake'));
        self::assertSame($novaPoshta, ProviderFactory::shipping($fake, $novaPoshta, 'novaposhta'));
    }
}
