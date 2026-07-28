<?php

namespace App\Soap;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Єдина SOAP-точка сервісу:
 *  - GET  /soap?wsdl → віддає WSDL-контракт;
 *  - POST /soap      → обробляє SOAP-запит нативним SoapServer у WSDL-режимі.
 */
final class SoapEndpointController
{
    private const WSDL = '/contracts/product-inventory-service.wsdl';

    public function __construct(private readonly ProductSoapHandler $handler)
    {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('GET') || $request->query->has('wsdl')) {
            return new Response((string) file_get_contents(self::WSDL), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
        }

        $server = new \SoapServer(self::WSDL, ['cache_wsdl' => \WSDL_CACHE_NONE]);
        $server->setObject($this->handler);

        ob_start();
        $server->handle($request->getContent());
        $xml = (string) ob_get_clean();

        return new Response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }
}
