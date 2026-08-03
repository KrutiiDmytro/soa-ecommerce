<?php

namespace App\Application;

use App\Domain\TemplateRenderer;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перетворює подію з шини на лист. Сервіс stateless, тому «вже оброблено»
 * тримаємо позначкою в Redis із TTL — це кеш, а не сховище стану сервісу.
 */
final class EventNotifier
{
    private const DEDUP_TTL = 86400;

    /** Подія → шаблон листа. */
    private const TEMPLATES = [
        'order.placed' => 'order_confirmation',
        'payment.captured' => 'payment_receipt',
        'shipment.dispatched' => 'shipment_dispatched',
    ];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TemplateRenderer $renderer,
        #[Autowire(service: 'notification.cache')]
        private readonly CacheItemPoolInterface $processed,
        #[Autowire('%env(NOTIFICATION_FALLBACK_EMAIL)%')]
        private readonly string $fallbackRecipient,
    ) {
    }

    /**
     * @param array<string, mixed> $envelope конверт події {eventId, eventType, occurredAt, payload}
     *
     * @return bool чи був відправлений лист (false = подія не наша або вже оброблена)
     */
    public function handle(array $envelope): bool
    {
        $eventType = (string) ($envelope['eventType'] ?? '');
        $template = self::TEMPLATES[$eventType] ?? null;

        if ($template === null) {
            return false;
        }

        $eventId = (string) ($envelope['eventId'] ?? '');
        $item = $this->processed->getItem('seen_'.preg_replace('/\W/', '_', $eventId));

        if ($eventId !== '' && $item->isHit()) {
            return false; // ідемпотентність: at-least-once доставка не має слати лист двічі
        }

        $payload = (array) ($envelope['payload'] ?? []);
        $message = $this->renderer->render($template, $this->recipient($payload), $this->parameters($payload));
        $this->notifications->dispatch($message);

        if ($eventId !== '') {
            $this->processed->save($item->set(true)->expiresAfter(self::DEDUP_TTL));
        }

        return true;
    }

    /**
     * Адресу дає подія; якщо продюсер її не несе (Fulfillment знає лише orderId) —
     * лист іде на службову адресу. Збагачення події адресою — задача медіації ESB.
     */
    private function recipient(array $payload): string
    {
        $recipient = (string) ($payload['recipient'] ?? $payload['customerEmail'] ?? '');

        return filter_var($recipient, \FILTER_VALIDATE_EMAIL) ? $recipient : $this->fallbackRecipient;
    }

    /** @return array<string, string> */
    private function parameters(array $payload): array
    {
        $parameters = [];

        foreach ($payload as $key => $value) {
            $parameters[$key] = match (true) {
                \is_array($value) && isset($value['amountMinor'], $value['currency']) => sprintf('%.2f %s', $value['amountMinor'] / 100, $value['currency']),
                \is_scalar($value) => (string) $value,
                default => '—',
            };
        }

        return $parameters;
    }
}
