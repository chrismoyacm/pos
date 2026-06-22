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
    $signedPath = facturacionMaterializeDocumentFile($document, 'signedXml');
    if ($signedPath === '') {
        throw new RuntimeException('Primero debe firmarse el XML.');
    }

    $validation = facturacionValidateSignedXmlStructure($signedPath);
    if (!($validation['ok'] ?? false)) {
        $errors = array_values(array_filter((array)($validation['errors'] ?? []), static fn($e) => trim((string)$e) !== ''));
        $message = 'El XML firmado no cumple la estructura minima SRI/XAdES-BES: ' . implode(' | ', $errors);
        facturacionAppendLog('error', $message, [
            'documentId' => $document['id'] ?? null,
            'signedXml' => $signedPath,
        ]);
        throw new RuntimeException($message);
    }

    if (!class_exists('SoapClient')) {
        $runtime = 'PHP=' . PHP_VERSION . ' | SAPI=' . PHP_SAPI . ' | BIN=' . PHP_BINARY;
        throw new RuntimeException(
            'No se puede enviar al SRI porque la extension SOAP no esta habilitada en el entorno web. '
            . 'Active extension=soap en php.ini de Apache y reinicie el servidor. '
            . '(' . $runtime . ')'
        );
    }

    $soap = facturacionCreateSoapClient(
        facturacionReceptionWsdl((string)($document['environment'] ?? '1'))
    );

    $xmlPayload = facturacionReadDocumentFile($document, 'signedXml');
    $xml = $xmlPayload['content'] ?? null;
    if ($xml === false) {
        throw new RuntimeException('No se pudo leer el XML firmado.');
    }
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('El XML firmado no esta disponible para enviar al SRI.');
    }

    $response = $soap->validarComprobante(['xml' => $xml]);
    $responseArray = json_decode(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    if (!is_array($responseArray)) {
        $responseArray = [];
    }
    $root = (array)($responseArray['RespuestaRecepcionComprobante'] ?? []);
    $lastRequestXml = method_exists($soap, '__getLastRequest') ? (string)$soap->__getLastRequest() : '';
    $lastResponseXml = method_exists($soap, '__getLastResponse') ? (string)$soap->__getLastResponse() : '';

    $estadoRaw = trim((string)($root['estado'] ?? ''));
    $estado = strtoupper($estadoRaw);

    $document['sri']['receptionStatus'] = $estado;
    $document['sri']['response'] = $responseArray;
    if ($lastRequestXml !== '') {
        $document['sri']['receptionLastRequestXml'] = $lastRequestXml;
    }
    if ($lastResponseXml !== '') {
        $document['sri']['receptionLastResponseXml'] = $lastResponseXml;
    }
    $document['updatedAt'] = date('c');

    $comprobantes = facturacionEnsureList($root['comprobantes']['comprobante'] ?? []);
    $mensajes = [];
    foreach ($comprobantes as $comprobante) {
        if (!is_array($comprobante)) {
            continue;
        }
        $items = facturacionEnsureList($comprobante['mensajes']['mensaje'] ?? []);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mensajes[] = trim((string)($item['mensaje'] ?? ''));
            $info = trim((string)($item['informacionAdicional'] ?? ''));
            if ($info !== '') {
                $mensajes[] = $info;
            }
        }
    }
    $mensajes = array_values(array_filter(array_unique($mensajes), static fn($m) => $m !== ''));

    if ($estado === 'RECIBIDA') {
        $document['status'] = 'sent';
        facturacionAppendLog('info', 'Comprobante recibido por SRI', [
            'documentId' => $document['id'] ?? null,
            'accessKey' => $document['accessKey'] ?? null,
            'lastRequestXml' => $lastRequestXml,
            'lastResponseXml' => $lastResponseXml,
        ]);
        return $document;
    }

    if ($estado === '' && $mensajes !== []) {
        $estado = 'DEVUELTA';
        $document['sri']['receptionStatus'] = $estado;
    }
    $document['status'] = 'error';
    $texto = $mensajes !== [] ? implode(' | ', $mensajes) : 'El SRI devolvio el comprobante en recepcion.';
    facturacionAppendLog('error', 'SRI rechazo comprobante en recepcion', [
        'documentId' => $document['id'] ?? null,
        'accessKey' => $document['accessKey'] ?? null,
        'estado' => $estado,
        'mensajes' => $mensajes,
        'lastRequestXml' => $lastRequestXml,
        'lastResponseXml' => $lastResponseXml,
    ]);
    throw new RuntimeException('SRI recepcion estado ' . $estado . ': ' . $texto);
}
