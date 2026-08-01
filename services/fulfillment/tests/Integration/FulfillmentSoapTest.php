<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration: оплата й доставка через SOAP із реальною БД (події летять у RabbitMQ).
 * Запуск: docker compose exec fulfillment php vendor/bin/phpunit --testsuite integration
 */
final class FulfillmentSoapTest extends TestCase
{
    private const WSDL = '/contracts/fulfillment-service.wsdl';

    private \SoapClient $client;
    private string $orderId;

    public static function setUpBeforeClass(): void
    {
        ini_set('default_socket_timeout', '180');
    }

    protected function setUp(): void
    {
        $this->client = new \SoapClient(self::WSDL, [
            'location' => getenv('SERVICE_ENDPOINT') ?: 'http://fulfillment/soap',
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
        ]);
        $this->orderId = sprintf('%s-2222-2222-2222-222222222222', substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function amount(int $minor = 99600): array
    {
        return ['amountMinor' => $minor, 'currency' => 'UAH'];
    }

    private function address(): array
    {
        return ['line1' => 'вул. Хрещатик, 1', 'city' => 'Київ', 'postalCode' => '01001', 'country' => 'UA'];
    }

    public function testAuthorizeIsIdempotentPerOrder(): void
    {
        $first = $this->client->AuthorizePayment(['orderId' => $this->orderId, 'amount' => $this->amount()]);
        $second = $this->client->AuthorizePayment(['orderId' => $this->orderId, 'amount' => $this->amount()]);

        self::assertTrue($first->authorized);
        self::assertSame($first->paymentId, $second->paymentId, 'Повторна авторизація не має створювати другий платіж');
    }

    public function testCaptureMovesPaymentToCaptured(): void
    {
        $paymentId = $this->client->AuthorizePayment(['orderId' => $this->orderId, 'amount' => $this->amount()])->paymentId;

        $captured = $this->client->CapturePayment(['paymentId' => $paymentId]);

        self::assertSame('CAPTURED', $captured->status);
        self::assertSame(99600, (int) $captured->capturedAmount->amountMinor);
    }

    public function testDoubleCaptureFaults(): void
    {
        $paymentId = $this->client->AuthorizePayment(['orderId' => $this->orderId, 'amount' => $this->amount()])->paymentId;
        $this->client->CapturePayment(['paymentId' => $paymentId]);

        $this->expectException(\SoapFault::class);
        $this->client->CapturePayment(['paymentId' => $paymentId]);
    }

    public function testDeclinedPaymentFaults(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->AuthorizePayment(['orderId' => $this->orderId, 'amount' => $this->amount(2_000_000)]);
    }

    public function testShipmentIsIdempotentAndTracked(): void
    {
        $first = $this->client->CreateShipment(['orderId' => $this->orderId, 'shippingAddress' => $this->address()]);
        $second = $this->client->CreateShipment(['orderId' => $this->orderId, 'shippingAddress' => $this->address()]);

        self::assertSame($first->shipmentId, $second->shipmentId);
        self::assertNotEmpty($first->trackingNumber);

        $tracking = $this->client->TrackShipment(['shipmentId' => $first->shipmentId]);

        self::assertSame('DISPATCHED', $tracking->status);
        self::assertCount(2, (array) $tracking->event, 'Історія має містити CREATED і DISPATCHED');
        self::assertSame('CREATED', $tracking->event[0]->status);
    }

    public function testTrackingUnknownShipmentFaults(): void
    {
        $this->expectException(\SoapFault::class);
        $this->client->TrackShipment(['shipmentId' => '00000000-0000-0000-0000-000000000000']);
    }
}
