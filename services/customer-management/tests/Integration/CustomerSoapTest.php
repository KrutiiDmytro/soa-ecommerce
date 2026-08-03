<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration: справжній SOAP-виклик до піднятого сервісу з реальною БД.
 * Запуск: docker compose exec customer-management php vendor/bin/phpunit --testsuite integration
 */
final class CustomerSoapTest extends TestCase
{
    private const WSDL = '/contracts/customer-management-service.wsdl';

    private \SoapClient $client;
    private string $email;

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180'); // dev-режим прогріває кеш на першому запиті
    }

    protected function setUp(): void
    {
        $this->client = new \SoapClient(self::WSDL, [
            'location' => getenv('SERVICE_ENDPOINT') ?: 'http://customer-management/soap',
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
        ]);
        $this->email = sprintf('it+%s@example.com', bin2hex(random_bytes(4)));
    }

    private function register(): string
    {
        return $this->client->RegisterCustomer([
            'email' => $this->email,
            'password' => 'secret123',
            'fullName' => 'Integration Test',
        ])->customerId;
    }

    public function testRegisteredCustomerIsPersistedAndReadable(): void
    {
        $customerId = $this->register();
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $customerId);

        $customer = $this->client->GetCustomer(['customerId' => $customerId])->customer;
        self::assertSame($this->email, $customer->email);
    }

    public function testAuthenticateIssuesIamToken(): void
    {
        $this->register();

        $response = $this->client->Authenticate(['email' => $this->email, 'password' => 'secret123']);

        self::assertCount(3, explode('.', $response->token), 'Токен IAM має бути JWT із трьох частин');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->register();

        $this->expectException(\SoapFault::class);
        $this->client->Authenticate(['email' => $this->email, 'password' => 'wrong']);
    }

    public function testDuplicateRegistrationIsRejected(): void
    {
        $this->register();

        $this->expectException(\SoapFault::class);
        $this->register();
    }

    public function testUpdateAddressIsStored(): void
    {
        $customerId = $this->register();

        $response = $this->client->UpdateAddress([
            'customerId' => $customerId,
            'address' => ['line1' => 'вул. Хрещатик, 1', 'city' => 'Київ', 'postalCode' => '01001', 'country' => 'UA'],
        ]);

        self::assertSame(1, $response->addressCount);
    }

    public function testUnknownCustomerFaults(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->GetCustomer(['customerId' => '00000000-0000-0000-0000-000000000000']);
    }
}
