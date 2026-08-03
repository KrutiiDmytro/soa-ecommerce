<?php

namespace App\Infrastructure;

use App\Domain\EventPublisher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Публікація подій каталогу в topic-exchange `printzone.events`
 * (ключ `stock.level.changed`). З'єднання лениве: читання каталогу
 * не має падати через недоступний брокер.
 */
final class AmqpEventPublisher implements EventPublisher
{
    public const EXCHANGE = 'printzone.events';

    private ?\AMQPExchange $exchange = null;
    private ?\AMQPConnection $connection = null;

    public function __construct(
        #[Autowire('%env(RABBITMQ_DSN)%')]
        private readonly string $dsn,
    ) {
    }

    public function publish(string $routingKey, array $payload): void
    {
        $envelope = [
            'eventId' => Uuid::v4()->toRfc4122(),
            'eventType' => $routingKey,
            'occurredAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'payload' => $payload,
        ];

        $this->exchange()->publish(
            json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            $routingKey,
            \AMQP_NOPARAM,
            ['content_type' => 'application/json', 'delivery_mode' => 2],
        );
    }

    private function exchange(): \AMQPExchange
    {
        if ($this->exchange !== null) {
            return $this->exchange;
        }

        $dsn = parse_url($this->dsn) ?: throw new \InvalidArgumentException(sprintf('Invalid RABBITMQ_DSN: "%s"', $this->dsn));

        $this->connection = new \AMQPConnection([
            'host' => $dsn['host'] ?? 'rabbitmq',
            'port' => $dsn['port'] ?? 5672,
            'login' => urldecode($dsn['user'] ?? 'guest'),
            'password' => urldecode($dsn['pass'] ?? 'guest'),
            'vhost' => urldecode(ltrim($dsn['path'] ?? '/', '/')) ?: '/',
        ]);
        $this->connection->connect();

        $exchange = new \AMQPExchange(new \AMQPChannel($this->connection));
        $exchange->setName(self::EXCHANGE);
        $exchange->setType(\AMQP_EX_TYPE_TOPIC);
        $exchange->setFlags(\AMQP_DURABLE);
        $exchange->declareExchange();

        return $this->exchange = $exchange;
    }
}
