<?php

namespace App\Tests\Domain;

use App\Domain\Address;
use App\Domain\FulfillmentException;
use App\Domain\Shipment;
use App\Domain\ShipmentStatus;
use PHPUnit\Framework\TestCase;

final class ShipmentTest extends TestCase
{
    private function shipment(): Shipment
    {
        return new Shipment('order-1', new Address('вул. Хрещатик, 1', 'Київ', '01001', 'UA'), 'FAKEUA0001');
    }

    public function testCreationRecordsFirstTrackingEvent(): void
    {
        $shipment = $this->shipment();

        self::assertSame(ShipmentStatus::CREATED, $shipment->status());
        self::assertCount(1, $shipment->events());
        self::assertSame(ShipmentStatus::CREATED, $shipment->events()[0]->status());
        self::assertSame(1, $shipment->events()[0]->sequenceNo());
    }

    public function testTrackingIsAppendOnlyAndOrdered(): void
    {
        $shipment = $this->shipment();
        $shipment->dispatch();
        $shipment->recordTracking(ShipmentStatus::IN_TRANSIT, 'Left the sorting centre');
        $shipment->recordTracking(ShipmentStatus::DELIVERED, 'Handed to the recipient');

        $statuses = array_map(static fn ($e) => $e->status(), $shipment->events());
        self::assertSame(
            [ShipmentStatus::CREATED, ShipmentStatus::DISPATCHED, ShipmentStatus::IN_TRANSIT, ShipmentStatus::DELIVERED],
            $statuses,
        );
        self::assertSame([1, 2, 3, 4], array_map(static fn ($e) => $e->sequenceNo(), $shipment->events()));
        self::assertSame(ShipmentStatus::DELIVERED, $shipment->status());
    }

    public function testCannotSkipStatuses(): void
    {
        $this->expectException(FulfillmentException::class);
        $this->shipment()->recordTracking(ShipmentStatus::IN_TRANSIT, 'too early');
    }

    public function testDeliveredIsTerminal(): void
    {
        $shipment = $this->shipment();
        $shipment->dispatch();
        $shipment->recordTracking(ShipmentStatus::DELIVERED, 'Handed to the recipient');

        $this->expectException(FulfillmentException::class);
        $shipment->recordTracking(ShipmentStatus::IN_TRANSIT, 'back in transit?');
    }

    public function testTrackingEventCanonicalShape(): void
    {
        $canonical = $this->shipment()->events()[0]->toCanonical();

        self::assertSame(['status', 'description', 'occurredAt'], array_keys($canonical));
        self::assertSame('CREATED', $canonical['status']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $canonical['occurredAt']));
    }

    public function testAddressIsSnapshotted(): void
    {
        self::assertSame('Київ', $this->shipment()->shippingAddress()->city);
    }
}
