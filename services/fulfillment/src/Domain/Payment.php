<?php

namespace App\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегат Payment. Один платіж на замовлення (унікальний order_id) — повторний
 * AuthorizePayment для того ж замовлення ідемпотентний і не плодить платежів.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payments', schema: 'fulfillment')]
#[ORM\UniqueConstraint(name: 'uniq_payment_order', columns: ['order_id'])]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(name: 'amount_currency', length: 3)]
    private string $amountCurrency;

    #[ORM\Column(length: 16, enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    /** Ідентифікатор транзакції у зовнішнього провайдера (Stripe / fake). */
    #[ORM\Column(name: 'provider_ref', length: 128, nullable: true)]
    private ?string $providerRef;

    #[ORM\Column(name: 'failure_reason', length: 255, nullable: true)]
    private ?string $failureReason;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    private function __construct(string $orderId, Money $amount, PaymentStatus $status, ?string $providerRef, ?string $failureReason)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->orderId = $orderId;
        $this->amountMinor = $amount->amountMinor;
        $this->amountCurrency = $amount->currency;
        $this->status = $status;
        $this->providerRef = $providerRef;
        $this->failureReason = $failureReason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function authorized(string $orderId, Money $amount, string $providerRef): self
    {
        return new self($orderId, $amount, PaymentStatus::AUTHORIZED, $providerRef, null);
    }

    public static function failed(string $orderId, Money $amount, string $reason): self
    {
        return new self($orderId, $amount, PaymentStatus::FAILED, null, $reason);
    }

    /** Повторна спроба після відмови провайдера. */
    public function reauthorize(string $providerRef): void
    {
        $this->transitionTo(PaymentStatus::AUTHORIZED);
        $this->providerRef = $providerRef;
        $this->failureReason = null;
    }

    public function capture(): void
    {
        $this->transitionTo(PaymentStatus::CAPTURED);
    }

    public function markFailed(string $reason): void
    {
        $this->transitionTo(PaymentStatus::FAILED);
        $this->failureReason = $reason;
        $this->providerRef = null;
    }

    private function transitionTo(PaymentStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw FulfillmentException::invalidPaymentTransition($this->id, $this->status, $target);
        }
        $this->status = $target;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function isAuthorized(): bool
    {
        return $this->status === PaymentStatus::AUTHORIZED;
    }

    public function amount(): Money
    {
        return new Money((int) $this->amountMinor, $this->amountCurrency);
    }

    public function providerRef(): ?string
    {
        return $this->providerRef;
    }
}
