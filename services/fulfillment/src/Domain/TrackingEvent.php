<?php

namespace App\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Незмінна подія трекінгу (append-only). Історія відправлення = послідовність подій,
 * поточний статус — наслідок останньої з них (event-sourced трекінг).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tracking_events', schema: 'fulfillment')]
#[ORM\Index(name: 'idx_tracking_event_shipment', columns: ['shipment_id'])]
class TrackingEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Shipment::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'shipment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Shipment $shipment;

    #[ORM\Column(length: 16, enumType: ShipmentStatus::class)]
    private ShipmentStatus $status;

    #[ORM\Column(length: 255)]
    private string $description;

    /** Порядковий номер у межах відправлення — детермінований порядок історії. */
    #[ORM\Column(name: 'sequence_no', type: 'integer')]
    private int $sequenceNo;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    public function __construct(Shipment $shipment, ShipmentStatus $status, string $description, int $sequenceNo)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->shipment = $shipment;
        $this->status = $status;
        $this->description = $description;
        $this->sequenceNo = $sequenceNo;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function status(): ShipmentStatus
    {
        return $this->status;
    }

    public function sequenceNo(): int
    {
        return $this->sequenceNo;
    }

    public function toCanonical(): array
    {
        return [
            'status' => $this->status->value,
            'description' => $this->description,
            'occurredAt' => $this->occurredAt->format(\DATE_ATOM),
        ];
    }
}
