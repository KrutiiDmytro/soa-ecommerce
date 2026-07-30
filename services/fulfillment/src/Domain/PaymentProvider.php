<?php

namespace App\Domain;

/**
 * Порт платіжного провайдера (seam). Реалізації: fake (default) та Stripe.
 * Домен не знає, хто саме проводить гроші.
 */
interface PaymentProvider
{
    /**
     * @return string ідентифікатор транзакції провайдера
     *
     * @throws PaymentDeclined якщо провайдер відмовив
     */
    public function authorize(string $orderId, Money $amount): string;

    /** @throws PaymentDeclined */
    public function capture(string $providerRef, Money $amount): void;
}
