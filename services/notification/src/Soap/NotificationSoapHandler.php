<?php

namespace App\Soap;

use App\Application\NotificationService;
use App\Domain\NotificationException;

/**
 * SOAP-обробник Notification: синхронна відправка на вимогу (для ESB).
 * Доменні винятки мапляться на SOAP Fault.
 */
final class NotificationSoapHandler
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function SendNotification(object $request): array
    {
        try {
            $notificationId = $this->notifications->send(
                (string) $request->recipient,
                (string) $request->channel,
                (string) $request->template,
                $this->parameters($request),
                isset($request->subject) ? (string) $request->subject : null,
                isset($request->body) ? (string) $request->body : null,
            );

            return ['accepted' => true, 'notificationId' => $notificationId];
        } catch (NotificationException | \InvalidArgumentException $e) {
            throw new \SoapFault('Client', $e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function parameters(object $request): array
    {
        $raw = $request->parameter ?? [];
        $parameters = [];

        foreach (\is_array($raw) ? $raw : [$raw] as $parameter) {
            $parameters[(string) $parameter->name] = (string) $parameter->value;
        }

        return $parameters;
    }
}
