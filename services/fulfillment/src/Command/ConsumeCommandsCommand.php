<?php

namespace App\Command;

use App\Application\FulfillmentService;
use App\Domain\Address;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Асинхронна гілка оркестрації: ESB публікує `shipment.requested`, а Fulfillment
 * створює відправлення поза синхронним відгуком клієнту (Task 27 §05.4).
 */
#[AsCommand(name: 'app:consume-commands', description: 'Consume fulfillment commands from RabbitMQ')]
final class ConsumeCommandsCommand extends Command
{
    public const QUEUE = 'fulfillment.commands';
    public const EXCHANGE = 'printzone.events';
    public const ROUTING_KEY = 'shipment.requested';

    public function __construct(
        private readonly FulfillmentService $fulfillment,
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
        $limit = (int) $input->getOption('limit');
        $handled = 0;

        $output->writeln(sprintf('<info>Consuming %s (key: %s)</info>', self::QUEUE, self::ROUTING_KEY));

        $this->declareQueue()->consume(function (\AMQPEnvelope $envelope, \AMQPQueue $queue) use ($output, $limit, &$handled): bool {
            $body = json_decode($envelope->getBody(), true) ?: [];
            $payload = (array) ($body['payload'] ?? []);

            try {
                $shipment = $this->fulfillment->createShipment(
                    (string) ($payload['orderId'] ?? ''),
                    Address::fromCanonical((array) ($payload['shippingAddress'] ?? [])),
                );
                $output->writeln(sprintf('<info>shipment</info> %s for order %s', $shipment->trackingNumber(), $shipment->orderId()));
            } catch (\Throwable $e) {
                // Команду не перевикладаємо: причина не зникне сама, а черга не має рости.
                $output->writeln(sprintf('<error>failed</error> %s', $e->getMessage()));
            }

            $queue->ack($envelope->getDeliveryTag());

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
        $queue->bind(self::EXCHANGE, self::ROUTING_KEY);

        return $queue;
    }
}
