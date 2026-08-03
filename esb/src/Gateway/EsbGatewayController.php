<?php

namespace App\Gateway;

use App\Orchestration\CheckoutOrchestrator;
use App\Registry\ServiceRegistry;
use App\Security\SecurityPolicy;
use App\Security\SecurityPolicyViolation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Єдина точка входу клієнта в систему (ESB):
 *  - GET  /soap?wsdl=<service> → контракт із підміненою адресою на ESB;
 *  - GET  /soap?xsd=canonical-data-model → канонічна модель даних;
 *  - POST /soap → перевірка політики безпеки + content-based routing до сервісу
 *                 або виконання оркестрації Checkout усередині ESB.
 *
 * Сервіси наживо доступні лише всередині docker-мережі — назовні відкрито тільки ESB.
 */
final class EsbGatewayController
{
    private const CANONICAL_XSD = '/contracts/canonical-data-model.xsd';

    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly MessageInspector $inspector,
        private readonly SecurityPolicy $policy,
        private readonly CheckoutOrchestrator $orchestrator,
        #[Autowire('%env(ESB_PUBLIC_ENDPOINT)%')]
        private readonly string $publicEndpoint,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->serveContract($request);
        }

        try {
            $message = $this->inspector->inspect($request->getContent());
            $service = $this->registry->byNamespace($message->namespace)
                ?? throw new \InvalidArgumentException(sprintf('No service is registered for namespace "%s"', $message->namespace));

            $this->policy->enforce($message);
        } catch (SecurityPolicyViolation $e) {
            return $this->fault('env:Sender', $e->getMessage(), 401);
        } catch (\InvalidArgumentException $e) {
            return $this->fault('env:Sender', $e->getMessage(), 400);
        }

        return $this->route($service, $this->inspector->withoutSecurityHeader($request->getContent()));
    }

    /** @param array{name: string, wsdl: string, endpoint: string|null} $service */
    private function route(array $service, string $envelope): Response
    {
        $server = new \SoapServer($service['wsdl'], ['cache_wsdl' => \WSDL_CACHE_NONE]);
        $server->setObject(
            $service['name'] === ServiceRegistry::CHECKOUT
                ? $this->orchestrator
                : new ServiceProxy($service['wsdl'], (string) $service['endpoint']),
        );

        ob_start();
        $server->handle($envelope);

        return new Response((string) ob_get_clean(), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    private function serveContract(Request $request): Response
    {
        if ($request->query->has('xsd')) {
            return new Response((string) file_get_contents(self::CANONICAL_XSD), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
        }

        $service = $this->registry->byName((string) $request->query->get('wsdl', ''));

        if ($service === null) {
            return new Response(
                'Unknown contract. See the service registry at /registry',
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        // Адресу беремо з самого запиту: клієнт із хоста бачить localhost:8080,
        // клієнт усередині мережі — http://esb. Env лишається запасним варіантом.
        $endpoint = $request->getSchemeAndHttpHost().$request->getPathInfo() ?: $this->publicEndpoint;

        return new Response($this->rewriteAddresses($service['wsdl'], $endpoint), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    /**
     * Медіація контракту: клієнт має бачити адресу ESB, а не внутрішнього сервісу,
     * та вміти дотягнути канонічну XSD по HTTP (відносний import ззовні не резолвиться).
     */
    private function rewriteAddresses(string $wsdlPath, string $endpoint): string
    {
        $document = new \DOMDocument();
        $document->load($wsdlPath);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/wsdl/soap/');
        $xpath->registerNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');

        foreach ($xpath->query('//soap:address') as $address) {
            $address->setAttribute('location', $endpoint);
        }
        foreach ($xpath->query('//xsd:import[@schemaLocation]') as $import) {
            $import->setAttribute('schemaLocation', $endpoint.'?xsd=canonical-data-model');
        }

        return (string) $document->saveXML();
    }

    private function fault(string $code, string $message, int $status): Response
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault>'
            .'<faultcode>%s</faultcode><faultstring>%s</faultstring>'
            .'</env:Fault></env:Body></env:Envelope>',
            htmlspecialchars($code, \ENT_XML1),
            htmlspecialchars($message, \ENT_XML1),
        );

        return new Response($xml, $status, ['Content-Type' => 'text/xml; charset=utf-8']);
    }
}
