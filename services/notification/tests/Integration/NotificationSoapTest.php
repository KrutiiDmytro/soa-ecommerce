<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration: SOAP-виклик до піднятого сервісу; результат перевіряємо за логом
 * fake-шлюза (власної БД сервіс не має — він stateless за дизайном).
 * Запуск: docker compose exec notification php vendor/bin/phpunit --testsuite integration
 */
final class NotificationSoapTest extends TestCase
{
    private const WSDL = '/contracts/notification-service.wsdl';
    private const MAIL_LOG = '/app/var/mail/sent.log';

    private \SoapClient $client;
    private string $recipient;

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180');
    }

    protected function setUp(): void
    {
        $this->client = new \SoapClient(self::WSDL, [
            'location' => getenv('SERVICE_ENDPOINT') ?: 'http://notification/soap',
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
        ]);
        $this->recipient = sprintf('it+%s@example.com', bin2hex(random_bytes(4)));
    }

    private function sentLog(): string
    {
        return is_file(self::MAIL_LOG) ? (string) file_get_contents(self::MAIL_LOG) : '';
    }

    private function request(array $overrides = []): array
    {
        return $overrides + [
            'customerId' => '99999999-9999-9999-9999-999999999999',
            'recipient' => $this->recipient,
            'channel' => 'EMAIL',
            'template' => 'order_confirmation',
            'parameter' => [
                ['name' => 'orderId', 'value' => 'order-integration'],
                ['name' => 'total', 'value' => '996.00 UAH'],
            ],
        ];
    }

    public function testTemplatedNotificationIsAcceptedAndDelivered(): void
    {
        $response = $this->client->SendNotification($this->request());

        self::assertTrue($response->accepted);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->notificationId);

        $log = $this->sentLog();
        self::assertStringContainsString($this->recipient, $log);
        self::assertStringContainsString('order-integration', $log);
        self::assertStringContainsString('996.00 UAH', $log);
    }

    public function testPlainTemplateUsesCallerText(): void
    {
        $this->client->SendNotification($this->request([
            'template' => 'plain',
            'parameter' => [],
            'subject' => 'Інтеграційний лист',
            'body' => 'Текст від викликача',
        ]));

        self::assertStringContainsString('Текст від викликача', $this->sentLog());
    }

    public function testUnknownTemplateFaults(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->SendNotification($this->request(['template' => 'no-such-template']));
    }

    public function testSmsChannelIsRejected(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->SendNotification($this->request(['channel' => 'SMS']));
    }

    public function testInvalidRecipientFaults(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->SendNotification($this->request(['recipient' => 'not-an-email']));
    }
}
