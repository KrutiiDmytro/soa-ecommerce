<?php

namespace App\Registry;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;

/** GET /registry — каталог контрактів для discovery (governance-артефакт SOA). */
final class RegistryController
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        #[Autowire('%env(ESB_PUBLIC_ENDPOINT)%')]
        private readonly string $publicEndpoint,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $services = [];

        foreach ($this->registry->all() as $name => $service) {
            $services[] = [
                'name' => $name,
                'namespace' => $service['namespace'],
                'description' => $service['description'],
                // Клієнт бачить ЛИШЕ адресу ESB — сервіси всередині мережі недосяжні ззовні.
                'wsdl' => $this->publicEndpoint.'?wsdl='.$name,
                'endpoint' => $this->publicEndpoint,
                'kind' => $service['endpoint'] === null ? 'orchestration' : 'service',
            ];
        }

        return new JsonResponse([
            'registry' => 'PrintZone SOA service registry',
            'canonicalModel' => $this->publicEndpoint.'?xsd=canonical-data-model',
            'services' => $services,
        ], 200, [], false);
    }
}
