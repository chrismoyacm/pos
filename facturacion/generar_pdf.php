<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function generarPDF(array $document): array
{
    $pdfDir = facturacionStoragePath('pdf');
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0777, true);
    }

    if (class_exists('TCPDF')) {
        throw new RuntimeException('La integración real con TCPDF aún no está implementada en esta instalación.');
    }

    $path = $pdfDir . DIRECTORY_SEPARATOR . (string)$document['accessKey'] . '.html';
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
        . '<style>body{font-family:Arial,sans-serif;font-size:12px;color:#111}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px}th{background:#f3f4f6}h1{font-size:20px;margin:0 0 10px} .meta{margin:0 0 14px}</style>'
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
        . '<p><strong>Subtotal:</strong> ' . htmlspecialchars(facturacionFormatDecimal((float)($totals['subtotalSinImpuestos'] ?? 0))) . '<br>'
        . '<strong>IVA:</strong> ' . htmlspecialchars(facturacionFormatDecimal((float)(($totals['iva15'] ?? 0) + ($totals['iva12'] ?? 0)))) . '<br>'
        . '<strong>Valor a pagar:</strong> ' . htmlspecialchars(facturacionFormatDecimal((float)($totals['importeTotal'] ?? 0))) . '</p>'
        . '</body></html>';

    file_put_contents($path, $html);
    $document['files']['pdf'] = $path;
    $document['updatedAt'] = date('c');
    facturacionAppendLog('info', 'Representación impresa generada en HTML fallback', ['documentId' => $document['id'] ?? null]);
    return $document;
}

