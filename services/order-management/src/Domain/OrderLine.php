<?php

namespace App\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Рядок замовлення — незмінний знімок рядка кошика на момент checkout. */
#[ORM\Entity]
#[ORM\Table(name: 'order_lines', schema: 'orders')]
#[ORM\Index(name: 'idx_order_line_order', columns: ['order_id'])]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

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

    public function __construct(Order $order, string $productId, string $sku, int $quantity, Money $unitPrice)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->order = $order;
        $this->productId = $productId;
        $this->sku = $sku;
        $this->quantity = $quantity;
        $this->unitPriceAmountMinor = $unitPrice->amountMinor;
        $this->unitPriceCurrency = $unitPrice->currency;
    }

    public function unitPrice(): Money
    {
        return new Money((int) $this->unitPriceAmountMinor, $this->unitPriceCurrency);
    }

    public function lineTotal(): Money
    {
        return $this->unitPrice()->multiply($this->quantity);
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    /** Канонічне представлення cdm:OrderLine. */
    public function toCanonical(): array
    {
        return [
            'productId' => $this->productId,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice()->toCanonical(),
            'lineTotal' => $this->lineTotal()->toCanonical(),
        ];
    }
}
