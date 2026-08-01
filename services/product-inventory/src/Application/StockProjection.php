<?php

namespace App\Application;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-model залишків, побудований ЛИШЕ з подій шини (`stock.level.changed`).
 * Демонструє асинхронний стиль: писати в проекцію може будь-хто, хто слухає шину,
 * а читання не турбує ані БД каталогу, ані сам сервіс.
 */
final class StockProjection
{
    public const LOW_STOCK_THRESHOLD = 10;

    private const INDEX_KEY = 'stock_projection_index';
    private const ITEM_PREFIX = 'stock_projection_';

    public function __construct(
        #[Autowire(service: 'projection.cache')]
        private readonly CacheItemPoolInterface $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $envelope конверт події
     *
     * @return bool чи оновлено проекцію (false = подія не наша)
     */
    public function apply(array $envelope): bool
    {
        if (($envelope['eventType'] ?? null) !== 'stock.level.changed') {
            return false;
        }

        $payload = (array) ($envelope['payload'] ?? []);
        $productId = (string) ($payload['productId'] ?? '');

        if ($productId === '') {
            return false;
        }

        $stockAvailable = (int) ($payload['stockAvailable'] ?? 0);

        $item = $this->storage->getItem(self::ITEM_PREFIX.$this->key($productId));
        $this->storage->save($item->set([
            'productId' => $productId,
            'sku' => (string) ($payload['sku'] ?? ''),
            'stockAvailable' => $stockAvailable,
            'lowStock' => $stockAvailable < self::LOW_STOCK_THRESHOLD,
            'lastOperation' => (string) ($payload['operation'] ?? ''),
            'lastQuantity' => (int) ($payload['quantity'] ?? 0),
            'updatedAt' => (string) ($envelope['occurredAt'] ?? ''),
        ]));

        $this->remember($productId);

        return true;
    }

    /** @return array<int, array<string, mixed>> поточний стан проекції */
    public function snapshot(): array
    {
        $products = [];

        foreach ($this->index() as $productId) {
            $item = $this->storage->getItem(self::ITEM_PREFIX.$this->key($productId));

            if ($item->isHit()) {
                $products[] = $item->get();
            }
        }

        usort($products, static fn (array $a, array $b) => $a['sku'] <=> $b['sku']);

        return $products;
    }

    /** @return string[] */
    private function index(): array
    {
        $item = $this->storage->getItem(self::INDEX_KEY);

        return $item->isHit() ? (array) $item->get() : [];
    }

    private function remember(string $productId): void
    {
        $index = $this->index();

        if (!\in_array($productId, $index, true)) {
            $index[] = $productId;
            $this->storage->save($this->storage->getItem(self::INDEX_KEY)->set($index));
        }
    }

    private function key(string $productId): string
    {
        return preg_replace('/\W/', '_', $productId) ?? $productId;
    }
}
