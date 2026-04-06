<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function facturacionReceptionWsdl(string $ambiente): string
{
    return $ambiente === '2'
        ? 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl'
        : 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl';
}

function enviarSRI(array $document): array
{
    $signature = facturacionLoadSignature();
    $signedPath = (string)($document['files']['signedXml'] ?? '');
    if ($signedPath === '' || !file_exists($signedPath)) {
        throw new RuntimeException('Primero debe firmarse el XML.');
    }

    if (($signature['signatureMode'] ?? 'mock') === 'mock') {
        $document['sri']['receptionStatus'] = 'RECIBIDA';
        $document['sri']['response'] = ['mode' => 'mock', 'message' => 'Comprobante recibido en modo de prueba'];
        $document['status'] = 'sent';
        $document['updatedAt'] = date('c');
        facturacionAppendLog('info', 'Envío SRI mock ejecutado', ['documentId' => $document['id'] ?? null]);
        return $document;
    }

    $soap = new SoapClient(facturacionReceptionWsdl((string)($document['environment'] ?? '1')), [
        'trace' => true,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
    ]);

    $xml = file_get_contents($signedPath);
    if ($xml === false) {
        throw new RuntimeException('No se pudo leer el XML firmado.');
    }

    $response = $soap->validarComprobante(['xml' => base64_encode($xml)]);
    $document['sri']['receptionStatus'] = 'RECIBIDA';
    $document['sri']['response'] = json_decode(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    $document['status'] = 'sent';
    $document['updatedAt'] = date('c');
    return $document;
}

