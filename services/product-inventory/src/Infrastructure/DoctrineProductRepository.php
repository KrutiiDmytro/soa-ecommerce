<?php

namespace App\Infrastructure;

use App\Domain\Product;
use App\Domain\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(Product $product): void
    {
        $this->em->persist($product);
        $this->em->flush();
    }

    public function byId(string $id): ?Product
    {
        return $this->em->find(Product::class, $id);
    }

    public function search(?string $query): array
    {
        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p');

        if ($query !== null && trim($query) !== '') {
            $qb->where('LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q')
                ->setParameter('q', '%'.strtolower(trim($query)).'%');
        }

        return $qb->orderBy('p.name', 'ASC')->getQuery()->getResult();
    }
}
