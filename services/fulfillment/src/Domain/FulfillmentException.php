<?php

namespace App\Domain;

/** Доменний виняток (мапиться на SOAP Fault у шарі SOAP). */
class FulfillmentException extends \DomainException
{
    public static function paymentNotFound(string $id): self
    {
        return new self(sprintf('Payment "%s" not found', $id));
    }

    public static function shipmentNotFound(string $id): self
    {
        return new self(sprintf('Shipment "%s" not found', $id));
    }

    public static function paymentDeclined(string $orderId, string $reason): self
    {
        return new self(sprintf('Payment for order "%s" declined: %s', $orderId, $reason));
    }

    public static function invalidPaymentTransition(string $paymentId, PaymentStatus $from, PaymentStatus $to): self
    {
        return new self(sprintf('Payment "%s" cannot go from %s to %s', $paymentId, $from->value, $to->value));
    }

    public static function invalidShipmentTransition(string $shipmentId, ShipmentStatus $from, ShipmentStatus $to): self
    {
        return new self(sprintf('Shipment "%s" cannot go from %s to %s', $shipmentId, $from->value, $to->value));
    }

    public static function providerNotConfigured(string $provider): self
    {
        return new self(sprintf('Provider "%s" is not configured in this environment', $provider));
    }
}
