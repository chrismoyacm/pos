<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$autoload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

use Dompdf\Dompdf;
use Dompdf\Options;

function generarPDF(array $document): array
{
    $pdfDir = facturacionStoragePath('pdf');
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0777, true);
    }

    $path = $pdfDir . DIRECTORY_SEPARATOR . (string)$document['accessKey'] . '.pdf';
    $buyer = $document['buyer'] ?? [];
    $totals = $document['totals'] ?? [];
    $rows = '';
    foreach (($document['details'] ?? []) as $detail) {
        $rows .= '<tr>'
            . '<td>' . htmlspecialchars((string)($detail['codigoPrincipal'] ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string)($detail['descripcion'] ?? '')) . '</td>'
            . '<td style="text-align:right">' . htmlspecialchars(facturacionFormatDecimal((float)($detail['cantidad'] ?? 0), 2)) . '</td>'
            . '<td style="text-align:right">' . htmlspecialchars(facturacionFormatDecimal((float)($detail['precioUnitario'] ?? 0))) . '</td>'
            . '<td style="text-align:right">' . htmlspecialchars(facturacionFormatDecimal((float)($detail['precioTotalSinImpuesto'] ?? 0))) . '</td>'
            . '</tr>';
    }

    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Factura</title>'
        . '<style>@page{margin:24px}body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#111}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px}th{background:#f3f4f6}h1{font-size:20px;margin:0 0 10px} .meta{margin:0 0 14px}.totals{margin-top:14px}.totals td{border:none;padding:3px 0}.right{text-align:right}</style>'
        . '</head><body>'
        . '<h1>Factura</h1>'
        . '<p class="meta"><strong>Clave de acceso:</strong> ' . htmlspecialchars((string)($document['accessKey'] ?? '')) . '<br>'
        . '<strong>Numero de autorizacion:</strong> ' . htmlspecialchars((string)($document['sri']['authorizationNumber'] ?? '')) . '<br>'
        . '<strong>Fecha de autorizacion:</strong> ' . htmlspecialchars((string)($document['sri']['authorizationDate'] ?? '')) . '</p>'
        . '<p><strong>Cliente:</strong> ' . htmlspecialchars((string)($buyer['razonSocial'] ?? '')) . '<br>'
        . '<strong>Identificacion:</strong> ' . htmlspecialchars((string)($buyer['identification'] ?? '')) . '</p>'
        . '<table><thead><tr><th>Codigo</th><th>Descripcion</th><th>Cantidad</th><th>P. Unitario</th><th>Total</th></tr></thead><tbody>'
        . $rows
        . '</tbody></table>'
        . '<table class="totals">'
        . '<tr><td><strong>Subtotal</strong></td><td class="right">' . htmlspecialchars(facturacionFormatDecimal((float)($totals['subtotalSinImpuestos'] ?? 0))) . '</td></tr>'
        . '<tr><td><strong>IVA</strong></td><td class="right">' . htmlspecialchars(facturacionFormatDecimal((float)(($totals['iva15'] ?? 0) + ($totals['iva12'] ?? 0)))) . '</td></tr>'
        . '<tr><td><strong>Valor a pagar</strong></td><td class="right">' . htmlspecialchars(facturacionFormatDecimal((float)($totals['importeTotal'] ?? 0))) . '</td></tr>'
        . '</table>'
        . '</body></html>';

    if (class_exists(Dompdf::class)) {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($path, $dompdf->output());
        facturacionAppendLog('info', 'Representacion impresa generada en PDF', ['documentId' => $document['id'] ?? null]);
    } else {
        throw new RuntimeException('No se encontro Dompdf para generar el PDF real.');
    }

    $document['files']['pdf'] = $path;
    $document['updatedAt'] = date('c');
    return $document;
}

