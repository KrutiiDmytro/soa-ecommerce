<?php

namespace App\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Агрегат Shipment. Одне відправлення на замовлення (унікальний order_id) —
 * повторний CreateShipment ідемпотентний. Кожна зміна статусу дописує TrackingEvent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shipments', schema: 'fulfillment')]
#[ORM\UniqueConstraint(name: 'uniq_shipment_order', columns: ['order_id'])]
class Shipment
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(name: 'tracking_number', length: 64)]
    private string $trackingNumber;

    #[ORM\Column(length: 16, enumType: ShipmentStatus::class)]
    private ShipmentStatus $status;

    /** @var Collection<int, TrackingEvent> */
    #[ORM\OneToMany(targetEntity: TrackingEvent::class, mappedBy: 'shipment', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequenceNo' => 'ASC'])]
    private Collection $events;

    #[ORM\Column(name: 'shipping_line1', length: 255)]
    private string $shippingLine1;

    #[ORM\Column(name: 'shipping_line2', length: 255, nullable: true)]
    private ?string $shippingLine2;

    #[ORM\Column(name: 'shipping_city', length: 128)]
    private string $shippingCity;

    #[ORM\Column(name: 'shipping_postal_code', length: 32)]
    private string $shippingPostalCode;

    #[ORM\Column(name: 'shipping_country', length: 2)]
    private string $shippingCountry;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $orderId, Address $shippingAddress, string $trackingNumber)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->orderId = $orderId;
        $this->trackingNumber = $trackingNumber;
        $this->status = ShipmentStatus::CREATED;
        $this->events = new ArrayCollection();
        $this->shippingLine1 = $shippingAddress->line1;
        $this->shippingLine2 = $shippingAddress->line2;
        $this->shippingCity = $shippingAddress->city;
        $this->shippingPostalCode = $shippingAddress->postalCode;
        $this->shippingCountry = $shippingAddress->country;
        $this->createdAt = new \DateTimeImmutable();

        $this->appendEvent(ShipmentStatus::CREATED, sprintf('Shipment created, tracking number %s', $trackingNumber));
    }

    /** Передано перевізнику. */
    public function dispatch(): void
    {
        $this->recordTracking(ShipmentStatus::DISPATCHED, 'Handed over to the carrier');
    }

    /** Зміна статусу від перевізника: валідний перехід + дописана подія. */
    public function recordTracking(ShipmentStatus $status, string $description): void
    {
        if (!$this->status->canTransitionTo($status)) {
            throw FulfillmentException::invalidShipmentTransition($this->id, $this->status, $status);
        }
        $this->status = $status;
        $this->appendEvent($status, $description);
    }

    private function appendEvent(ShipmentStatus $status, string $description): void
    {
        $this->events->add(new TrackingEvent($this, $status, $description, $this->events->count() + 1));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function trackingNumber(): string
    {
        return $this->trackingNumber;
    }

    public function status(): ShipmentStatus
    {
        return $this->status;
    }

    /** @return TrackingEvent[] історія в порядку настання */
    public function events(): array
    {
        return $this->events->toArray();
    }

    public function shippingAddress(): Address
    {
        return new Address($this->shippingLine1, $this->shippingCity, $this->shippingPostalCode, $this->shippingCountry, $this->shippingLine2);
    }
}
