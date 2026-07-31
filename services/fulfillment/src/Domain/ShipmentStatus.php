<?php

namespace App\Domain;

/** Життєвий цикл відправлення (tns:ShipmentStatus у WSDL Fulfillment). */
enum ShipmentStatus: string
{
    case CREATED = 'CREATED';
    case DISPATCHED = 'DISPATCHED';
    case IN_TRANSIT = 'IN_TRANSIT';
    case DELIVERED = 'DELIVERED';

    /** @return self[] */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CREATED => [self::DISPATCHED],
            self::DISPATCHED => [self::IN_TRANSIT, self::DELIVERED],
            self::IN_TRANSIT => [self::DELIVERED],
            self::DELIVERED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
