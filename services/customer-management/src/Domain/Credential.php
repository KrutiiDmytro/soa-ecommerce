<?php

namespace App\Domain;

/** Value object: облікові дані. Пароль зберігається лише як bcrypt-хеш. */
final class Credential
{
    private const MIN_LENGTH = 6;

    public static function hash(string $plainPassword): string
    {
        if (strlen($plainPassword) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException('Password must be at least '.self::MIN_LENGTH.' characters');
        }

        return password_hash($plainPassword, PASSWORD_BCRYPT);
    }

    public static function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }
}
