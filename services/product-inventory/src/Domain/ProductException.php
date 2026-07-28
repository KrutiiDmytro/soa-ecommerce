<?php

namespace App\Domain;

/** Доменний виняток (мапиться на SOAP Fault у шарі SOAP). */
class ProductException extends \DomainException
{
    public static function notFound(string $id): self
    {
        return new self(sprintf('Product "%s" not found', $id));
    }

    public static function insufficientStock(string $id, int $requested, int $available): self
    {
        return new self(sprintf('Insufficient stock for product "%s": requested %d, available %d', $id, $requested, $available));
    }
}
