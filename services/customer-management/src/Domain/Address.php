<?php

namespace App\Domain;

/** Value object: поштова адреса (канонічна структура cdm:Address). */
final class Address
{
    public function __construct(
        public readonly string $line1,
        public readonly ?string $line2,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $country,
    ) {
        foreach (['line1' => $line1, 'city' => $city, 'postalCode' => $postalCode, 'country' => $country] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException(sprintf('Address field "%s" is required', $field));
            }
        }
    }

    public function toArray(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'postalCode' => $this->postalCode,
            'country' => $this->country,
        ];
    }

    public static function fromArray(array $row): self
    {
        return new self(
            $row['line1'],
            $row['line2'] ?? null,
            $row['city'],
            $row['postalCode'],
            $row['country'],
        );
    }

    /** Канонічне представлення (line2 опускається, якщо порожнє). */
    public function toCanonical(): array
    {
        $out = ['line1' => $this->line1];
        if ($this->line2 !== null && $this->line2 !== '') {
            $out['line2'] = $this->line2;
        }
        $out['city'] = $this->city;
        $out['postalCode'] = $this->postalCode;
        $out['country'] = $this->country;

        return $out;
    }
}
