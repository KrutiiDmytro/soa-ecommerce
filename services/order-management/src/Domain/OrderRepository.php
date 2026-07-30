<?php

namespace App\Domain;

/** Доменний порт репозиторію замовлень (реалізується в Infrastructure). */
interface OrderRepository
{
    public function save(Order $order): void;

    public function byId(string $id): ?Order;
}
