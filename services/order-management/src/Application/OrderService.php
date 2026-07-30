<?php

namespace App\Application;

use App\Domain\Address;
use App\Domain\Cart;
use App\Domain\CartRepository;
use App\Domain\Money;
use App\Domain\Order;
use App\Domain\OrderException;
use App\Domain\OrderRepository;

/**
 * Application-сервіс Order Management: кошик → checkout → життєвий цикл замовлення.
 * Сервіс автономний — ціни приходять знімком у AddCartItem, звернень до інших
 * сервісів немає (зведення даних робить оркестрація ESB).
 */
final class OrderService
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly OrderRepository $orders,
    ) {
    }

    public function createCart(string $customerId): string
    {
        $cart = new Cart($customerId);
        $this->carts->save($cart);

        return $cart->id();
    }

    public function addCartItem(string $cartId, string $productId, string $sku, int $quantity, Money $unitPrice): Cart
    {
        $cart = $this->cart($cartId);
        $cart->addItem($productId, $sku, $quantity, $unitPrice);
        $this->carts->save($cart);

        return $cart;
    }

    /** Кошик стає замовленням у стані PENDING; кошик після цього не потрібен. */
    public function checkout(string $cartId, string $customerId, Address $shippingAddress): Order
    {
        $cart = $this->cart($cartId);

        if ($cart->customerId() !== $customerId) {
            throw OrderException::cartOwnerMismatch($cartId, $customerId);
        }

        $order = Order::fromCart($cart, $shippingAddress);
        $this->orders->save($order);
        $this->carts->remove($cart);

        return $order;
    }

    public function getOrder(string $orderId): Order
    {
        return $this->order($orderId);
    }

    public function markPaid(string $orderId): Order
    {
        $order = $this->order($orderId);
        $order->markPaid();
        $this->orders->save($order);

        return $order;
    }

    public function cancelOrder(string $orderId): Order
    {
        $order = $this->order($orderId);
        $order->cancel();
        $this->orders->save($order);

        return $order;
    }

    private function cart(string $id): Cart
    {
        return $this->carts->byId($id) ?? throw OrderException::cartNotFound($id);
    }

    private function order(string $id): Order
    {
        return $this->orders->byId($id) ?? throw OrderException::orderNotFound($id);
    }
}
