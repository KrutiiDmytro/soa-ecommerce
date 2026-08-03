<?php

namespace App\Infrastructure;

use App\Domain\Payment;
use App\Domain\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrinePaymentRepository implements PaymentRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(Payment $payment): void
    {
        $this->em->persist($payment);
        $this->em->flush();
    }

    public function byId(string $id): ?Payment
    {
        return $this->em->find(Payment::class, $id);
    }

    public function byOrderId(string $orderId): ?Payment
    {
        return $this->em->getRepository(Payment::class)->findOneBy(['orderId' => $orderId]);
    }
}
