<?php

namespace App\Infrastructure;

use App\Domain\MailGateway;
use App\Domain\Message;

/**
 * Шлюз за замовчуванням: нічого нікуди не шле, а пише лист у файл-лог.
 * Достатньо, щоб побачити наскрізний потік подій без зовнішнього SMTP/SES.
 */
final class FakeMailGateway implements MailGateway
{
    public function __construct(private readonly string $logFile)
    {
    }

    public function send(Message $message): void
    {
        $entry = sprintf(
            "[%s] template=%s to=%s\nSubject: %s\n%s\n%s\n",
            (new \DateTimeImmutable())->format(\DATE_ATOM),
            $message->template,
            $message->recipient,
            $message->subject,
            $message->body,
            str_repeat('-', 60),
        );

        if (!is_dir($directory = \dirname($this->logFile))) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->logFile, $entry, \FILE_APPEND);
    }
}
