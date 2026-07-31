<?php

namespace App\Tests\Domain;

use App\Domain\FulfillmentException;
use App\Domain\Money;
use App\Domain\Payment;
use App\Domain\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    private function authorized(): Payment
    {
        return Payment::authorized('order-1', new Money(99600, 'UAH'), 'fake_ref');
    }

    public function testAuthorizeThenCapture(): void
    {
        $payment = $this->authorized();
        self::assertSame(PaymentStatus::AUTHORIZED, $payment->status());
        self::assertTrue($payment->isAuthorized());

        $payment->capture();
        self::assertSame(PaymentStatus::CAPTURED, $payment->status());
    }

    public function testDoubleCaptureThrows(): void
    {
        $payment = $this->authorized();
        $payment->capture();

        $this->expectException(FulfillmentException::class);
        $payment->capture();
    }

    public function testFailedPaymentCanBeRetried(): void
    {
        $payment = Payment::failed('order-1', new Money(99600, 'UAH'), 'card declined');
        self::assertSame(PaymentStatus::FAILED, $payment->status());
        self::assertNull($payment->providerRef());

        $payment->reauthorize('fake_ref_2');
        self::assertSame(PaymentStatus::AUTHORIZED, $payment->status());
        self::assertSame('fake_ref_2', $payment->providerRef());
    }

    public function testCapturedPaymentIsTerminal(): void
    {
        $payment = $this->authorized();
        $payment->capture();

        $this->expectException(FulfillmentException::class);
        $payment->markFailed('too late');
    }

    public function testAmountIsPreserved(): void
    {
        self::assertSame(99600, $this->authorized()->amount()->amountMinor);
        self::assertSame('UAH', $this->authorized()->amount()->currency);
    }
}
