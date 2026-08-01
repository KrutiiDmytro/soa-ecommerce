<?php

namespace App\Orchestration;

use App\Messaging\AmqpPublisher;
use App\Registry\ServiceRegistry;

/**
 * Оркестрація процесу Checkout (Task 27 §05) — серце SOA-підходу: стан процесу і
 * логіка «що після чого, що при збої» живуть у ESB, а не розмазані по сервісах.
 *
 *   GetCart(OM) → ReserveStock(PI, по рядках) → Checkout(OM) → AuthorizePayment(FF)
 *   → CapturePayment(FF) → MarkPaid(OM) → async: shipment.requested + order.placed
 *
 * Компенсація: будь-який збій → звільнити вже зарезервоване (ReleaseStock) і, якщо
 * замовлення вже створене, скасувати його (CancelOrder).
 */
final class CheckoutOrchestrator
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly CanonicalTransformer $transformer,
        private readonly AmqpPublisher $bus,
    ) {
    }

    public function Checkout(object $request): array
    {
        $reserved = [];
        $orderId = null;

        try {
            $om = $this->client('order-management');
            $pi = $this->client('product-inventory');
            $ff = $this->client('fulfillment');

            // 1. Читаємо канонічний кошик — ESB не тримає власних даних про товари.
            $cart = $om->GetCart(['cartId' => (string) $request->cartId]);

            // 2. Резервуємо залишки (sync — від результату залежить наступний крок).
            foreach ($this->transformer->reservations($cart) as $reservation) {
                $pi->ReserveStock($reservation);
                $reserved[] = $reservation;
            }

            // 3. Кошик стає замовленням (PENDING).
            $order = $om->Checkout([
                'cartId' => (string) $request->cartId,
                'customerId' => (string) $request->customerId,
                'shippingAddress' => $this->transformer->address($request->shippingAddress),
            ])->order;
            $orderId = (string) $order->id;

            // 4. Оплата (sync — клієнт має одразу знати результат).
            $payment = $ff->AuthorizePayment([
                'orderId' => $orderId,
                'amount' => $this->transformer->money($order->total),
            ]);

            if (!$payment->authorized) {
                throw new \RuntimeException(sprintf('Payment for order "%s" was not authorized', $orderId));
            }

            $ff->CapturePayment(['paymentId' => $payment->paymentId]);
            $order = $om->MarkPaid(['orderId' => $orderId])->order;

            // 5. Асинхронна гілка: доставку й лист можна відкласти без шкоди для відгуку.
            $this->bus->publish('shipment.requested', $this->transformer->shipmentCommand($order, $request->shippingAddress));
            $this->bus->publish('order.placed', $this->transformer->orderPlacedEvent($order, (string) $request->customerEmail));

            return [
                'order' => $order,
                'paymentId' => $payment->paymentId,
                'shipmentRequested' => true,
            ];
        } catch (\Throwable $e) {
            $compensation = $this->compensate($reserved, $orderId);

            throw new \SoapFault('Client', sprintf('Checkout failed: %s. %s', $e->getMessage(), $compensation));
        }
    }

    /**
     * @param array<int, array{productId: string, quantity: int}> $reserved
     */
    private function compensate(array $reserved, ?string $orderId): string
    {
        $done = [];

        try {
            $pi = $this->client('product-inventory');

            foreach ($reserved as $reservation) {
                $pi->ReleaseStock($reservation);
            }
            $done[] = sprintf('released %d reservation(s)', \count($reserved));

            if ($orderId !== null) {
                $this->client('order-management')->CancelOrder(['orderId' => $orderId]);
                $done[] = sprintf('order %s cancelled', $orderId);
            }
        } catch (\Throwable $e) {
            return sprintf('Compensation incomplete (%s): %s', implode(', ', $done) ?: 'nothing rolled back', $e->getMessage());
        }

        return 'Compensation: '.(implode(', ', $done) ?: 'nothing to roll back').'.';
    }

    private function client(string $service): \SoapClient
    {
        $definition = $this->registry->byName($service) ?? throw new \RuntimeException(sprintf('Service "%s" is not registered', $service));

        return new \SoapClient($definition['wsdl'], [
            'location' => (string) $definition['endpoint'],
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
            'connection_timeout' => 10,
        ]);
    }
}
