<?php

namespace App\Domain;

/** Value object: гроші як ціле в мінорних одиницях + валюта (канонічний cdm:Money). */
final class Money
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Money amount cannot be negative');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(sprintf('Invalid ISO-4217 currency: "%s"', $currency));
        }
    }

    public function toCanonical(): array
    {
        return ['amountMinor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
