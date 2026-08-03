<?php

namespace App\Tests\Orchestration;

use App\Orchestration\CanonicalTransformer;
use PHPUnit\Framework\TestCase;

/** Канонічна модель → внутрішні виклики сервісів (медіація на шині). */
final class CanonicalTransformerTest extends TestCase
{
    private function cart(): object
    {
        return (object) [
            'cartId' => 'cart-1',
            'line' => [
                (object) ['productId' => 'p-1', 'quantity' => 2],
                (object) ['productId' => 'p-2', 'quantity' => 1],
            ],
        ];
    }

    public function testCartLinesBecomeReservations(): void
    {
        $reservations = (new CanonicalTransformer())->reservations($this->cart());

        self::assertSame([
            ['productId' => 'p-1', 'quantity' => 2],
            ['productId' => 'p-2', 'quantity' => 1],
        ], $reservations);
    }

    public function testSingleLineIsNormalisedToList(): void
    {
        $cart = (object) ['line' => (object) ['productId' => 'p-1', 'quantity' => 5]];

        self::assertSame([['productId' => 'p-1', 'quantity' => 5]], (new CanonicalTransformer())->reservations($cart));
    }

    public function testEmptyCartYieldsNoReservations(): void
    {
        self::assertSame([], (new CanonicalTransformer())->reservations((object) []));
    }

    public function testOrderPlacedEventIsEnrichedWithCustomerEmail(): void
    {
        $order = (object) [
            'id' => 'order-1',
            'customerId' => 'customer-1',
            'total' => (object) ['amountMinor' => 99600, 'currency' => 'UAH'],
        ];

        $event = (new CanonicalTransformer())->orderPlacedEvent($order, 'buyer@example.com');

        self::assertSame('order-1', $event['orderId']);
        self::assertSame(['amountMinor' => 99600, 'currency' => 'UAH'], $event['total']);
        self::assertSame('buyer@example.com', $event['recipient']);
    }

    public function testAddressKeepsCanonicalOrderAndDropsEmptyLine2(): void
    {
        $address = (object) ['line1' => 'вул. Хрещатик, 1', 'line2' => '', 'city' => 'Київ', 'postalCode' => '01001', 'country' => 'UA'];

        self::assertSame(['line1', 'city', 'postalCode', 'country'], array_keys((new CanonicalTransformer())->address($address)));
    }
}
