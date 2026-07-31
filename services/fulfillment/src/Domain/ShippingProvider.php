<?php

namespace App\Domain;

/**
 * Порт перевізника (seam). Реалізації: fake (default) та Нова Пошта.
 */
interface ShippingProvider
{
    /** @return string номер накладної (tracking number) */
    public function createShipment(string $orderId, Address $shippingAddress): string;
}
