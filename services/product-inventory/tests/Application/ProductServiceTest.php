<?php

namespace App\Tests\Application;

use App\Application\ProductService;
use App\Domain\EventPublisher;
use App\Domain\Money;
use App\Domain\Product;
use App\Domain\ProductRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/** Зміна залишку публікує подію шини — на ній тримається проекція. */
final class ProductServiceTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $published = [];

    private Product $product;

    private function service(): ProductService
    {
        $this->product = new Product('p-1', 'PRINT-MUG', 'Custom Mug', new Money(19900, 'UAH'), 50);
        $product = $this->product;

        $products = new class($product) implements ProductRepository {
            public function __construct(private Product $product)
            {
            }

            public function save(Product $product): void
            {
            }

            public function byId(string $id): ?Product
            {
                return $id === $this->product->id() ? $this->product : null;
            }

            public function search(?string $query): array
            {
                return [$this->product];
            }
        };

        $published = &$this->published;
        $events = new class($published) implements EventPublisher {
            public function __construct(private array &$published)
            {
            }

            public function publish(string $routingKey, array $payload): void
            {
                $this->published[] = [$routingKey, $payload];
            }
        };

        return new ProductService($products, new TagAwareAdapter(new ArrayAdapter()), $events);
    }

    public function testReserveStockPublishesStockLevelChanged(): void
    {
        $this->service()->reserveStock('p-1', 2);

        self::assertCount(1, $this->published);
        [$routingKey, $payload] = $this->published[0];
        self::assertSame('stock.level.changed', $routingKey);
        self::assertSame('p-1', $payload['productId']);
        self::assertSame(48, $payload['stockAvailable']);
        self::assertSame('reserved', $payload['operation']);
        self::assertSame(2, $payload['quantity']);
    }

    public function testReleaseStockPublishesStockLevelChanged(): void
    {
        $service = $this->service();
        $service->reserveStock('p-1', 2);
        $service->releaseStock('p-1', 2);

        [$routingKey, $payload] = $this->published[1];
        self::assertSame('stock.level.changed', $routingKey);
        self::assertSame(50, $payload['stockAvailable']);
        self::assertSame('released', $payload['operation']);
    }

    public function testFailedReservationPublishesNothing(): void
    {
        try {
            $this->service()->reserveStock('p-1', 999);
        } catch (\DomainException) {
        }

        self::assertSame([], $this->published);
    }
}
