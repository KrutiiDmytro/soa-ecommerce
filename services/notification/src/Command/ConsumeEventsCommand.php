<?php

namespace App\Command;

use App\Application\EventNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Асинхронний споживач подій шини: черга `notification.events` прив'язана до
 * topic-exchange `printzone.events` за ключами замовлень/оплат/відправлень.
 */
#[AsCommand(name: 'app:consume-events', description: 'Consume domain events from RabbitMQ and send notifications')]
final class ConsumeEventsCommand extends Command
{
    public const QUEUE = 'notification.events';
    public const EXCHANGE = 'printzone.events';
    public const ROUTING_KEYS = ['order.placed', 'payment.captured', 'shipment.dispatched'];

    public function __construct(
        private readonly EventNotifier $notifier,
        #[Autowire('%env(RABBITMQ_DSN)%')]
        private readonly string $dsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N messages (0 = run forever)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $queue = $this->declareQueue();
        $handled = 0;

        $io->writeln(sprintf('<info>Consuming %s (keys: %s)</info>', self::QUEUE, implode(', ', self::ROUTING_KEYS)));

        $queue->consume(function (\AMQPEnvelope $envelope, \AMQPQueue $q) use ($io, $limit, &$handled): bool {
            $body = json_decode($envelope->getBody(), true) ?: [];
            $sent = $this->notifier->handle($body);

            $io->writeln(sprintf(
                '%s %s (eventId %s)',
                $sent ? '<info>sent</info>' : '<comment>skipped</comment>',
                $envelope->getRoutingKey(),
                $body['eventId'] ?? '—',
            ));
            $q->ack($envelope->getDeliveryTag());

            return !($limit > 0 && ++$handled >= $limit);
        });

        return Command::SUCCESS;
    }

    private function declareQueue(): \AMQPQueue
    {
        $dsn = parse_url($this->dsn) ?: throw new \InvalidArgumentException(sprintf('Invalid RABBITMQ_DSN: "%s"', $this->dsn));

        $connection = new \AMQPConnection([
            'host' => $dsn['host'] ?? 'rabbitmq',
            'port' => $dsn['port'] ?? 5672,
            'login' => urldecode($dsn['user'] ?? 'guest'),
            'password' => urldecode($dsn['pass'] ?? 'guest'),
            'vhost' => urldecode(ltrim($dsn['path'] ?? '/', '/')) ?: '/',
            'read_timeout' => 0,
        ]);
        $connection->connect();
        $channel = new \AMQPChannel($connection);

        $exchange = new \AMQPExchange($channel);
        $exchange->setName(self::EXCHANGE);
        $exchange->setType(\AMQP_EX_TYPE_TOPIC);
        $exchange->setFlags(\AMQP_DURABLE);
        $exchange->declareExchange();

        $queue = new \AMQPQueue($channel);
        $queue->setName(self::QUEUE);
        $queue->setFlags(\AMQP_DURABLE);
        $queue->declareQueue();

        foreach (self::ROUTING_KEYS as $key) {
            $queue->bind(self::EXCHANGE, $key);
        }

        return $queue;
    }
}
