<?php

namespace App\Application;

use App\Domain\EventPublisher;
use App\Domain\Product;
use App\Domain\ProductException;
use App\Domain\ProductRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Application-сервіс каталогу. Читання (search/get) кешуються в Redis із тегом 'products';
 * резервування залишку інвалідує тег (кеш більше не відповідає наявності).
 */
final class ProductService
{
    private const TTL = 300;

    public function __construct(
        private readonly ProductRepository $products,
        #[Autowire(service: 'catalog.cache')]
        private readonly TagAwareCacheInterface $cache,
        private readonly EventPublisher $events,
    ) {
    }

    /** @return array[] канонічні представлення продуктів */
    public function search(?string $query): array
    {
        $key = 'search_'.md5($query ?? '');

        return $this->cache->get($key, function (ItemInterface $item) use ($query): array {
            $item->expiresAfter(self::TTL)->tag('products');

            return array_map(static fn (Product $p): array => $p->toCanonical(), $this->products->search($query));
        });
    }

    public function getCanonical(string $id): array
    {
        return $this->cache->get('product_'.$id, function (ItemInterface $item) use ($id): array {
            $item->expiresAfter(self::TTL)->tag('products');

            return $this->get($id)->toCanonical();
        });
    }

    public function checkStock(string $id, int $quantity): array
    {
        $product = $this->get($id);

        return [
            'available' => $product->hasStock($quantity),
            'stockAvailable' => $product->stockAvailable(),
        ];
    }

    public function reserveStock(string $id, int $quantity): Product
    {
        $product = $this->get($id);
        $product->reserve($quantity);
        $this->products->save($product);

        // Наявність змінилась → скидаємо кешовані читання каталогу.
        $this->cache->invalidateTags(['products']);
        $this->publishStockLevel($product, 'reserved', $quantity);

        return $product;
    }

    /** Компенсаційна операція: повертає резерв (використовує оркестрація ESB при відкаті). */
    public function releaseStock(string $id, int $quantity): Product
    {
        $product = $this->get($id);
        $product->release($quantity);
        $this->products->save($product);

        $this->cache->invalidateTags(['products']);
        $this->publishStockLevel($product, 'released', $quantity);

        return $product;
    }

    private function get(string $id): Product
    {
        return $this->products->byId($id) ?? throw ProductException::notFound($id);
    }

    /** Подія шини: на неї підписана проекція залишків (див. app:project-stock). */
    private function publishStockLevel(Product $product, string $operation, int $quantity): void
    {
        $this->events->publish('stock.level.changed', [
            'productId' => $product->id(),
            'sku' => $product->sku(),
            'stockAvailable' => $product->stockAvailable(),
            'operation' => $operation,
            'quantity' => $quantity,
        ]);
    }
}
