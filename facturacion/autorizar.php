<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function facturacionAuthorizationWsdl(string $ambiente): string
{
    return $ambiente === '2'
        ? 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'
        : 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl';
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

function consultarAutorizacion(array $document, array $options = []): array
{
    $previousAuth = strtoupper(trim((string)($document['sri']['authorizationStatus'] ?? '')));
    $previousStatus = strtolower(trim((string)($document['status'] ?? '')));
    $signedPath = facturacionMaterializeDocumentFile($document, 'signedXml');
    if ($signedPath === '') {
        throw new RuntimeException('No existe XML firmado para consultar autorizacion.');
    }

    if (!class_exists('SoapClient')) {
        $runtime = 'PHP=' . PHP_VERSION . ' | SAPI=' . PHP_SAPI . ' | BIN=' . PHP_BINARY;
        throw new RuntimeException(
            'No se puede consultar autorizacion del SRI porque la extension SOAP no esta habilitada en el entorno web. '
            . 'Active extension=soap en php.ini de Apache y reinicie el servidor. '
            . '(' . $runtime . ')'
        );
    }

    $soap = facturacionCreateSoapClient(
        facturacionAuthorizationWsdl((string)($document['environment'] ?? '1'))
    );

    // En esquema offline la autorizacion puede tardar algunos segundos/minutos.
    // Para llamadas desde UI usamos una consulta corta para no superar el timeout
    // de FastCGI en IIS. Los reintentos de fondo siguen controlados por opciones.
    $maxAttempts = max(1, (int)($options['maxAttempts'] ?? 3));
    $sleepMicros = max(0, (int)($options['sleepMicros'] ?? 750000));
    $logRawResponse = !empty($options['logRawResponse']);
    $responseArray = [];
    $root = [];
    $autorizaciones = [];
    $lastRequestXml = '';
    $lastResponseXml = '';
    $attempt = 1;
    while ($attempt <= $maxAttempts) {
        $response = $soap->autorizacionComprobante(['claveAccesoComprobante' => (string)($document['accessKey'] ?? '')]);
        if (method_exists($soap, '__getLastRequest')) {
            $lastRequestXml = (string)$soap->__getLastRequest();
        }
        if (method_exists($soap, '__getLastResponse')) {
            $lastResponseXml = (string)$soap->__getLastResponse();
        }
        $responseArray = json_decode(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        if (!is_array($responseArray)) {
            $responseArray = [];
        }
        // El SOAP puede devolver la respuesta envuelta en
        // RespuestaAutorizacionComprobante o directamente con las claves
        // claveAccesoConsultada/numeroComprobantes/autorizaciones.
        // Soportamos ambos formatos para no dejar comprobantes en PPR por parseo.
        $root = (array)($responseArray['RespuestaAutorizacionComprobante'] ?? $responseArray);
        $autorizacionesRaw = (array)($root['autorizaciones'] ?? []);
        $autorizaciones = facturacionEnsureList($autorizacionesRaw['autorizacion'] ?? []);
        if ($autorizaciones !== []) {
            break;
        }
        if ($attempt < $maxAttempts && $sleepMicros > 0) {
            usleep($sleepMicros);
        }
        $attempt += 1;
    }

    $document['sri']['response'] = $responseArray;
    if ($lastRequestXml !== '') {
        $document['sri']['authorizationLastRequestXml'] = $lastRequestXml;
    }
    if ($lastResponseXml !== '') {
        $document['sri']['authorizationLastResponseXml'] = $lastResponseXml;
    }

    if ($autorizaciones === []) {
        if ($previousAuth === 'AUT' || $previousStatus === 'authorized') {
            $document['sri']['authorizationStatus'] = 'AUT';
            $document['status'] = 'authorized';
            $document['updatedAt'] = date('c');
            facturacionAppendLog('warning', 'SRI no devolvio autorizacion en esta consulta; se conserva estado AUT previo', [
                'documentId' => $document['id'] ?? null,
                'accessKey' => $document['accessKey'] ?? null,
                'attempts' => min($attempt, $maxAttempts),
            ]);
            return $document;
        }
        $document['sri']['authorizationStatus'] = 'PPR';
        $document['status'] = 'sent';
        $document['updatedAt'] = date('c');
        facturacionAppendLog('warning', 'SRI aun no entrega autorizacion (PPR)', [
            'documentId' => $document['id'] ?? null,
            'accessKey' => $document['accessKey'] ?? null,
            'attempts' => min($attempt, $maxAttempts),
            'response' => $root,
        ]);
        if ($logRawResponse || ((string)($root['numeroComprobantes'] ?? '') === '0')) {
            facturacionAppendLog('warning', 'Respuesta cruda de autorizacion sin comprobantes', [
                'documentId' => $document['id'] ?? null,
                'accessKey' => $document['accessKey'] ?? null,
                'attempts' => min($attempt, $maxAttempts),
                'numeroComprobantes' => (string)($root['numeroComprobantes'] ?? ''),
                'lastRequestXml' => $lastRequestXml,
                'lastResponseXml' => $lastResponseXml,
            ]);
        }
        return $document;
    }

    $authorization = is_array($autorizaciones[0]) ? $autorizaciones[0] : [];
    $estado = strtoupper(trim((string)($authorization['estado'] ?? '')));
    $document['sri']['authorizationStatus'] = $estado === 'AUTORIZADO'
        ? 'AUT'
        : (in_array($estado, ['NO AUTORIZADO', 'RECHAZADO'], true) ? 'NAT' : ($estado !== '' ? $estado : 'PPR'));
    $document['sri']['authorizationNumber'] = (string)($authorization['numeroAutorizacion'] ?? '');
    $document['sri']['authorizationDate'] = (string)($authorization['fechaAutorizacion'] ?? date('c'));

    if ($estado === 'AUTORIZADO') {
        $authorizedXml = facturacionBuildAuthorizationXml($authorization);
        if ($authorizedXml === '') {
            throw new RuntimeException('No se pudo construir el XML autorizado del SRI.');
        }
        $document = facturacionStoreDocumentFile($document, 'authorizedXml', $authorizedXml);
        $document['status'] = 'authorized';
        facturacionAppendLog('info', 'Comprobante autorizado por SRI', [
            'documentId' => $document['id'] ?? null,
            'authorizationNumber' => $document['sri']['authorizationNumber'],
        ]);
    } elseif (in_array($estado, ['NO AUTORIZADO', 'RECHAZADO'], true)) {
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

