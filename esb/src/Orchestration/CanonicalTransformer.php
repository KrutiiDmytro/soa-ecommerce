<?php

namespace App\Orchestration;

/**
 * Канонічна модель ↔ внутрішні формати сервісів. Саме тут ESB «розбирає» канонічну
 * сутність на виклики, які розуміє конкретний сервіс: канонічний кошик (cdm:CartLine[])
 * → окремі ReserveStock(productId, quantity) у Product & Inventory тощо.
 */
final class CanonicalTransformer
{
    /**
     * @return array<int, array{productId: string, quantity: int}>
     */
    public function reservations(object $cart): array
    {
        $lines = $cart->line ?? [];
        $reservations = [];

        foreach (\is_array($lines) ? $lines : [$lines] as $line) {
            $reservations[] = ['productId' => (string) $line->productId, 'quantity' => (int) $line->quantity];
        }

        return $reservations;
    }

    /** cdm:Money → масив для SOAP-виклику (канонічна форма зберігається). */
    public function money(object $money): array
    {
        return ['amountMinor' => (int) $money->amountMinor, 'currency' => (string) $money->currency];
    }

    /** cdm:Address → масив (line2 опційний). */
    public function address(object $address): array
    {
        $canonical = ['line1' => (string) $address->line1];

        if (isset($address->line2) && $address->line2 !== '') {
            $canonical['line2'] = (string) $address->line2;
        }

        return $canonical + [
            'city' => (string) $address->city,
            'postalCode' => (string) $address->postalCode,
            'country' => (string) $address->country,
        ];
    }

    /**
     * Подія для Notification. ESB ЗБАГАЧУЄ її поштою клієнта, якої немає в жодного
     * сервісу нижче за течією — це і є медіація на шині.
     */
    public function orderPlacedEvent(object $order, string $customerEmail): array
    {
        return [
            'orderId' => (string) $order->id,
            'customerId' => (string) $order->customerId,
            'total' => $this->money($order->total),
            'recipient' => $customerEmail,
        ];
    }

    /** Команда для Fulfillment (async-гілка процесу). */
    public function shipmentCommand(object $order, object $shippingAddress): array
    {
        return [
            'orderId' => (string) $order->id,
            'shippingAddress' => $this->address($shippingAddress),
        ];
    }
}
