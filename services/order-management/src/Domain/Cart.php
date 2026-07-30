<?php

namespace App\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегат Cart. У SOA кошик живе ВСЕРЕДИНІ Order Management (грубіша зернистість):
 * окремого cart-сервісу, як у мікросервісах, немає.
 */
#[ORM\Entity]
#[ORM\Table(name: 'carts', schema: 'orders')]
class Cart
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    /** @var Collection<int, CartItem> */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $customerId, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v4()->toRfc4122();
        $this->customerId = $customerId;
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Додає товар; повторний productId зливається в наявний рядок. */
    public function addItem(string $productId, string $sku, int $quantity, Money $unitPrice): void
    {
        foreach ($this->items as $item) {
            if ($item->productId() === $productId) {
                $item->increaseQuantity($quantity);

                return;
            }
        }

        $this->items->add(new CartItem($this, $productId, $sku, $quantity, $unitPrice));
    }

    /** @return CartItem[] */
    public function items(): array
    {
        return $this->items->toArray();
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function lineCount(): int
    {
        return $this->items->count();
    }

    public function total(): Money
    {
        $total = null;

        foreach ($this->items as $item) {
            $lineTotal = $item->lineTotal();
            $total = $total === null ? $lineTotal : $total->add($lineTotal);
        }

        return $total ?? Money::zero('UAH');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }
}
