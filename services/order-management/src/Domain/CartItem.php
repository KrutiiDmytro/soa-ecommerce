<?php

namespace App\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Рядок кошика. Ціна зберігається ЗНІМКОМ (не читається з каталогу):
 * Order Management автономний і не залежить від Product & Inventory.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cart_items', schema: 'orders')]
#[ORM\Index(name: 'idx_cart_item_cart', columns: ['cart_id'])]
class CartItem
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'cart_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(name: 'unit_price_amount_minor', type: 'bigint')]
    private int $unitPriceAmountMinor;

    #[ORM\Column(name: 'unit_price_currency', length: 3)]
    private string $unitPriceCurrency;

    public function __construct(Cart $cart, string $productId, string $sku, int $quantity, Money $unitPrice)
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Cart item quantity must be positive');
        }
        $this->id = Uuid::v4()->toRfc4122();
        $this->cart = $cart;
        $this->productId = $productId;
        $this->sku = $sku;
        $this->quantity = $quantity;
        $this->unitPriceAmountMinor = $unitPrice->amountMinor;
        $this->unitPriceCurrency = $unitPrice->currency;
    }

    public function increaseQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Cart item quantity must be positive');
        }
        $this->quantity += $quantity;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return new Money((int) $this->unitPriceAmountMinor, $this->unitPriceCurrency);
    }

    public function lineTotal(): Money
    {
        return $this->unitPrice()->multiply($this->quantity);
    }
}
