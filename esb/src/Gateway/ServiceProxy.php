<?php

namespace App\Gateway;

/**
 * Прозорий проксі до сервісу-призначення: ESB приймає виклик за контрактом,
 * викликає ту саму операцію у внутрішнього сервісу й повертає відповідь клієнту.
 * Магічний __call — щоб не дублювати підписи 20+ операцій п'яти контрактів.
 */
final class ServiceProxy
{
    public function __construct(
        private readonly string $wsdl,
        private readonly string $endpoint,
    ) {
    }

    public function __call(string $operation, array $arguments): mixed
    {
        $client = new \SoapClient($this->wsdl, [
            'location' => $this->endpoint,
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
            'connection_timeout' => 10,
        ]);

        return $client->__soapCall($operation, $arguments);
    }
}
