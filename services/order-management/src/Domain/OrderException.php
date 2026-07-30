<?php

namespace App\Domain;

/** Доменний виняток (мапиться на SOAP Fault у шарі SOAP). */
class OrderException extends \DomainException
{
    public static function cartNotFound(string $id): self
    {
        return new self(sprintf('Cart "%s" not found', $id));
    }

    public static function orderNotFound(string $id): self
    {
        return new self(sprintf('Order "%s" not found', $id));
    }

    public static function emptyCart(string $id): self
    {
        return new self(sprintf('Cart "%s" is empty — nothing to check out', $id));
    }

    public static function cartOwnerMismatch(string $cartId, string $customerId): self
    {
        return new self(sprintf('Cart "%s" does not belong to customer "%s"', $cartId, $customerId));
    }

    public static function invalidTransition(string $orderId, OrderStatus $from, OrderStatus $to): self
    {
        return new self(sprintf('Order "%s" cannot go from %s to %s', $orderId, $from->value, $to->value));
    }
}
