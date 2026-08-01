<?php

namespace App\Gateway;

/**
 * Розбирає SOAP-конверт на вході в ESB: що за операція (для content-based routing)
 * і що лежить у <wsse:Security> (для перевірки політики безпеки).
 */
final class MessageInspector
{
    private const NS_ENVELOPE = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';

    public function inspect(string $xml): InspectedMessage
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if ($xml === '' || !$document->loadXML($xml)) {
                throw new \InvalidArgumentException('Request body is not a valid SOAP envelope');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('env', self::NS_ENVELOPE);
        $xpath->registerNamespace('wsse', self::NS_WSSE);
        $xpath->registerNamespace('wsu', self::NS_WSU);

        $body = $xpath->query('/env:Envelope/env:Body/*')->item(0);

        if (!$body instanceof \DOMElement) {
            throw new \InvalidArgumentException('SOAP body carries no operation element');
        }

        return new InspectedMessage(
            namespace: (string) $body->namespaceURI,
            operation: preg_replace('/Request$/', '', $body->localName) ?? $body->localName,
            token: $this->text($xpath, '//wsse:Security/wsse:BinarySecurityToken')
                ?? $this->text($xpath, '//wsse:Security/wsse:UsernameToken/wsse:Password'),
            username: $this->text($xpath, '//wsse:Security/wsse:UsernameToken/wsse:Username'),
            createdAt: $this->text($xpath, '//wsse:Security/wsu:Timestamp/wsu:Created'),
            expiresAt: $this->text($xpath, '//wsse:Security/wsu:Timestamp/wsu:Expires'),
        );
    }

    /**
     * ESB — WS-Security intermediary: політику він уже перевірив, тож заголовок
     * <wsse:Security> знімається й далі до сервісу не йде (інакше `mustUnderstand`
     * дійшов би до адресата, який про безпеку нічого не знає).
     */
    public function withoutSecurityHeader(string $xml): string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (!$document->loadXML($xml)) {
                return $xml;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('env', self::NS_ENVELOPE);
        $xpath->registerNamespace('wsse', self::NS_WSSE);

        foreach (iterator_to_array($xpath->query('//wsse:Security')) as $header) {
            $header->parentNode?->removeChild($header);
        }
        foreach (iterator_to_array($xpath->query('/env:Envelope/env:Header')) as $header) {
            if (!$header->hasChildNodes()) {
                $header->parentNode?->removeChild($header);
            }
        }

        return (string) $document->saveXML();
    }

    private function text(\DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)->item(0);
        $value = $node?->textContent;

        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
