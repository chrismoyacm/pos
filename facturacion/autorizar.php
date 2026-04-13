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

function facturacionEnsureList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $keys = array_keys($value);
    $isAssoc = array_keys($keys) !== $keys;
    if ($isAssoc) {
        return [$value];
    }
    return $value;
}

function facturacionBuildAuthorizationXml(array $authorization): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $root = $dom->createElement('autorizacion');
    $dom->appendChild($root);

    $root->appendChild($dom->createElement('estado', (string)($authorization['estado'] ?? '')));
    $root->appendChild($dom->createElement('numeroAutorizacion', (string)($authorization['numeroAutorizacion'] ?? '')));
    $root->appendChild($dom->createElement('fechaAutorizacion', (string)($authorization['fechaAutorizacion'] ?? '')));
    $root->appendChild($dom->createElement('ambiente', (string)($authorization['ambiente'] ?? '')));

    $comprobanteNode = $dom->createElement('comprobante');
    $comprobanteNode->appendChild($dom->createCDATASection((string)($authorization['comprobante'] ?? '')));
    $root->appendChild($comprobanteNode);

    $mensajesNode = $dom->createElement('mensajes');
    $mensajes = facturacionEnsureList(($authorization['mensajes']['mensaje'] ?? $authorization['mensajes'] ?? []));
    foreach ($mensajes as $mensaje) {
        if (!is_array($mensaje)) {
            continue;
        }
        $mensajeNode = $dom->createElement('mensaje');
        foreach (['identificador', 'mensaje', 'informacionAdicional', 'tipo'] as $field) {
            if (!array_key_exists($field, $mensaje)) {
                continue;
            }
            $mensajeNode->appendChild($dom->createElement($field, (string)$mensaje[$field]));
        }
        $mensajesNode->appendChild($mensajeNode);
    }
    $root->appendChild($mensajesNode);

    return $dom->saveXML() ?: '';
}

function consultarAutorizacion(array $document): array
{
    $signature = facturacionLoadSignature();
    $signedPath = (string)($document['files']['signedXml'] ?? '');
    if ($signedPath === '' || !file_exists($signedPath)) {
        throw new RuntimeException('No existe XML firmado para consultar autorizacion.');
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
        facturacionAppendLog('info', 'Autorizacion mock generada', ['documentId' => $document['id'] ?? null]);
        return $document;
    }

    if (!class_exists('SoapClient')) {
        $runtime = 'PHP=' . PHP_VERSION . ' | SAPI=' . PHP_SAPI . ' | BIN=' . PHP_BINARY;
        throw new RuntimeException(
            'No se puede consultar autorizacion del SRI porque la extension SOAP no esta habilitada en el entorno web. '
            . 'Active extension=soap en php.ini de Apache y reinicie el servidor. '
            . '(' . $runtime . ')'
        );
    }

    $soap = new SoapClient(facturacionAuthorizationWsdl((string)($document['environment'] ?? '1')), [
        'trace' => true,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
    ]);

    $response = $soap->autorizacionComprobante(['claveAccesoComprobante' => (string)($document['accessKey'] ?? '')]);
    $responseArray = json_decode(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    if (!is_array($responseArray)) {
        $responseArray = [];
    }
    $document['sri']['response'] = $responseArray;

    $root = (array)($responseArray['RespuestaAutorizacionComprobante'] ?? []);
    $autorizacionesRaw = (array)($root['autorizaciones'] ?? []);
    $autorizaciones = facturacionEnsureList($autorizacionesRaw['autorizacion'] ?? []);

    if ($autorizaciones === []) {
        $document['sri']['authorizationStatus'] = 'PPR';
        $document['status'] = 'sent';
        $document['updatedAt'] = date('c');
        facturacionAppendLog('warning', 'SRI aun no entrega autorizacion (PPR)', [
            'documentId' => $document['id'] ?? null,
            'accessKey' => $document['accessKey'] ?? null,
            'response' => $root,
        ]);
        return $document;
    }

    $authorization = is_array($autorizaciones[0]) ? $autorizaciones[0] : [];
    $estado = strtoupper(trim((string)($authorization['estado'] ?? '')));
    $document['sri']['authorizationStatus'] = $estado === 'AUTORIZADO' ? 'AUT' : ($estado === 'NO AUTORIZADO' ? 'NAT' : ($estado !== '' ? $estado : 'PPR'));
    $document['sri']['authorizationNumber'] = (string)($authorization['numeroAutorizacion'] ?? '');
    $document['sri']['authorizationDate'] = (string)($authorization['fechaAutorizacion'] ?? date('c'));

    if ($estado === 'AUTORIZADO') {
        file_put_contents($authorizedPath, facturacionBuildAuthorizationXml($authorization));
        $document['files']['authorizedXml'] = $authorizedPath;
        $document['status'] = 'authorized';
        facturacionAppendLog('info', 'Comprobante autorizado por SRI', [
            'documentId' => $document['id'] ?? null,
            'authorizationNumber' => $document['sri']['authorizationNumber'],
        ]);
    } elseif ($estado === 'NO AUTORIZADO') {
        $document['status'] = 'error';
        facturacionAppendLog('error', 'Comprobante no autorizado por SRI', [
            'documentId' => $document['id'] ?? null,
            'authorization' => $authorization,
        ]);
    } else {
        $document['status'] = 'sent';
        facturacionAppendLog('warning', 'Comprobante en proceso de autorizacion SRI', [
            'documentId' => $document['id'] ?? null,
            'estado' => $estado,
        ]);
    }

    $document['updatedAt'] = date('c');
    return $document;
}

