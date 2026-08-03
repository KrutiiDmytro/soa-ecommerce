<?php

namespace App\Application;

use App\Domain\MailGateway;
use App\Domain\Message;
use App\Domain\NotificationException;
use App\Domain\TemplateRenderer;
use Symfony\Component\Uid\Uuid;

/** Application-сервіс: зібрати лист за шаблоном і віддати його шлюзу. */
final class NotificationService
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly MailGateway $gateway,
    ) {
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return string notificationId
     */
    public function send(
        string $recipient,
        string $channel,
        string $template,
        array $parameters = [],
        ?string $subject = null,
        ?string $body = null,
    ): string {
        if (strtoupper($channel) !== 'EMAIL') {
            throw NotificationException::unsupportedChannel($channel);
        }

        $this->dispatch($this->renderer->render($template, $recipient, $parameters, $subject, $body));

        return Uuid::v4()->toRfc4122();
    }

    public function dispatch(Message $message): void
    {
        $this->gateway->send($message);
    }
}
