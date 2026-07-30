<?php

namespace App\Soap;

use App\Application\OrderService;
use App\Domain\Address;
use App\Domain\Money;
use App\Domain\OrderException;

/**
 * SOAP-обробник Order Management: методи відповідають операціям WSDL.
 * Доменні винятки мапляться на SOAP Fault.
 */
final class OrderSoapHandler
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    public function CreateCart(object $request): array
    {
        return $this->guard(fn (): array => [
            'cartId' => $this->orders->createCart((string) $request->customerId),
        ]);
    }

    public function AddCartItem(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $price = new Money((int) $request->unitPrice->amountMinor, (string) $request->unitPrice->currency);
            $cart = $this->orders->addCartItem(
                (string) $request->cartId,
                (string) $request->productId,
                (string) $request->sku,
                (int) $request->quantity,
                $price,
            );

            return ['cartId' => $cart->id(), 'lineCount' => $cart->lineCount()];
        });
    }

    public function Checkout(object $request): array
    {
        return $this->guard(fn (): array => [
            'order' => $this->orders->checkout(
                (string) $request->cartId,
                (string) $request->customerId,
                Address::fromCanonical($request->shippingAddress),
            )->toCanonical(),
        ]);
    }

    public function GetOrder(object $request): array
    {
        return $this->guard(fn (): array => [
            'order' => $this->orders->getOrder((string) $request->orderId)->toCanonical(),
        ]);
    }

    public function MarkPaid(object $request): array
    {
        return $this->guard(fn (): array => [
            'order' => $this->orders->markPaid((string) $request->orderId)->toCanonical(),
        ]);
    }

    public function CancelOrder(object $request): array
    {
        return $this->guard(fn (): array => [
            'order' => $this->orders->cancelOrder((string) $request->orderId)->toCanonical(),
        ]);
    }

    /**
     * @param callable():array $operation
     */
    private function guard(callable $operation): array
    {
        try {
            return $operation();
        } catch (OrderException | \InvalidArgumentException $e) {
            throw new \SoapFault('Client', $e->getMessage());
        }
    }
}
