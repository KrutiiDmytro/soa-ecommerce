<?php

namespace App;

use App\Application\StockProjection;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Діагностичний перегляд read-моделі залишків (як /health — лише всередині мережі,
 * назовні відкрито тільки ESB). Бізнес-доступ до каталогу — через SOAP.
 */
final class StockProjectionController
{
    public function __construct(private readonly StockProjection $projection)
    {
    }

    public function __invoke(): JsonResponse
    {
        $products = $this->projection->snapshot();

        return new JsonResponse([
            'projection' => 'stock levels built from stock.level.changed events',
            'lowStockThreshold' => StockProjection::LOW_STOCK_THRESHOLD,
            'products' => $products,
        ]);
    }
}
