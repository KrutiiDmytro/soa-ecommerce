<?php

namespace App\Domain;

/** Доменний порт репозиторію платежів (реалізується в Infrastructure). */
interface PaymentRepository
{
    public function save(Payment $payment): void;

    public function byId(string $id): ?Payment;

    public function byOrderId(string $orderId): ?Payment;
}
