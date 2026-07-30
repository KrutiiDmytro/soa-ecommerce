<?php

namespace App\Infrastructure;

use App\Domain\Order;
use App\Domain\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineOrderRepository implements OrderRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(Order $order): void
    {
        $this->em->persist($order);
        $this->em->flush();
    }

    public function byId(string $id): ?Order
    {
        return $this->em->find(Order::class, $id);
    }
}
