<?php

namespace App\Infrastructure;

use App\Domain\MailGateway;

/** Вибір поштового шлюзу за env NOTIFICATION_GATEWAY; default — fake. */
final class MailGatewayFactory
{
    public static function create(FakeMailGateway $fake, SesMailGateway $ses, string $type): MailGateway
    {
        return match (strtolower($type)) {
            'ses' => $ses,
            default => $fake,
        };
    }
}
