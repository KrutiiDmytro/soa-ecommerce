<?php

namespace App;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Спільний health-endpoint. Ім'я сервісу береться з env SERVICE_NAME,
 * тож файл ідентичний для всіх сервісів скелета.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => $_ENV['SERVICE_NAME'] ?? getenv('SERVICE_NAME') ?: 'unknown',
        ]);
    }
}
