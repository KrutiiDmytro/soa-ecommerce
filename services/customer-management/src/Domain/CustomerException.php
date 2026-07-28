<?php

namespace App\Domain;

/** Базовий доменний виняток (мапиться на SOAP Fault у шарі SOAP). */
class CustomerException extends \DomainException
{
    public static function emailAlreadyUsed(string $email): self
    {
        return new self(sprintf('Email "%s" is already registered', $email));
    }

    public static function invalidCredentials(): self
    {
        return new self('Invalid email or password');
    }

    public static function notFound(string $id): self
    {
        return new self(sprintf('Customer "%s" not found', $id));
    }
}
