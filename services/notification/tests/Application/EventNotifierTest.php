<?php

namespace App\Tests\Application;

use App\Application\EventNotifier;
use App\Application\NotificationService;
use App\Domain\MailGateway;
use App\Domain\Message;
use App\Domain\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Подія з шини → лист: вибір шаблону, отримувач, дедуп. */
final class EventNotifierTest extends TestCase
{
    /** @var Message[] */
    private array $sent = [];

    private MailGateway $gateway;
    private ArrayAdapter $processed;

    protected function setUp(): void
    {
        $sent = &$this->sent;
        $this->gateway = new class($sent) implements MailGateway {
            public function __construct(private array &$sent)
            {
            }

            public function send(Message $message): void
            {
                $this->sent[] = $message;
            }
        };
        $this->processed = new ArrayAdapter();
    }

    private function notifier(string $fallback = 'ops@printzone.local'): EventNotifier
    {
        $renderer = new TemplateRenderer();

        return new EventNotifier(
            new NotificationService($renderer, $this->gateway),
            $renderer,
            $this->processed,
            $fallback,
        );
    }

    private function envelope(string $type, array $payload, string $eventId = 'event-1'): array
    {
        return ['eventId' => $eventId, 'eventType' => $type, 'occurredAt' => '2026-07-30T12:00:00+00:00', 'payload' => $payload];
    }

    public function testPaymentCapturedProducesReceipt(): void
    {
        $sent = $this->notifier()->handle($this->envelope('payment.captured', [
            'paymentId' => 'pay-1',
            'orderId' => 'order-1',
            'amount' => ['amountMinor' => 99600, 'currency' => 'UAH'],
            'recipient' => 'buyer@example.com',
        ]));

        self::assertTrue($sent);
        self::assertCount(1, $this->sent);
        self::assertSame('payment_receipt', $this->sent[0]->template);
        self::assertSame('buyer@example.com', $this->sent[0]->recipient);
        self::assertStringContainsString('996.00 UAH', $this->sent[0]->body);
    }

    public function testShipmentDispatchedProducesShipmentEmail(): void
    {
        $this->notifier()->handle($this->envelope('shipment.dispatched', [
            'orderId' => 'order-1',
            'trackingNumber' => 'FAKEUA0001',
        ]));

        self::assertSame('shipment_dispatched', $this->sent[0]->template);
        self::assertStringContainsString('FAKEUA0001', $this->sent[0]->body);
    }

    public function testEventWithoutRecipientFallsBackToServiceAddress(): void
    {
        $this->notifier('ops@printzone.local')->handle($this->envelope('shipment.dispatched', [
            'orderId' => 'order-1',
            'trackingNumber' => 'FAKEUA0001',
        ]));

        self::assertSame('ops@printzone.local', $this->sent[0]->recipient);
    }

    public function testDuplicateEventIsNotSentTwice(): void
    {
        $notifier = $this->notifier();
        $envelope = $this->envelope('payment.captured', ['orderId' => 'order-1'], 'event-42');

        self::assertTrue($notifier->handle($envelope));
        self::assertFalse($notifier->handle($envelope));
        self::assertCount(1, $this->sent);
    }

    public function testUnrelatedEventIsIgnored(): void
    {
        self::assertFalse($this->notifier()->handle($this->envelope('stock.level.changed', ['productId' => 'p-1'])));
        self::assertSame([], $this->sent);
    }

    public function testOrderPlacedProducesConfirmation(): void
    {
        $this->notifier()->handle($this->envelope('order.placed', [
            'orderId' => 'order-1',
            'total' => ['amountMinor' => 50000, 'currency' => 'UAH'],
            'customerEmail' => 'buyer@example.com',
        ]));

        self::assertSame('order_confirmation', $this->sent[0]->template);
        self::assertStringContainsString('500.00 UAH', $this->sent[0]->body);
    }
}
