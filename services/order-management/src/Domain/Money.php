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

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException('Money factor cannot be negative');
        }

        return new self($this->amountMinor * $factor, $this->currency);
    }

    public function add(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException(sprintf('Currency mismatch: %s vs %s', $this->currency, $other->currency));
        }

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    public function toCanonical(): array
    {
        return ['amountMinor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
