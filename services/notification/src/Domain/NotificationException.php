<?php

namespace App\Domain;

/** Доменний виняток (мапиться на SOAP Fault у шарі SOAP). */
class NotificationException extends \DomainException
{
    public static function unknownTemplate(string $template): self
    {
        return new self(sprintf('Unknown notification template "%s"', $template));
    }

    public static function unsupportedChannel(string $channel): self
    {
        return new self(sprintf('Channel "%s" is not supported by this service', $channel));
    }

    public static function gatewayNotConfigured(string $gateway): self
    {
        return new self(sprintf('Mail gateway "%s" is not configured in this environment', $gateway));
    }
}
