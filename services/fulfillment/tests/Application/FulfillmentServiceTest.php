<?php

namespace App\Tests\Application;

use App\Application\FulfillmentService;
use App\Domain\Address;
use App\Domain\EventPublisher;
use App\Domain\FulfillmentException;
use App\Domain\Money;
use App\Domain\Payment;
use App\Domain\PaymentDeclined;
use App\Domain\PaymentProvider;
use App\Domain\PaymentRepository;
use App\Domain\PaymentStatus;
use App\Domain\Shipment;
use App\Domain\ShipmentRepository;
use App\Domain\ShippingProvider;
use App\Infrastructure\FakePaymentProvider;
use App\Infrastructure\FakeShippingProvider;
use PHPUnit\Framework\TestCase;

/** Сценарії сервісу на in-memory портах: ідемпотентність, відмова провайдера, події. */
final class FulfillmentServiceTest extends TestCase
{
    private PaymentRepository $payments;
    private ShipmentRepository $shipments;
    private EventPublisher $events;

    /** @var array<int, array{0: string, 1: array}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->payments = new class implements PaymentRepository {
            /** @var Payment[] */
            public array $items = [];

            public function save(Payment $payment): void
            {
                $this->items[$payment->id()] = $payment;
            }

            public function byId(string $id): ?Payment
            {
                return $this->items[$id] ?? null;
            }

            public function byOrderId(string $orderId): ?Payment
            {
                foreach ($this->items as $payment) {
                    if ($payment->orderId() === $orderId) {
                        return $payment;
                    }
                }

                return null;
            }
        };

        $this->shipments = new class implements ShipmentRepository {
            /** @var Shipment[] */
            public array $items = [];

            public function save(Shipment $shipment): void
            {
                $this->items[$shipment->id()] = $shipment;
            }

            public function byId(string $id): ?Shipment
            {
                return $this->items[$id] ?? null;
            }

            public function byOrderId(string $orderId): ?Shipment
            {
                foreach ($this->items as $shipment) {
                    if ($shipment->orderId() === $orderId) {
                        return $shipment;
                    }
                }

                return null;
            }
        };

        $published = &$this->published;
        $this->events = new class($published) implements EventPublisher {
            public function __construct(private array &$published)
            {
            }

            public function publish(string $routingKey, array $payload): void
            {
                $this->published[] = [$routingKey, $payload];
            }
        };
    }

    private function service(?PaymentProvider $payment = null, ?ShippingProvider $shipping = null): FulfillmentService
    {
        return new FulfillmentService(
            $this->payments,
            $this->shipments,
            $payment ?? new FakePaymentProvider(),
            $shipping ?? new FakeShippingProvider(),
            $this->events,
        );
    }

    public function testAuthorizeIsIdempotentPerOrder(): void
    {
        $service = $this->service();

        $first = $service->authorizePayment('order-1', new Money(99600, 'UAH'));
        $second = $service->authorizePayment('order-1', new Money(99600, 'UAH'));

        self::assertSame($first->id(), $second->id());
    }

    public function testDeclinedPaymentIsRecordedAndFaults(): void
    {
        $service = $this->service();
        $amount = new Money(FakePaymentProvider::DECLINE_ABOVE_MINOR + 1, 'UAH');

        try {
            $service->authorizePayment('order-2', $amount);
            self::fail('Expected the payment to be declined');
        } catch (FulfillmentException $e) {
            self::assertStringContainsString('declined', $e->getMessage());
        }

        $recorded = $this->payments->byOrderId('order-2');
        self::assertNotNull($recorded);
        self::assertSame(PaymentStatus::FAILED, $recorded->status());
    }

    public function testFailedPaymentIsRetriedOnNextAuthorize(): void
    {
        $flaky = new class implements PaymentProvider {
            public int $calls = 0;

            public function authorize(string $orderId, Money $amount): string
            {
                if (++$this->calls === 1) {
                    throw new PaymentDeclined('temporary decline');
                }

                return 'ref_2';
            }

            public function capture(string $providerRef, Money $amount): void
            {
            }
        };
        $service = $this->service($flaky);

        try {
            $service->authorizePayment('order-3', new Money(1000, 'UAH'));
        } catch (FulfillmentException) {
        }

        $payment = $service->authorizePayment('order-3', new Money(1000, 'UAH'));
        self::assertSame(PaymentStatus::AUTHORIZED, $payment->status());
        self::assertSame('ref_2', $payment->providerRef());
    }

    public function testCapturePublishesPaymentCaptured(): void
    {
        $service = $this->service();
        $payment = $service->authorizePayment('order-4', new Money(50000, 'UAH'));

        $captured = $service->capturePayment($payment->id());

        self::assertSame(PaymentStatus::CAPTURED, $captured->status());
        self::assertSame('payment.captured', $this->published[0][0]);
        self::assertSame('order-4', $this->published[0][1]['orderId']);
        self::assertSame(50000, $this->published[0][1]['amount']['amountMinor']);
    }

    public function testCaptureOfFailedPaymentFaults(): void
    {
        $service = $this->service();
        $this->payments->save($failed = Payment::failed('order-5', new Money(1000, 'UAH'), 'declined'));

        $this->expectException(FulfillmentException::class);
        $service->capturePayment($failed->id());
    }

    public function testCreateShipmentDispatchesAndPublishes(): void
    {
        $shipment = $this->service()->createShipment('order-6', new Address('вул. Хрещатик, 1', 'Київ', '01001', 'UA'));

        self::assertCount(2, $shipment->events()); // CREATED + DISPATCHED
        self::assertSame('shipment.dispatched', $this->published[0][0]);
        self::assertSame($shipment->trackingNumber(), $this->published[0][1]['trackingNumber']);
    }

    public function testCreateShipmentIsIdempotentPerOrder(): void
    {
        $service = $this->service();
        $address = new Address('вул. Хрещатик, 1', 'Київ', '01001', 'UA');

        $first = $service->createShipment('order-7', $address);
        $second = $service->createShipment('order-7', $address);

        self::assertSame($first->id(), $second->id());
        self::assertCount(1, $this->published); // подія лише на перше створення
    }

    public function testTrackingUnknownShipmentFaults(): void
    {
        $this->expectException(FulfillmentException::class);
        $this->service()->trackShipment('missing');
    }
}
