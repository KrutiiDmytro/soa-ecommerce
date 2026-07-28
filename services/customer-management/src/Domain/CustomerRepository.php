<?php

namespace App\Domain;

/** Доменний порт репозиторію (реалізується в Infrastructure). */
interface CustomerRepository
{
    public function save(Customer $customer): void;

    public function byId(string $id): ?Customer;

    public function byEmail(string $email): ?Customer;
}
