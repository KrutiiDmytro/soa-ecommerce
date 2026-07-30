<?php

namespace App\Infrastructure;

use App\Domain\Shipment;
use App\Domain\ShipmentRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineShipmentRepository implements ShipmentRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(Shipment $shipment): void
    {
        $this->em->persist($shipment);
        $this->em->flush();
    }

    public function byId(string $id): ?Shipment
    {
        return $this->em->find(Shipment::class, $id);
    }

    public function byOrderId(string $orderId): ?Shipment
    {
        return $this->em->getRepository(Shipment::class)->findOneBy(['orderId' => $orderId]);
    }
}
