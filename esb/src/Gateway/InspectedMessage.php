<?php

namespace App\Gateway;

/** Те, що ESB витягнув із конверта: адресація + безпекові дані. */
final class InspectedMessage
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $operation,
        public readonly ?string $token,
        public readonly ?string $username,
        public readonly ?string $createdAt,
        public readonly ?string $expiresAt,
    ) {
    }
}
