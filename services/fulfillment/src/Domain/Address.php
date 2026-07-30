<?php

namespace App\Domain;

/** Value object адреси доставки (канонічний cdm:Address). */
final class Address
{
    public function __construct(
        public readonly string $line1,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $country,
        public readonly ?string $line2 = null,
    ) {
        if (trim($line1) === '' || trim($city) === '' || trim($postalCode) === '') {
            throw new \InvalidArgumentException('Address line1, city and postalCode are required');
        }
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException(sprintf('Invalid ISO 3166-1 alpha-2 country: "%s"', $country));
        }
    }

    /** Із канонічного cdm:Address (об'єкт від SoapServer або масив). */
    public static function fromCanonical(object|array $data): self
    {
        $get = static fn (string $key): ?string => ((array) $data)[$key] ?? null;

        return new self(
            (string) $get('line1'),
            (string) $get('city'),
            (string) $get('postalCode'),
            (string) $get('country'),
            $get('line2'),
        );
    }

    public function toCanonical(): array
    {
        $canonical = ['line1' => $this->line1];

        if ($this->line2 !== null) {
            $canonical['line2'] = $this->line2;
        }

        return $canonical + [
            'city' => $this->city,
            'postalCode' => $this->postalCode,
            'country' => $this->country,
        ];
    }
}
