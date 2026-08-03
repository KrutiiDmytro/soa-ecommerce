<?php

namespace App\Tests\Gateway;

use App\Gateway\MessageInspector;
use PHPUnit\Framework\TestCase;

/** Content-based routing: адресація береться зі ЗМІСТУ повідомлення, не з URL. */
final class MessageInspectorTest extends TestCase
{
    private const SECURITY_HEADER = <<<'XML'
        <env:Header>
          <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"
                         xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd"
                         env:mustUnderstand="1">
            <wsu:Timestamp><wsu:Created>2026-07-31T10:00:00Z</wsu:Created><wsu:Expires>2026-07-31T10:05:00Z</wsu:Expires></wsu:Timestamp>
            <wsse:BinarySecurityToken>header.payload.signature</wsse:BinarySecurityToken>
          </wsse:Security>
        </env:Header>
        XML;

    private function envelope(string $namespace, string $operation, string $header = ''): string
    {
        return sprintf(
            '<?xml version="1.0"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/">%s'
            .'<env:Body><ns:%sRequest xmlns:ns="%s"><ns:cartId>cart-1</ns:cartId></ns:%sRequest></env:Body></env:Envelope>',
            $header,
            $operation,
            $namespace,
            $operation,
        );
    }

    public function testDetectsNamespaceAndOperation(): void
    {
        $message = (new MessageInspector())->inspect($this->envelope('urn:printzone:soa:order-management:v1', 'GetCart'));

        self::assertSame('urn:printzone:soa:order-management:v1', $message->namespace);
        self::assertSame('GetCart', $message->operation);
        self::assertNull($message->token);
    }

    public function testExtractsSecurityHeader(): void
    {
        $message = (new MessageInspector())->inspect(
            $this->envelope('urn:printzone:soa:esb-checkout:v1', 'Checkout', self::SECURITY_HEADER),
        );

        self::assertSame('header.payload.signature', $message->token);
        self::assertSame('2026-07-31T10:00:00Z', $message->createdAt);
        self::assertSame('2026-07-31T10:05:00Z', $message->expiresAt);
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MessageInspector())->inspect('not xml at all');
    }

    public function testRejectsEnvelopeWithoutOperation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MessageInspector())->inspect('<?xml version="1.0"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body/></env:Envelope>');
    }
}
