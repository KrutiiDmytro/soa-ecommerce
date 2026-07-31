<?php

namespace App\Domain;

/** Доменний порт репозиторію відправлень (реалізується в Infrastructure). */
interface ShipmentRepository
{
    public function save(Shipment $shipment): void;

    public function byId(string $id): ?Shipment;

    public function byOrderId(string $orderId): ?Shipment;
}
