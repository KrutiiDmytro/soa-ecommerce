<?php

namespace App\Domain;

/** Доменний порт репозиторію (реалізується в Infrastructure). */
interface ProductRepository
{
    public function save(Product $product): void;

    public function byId(string $id): ?Product;

    /** @return Product[] */
    public function search(?string $query): array;
}
