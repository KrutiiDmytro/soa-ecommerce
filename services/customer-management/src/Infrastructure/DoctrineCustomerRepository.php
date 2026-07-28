<?php

namespace App\Infrastructure;

use App\Domain\Customer;
use App\Domain\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineCustomerRepository implements CustomerRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(Customer $customer): void
    {
        $this->em->persist($customer);
        $this->em->flush();
    }

    public function byId(string $id): ?Customer
    {
        return $this->em->find(Customer::class, $id);
    }

    public function byEmail(string $email): ?Customer
    {
        return $this->em->getRepository(Customer::class)->findOneBy(['email' => strtolower(trim($email))]);
    }
}
