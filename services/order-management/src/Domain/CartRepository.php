<?php

namespace App\Domain;

/** Доменний порт репозиторію кошика (реалізується в Infrastructure). */
interface CartRepository
{
    public function save(Cart $cart): void;

    public function byId(string $id): ?Cart;

    public function remove(Cart $cart): void;
}
