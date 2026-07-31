<?php

namespace App\Infrastructure;

use App\Domain\MailGateway;
use App\Domain\Message;
use App\Domain\NotificationException;

/**
 * Реальний шлюз за тим самим seam (NOTIFICATION_GATEWAY=ses).
 * Креди в цьому стенді не налаштовані — шлюз чесно про це каже, а не вдає відправку.
 */
final class SesMailGateway implements MailGateway
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $region,
    ) {
    }

    public function send(Message $message): void
    {
        if ($this->accessKey === '' || $this->region === '') {
            throw NotificationException::gatewayNotConfigured('ses (AWS_ACCESS_KEY_ID / AWS_DEFAULT_REGION are empty)');
        }

        throw NotificationException::gatewayNotConfigured('ses');
    }
}
