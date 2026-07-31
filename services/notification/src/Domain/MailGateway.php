<?php

namespace App\Domain;

/** Порт поштового шлюзу (seam). Реалізації: fake (default) та SES. */
interface MailGateway
{
    public function send(Message $message): void;
}
