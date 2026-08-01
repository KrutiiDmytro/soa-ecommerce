<?php

namespace App\Soap;

use App\Application\ProductService;
use App\Domain\ProductException;

/**
 * SOAP-обробник Product & Inventory: методи відповідають операціям WSDL.
 * Доменні винятки мапляться на SOAP Fault.
 */
final class ProductSoapHandler
{
    public function __construct(private readonly ProductService $products)
    {
    }

    public function SearchProducts(object $request): array
    {
        return $this->guard(fn (): array => [
            'product' => $this->products->search($request->query ?? null),
        ]);
    }

    public function GetProduct(object $request): array
    {
        return $this->guard(fn (): array => [
            'product' => $this->products->getCanonical($request->productId),
        ]);
    }

    public function CheckStock(object $request): array
    {
        return $this->guard(fn (): array => $this->products->checkStock($request->productId, (int) $request->quantity));
    }

    public function ReserveStock(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $product = $this->products->reserveStock($request->productId, (int) $request->quantity);

            return [
                'productId' => $product->id(),
                'reserved' => true,
                'stockAvailable' => $product->stockAvailable(),
            ];
        });
    }

    public function ReleaseStock(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $product = $this->products->releaseStock($request->productId, (int) $request->quantity);

            return [
                'productId' => $product->id(),
                'released' => true,
                'stockAvailable' => $product->stockAvailable(),
            ];
        });
    }

    /**
     * @param callable():array $operation
     */
    private function guard(callable $operation): array
    {
        try {
            return $operation();
        } catch (ProductException | \InvalidArgumentException $e) {
            throw new \SoapFault('Client', $e->getMessage());
        }
    }
}
