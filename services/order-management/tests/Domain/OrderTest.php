<?php

namespace App\Tests\Domain;

use App\Domain\Address;
use App\Domain\Cart;
use App\Domain\Money;
use App\Domain\Order;
use App\Domain\OrderException;
use App\Domain\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private function address(): Address
    {
        return new Address('вул. Хрещатик, 1', 'Київ', '01001', 'UA');
    }

    private function cart(): Cart
    {
        $cart = new Cart('customer-1');
        $cart->addItem('product-1', 'PRINT-TSHIRT', 2, new Money(29900, 'UAH'));
        $cart->addItem('product-2', 'PRINT-MUG', 1, new Money(19900, 'UAH'));

        return $cart;
    }

    public function testCheckoutSnapshotsCartLinesAndTotal(): void
    {
        $order = Order::fromCart($this->cart(), $this->address());

        self::assertSame(OrderStatus::PENDING, $order->status());
        self::assertCount(2, $order->lines());
        self::assertSame(2 * 29900 + 19900, $order->total()->amountMinor);
        self::assertSame(2, $order->lines()[0]->quantity());
    }

    public function testCheckoutOfEmptyCartThrows(): void
    {
        $this->expectException(OrderException::class);
        Order::fromCart(new Cart('customer-1'), $this->address());
    }

    public function testFullLifecycle(): void
    {
        $order = Order::fromCart($this->cart(), $this->address());

        $order->markPaid();
        self::assertSame(OrderStatus::PAID, $order->status());

        $order->transitionTo(OrderStatus::PROCESSING);
        $order->transitionTo(OrderStatus::SHIPPED);
        $order->transitionTo(OrderStatus::DELIVERED);

        self::assertSame(OrderStatus::DELIVERED, $order->status());
    }

    public function testDeliveredIsTerminal(): void
    {
        $order = Order::fromCart($this->cart(), $this->address());
        $order->markPaid();
        $order->transitionTo(OrderStatus::PROCESSING);
        $order->transitionTo(OrderStatus::SHIPPED);
        $order->transitionTo(OrderStatus::DELIVERED);

        $this->expectException(OrderException::class);
        $order->cancel();
    }

    public function testCannotPayTwice(): void
    {
        $order = Order::fromCart($this->cart(), $this->address());
        $order->markPaid();

        $this->expectException(OrderException::class);
        $order->markPaid();
    }

    public function testCannotSkipStatuses(): void
    {
        $order = Order::fromCart($this->cart(), $this->address());

        $this->expectException(OrderException::class);
        $order->transitionTo(OrderStatus::SHIPPED);
    }

    public function testPendingOrderCanBeCancelled(): void
    {
        $order = Order::fromCart($this->cart(), $this->address());
        $order->cancel();

        self::assertSame(OrderStatus::CANCELLED, $order->status());
    }

    public function testCancelledIsTerminal(): void
    {
        $order = Order::fromCart($this->cart(), $this->address());
        $order->cancel();

        $this->expectException(OrderException::class);
        $order->cancel();
    }

    public function testCanonicalShapeMatchesCanonicalModel(): void
    {
        $canonical = Order::fromCart($this->cart(), $this->address())->toCanonical();

        self::assertSame(['id', 'customerId', 'status', 'line', 'total', 'shippingAddress', 'createdAt'], array_keys($canonical));
        self::assertSame('PENDING', $canonical['status']);
        self::assertSame('customer-1', $canonical['customerId']);
        self::assertSame(['productId', 'sku', 'quantity', 'unitPrice', 'lineTotal'], array_keys($canonical['line'][0]));
        self::assertSame(2 * 29900, $canonical['line'][0]['lineTotal']['amountMinor']);
        self::assertSame(['line1', 'city', 'postalCode', 'country'], array_keys($canonical['shippingAddress']));
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $canonical['createdAt']));
    }
}
