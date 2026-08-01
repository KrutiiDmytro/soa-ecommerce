<?php

namespace App\Domain;

/** Порт публікації доменних подій у шину (AMQP). */
interface EventPublisher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function publish(string $routingKey, array $payload): void;
}
