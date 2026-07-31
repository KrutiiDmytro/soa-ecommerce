<?php

namespace App\Tests\Domain;

use App\Domain\NotificationException;
use App\Domain\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testOrderConfirmationInterpolatesParameters(): void
    {
        $message = $this->renderer->render('order_confirmation', 'buyer@example.com', [
            'orderId' => 'order-1',
            'total' => '996.00 UAH',
        ]);

        self::assertSame('Замовлення order-1 прийнято', $message->subject);
        self::assertStringContainsString('996.00 UAH', $message->body);
        self::assertSame('buyer@example.com', $message->recipient);
    }

    public function testShipmentTemplateUsesTrackingNumber(): void
    {
        $message = $this->renderer->render('shipment_dispatched', 'buyer@example.com', [
            'orderId' => 'order-1',
            'trackingNumber' => 'FAKEUA0001',
        ]);

        self::assertStringContainsString('FAKEUA0001', $message->body);
    }

    public function testMissingParameterIsReplacedWithPlaceholder(): void
    {
        $message = $this->renderer->render('payment_receipt', 'buyer@example.com', ['orderId' => 'order-1']);

        self::assertStringContainsString('—', $message->body);
    }

    public function testPlainTemplateUsesProvidedSubjectAndBody(): void
    {
        $message = $this->renderer->render(TemplateRenderer::PLAIN, 'buyer@example.com', [], 'Привіт', 'Тіло листа');

        self::assertSame('Привіт', $message->subject);
        self::assertSame('Тіло листа', $message->body);
    }

    public function testUnknownTemplateThrows(): void
    {
        $this->expectException(NotificationException::class);
        $this->renderer->render('nope', 'buyer@example.com', []);
    }

    public function testInvalidRecipientThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render('order_confirmation', 'not-an-email', ['orderId' => 'order-1']);
    }
}
