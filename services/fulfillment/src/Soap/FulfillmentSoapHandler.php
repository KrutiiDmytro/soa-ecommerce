<?php

namespace App\Soap;

use App\Application\FulfillmentService;
use App\Domain\Address;
use App\Domain\FulfillmentException;
use App\Domain\Money;
use App\Domain\TrackingEvent;

/**
 * SOAP-обробник Fulfillment: методи відповідають операціям WSDL.
 * Доменні винятки мапляться на SOAP Fault.
 */
final class FulfillmentSoapHandler
{
    public function __construct(private readonly FulfillmentService $fulfillment)
    {
    }

    public function AuthorizePayment(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $payment = $this->fulfillment->authorizePayment(
                (string) $request->orderId,
                new Money((int) $request->amount->amountMinor, (string) $request->amount->currency),
            );

            return ['paymentId' => $payment->id(), 'authorized' => $payment->isAuthorized()];
        });
    }

    public function CapturePayment(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $payment = $this->fulfillment->capturePayment((string) $request->paymentId);

            return [
                'paymentId' => $payment->id(),
                'status' => $payment->status()->value,
                'capturedAmount' => $payment->amount()->toCanonical(),
            ];
        });
    }

    public function CreateShipment(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $shipment = $this->fulfillment->createShipment(
                (string) $request->orderId,
                Address::fromCanonical($request->shippingAddress),
            );

            return ['shipmentId' => $shipment->id(), 'trackingNumber' => $shipment->trackingNumber()];
        });
    }

    public function TrackShipment(object $request): array
    {
        return $this->guard(function () use ($request): array {
            $shipment = $this->fulfillment->trackShipment((string) $request->shipmentId);

            return [
                'shipmentId' => $shipment->id(),
                'trackingNumber' => $shipment->trackingNumber(),
                'status' => $shipment->status()->value,
                'event' => array_map(static fn (TrackingEvent $e): array => $e->toCanonical(), $shipment->events()),
            ];
        });
    }

    /**
     * @param callable():array $operation
     */
    private function guard(callable $operation): array
    {
        try {
            return $operation();
        } catch (FulfillmentException | \InvalidArgumentException $e) {
            throw new \SoapFault('Client', $e->getMessage());
        }
    }
}
