<?php

namespace App\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегат Product. Каталог + наявність. Резервування зменшує доступний залишок
 * як доменна операція (з інваріантом «не можна зарезервувати більше, ніж є»).
 */
#[ORM\Entity]
#[ORM\Table(name: 'products', schema: 'catalog')]
#[ORM\UniqueConstraint(name: 'uniq_product_sku', columns: ['sku'])]
class Product
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'price_amount_minor', type: 'bigint')]
    private int $priceAmountMinor;

    #[ORM\Column(name: 'price_currency', length: 3)]
    private string $priceCurrency;

    #[ORM\Column(name: 'stock_available', type: 'integer')]
    private int $stockAvailable;

    public function __construct(string $id, string $sku, string $name, Money $price, int $stockAvailable)
    {
        if ($stockAvailable < 0) {
            throw new \InvalidArgumentException('Stock cannot be negative');
        }
        $this->id = $id;
        $this->sku = $sku;
        $this->name = $name;
        $this->priceAmountMinor = $price->amountMinor;
        $this->priceCurrency = $price->currency;
        $this->stockAvailable = $stockAvailable;
    }

    public static function create(string $sku, string $name, Money $price, int $stockAvailable): self
    {
        return new self(Uuid::v4()->toRfc4122(), $sku, $name, $price, $stockAvailable);
    }

    public function hasStock(int $quantity): bool
    {
        return $quantity > 0 && $quantity <= $this->stockAvailable;
    }

    /** Резервує залишок (зменшує доступну кількість). */
    public function reserve(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Reserve quantity must be positive');
        }
        if ($quantity > $this->stockAvailable) {
            throw ProductException::insufficientStock($this->id, $quantity, $this->stockAvailable);
        }
        $this->stockAvailable -= $quantity;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function stockAvailable(): int
    {
        return $this->stockAvailable;
    }

    public function price(): Money
    {
        return new Money((int) $this->priceAmountMinor, $this->priceCurrency);
    }

    /** Канонічне представлення cdm:Product. */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'price' => $this->price()->toCanonical(),
            'stockAvailable' => $this->stockAvailable,
        ];
    }
}
