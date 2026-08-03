<?php

namespace E2E\Tests;

use E2E\EsbClient;
use PHPUnit\Framework\TestCase;

/** E2E-перевірки SOA-ознак: єдина точка входу, каталог контрактів, безпека на шині. */
final class BusGovernanceTest extends TestCase
{
    private EsbClient $esb;

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180');
    }

    protected function setUp(): void
    {
        $this->esb = new EsbClient();
    }

    public function testRegistryPublishesEveryContractThroughTheBus(): void
    {
        $registry = $this->esb->registry();

        self::assertCount(6, $registry['services']);
        foreach ($registry['services'] as $service) {
            self::assertStringContainsString('?wsdl=', $service['wsdl']);
            self::assertStringNotContainsString('customer-management/soap', $service['endpoint']);
        }
    }

    public function testCatalogIsBrowsableWithoutAuthentication(): void
    {
        $products = (array) $this->esb->forContract('product-inventory')->SearchProducts([])->product;

        self::assertGreaterThanOrEqual(3, \count($products));
    }

    public function testBusinessOperationRequiresIdentityToken(): void
    {
        $this->expectException(\SoapFault::class);
        $this->expectExceptionMessageMatches('/requires a <wsse:Security> header/');

        $this->esb->forContract('order-management')->CreateCart(['customerId' => '99999999-9999-9999-9999-999999999999']);
    }

    public function testForgedTokenIsRejectedOnTheBus(): void
    {
        $forged = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJoYWNrZXIifQ.not-a-valid-signature';

        $this->expectException(\SoapFault::class);
        $this->expectExceptionMessageMatches('/signature is invalid/');

        $this->esb->forContract('order-management', $forged)->CreateCart(['customerId' => '99999999-9999-9999-9999-999999999999']);
    }

    public function testContractsServedByTheBusHideInternalEndpoints(): void
    {
        $wsdl = (string) file_get_contents((getenv('ESB_ENDPOINT') ?: 'http://esb/soap').'?wsdl=fulfillment');

        self::assertStringNotContainsString('http://fulfillment/soap', $wsdl);
        self::assertStringContainsString('/soap"', $wsdl);
    }
}
