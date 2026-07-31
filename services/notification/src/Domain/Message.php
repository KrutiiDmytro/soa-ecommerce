<?php

namespace App\Domain;

/** Готовий до відправки лист. Сервіс stateless — повідомлення ніде не зберігається. */
final class Message
{
    public function __construct(
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $template,
    ) {
        if (!filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Invalid recipient email: "%s"', $recipient));
        }
        if (trim($subject) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('Message subject and body cannot be empty');
        }
    }
}
