<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function generarXMLFactura(array $document): array
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $factura = $dom->createElement('factura');
    $factura->setAttribute('id', 'comprobante');
    $factura->setAttribute('version', '1.1.0');
    $dom->appendChild($factura);

    $infoTributaria = $dom->createElement('infoTributaria');
    $factura->appendChild($infoTributaria);
    $tributaria = [
        'ambiente' => (string)($document['emitter']['ambiente'] ?? '1'),
        'tipoEmision' => (string)($document['emitter']['tipoEmision'] ?? '1'),
        'razonSocial' => (string)($document['emitter']['razonSocial'] ?? ''),
        'nombreComercial' => (string)($document['emitter']['nombreComercial'] ?? ''),
        'ruc' => (string)($document['emitter']['ruc'] ?? ''),
        'claveAcceso' => (string)($document['accessKey'] ?? ''),
        'codDoc' => '01',
        'estab' => (string)($document['estab'] ?? ''),
        'ptoEmi' => (string)($document['ptoEmi'] ?? ''),
        'secuencial' => (string)($document['secuencial'] ?? ''),
        'dirMatriz' => (string)($document['emitter']['dirMatriz'] ?? ''),
    ];
    if ((string)($document['emitter']['agenteRetencion'] ?? '') !== '') {
        $tributaria['agenteRetencion'] = (string)$document['emitter']['agenteRetencion'];
    }
    foreach ($tributaria as $tag => $value) {
        $infoTributaria->appendChild($dom->createElement($tag, $value));
    }

    $infoFactura = $dom->createElement('infoFactura');
    $factura->appendChild($infoFactura);
    $buyer = $document['buyer'] ?? [];
    $totals = $document['totals'] ?? [];
    $baseInfo = [
        'fechaEmision' => (string)($document['issueDate'] ?? ''),
        'dirEstablecimiento' => (string)($document['point']['dirEstablecimiento'] ?? ($document['emitter']['dirEstablecimiento'] ?? '')),
        'obligadoContabilidad' => (string)($document['emitter']['obligadoContabilidad'] ?? 'NO'),
        'tipoIdentificacionComprador' => facturacionMapBuyerIdType((string)($buyer['identificationType'] ?? 'Consumidor final')),
        'razonSocialComprador' => (string)($buyer['razonSocial'] ?? 'Consumidor final'),
        'identificacionComprador' => (string)($buyer['identification'] ?? '9999999999999'),
        'direccionComprador' => (string)($buyer['address'] ?? ''),
        'totalSinImpuestos' => facturacionFormatDecimal((float)($totals['subtotalSinImpuestos'] ?? 0)),
        'totalDescuento' => facturacionFormatDecimal((float)($totals['totalDescuento'] ?? 0)),
    ];
    foreach ($baseInfo as $tag => $value) {
        $infoFactura->appendChild($dom->createElement($tag, $value));
    }

    $totalConImpuestos = $dom->createElement('totalConImpuestos');
    foreach (($totals['taxGroups'] ?? []) as $tax) {
        $totalImpuesto = $dom->createElement('totalImpuesto');
        $totalImpuesto->appendChild($dom->createElement('codigo', (string)($tax['codigo'] ?? '2')));
        $totalImpuesto->appendChild($dom->createElement('codigoPorcentaje', (string)($tax['codigoPorcentaje'] ?? '0')));
        $totalImpuesto->appendChild($dom->createElement('baseImponible', facturacionFormatDecimal((float)($tax['baseImponible'] ?? 0))));
        $totalImpuesto->appendChild($dom->createElement('valor', facturacionFormatDecimal((float)($tax['valor'] ?? 0))));
        $totalConImpuestos->appendChild($totalImpuesto);
    }
    $infoFactura->appendChild($totalConImpuestos);
    $infoFactura->appendChild($dom->createElement('propina', facturacionFormatDecimal((float)($totals['propina'] !== '' ? (float)$totals['propina'] : 0))));
    $infoFactura->appendChild($dom->createElement('importeTotal', facturacionFormatDecimal((float)($totals['importeTotal'] ?? 0))));

    $pagosNode = $dom->createElement('pagos');
    foreach (($document['payments'] ?? []) as $payment) {
        $pagoNode = $dom->createElement('pago');
        $pagoNode->appendChild($dom->createElement('formaPago', (string)($payment['formaPago'] ?? '01')));
        $pagoNode->appendChild($dom->createElement('total', facturacionFormatDecimal((float)($payment['total'] ?? 0))));
        $pagoNode->appendChild($dom->createElement('plazo', (string)($payment['plazo'] ?? 0)));
        $pagoNode->appendChild($dom->createElement('unidadTiempo', (string)($payment['unidadTiempo'] ?? 'dias')));
        $pagosNode->appendChild($pagoNode);
    }
    $infoFactura->appendChild($pagosNode);

    $detallesNode = $dom->createElement('detalles');
    foreach (($document['details'] ?? []) as $detail) {
        $detalleNode = $dom->createElement('detalle');
        $detalleNode->appendChild($dom->createElement('codigoPrincipal', (string)($detail['codigoPrincipal'] ?? '')));
        $detalleNode->appendChild($dom->createElement('codigoAuxiliar', (string)($detail['codigoAuxiliar'] ?? '')));
        $detalleNode->appendChild($dom->createElement('descripcion', (string)($detail['descripcion'] ?? '')));
        $detalleNode->appendChild($dom->createElement('cantidad', facturacionFormatDecimal((float)($detail['cantidad'] ?? 0), 6)));
        $detalleNode->appendChild($dom->createElement('precioUnitario', facturacionFormatDecimal((float)($detail['precioUnitario'] ?? 0), 6)));
        $detalleNode->appendChild($dom->createElement('descuento', facturacionFormatDecimal((float)($detail['descuento'] ?? 0))));
        $detalleNode->appendChild($dom->createElement('precioTotalSinImpuesto', facturacionFormatDecimal((float)($detail['precioTotalSinImpuesto'] ?? 0))));

        $impuestosNode = $dom->createElement('impuestos');
        $impuestoNode = $dom->createElement('impuesto');
        $impuestoNode->appendChild($dom->createElement('codigo', (string)($detail['tax']['codigo'] ?? '2')));
        $impuestoNode->appendChild($dom->createElement('codigoPorcentaje', (string)($detail['tax']['codigoPorcentaje'] ?? '0')));
        $impuestoNode->appendChild($dom->createElement('tarifa', facturacionFormatDecimal((float)($detail['tax']['tarifa'] ?? 0))));
        $impuestoNode->appendChild($dom->createElement('baseImponible', facturacionFormatDecimal((float)($detail['precioTotalSinImpuesto'] ?? 0))));
        $impuestoNode->appendChild($dom->createElement('valor', facturacionFormatDecimal((float)($detail['taxValue'] ?? 0))));
        $impuestosNode->appendChild($impuestoNode);
        $detalleNode->appendChild($impuestosNode);

        $detallesNode->appendChild($detalleNode);
    }
    $factura->appendChild($detallesNode);

    if (!empty($document['additionalFields'])) {
        $infoAdicional = $dom->createElement('infoAdicional');
        foreach ($document['additionalFields'] as $field) {
            $node = $dom->createElement('campoAdicional', (string)($field['value'] ?? ''));
            $node->setAttribute('nombre', (string)($field['name'] ?? ''));
            $infoAdicional->appendChild($node);
        }
        $factura->appendChild($infoAdicional);
    }

    $xmlDir = facturacionStoragePath('xml/generados');
    if (!is_dir($xmlDir)) {
        mkdir($xmlDir, 0777, true);
    }
    $path = $xmlDir . DIRECTORY_SEPARATOR . (string)$document['accessKey'] . '.xml';
    $dom->save($path);

    $document['files']['generatedXml'] = $path;
    $document['status'] = 'xml_generated';
    $document['updatedAt'] = date('c');

    return $document;
}

