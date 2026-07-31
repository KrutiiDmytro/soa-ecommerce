<?php

namespace App\Infrastructure;

use App\Domain\Address;
use App\Domain\FulfillmentException;
use App\Domain\ShippingProvider;

/**
 * Реальний перевізник за тим самим seam. Вмикається через SHIPPING_PROVIDER=novaposhta.
 * API-ключ у стенді не налаштований — див. StripePaymentProvider щодо мотивації.
 */
final class NovaPoshtaShippingProvider implements ShippingProvider
{
    public function __construct(private readonly string $apiKey)
    {
    }

    public function createShipment(string $orderId, Address $shippingAddress): string
    {
        if ($this->apiKey === '') {
            throw FulfillmentException::providerNotConfigured('novaposhta (NOVAPOSHTA_API_KEY is empty)');
        }

        throw FulfillmentException::providerNotConfigured('novaposhta');
    }
}
