<?php

namespace App\Application;

use App\Domain\Address;
use App\Domain\EventPublisher;
use App\Domain\FulfillmentException;
use App\Domain\Money;
use App\Domain\Payment;
use App\Domain\PaymentDeclined;
use App\Domain\PaymentProvider;
use App\Domain\PaymentRepository;
use App\Domain\PaymentStatus;
use App\Domain\Shipment;
use App\Domain\ShipmentRepository;
use App\Domain\ShippingProvider;

/**
 * Application-сервіс Fulfillment: оплата та доставка.
 * Обидві операції ідемпотентні за orderId — оркестрація ESB може безпечно повторити крок.
 */
final class FulfillmentService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly ShipmentRepository $shipments,
        private readonly PaymentProvider $paymentProvider,
        private readonly ShippingProvider $shippingProvider,
        private readonly EventPublisher $events,
    ) {
    }

    public function authorizePayment(string $orderId, Money $amount): Payment
    {
        $payment = $this->payments->byOrderId($orderId);

        if ($payment !== null && $payment->status() !== PaymentStatus::FAILED) {
            return $payment; // ідемпотентність: платіж для замовлення вже є
        }

        try {
            $providerRef = $this->paymentProvider->authorize($orderId, $amount);
        } catch (PaymentDeclined $e) {
            if ($payment === null) {
                $this->payments->save(Payment::failed($orderId, $amount, $e->getMessage()));
            }

            throw FulfillmentException::paymentDeclined($orderId, $e->getMessage());
        }

        if ($payment === null) {
            $payment = Payment::authorized($orderId, $amount, $providerRef);
        } else {
            $payment->reauthorize($providerRef);
        }
        $this->payments->save($payment);

        return $payment;
    }

    public function capturePayment(string $paymentId): Payment
    {
        $payment = $this->payments->byId($paymentId) ?? throw FulfillmentException::paymentNotFound($paymentId);

        if (!$payment->isAuthorized()) {
            throw FulfillmentException::invalidPaymentTransition($payment->id(), $payment->status(), PaymentStatus::CAPTURED);
        }

        $this->paymentProvider->capture((string) $payment->providerRef(), $payment->amount());
        $payment->capture();
        $this->payments->save($payment);

        $this->events->publish('payment.captured', [
            'paymentId' => $payment->id(),
            'orderId' => $payment->orderId(),
            'amount' => $payment->amount()->toCanonical(),
        ]);

        return $payment;
    }

    public function createShipment(string $orderId, Address $shippingAddress): Shipment
    {
        $existing = $this->shipments->byOrderId($orderId);

        if ($existing !== null) {
            return $existing; // ідемпотентність: повторна подія не плодить відправлень
        }

        $shipment = new Shipment($orderId, $shippingAddress, $this->shippingProvider->createShipment($orderId, $shippingAddress));
        $shipment->dispatch();
        $this->shipments->save($shipment);

        $this->events->publish('shipment.dispatched', [
            'shipmentId' => $shipment->id(),
            'orderId' => $shipment->orderId(),
            'trackingNumber' => $shipment->trackingNumber(),
        ]);

        return $shipment;
    }

    public function trackShipment(string $shipmentId): Shipment
    {
        return $this->shipments->byId($shipmentId) ?? throw FulfillmentException::shipmentNotFound($shipmentId);
    }
}
