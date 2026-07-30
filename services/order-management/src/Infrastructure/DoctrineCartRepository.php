<?php

namespace App\Infrastructure;

use App\Domain\Cart;
use App\Domain\CartRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineCartRepository implements CartRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(Cart $cart): void
    {
        $this->em->persist($cart);
        $this->em->flush();
    }

    public function byId(string $id): ?Cart
    {
        return $this->em->find(Cart::class, $id);
    }

    public function remove(Cart $cart): void
    {
        $this->em->remove($cart);
        $this->em->flush();
    }
}
