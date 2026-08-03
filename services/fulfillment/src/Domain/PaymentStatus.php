<?php

namespace App\Domain;

/** Життєвий цикл платежу (tns:PaymentStatus у WSDL Fulfillment). */
enum PaymentStatus: string
{
    case AUTHORIZED = 'AUTHORIZED';
    case CAPTURED = 'CAPTURED';
    case FAILED = 'FAILED';

    /** @return self[] */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AUTHORIZED => [self::CAPTURED, self::FAILED],
            self::FAILED => [self::AUTHORIZED],
            self::CAPTURED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
