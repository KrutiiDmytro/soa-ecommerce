<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration: піднятий ESB — governance-каталог, віддача контрактів, content-based
 * routing і enforcement політики безпеки.
 * Запуск: docker compose exec esb php vendor/bin/phpunit --testsuite integration
 */
final class EsbGatewayTest extends TestCase
{
    private const ESB = 'http://esb/soap';
    private const REGISTRY = 'http://esb/registry';

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180');
    }

    private function get(string $url): string
    {
        return (string) file_get_contents($url);
    }

    public function testRegistryListsEveryContract(): void
    {
        $registry = json_decode($this->get(self::REGISTRY), true);
        $names = array_column($registry['services'], 'name');

        self::assertCount(6, $names);
        self::assertContains('checkout-orchestration', $names);
        foreach ($registry['services'] as $service) {
            self::assertStringContainsString('/soap', $service['endpoint'], 'Клієнт має бачити лише адресу ESB');
        }
    }

    public function testServedContractPointsAtTheBusNotAtTheService(): void
    {
        $wsdl = $this->get(self::ESB.'?wsdl=order-management');

        self::assertStringContainsString('<soap:address location="http://esb/soap"', $wsdl);
        self::assertStringNotContainsString('http://order-management/soap', $wsdl);
        self::assertStringContainsString('?xsd=canonical-data-model', $wsdl, 'Імпорт канонічної XSD має резолвитись по HTTP');
    }

    public function testCanonicalModelIsServed(): void
    {
        self::assertStringContainsString('urn:printzone:soa:canonical:v1', $this->get(self::ESB.'?xsd=canonical-data-model'));
    }

    public function testUnknownContractIs404(): void
    {
        @file_get_contents(self::ESB.'?wsdl=no-such-service');

        self::assertStringContainsString('404', $http_response_header[0] ?? '');
    }

    /** Публічна операція маршрутизується за namespace конверта — без токена. */
    public function testPublicOperationIsRoutedToTheService(): void
    {
        $client = new \SoapClient(self::ESB.'?wsdl=product-inventory', ['cache_wsdl' => \WSDL_CACHE_NONE, 'exceptions' => true]);

        $products = (array) $client->SearchProducts([])->product;

        self::assertGreaterThanOrEqual(3, \count($products));
    }

    public function testBusinessOperationWithoutSecurityHeaderIsRejected(): void
    {
        $client = new \SoapClient(self::ESB.'?wsdl=order-management', ['cache_wsdl' => \WSDL_CACHE_NONE, 'exceptions' => true]);

        $this->expectException(\SoapFault::class);
        $this->expectExceptionMessageMatches('/requires a <wsse:Security> header/');
        $client->CreateCart(['customerId' => '99999999-9999-9999-9999-999999999999']);
    }

    public function testMessageForUnknownNamespaceIsRejected(): void
    {
        $envelope = '<?xml version="1.0"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<env:Body><ns:DoThingRequest xmlns:ns="urn:printzone:soa:unknown:v1"/></env:Body></env:Envelope>';

        $response = $this->post($envelope);

        self::assertStringContainsString('No service is registered for namespace', $response);
    }

    private function post(string $envelope): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: text/xml; charset=utf-8\r\n",
            'content' => $envelope,
            'ignore_errors' => true,
        ]]);

        return (string) file_get_contents(self::ESB, false, $context);
    }
}
