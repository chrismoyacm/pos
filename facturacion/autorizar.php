<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function facturacionAuthorizationWsdl(string $ambiente): string
{
    return $ambiente === '2'
        ? 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'
        : 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl';
}

function facturacionMockAuthorizationXml(array $document, string $signedXml): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $auth = $dom->createElement('autorizacion');
    $dom->appendChild($auth);
    $auth->appendChild($dom->createElement('estado', 'AUTORIZADO'));
    $auth->appendChild($dom->createElement('numeroAutorizacion', (string)($document['accessKey'] ?? '')));
    $auth->appendChild($dom->createElement('fechaAutorizacion', date('c')));
    $auth->appendChild($dom->createElement('ambiente', ((string)($document['environment'] ?? '1')) === '2' ? 'PRODUCCION' : 'PRUEBAS'));
    $comp = $dom->createElement('comprobante');
    $comp->appendChild($dom->createCDATASection($signedXml));
    $auth->appendChild($comp);
    $auth->appendChild($dom->createElement('mensajes'));
    return $dom->saveXML() ?: '';
}

function consultarAutorizacion(array $document): array
{
    $signature = facturacionLoadSignature();
    $signedPath = (string)($document['files']['signedXml'] ?? '');
    if ($signedPath === '' || !file_exists($signedPath)) {
        throw new RuntimeException('No existe XML firmado para consultar autorización.');
    }

    $authorizedDir = facturacionStoragePath('xml/autorizados');
    if (!is_dir($authorizedDir)) {
        mkdir($authorizedDir, 0777, true);
    }
    $authorizedPath = $authorizedDir . DIRECTORY_SEPARATOR . (string)$document['accessKey'] . '.xml';

    if (($signature['signatureMode'] ?? 'mock') === 'mock') {
        $signedXml = file_get_contents($signedPath);
        if ($signedXml === false) {
            throw new RuntimeException('No se pudo leer el XML firmado.');
        }
        file_put_contents($authorizedPath, facturacionMockAuthorizationXml($document, $signedXml));
        $document['files']['authorizedXml'] = $authorizedPath;
        $document['sri']['authorizationStatus'] = 'AUT';
        $document['sri']['authorizationNumber'] = (string)($document['accessKey'] ?? '');
        $document['sri']['authorizationDate'] = date('c');
        $document['status'] = 'authorized';
        $document['updatedAt'] = date('c');
        facturacionAppendLog('info', 'Autorización mock generada', ['documentId' => $document['id'] ?? null]);
        return $document;
    }

    $soap = new SoapClient(facturacionAuthorizationWsdl((string)($document['environment'] ?? '1')), [
        'trace' => true,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
    ]);

    $response = $soap->autorizacionComprobante(['claveAccesoComprobante' => (string)($document['accessKey'] ?? '')]);
    $document['sri']['response'] = json_decode(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    $document['updatedAt'] = date('c');
    return $document;
}

