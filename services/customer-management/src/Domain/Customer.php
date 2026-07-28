<?php

namespace App\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегат Customer. Ідентичність + профіль + адреси.
 * Email/пароль валідуються через VO (Email/Credential), у БД лежать примітиви;
 * адреси зберігаються як JSON усередині агрегату (немає окремих сутностей поза межею).
 */
#[ORM\Entity]
#[ORM\Table(name: 'customers', schema: 'customer')]
#[ORM\UniqueConstraint(name: 'uniq_customer_email', columns: ['email'])]
class Customer
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(name: 'full_name', length: 255, nullable: true)]
    private ?string $fullName;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    /** @var array<int, array<string, ?string>> */
    #[ORM\Column(type: 'json')]
    private array $addresses = [];

    private function __construct(string $id, string $email, ?string $fullName, string $passwordHash)
    {
        $this->id = $id;
        $this->email = $email;
        $this->fullName = $fullName;
        $this->passwordHash = $passwordHash;
    }

    public static function register(Email $email, string $plainPassword, ?string $fullName = null): self
    {
        return new self(
            Uuid::v4()->toRfc4122(),
            $email->value,
            $fullName,
            Credential::hash($plainPassword),
        );
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return Credential::verify($plainPassword, $this->passwordHash);
    }

    public function addAddress(Address $address): void
    {
        $this->addresses[] = $address->toArray();
    }

    /** @return Address[] */
    public function addresses(): array
    {
        return array_map(static fn (array $row): Address => Address::fromArray($row), $this->addresses);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function fullName(): ?string
    {
        return $this->fullName;
    }

    /** Канонічне представлення cdm:Customer. */
    public function toCanonical(): array
    {
        $out = ['id' => $this->id, 'email' => $this->email];
        if ($this->fullName !== null) {
            $out['fullName'] = $this->fullName;
        }
        $out['address'] = array_map(static fn (Address $a): array => $a->toCanonical(), $this->addresses());

        return $out;
    }
}
