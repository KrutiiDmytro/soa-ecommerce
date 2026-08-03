<?php

namespace App\Command;

use App\Domain\Money;
use App\Domain\Product;
use App\Domain\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ідемпотентний сідер каталогу з ФІКСОВАНИМИ UUID — щоб Order Management (Фаза 3)
 * і e2e могли посилатися на відомі товари.
 */
#[AsCommand(name: 'app:seed-products', description: 'Seed the catalog with fixed demo products')]
final class SeedProductsCommand extends Command
{
    private const SEED = [
        ['11111111-1111-1111-1111-111111111111', 'PRINT-TSHIRT', 'Custom T-Shirt', 29900, 100],
        ['22222222-2222-2222-2222-222222222222', 'PRINT-MUG', 'Custom Mug', 19900, 50],
        ['33333333-3333-3333-3333-333333333333', 'PRINT-POSTER', 'Poster A2', 14900, 5],
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'reset-stock',
            null,
            InputOption::VALUE_NONE,
            'Повернути залишки демо-товарів до початкових (щоб e2e можна було ганяти повторно)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reset = (bool) $input->getOption('reset-stock');
        $created = 0;
        $restocked = 0;

        foreach (self::SEED as [$id, $sku, $name, $amountMinor, $stock]) {
            $product = $this->products->byId($id);

            if ($product === null) {
                $this->em->persist(new Product($id, $sku, $name, new Money($amountMinor, 'UAH'), $stock));
                ++$created;
                continue;
            }

            // Поповнення робимо доменною операцією release() — окремого сетера не заводимо.
            if ($reset && ($missing = $stock - $product->stockAvailable()) > 0) {
                $product->release($missing);
                ++$restocked;
            }
        }
        $this->em->flush();

        $io->success(sprintf(
            'Seed complete: %d created, %d already present%s.',
            $created,
            \count(self::SEED) - $created,
            $reset ? sprintf(', %d restocked', $restocked) : '',
        ));

        return Command::SUCCESS;
    }
}
