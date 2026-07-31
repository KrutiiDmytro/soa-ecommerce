<?php

namespace App\Infrastructure;

use App\Domain\Address;
use App\Domain\ShippingProvider;

/** Перевізник за замовчуванням: видає правдоподібний номер накладної. */
final class FakeShippingProvider implements ShippingProvider
{
    public function createShipment(string $orderId, Address $shippingAddress): string
    {
        return sprintf('FAKE%s%04d', strtoupper($shippingAddress->country), random_int(0, 9999));
    }
}
