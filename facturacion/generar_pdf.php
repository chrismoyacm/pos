<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use Dompdf\Dompdf;
use Dompdf\Options;

function facturacionPdfEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function facturacionPdfPaymentLabel(array $payment): string
{
    $label = trim((string)($payment['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return match ((string)($payment['formaPago'] ?? '01')) {
        '01' => 'SIN UTILIZACION DEL SISTEMA FINANCIERO',
        '15' => 'COMPENSACION DE DEUDAS',
        '16' => 'TARJETA DE DEBITO',
        '17' => 'DINERO ELECTRONICO',
        '18' => 'TARJETA PREPAGO',
        '19' => 'TARJETA DE CREDITO',
        '20' => 'OTROS CON UTILIZACION DEL SISTEMA FINANCIERO',
        '21' => 'ENDOSO DE TITULOS',
        default => (string)($payment['formaPago'] ?? '01'),
    };
}

function facturacionPdfAccessKeyLabel(array $document): string
{
    return trim((string)($document['sri']['authorizationNumber'] ?? ($document['accessKey'] ?? '')));
}

function facturacionPdfBuildTotalsRows(array $totals): string
{
    $rows = [
        ['SUBTOTAL SIN IMPUESTO', (float)($totals['subtotalSinImpuestos'] ?? 0)],
        ['DESCUENTO', (float)($totals['displayTotalDescuento'] ?? $totals['totalDescuento'] ?? 0)],
        ['SUBTOTAL IVA 0%', (float)($totals['subtotal0'] ?? 0)],
        ['SUBTOTAL IVA 15%', (float)($totals['subtotal15'] ?? 0)],
        ['IVA 15%', (float)($totals['iva15'] ?? 0)],
        ['PROPINA', (($totals['propina'] ?? '') !== '' ? (float)$totals['propina'] : 0.0)],
        ['VALOR TOTAL', (float)($totals['importeTotal'] ?? 0), true],
    ];

    $html = '';
    foreach ($rows as $row) {
        $isStrong = !empty($row[2]);
        $label = facturacionPdfEscape((string)$row[0]);
        $value = '$' . facturacionPdfEscape(facturacionFormatDecimal((float)$row[1]));
        $html .= '<tr>'
            . '<td>' . ($isStrong ? '<strong>' . $label . '</strong>' : $label) . '</td>'
            . '<td class="num">' . ($isStrong ? '<strong>' . $value . '</strong>' : $value) . '</td>'
            . '</tr>';
    }
    return $html;
}

function facturacionPdfBuildHtml(array $document): string
{
    $buyer = is_array($document['buyer'] ?? null) ? $document['buyer'] : [];
    $totals = is_array($document['totals'] ?? null) ? $document['totals'] : [];
    $emitter = is_array($document['emitter'] ?? null) ? $document['emitter'] : [];
    $details = is_array($document['details'] ?? null) ? $document['details'] : [];
    $payments = is_array($document['payments'] ?? null) ? $document['payments'] : [];
    $additionalFields = is_array($document['additionalFields'] ?? null) ? $document['additionalFields'] : [];

    $issueDate = trim((string)($document['issueDate'] ?? ''));
    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $issueDate) === 1) {
        $issueDate = str_replace('-', '/', $issueDate);
    }

    $environmentLabel = ((string)($document['environment'] ?? '1') === '2') ? 'Produccion' : 'Pruebas';
    $authorizationDate = trim((string)($document['sri']['authorizationDate'] ?? ''));
    $accessKeyLabel = facturacionPdfAccessKeyLabel($document);
    $identificationType = strtoupper((string)($buyer['identificationType'] ?? 'Consumidor final'));
    $identificationCode = facturacionMapBuyerIdType((string)($buyer['identificationType'] ?? 'Consumidor final'));

    $detailRows = '';
    foreach ($details as $detail) {
        if (!is_array($detail)) {
            continue;
        }
        $qty = (float)($detail['cantidad'] ?? 0);
        $displayUnit = (float)($detail['precioVenta'] ?? $detail['precioUnitario'] ?? 0);
        $displayDiscount = (float)($detail['descuentoVenta'] ?? $detail['descuento'] ?? 0);
        $displayTotal = max(0.0, (float)($detail['totalVenta'] ?? ($qty * $displayUnit)) - $displayDiscount);
        $detailRows .= '<tr>'
            . '<td class="num">' . facturacionPdfEscape(facturacionFormatDecimal($qty, 2)) . '</td>'
            . '<td>' . facturacionPdfEscape((string)($detail['descripcion'] ?? '')) . '</td>'
            . '<td class="num">$' . facturacionPdfEscape(facturacionFormatDecimal($displayUnit)) . '</td>'
            . '<td class="num">$' . facturacionPdfEscape(facturacionFormatDecimal($displayDiscount)) . '</td>'
            . '<td class="num">$' . facturacionPdfEscape(facturacionFormatDecimal($displayTotal)) . '</td>'
            . '</tr>';
    }
    if ($detailRows === '') {
        $detailRows = '<tr><td colspan="5" class="empty">Sin detalles</td></tr>';
    }

    $paymentRows = '';
    foreach ($payments as $payment) {
        if (!is_array($payment)) {
            continue;
        }
        $paymentRows .= '<tr>'
            . '<td>' . facturacionPdfEscape(facturacionPdfPaymentLabel($payment)) . '</td>'
            . '<td class="num">$' . facturacionPdfEscape(facturacionFormatDecimal((float)($payment['total'] ?? 0))) . '</td>'
            . '<td class="num">' . facturacionPdfEscape(trim((string)($payment['plazo'] ?? ''))) . '</td>'
            . '<td>' . facturacionPdfEscape(trim((string)($payment['unidadTiempo'] ?? ''))) . '</td>'
            . '</tr>';
    }
    if ($paymentRows === '') {
        $paymentRows = '<tr><td colspan="4" class="empty">Sin informacion de pago</td></tr>';
    }

    $additionalRows = '';
    foreach ($additionalFields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = trim((string)($field['name'] ?? ''));
        $value = trim((string)($field['value'] ?? ''));
        if ($name === '' || $value === '') {
            continue;
        }
        $additionalRows .= '<tr><td class="label">' . facturacionPdfEscape(strtoupper($name)) . '</td><td>' . facturacionPdfEscape($value) . '</td></tr>';
    }
    if ($additionalRows === '') {
        if (trim((string)($buyer['email'] ?? '')) !== '') {
            $additionalRows .= '<tr><td class="label">EMAIL CLIENTE</td><td>' . facturacionPdfEscape((string)$buyer['email']) . '</td></tr>';
        }
        if (trim((string)($buyer['phone'] ?? '')) !== '') {
            $additionalRows .= '<tr><td class="label">TELEFONO</td><td>' . facturacionPdfEscape((string)$buyer['phone']) . '</td></tr>';
        }
        $additionalRows .= '<tr><td class="label">NOTA</td><td>CONTRIBUYENTE REGIMEN GENERAL</td></tr>';
    }

    $totalsHtml = facturacionPdfBuildTotalsRows($totals);

    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Factura</title>'
        . '<style>'
        . '@page{margin:20px 24px;}'
        . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#111;line-height:1.28;}'
        . '.note{font-size:9px;text-align:center;margin:0 0 8px 0;padding:2px 0;}'
        . '.top{width:100%;border-collapse:collapse;margin-bottom:8px;}'
        . '.top td{vertical-align:top;}'
        . '.left-col{width:57%;padding-right:14px;}'
        . '.right-col{width:43%;}'
        . '.box{border:1px solid #222;padding:8px 10px;}'
        . '.box .title{font-size:16px;font-weight:700;margin-bottom:3px;}'
        . '.box .ruc{font-size:12px;font-weight:700;margin-bottom:5px;}'
        . '.line{margin:2px 0;}'
        . '.section-title{font-size:11px;font-weight:700;margin:7px 0 4px 0;}'
        . '.plain{width:100%;border-collapse:collapse;}'
        . '.plain td{padding:2px 4px 2px 0;vertical-align:top;}'
        . '.label{font-weight:700;width:145px;}'
        . '.grid{width:100%;border-collapse:collapse;margin-top:5px;}'
        . '.grid th,.grid td{border:1px solid #444;padding:5px 6px;font-size:9px;}'
        . '.grid th{background:#efefef;text-align:left;}'
        . '.num{text-align:right;}'
        . '.bottom{width:100%;border-collapse:collapse;margin-top:8px;}'
        . '.bottom td{vertical-align:top;}'
        . '.payments-col{width:58%;padding-right:16px;}'
        . '.totals-col{width:42%;}'
        . '.totals{width:100%;border-collapse:collapse;}'
        . '.totals td{padding:3px 0;border:none;font-size:10px;}'
        . '.totals .num{width:120px;}'
        . '.empty{text-align:center;color:#666;padding:10px 0;}'
        . '</style>'
        . '</head><body>'
        . '<div class="note">Este es una Representacion Impresa de un Documento Electronico</div>'
        . '<table class="top"><tr>'
        . '<td class="left-col">'
        . '<div class="section-title">INFORMACION DEL EMISOR</div>'
        . '<table class="plain">'
        . '<tr><td colspan="2"><strong>' . facturacionPdfEscape((string)($emitter['razonSocial'] ?? '')) . '</strong></td></tr>'
        . '<tr><td colspan="2">' . facturacionPdfEscape((string)($emitter['nombreComercial'] ?? '')) . '</td></tr>'
        . '<tr><td class="label">RUC:</td><td>' . facturacionPdfEscape((string)($emitter['ruc'] ?? '')) . '</td></tr>'
        . '<tr><td class="label">DIRECCION MATRIZ:</td><td>' . facturacionPdfEscape((string)($emitter['dirMatriz'] ?? '')) . '</td></tr>'
        . '</table>'
        . '</td>'
        . '<td class="right-col">'
        . '<div class="box">'
        . '<div class="title">01 FACTURA</div>'
        . '<div class="ruc">R.U.C. ' . facturacionPdfEscape((string)($emitter['ruc'] ?? '')) . '</div>'
        . '<div class="line"><strong>Factura N&deg;</strong> ' . facturacionPdfEscape((string)($document['estab'] ?? '') . '-' . (string)($document['ptoEmi'] ?? '') . '-' . (string)($document['secuencial'] ?? '')) . '</div>'
        . '<div class="line"><strong>CLAVE DE ACCESO - No. AUTORIZACION</strong><br>' . facturacionPdfEscape($accessKeyLabel) . '</div>'
        . '<div class="line"><strong>Fecha Emision</strong> ' . facturacionPdfEscape($issueDate) . '</div>'
        . '<div class="line"><strong>Fecha Autorizacion</strong> ' . facturacionPdfEscape($authorizationDate) . '</div>'
        . '<div class="line"><strong>Ambiente</strong> ' . facturacionPdfEscape($environmentLabel) . '</div>'
        . '<div class="line"><strong>Emision</strong> Normal</div>'
        . '</div>'
        . '</td>'
        . '</tr></table>'
        . '<div class="section-title">INFORMACION DEL RECEPTOR</div>'
        . '<table class="plain">'
        . '<tr><td class="label">RAZON SOCIAL:</td><td>' . facturacionPdfEscape((string)($buyer['razonSocial'] ?? '')) . '</td><td class="label">IDENTIFICACION:</td><td>' . facturacionPdfEscape((string)($buyer['identification'] ?? '')) . '</td></tr>'
        . '<tr><td class="label">DIRECCION:</td><td>' . facturacionPdfEscape((string)($buyer['address'] ?? '')) . '</td><td class="label">TIPO IDENTIFICACION:</td><td>' . facturacionPdfEscape($identificationCode . ' ' . $identificationType) . '</td></tr>'
        . '</table>'
        . '<table class="grid"><thead><tr><th class="num">CANT.</th><th>DESCRIPCION</th><th class="num">P. UNIT</th><th class="num">DESC.</th><th class="num">TOTAL</th></tr></thead><tbody>'
        . $detailRows
        . '</tbody></table>'
        . '<table class="bottom"><tr>'
        . '<td class="payments-col">'
        . '<div class="section-title">Forma de pago</div>'
        . '<table class="grid"><thead><tr><th>Forma de pago</th><th class="num">Valor</th><th class="num">Plazo</th><th>Unidad de tiempo</th></tr></thead><tbody>'
        . $paymentRows
        . '</tbody></table>'
        . '<div class="section-title">Informacion adicional</div>'
        . '<table class="plain">' . $additionalRows . '</table>'
        . '</td>'
        . '<td class="totals-col">'
        . '<table class="totals">' . $totalsHtml . '</table>'
        . '</td>'
        . '</tr></table>'
        . '</body></html>';
}

function generarPDF(array $document): array
{
    $html = facturacionPdfBuildHtml($document);

    if (!class_exists(Dompdf::class)) {
        throw new RuntimeException('No se encontro Dompdf para generar el PDF real.');
    }

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfBinary = $dompdf->output();
    if (!is_string($pdfBinary) || $pdfBinary === '') {
        throw new RuntimeException('No se pudo renderizar el PDF de la factura.');
    }

    facturacionAppendLog('info', 'Representacion impresa generada en PDF', ['documentId' => $document['id'] ?? null]);
    $document = facturacionStoreDocumentFile($document, 'pdf', $pdfBinary);
    $document['updatedAt'] = date('c');
    return $document;
}
