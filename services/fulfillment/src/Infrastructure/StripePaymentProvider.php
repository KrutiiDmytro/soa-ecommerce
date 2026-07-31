<?php

namespace App\Infrastructure;

use App\Domain\FulfillmentException;
use App\Domain\Money;
use App\Domain\PaymentProvider;

/**
 * Реальна інтеграція за тим самим seam. Вмикається через PAYMENT_PROVIDER=stripe.
 * Ключі в цьому навчальному стенді не налаштовані — провайдер чесно про це каже,
 * замість того щоб мовчки вдавати успішний платіж.
 */
final class StripePaymentProvider implements PaymentProvider
{
    public function __construct(private readonly string $secretKey)
    {
    }

    public function authorize(string $orderId, Money $amount): string
    {
        $this->assertConfigured();

        throw FulfillmentException::providerNotConfigured('stripe');
    }

    public function capture(string $providerRef, Money $amount): void
    {
        $this->assertConfigured();

        throw FulfillmentException::providerNotConfigured('stripe');
    }

    private function assertConfigured(): void
    {
        if ($this->secretKey === '') {
            throw FulfillmentException::providerNotConfigured('stripe (STRIPE_SECRET_KEY is empty)');
        }
    }
}
