<?php

namespace App\Domain;

/**
 * Шаблони листів. Простий рендер із підстановкою {параметрів} — Twig тут зайвий:
 * сервіс не має ані вебу, ані станів, лише формує текст і віддає його шлюзу.
 */
final class TemplateRenderer
{
    public const PLAIN = 'plain';

    private const TEMPLATES = [
        'order_confirmation' => [
            'subject' => 'Замовлення {orderId} прийнято',
            'body' => "Дякуємо за замовлення!\n\nНомер замовлення: {orderId}\nСума: {total}\n\nМи повідомимо, щойно його відправлять.",
        ],
        'payment_receipt' => [
            'subject' => 'Оплату отримано — замовлення {orderId}',
            'body' => "Оплату успішно проведено.\n\nНомер замовлення: {orderId}\nСписано: {amount}\nПлатіж: {paymentId}",
        ],
        'shipment_dispatched' => [
            'subject' => 'Замовлення {orderId} відправлено',
            'body' => "Ваше замовлення передано перевізнику.\n\nНомер замовлення: {orderId}\nНомер накладної: {trackingNumber}",
        ],
    ];

    /**
     * @param array<string, string> $parameters
     */
    public function render(string $template, string $recipient, array $parameters, ?string $subject = null, ?string $body = null): Message
    {
        if ($template === self::PLAIN) {
            return new Message($recipient, (string) $subject, (string) $body, $template);
        }

        $definition = self::TEMPLATES[$template] ?? throw NotificationException::unknownTemplate($template);

        return new Message(
            $recipient,
            $this->interpolate($subject ?? $definition['subject'], $parameters),
            $this->interpolate($body ?? $definition['body'], $parameters),
            $template,
        );
    }

    public static function knows(string $template): bool
    {
        return $template === self::PLAIN || isset(self::TEMPLATES[$template]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function interpolate(string $text, array $parameters): string
    {
        return preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => $parameters[$m[1]] ?? '—',
            $text,
        ) ?? $text;
    }
}
