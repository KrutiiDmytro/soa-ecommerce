<?php

namespace App\Command;

use App\Application\StockProjection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Споживач подій каталогу: тримає проекцію залишків актуальною.
 * Черга `catalog.projection` прив'язана до `stock.level.changed`.
 */
#[AsCommand(name: 'app:project-stock', description: 'Project stock level events into the read model')]
final class ProjectStockCommand extends Command
{
    public const QUEUE = 'catalog.projection';
    public const EXCHANGE = 'printzone.events';
    public const ROUTING_KEY = 'stock.level.changed';

    public function __construct(
        private readonly StockProjection $projection,
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
            $applied = $this->projection->apply($body);

            $output->writeln(sprintf(
                '%s %s %s',
                $applied ? '<info>projected</info>' : '<comment>skipped</comment>',
                $envelope->getRoutingKey(),
                json_encode($body['payload'] ?? [], \JSON_UNESCAPED_UNICODE),
            ));
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
