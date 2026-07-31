<?php

namespace App\Tests\Application;

use App\Application\NotificationService;
use App\Domain\MailGateway;
use App\Domain\Message;
use App\Domain\NotificationException;
use App\Domain\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
{
    /** @var Message[] */
    private array $sent = [];

    private function service(): NotificationService
    {
        $sent = &$this->sent;
        $gateway = new class($sent) implements MailGateway {
            public function __construct(private array &$sent)
            {
            }

            public function send(Message $message): void
            {
                $this->sent[] = $message;
            }
        };

        return new NotificationService(new TemplateRenderer(), $gateway);
    }

    public function testSendReturnsNotificationIdAndHandsMessageToGateway(): void
    {
        $id = $this->service()->send('buyer@example.com', 'EMAIL', 'order_confirmation', ['orderId' => 'order-1']);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('order-1', $this->sent[0]->subject);
    }

    public function testSmsChannelIsRejected(): void
    {
        $this->expectException(NotificationException::class);
        $this->service()->send('buyer@example.com', 'SMS', 'order_confirmation');
    }

    public function testChannelIsCaseInsensitive(): void
    {
        $this->service()->send('buyer@example.com', 'email', TemplateRenderer::PLAIN, [], 'Тема', 'Текст');

        self::assertSame('Тема', $this->sent[0]->subject);
    }
}
