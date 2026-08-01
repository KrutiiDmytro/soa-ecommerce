<?php

namespace App\Tests\Application;

use App\Application\StockProjection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Read-model залишків будується виключно з подій шини. */
final class StockProjectionTest extends TestCase
{
    private StockProjection $projection;

    protected function setUp(): void
    {
        $this->projection = new StockProjection(new ArrayAdapter());
    }

    private function event(string $productId, string $sku, int $stock, string $operation = 'reserved', int $quantity = 2): array
    {
        return [
            'eventId' => 'event-'.$productId,
            'eventType' => 'stock.level.changed',
            'occurredAt' => '2026-08-01T10:00:00+00:00',
            'payload' => [
                'productId' => $productId,
                'sku' => $sku,
                'stockAvailable' => $stock,
                'operation' => $operation,
                'quantity' => $quantity,
            ],
        ];
    }

    public function testEventBuildsProjectionEntry(): void
    {
        self::assertTrue($this->projection->apply($this->event('p-1', 'PRINT-MUG', 48)));

        $snapshot = $this->projection->snapshot();
        self::assertCount(1, $snapshot);
        self::assertSame('PRINT-MUG', $snapshot[0]['sku']);
        self::assertSame(48, $snapshot[0]['stockAvailable']);
        self::assertFalse($snapshot[0]['lowStock']);
        self::assertSame('reserved', $snapshot[0]['lastOperation']);
    }

    public function testLatestEventWinsForTheSameProduct(): void
    {
        $this->projection->apply($this->event('p-1', 'PRINT-MUG', 48));
        $this->projection->apply($this->event('p-1', 'PRINT-MUG', 50, 'released'));

        $snapshot = $this->projection->snapshot();
        self::assertCount(1, $snapshot);
        self::assertSame(50, $snapshot[0]['stockAvailable']);
        self::assertSame('released', $snapshot[0]['lastOperation']);
    }

    public function testLowStockIsFlagged(): void
    {
        $this->projection->apply($this->event('p-1', 'PRINT-POSTER', StockProjection::LOW_STOCK_THRESHOLD - 1));

        self::assertTrue($this->projection->snapshot()[0]['lowStock']);
    }

    public function testSnapshotIsSortedBySku(): void
    {
        $this->projection->apply($this->event('p-2', 'PRINT-TSHIRT', 90));
        $this->projection->apply($this->event('p-1', 'PRINT-MUG', 48));

        self::assertSame(['PRINT-MUG', 'PRINT-TSHIRT'], array_column($this->projection->snapshot(), 'sku'));
    }

    public function testUnrelatedEventIsIgnored(): void
    {
        self::assertFalse($this->projection->apply(['eventType' => 'payment.captured', 'payload' => ['orderId' => 'o-1']]));
        self::assertSame([], $this->projection->snapshot());
    }

    public function testEventWithoutProductIsIgnored(): void
    {
        self::assertFalse($this->projection->apply(['eventType' => 'stock.level.changed', 'payload' => []]));
    }
}
