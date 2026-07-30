<?php

namespace App\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегат Order. Створюється зі знімка кошика; статус змінюється лише через
 * дозволені переходи життєвого циклу (див. OrderStatus::allowedTransitions()).
 */
#[ORM\Entity]
#[ORM\Table(name: 'orders', schema: 'orders')]
#[ORM\Index(name: 'idx_order_customer', columns: ['customer_id'])]
class Order
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(length: 16, enumType: OrderStatus::class)]
    private OrderStatus $status;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    #[ORM\Column(name: 'total_amount_minor', type: 'bigint')]
    private int $totalAmountMinor;

    #[ORM\Column(name: 'total_currency', length: 3)]
    private string $totalCurrency;

    #[ORM\Column(name: 'shipping_line1', length: 255)]
    private string $shippingLine1;

    #[ORM\Column(name: 'shipping_line2', length: 255, nullable: true)]
    private ?string $shippingLine2;

    #[ORM\Column(name: 'shipping_city', length: 128)]
    private string $shippingCity;

    #[ORM\Column(name: 'shipping_postal_code', length: 32)]
    private string $shippingPostalCode;

    #[ORM\Column(name: 'shipping_country', length: 2)]
    private string $shippingCountry;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    private function __construct(string $customerId, Money $total, Address $shippingAddress)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->customerId = $customerId;
        $this->status = OrderStatus::PENDING;
        $this->lines = new ArrayCollection();
        $this->totalAmountMinor = $total->amountMinor;
        $this->totalCurrency = $total->currency;
        $this->shippingLine1 = $shippingAddress->line1;
        $this->shippingLine2 = $shippingAddress->line2;
        $this->shippingCity = $shippingAddress->city;
        $this->shippingPostalCode = $shippingAddress->postalCode;
        $this->shippingCountry = $shippingAddress->country;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Checkout: замовлення = незмінний знімок кошика. */
    public static function fromCart(Cart $cart, Address $shippingAddress): self
    {
        if ($cart->isEmpty()) {
            throw OrderException::emptyCart($cart->id());
        }

        $order = new self($cart->customerId(), $cart->total(), $shippingAddress);

        foreach ($cart->items() as $item) {
            $order->lines->add(new OrderLine($order, $item->productId(), $item->sku(), $item->quantity(), $item->unitPrice()));
        }

        return $order;
    }

    public function transitionTo(OrderStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw OrderException::invalidTransition($this->id, $this->status, $target);
        }
        $this->status = $target;
    }

    /** Викликається оркестрацією ESB після успішної оплати. */
    public function markPaid(): void
    {
        $this->transitionTo(OrderStatus::PAID);
    }

    public function cancel(): void
    {
        $this->transitionTo(OrderStatus::CANCELLED);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function total(): Money
    {
        return new Money((int) $this->totalAmountMinor, $this->totalCurrency);
    }

    public function shippingAddress(): Address
    {
        return new Address($this->shippingLine1, $this->shippingCity, $this->shippingPostalCode, $this->shippingCountry, $this->shippingLine2);
    }

    /** @return OrderLine[] */
    public function lines(): array
    {
        return $this->lines->toArray();
    }

    /** Канонічне представлення cdm:Order. */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'customerId' => $this->customerId,
            'status' => $this->status->value,
            'line' => array_map(static fn (OrderLine $line): array => $line->toCanonical(), $this->lines()),
            'total' => $this->total()->toCanonical(),
            'shippingAddress' => $this->shippingAddress()->toCanonical(),
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
