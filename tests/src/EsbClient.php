<?php

namespace E2E;

/**
 * Клієнт системи в тому вигляді, в якому його бачить зовнішній світ:
 * знає лише адресу ESB, контракти тягне звідти ж, безпеку кладе у <wsse:Security>.
 */
final class EsbClient
{
    public const WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    public const WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    private string $endpoint;

    public function __construct(?string $endpoint = null)
    {
        $this->endpoint = $endpoint ?? (getenv('ESB_ENDPOINT') ?: 'http://esb/soap');
    }

    /** SOAP-клієнт за контрактом, який віддає сам ESB. */
    public function forContract(string $contract, ?string $token = null): \SoapClient
    {
        $client = new \SoapClient($this->endpoint.'?wsdl='.$contract, [
            'cache_wsdl' => \WSDL_CACHE_NONE,
            'exceptions' => true,
        ]);

        if ($token !== null) {
            $client->__setSoapHeaders([self::securityHeader($token)]);
        }

        return $client;
    }

    /** WS-Security: токен ідентичності від IAM + timestamp проти replay. */
    public static function securityHeader(string $token): \SoapHeader
    {
        $xml = sprintf(
            '<wsse:Security xmlns:wsse="%s" xmlns:wsu="%s">'
            .'<wsu:Timestamp><wsu:Created>%s</wsu:Created><wsu:Expires>%s</wsu:Expires></wsu:Timestamp>'
            .'<wsse:BinarySecurityToken>%s</wsse:BinarySecurityToken></wsse:Security>',
            self::WSSE,
            self::WSU,
            gmdate('Y-m-d\TH:i:s\Z'),
            gmdate('Y-m-d\TH:i:s\Z', time() + 300),
            $token,
        );

        return new \SoapHeader(self::WSSE, 'Security', new \SoapVar($xml, \XSD_ANYXML), true);
    }

    /** @return array<string, mixed> */
    public function registry(): array
    {
        $url = getenv('ESB_REGISTRY') ?: 'http://esb/registry';

        return json_decode((string) file_get_contents($url), true) ?: [];
    }
}
