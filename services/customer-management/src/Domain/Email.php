<?php

namespace App\Domain;

/** Value object: валідована email-адреса. */
final class Email
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Invalid email address: "%s"', $value));
        }
        $this->value = strtolower($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
