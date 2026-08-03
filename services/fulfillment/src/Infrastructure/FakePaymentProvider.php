<?php

namespace App\Infrastructure;

use App\Domain\Money;
use App\Domain\PaymentDeclined;
use App\Domain\PaymentProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Провайдер за замовчуванням: гроші не рухає, поводиться детерміновано.
 * Суми понад ліміт відхиляє — щоб і тест, і e2e могли перевірити гілку відмови.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const DECLINE_ABOVE_MINOR = 1_000_000;

    public function authorize(string $orderId, Money $amount): string
    {
        if ($amount->amountMinor > self::DECLINE_ABOVE_MINOR) {
            throw new PaymentDeclined(sprintf('amount %d %s exceeds the fake provider limit', $amount->amountMinor, $amount->currency));
        }

        return 'fake_'.Uuid::v4()->toRfc4122();
    }

    public function capture(string $providerRef, Money $amount): void
    {
        // Немає зовнішньої системи — захоплення завжди успішне.
    }
}
