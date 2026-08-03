<?php

namespace App\Infrastructure;

use App\Domain\PaymentProvider;
use App\Domain\ShippingProvider;

/**
 * Вибір реалізації провайдера за env (PAYMENT_PROVIDER / SHIPPING_PROVIDER).
 * Домен бачить лише порт — підміна провайдера не торкається бізнес-логіки.
 */
final class ProviderFactory
{
    public static function payment(FakePaymentProvider $fake, StripePaymentProvider $stripe, string $type): PaymentProvider
    {
        return match (strtolower($type)) {
            'stripe' => $stripe,
            default => $fake,
        };
    }

    public static function shipping(FakeShippingProvider $fake, NovaPoshtaShippingProvider $novaPoshta, string $type): ShippingProvider
    {
        return match (strtolower($type)) {
            'novaposhta' => $novaPoshta,
            default => $fake,
        };
    }
}
