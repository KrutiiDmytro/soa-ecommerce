<?php

namespace App\Registry;

/**
 * Governance-артефакт SOA: єдиний каталог контрактів. Він же — таблиця
 * content-based routing: namespace повідомлення → сервіс-призначення.
 */
final class ServiceRegistry
{
    public const CHECKOUT = 'checkout-orchestration';

    private const SERVICES = [
        'customer-management' => [
            'namespace' => 'urn:printzone:soa:customer-management:v1',
            'wsdl' => '/contracts/customer-management-service.wsdl',
            'endpoint' => 'http://customer-management/soap',
            'description' => 'Клієнти та централізований IAM (видача токена ідентичності)',
        ],
        'product-inventory' => [
            'namespace' => 'urn:printzone:soa:product-inventory:v1',
            'wsdl' => '/contracts/product-inventory-service.wsdl',
            'endpoint' => 'http://product-inventory/soap',
            'description' => 'Каталог, наявність, резервування та звільнення залишків',
        ],
        'order-management' => [
            'namespace' => 'urn:printzone:soa:order-management:v1',
            'wsdl' => '/contracts/order-management-service.wsdl',
            'endpoint' => 'http://order-management/soap',
            'description' => 'Кошик, checkout, життєвий цикл замовлення',
        ],
        'fulfillment' => [
            'namespace' => 'urn:printzone:soa:fulfillment:v1',
            'wsdl' => '/contracts/fulfillment-service.wsdl',
            'endpoint' => 'http://fulfillment/soap',
            'description' => 'Оплата та доставка',
        ],
        'notification' => [
            'namespace' => 'urn:printzone:soa:notification:v1',
            'wsdl' => '/contracts/notification-service.wsdl',
            'endpoint' => 'http://notification/soap',
            'description' => 'Сповіщення (SOAP на вимогу + споживач подій шини)',
        ],
        self::CHECKOUT => [
            'namespace' => 'urn:printzone:soa:esb-checkout:v1',
            'wsdl' => '/contracts/esb-checkout-service.wsdl',
            'endpoint' => null, // виконується самим ESB — це оркестрація, а не сервіс
            'description' => 'Оркестрація процесу Checkout (виконується в ESB)',
        ],
    ];

    /** @return array<string, array<string, string|null>> */
    public function all(): array
    {
        return self::SERVICES;
    }

    /** @return array{name: string, namespace: string, wsdl: string, endpoint: string|null}|null */
    public function byNamespace(string $namespace): ?array
    {
        foreach (self::SERVICES as $name => $service) {
            if ($service['namespace'] === $namespace) {
                return ['name' => $name] + $service;
            }
        }

        return null;
    }

    /** @return array{name: string, namespace: string, wsdl: string, endpoint: string|null}|null */
    public function byName(string $name): ?array
    {
        $service = self::SERVICES[$name] ?? null;

        return $service === null ? null : ['name' => $name] + $service;
    }
}
