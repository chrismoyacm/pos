<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

function facturacionStorageDir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'facturacion';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function facturacionStoragePath(string $relative): string
{
    return facturacionStorageDir() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function facturacionReadJson(string $relative, array $default = []): array
{
    $path = facturacionStoragePath($relative);
    if (!file_exists($path)) {
        return $default;
    }
    $data = readJsonFile($path);
    return $data === [] ? $default : $data;
}

function facturacionWriteJson(string $relative, array $data): void
{
    writeJsonFile(facturacionStoragePath($relative), $data);
}

function facturacionAppendLog(string $level, string $message, array $context = []): void
{
    $rows = facturacionReadJson('logs.json', []);
    $rows[] = [
        'id' => 'log-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'createdAt' => date('c'),
    ];
    if (count($rows) > 3000) {
        $rows = array_slice($rows, -3000);
    }
    facturacionWriteJson('logs.json', $rows);
}

function facturacionDefaultEmitter(): array
{
    return [
        'ambiente' => '1',
        'tipoEmision' => '1',
        'razonSocial' => '',
        'nombreComercial' => '',
        'ruc' => '',
        'dirMatriz' => '',
        'dirEstablecimiento' => '',
        'obligadoContabilidad' => 'NO',
        'agenteRetencion' => '',
        'contribuyenteRimpe' => '',
        'email' => '',
        'telefono' => '',
    ];
}

function facturacionDefaultSignature(): array
{
    return [
        'signatureMode' => 'mock',
        'certificatePath' => '',
        'certificatePassword' => '',
        'emailMode' => 'mock',
        'smtpHost' => '',
        'smtpPort' => '587',
        'smtpUser' => '',
        'smtpPassword' => '',
        'fromEmail' => '',
        'fromName' => '',
    ];
}

function facturacionLoadEmitter(): array
{
    return array_merge(facturacionDefaultEmitter(), facturacionReadJson('emisor.json', []));
}

function facturacionSaveEmitter(array $data): array
{
    $current = facturacionLoadEmitter();
    $next = array_merge($current, [
        'ambiente' => in_array((string)($data['ambiente'] ?? '1'), ['1', '2'], true) ? (string)$data['ambiente'] : '1',
        'tipoEmision' => '1',
        'razonSocial' => trim((string)($data['razonSocial'] ?? '')),
        'nombreComercial' => trim((string)($data['nombreComercial'] ?? '')),
        'ruc' => preg_replace('/\D+/', '', (string)($data['ruc'] ?? '')),
        'dirMatriz' => trim((string)($data['dirMatriz'] ?? '')),
        'dirEstablecimiento' => trim((string)($data['dirEstablecimiento'] ?? '')),
        'obligadoContabilidad' => strtoupper(trim((string)($data['obligadoContabilidad'] ?? 'NO'))) === 'SI' ? 'SI' : 'NO',
        'agenteRetencion' => trim((string)($data['agenteRetencion'] ?? '')),
        'contribuyenteRimpe' => trim((string)($data['contribuyenteRimpe'] ?? '')),
        'email' => trim((string)($data['email'] ?? '')),
        'telefono' => trim((string)($data['telefono'] ?? '')),
    ]);

    facturacionWriteJson('emisor.json', $next);
    return $next;
}

function facturacionLoadSignature(): array
{
    return array_merge(facturacionDefaultSignature(), facturacionReadJson('firma.json', []));
}

function facturacionSaveSignature(array $data): array
{
    $current = facturacionLoadSignature();
    $next = array_merge($current, [
        'signatureMode' => in_array((string)($data['signatureMode'] ?? 'mock'), ['mock', 'real'], true) ? (string)$data['signatureMode'] : 'mock',
        'certificatePath' => trim((string)($data['certificatePath'] ?? '')),
        'certificatePassword' => (string)($data['certificatePassword'] ?? ''),
        'emailMode' => in_array((string)($data['emailMode'] ?? 'mock'), ['mock', 'smtp'], true) ? (string)$data['emailMode'] : 'mock',
        'smtpHost' => trim((string)($data['smtpHost'] ?? '')),
        'smtpPort' => trim((string)($data['smtpPort'] ?? '587')),
        'smtpUser' => trim((string)($data['smtpUser'] ?? '')),
        'smtpPassword' => (string)($data['smtpPassword'] ?? ''),
        'fromEmail' => trim((string)($data['fromEmail'] ?? '')),
        'fromName' => trim((string)($data['fromName'] ?? '')),
    ]);

    facturacionWriteJson('firma.json', $next);
    return $next;
}

function facturacionLoadPoints(): array
{
    return facturacionReadJson('puntos.json', []);
}

function facturacionSavePoint(array $data): array
{
    $rows = facturacionLoadPoints();
    $id = trim((string)($data['id'] ?? ''));
    $point = [
        'id' => $id !== '' ? $id : 'pto-' . str_pad((string)(count($rows) + 1), 3, '0', STR_PAD_LEFT),
        'estab' => str_pad(substr(preg_replace('/\D+/', '', (string)($data['estab'] ?? '1')), 0, 3), 3, '0', STR_PAD_LEFT),
        'ptoEmi' => str_pad(substr(preg_replace('/\D+/', '', (string)($data['ptoEmi'] ?? '1')), 0, 3), 3, '0', STR_PAD_LEFT),
        'nombre' => trim((string)($data['nombre'] ?? 'Punto de emision')),
        'dirEstablecimiento' => trim((string)($data['dirEstablecimiento'] ?? '')),
        'secuencialActual' => max(0, (int)($data['secuencialActual'] ?? 0)),
        'active' => !isset($data['active']) || (bool)$data['active'],
        'updatedAt' => date('c'),
    ];

    $updated = false;
    foreach ($rows as $index => $row) {
        if ((string)($row['id'] ?? '') !== $point['id']) {
            continue;
        }
        $rows[$index] = array_merge($row, $point);
        $updated = true;
        break;
    }
    if (!$updated) {
        $rows[] = $point;
    }

    facturacionWriteJson('puntos.json', $rows);
    return $point;
}

function facturacionDeletePoint(string $id): bool
{
    $rows = facturacionLoadPoints();
    $before = count($rows);
    $rows = array_values(array_filter($rows, static fn($row) => (string)($row['id'] ?? '') !== $id));
    facturacionWriteJson('puntos.json', $rows);
    return count($rows) < $before;
}

function facturacionLoadProductServices(): array
{
    return facturacionReadJson('productos_servicios.json', []);
}

function facturacionSaveProductService(array $data): array
{
    $rows = facturacionLoadProductServices();
    $productId = trim((string)($data['productId'] ?? ''));
    if ($productId === '') {
        throw new InvalidArgumentException('Producto requerido');
    }

    $record = [
        'productId' => $productId,
        'codigoPrincipal' => trim((string)($data['codigoPrincipal'] ?? '')),
        'codigoAuxiliar' => trim((string)($data['codigoAuxiliar'] ?? '')),
        'unidadMedida' => trim((string)($data['unidadMedida'] ?? '')),
        'iceCode' => trim((string)($data['iceCode'] ?? '')),
        'iceValue' => round((float)($data['iceValue'] ?? 0), 2),
        'updatedAt' => date('c'),
    ];

    $updated = false;
    foreach ($rows as $index => $row) {
        if ((string)($row['productId'] ?? '') !== $productId) {
            continue;
        }
        $rows[$index] = array_merge($row, $record);
        $updated = true;
        break;
    }
    if (!$updated) {
        $rows[] = $record;
    }

    facturacionWriteJson('productos_servicios.json', $rows);
    return $record;
}

function facturacionSyncProductServicesFromProducts(): array
{
    $products = readJsonFile(storagePath('products.json'));
    $current = facturacionLoadProductServices();
    $map = [];
    foreach ($current as $row) {
        $map[(string)($row['productId'] ?? '')] = $row;
    }

    foreach ($products as $product) {
        $productId = (string)($product['id'] ?? '');
        if ($productId === '') {
            continue;
        }
        if (isset($map[$productId])) {
            continue;
        }
        $map[$productId] = [
            'productId' => $productId,
            'codigoPrincipal' => trim((string)($product['barcode'] ?? $productId)),
            'codigoAuxiliar' => trim((string)($product['barcode'] ?? '')),
            'unidadMedida' => 'UNI',
            'iceCode' => '',
            'iceValue' => 0,
            'updatedAt' => date('c'),
        ];
    }

    $rows = array_values($map);
    facturacionWriteJson('productos_servicios.json', $rows);
    return $rows;
}

function facturacionLoadDocuments(): array
{
    return facturacionReadJson('documentos.json', []);
}

function facturacionSaveDocuments(array $rows): void
{
    facturacionWriteJson('documentos.json', $rows);
}

function facturacionNextDocumentId(array $rows): string
{
    $max = 0;
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        if (preg_match('/^doc-(\d+)$/', $id, $matches) === 1) {
            $max = max($max, (int)$matches[1]);
        }
    }
    return 'doc-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

function facturacionModulo11(string $cadena): int
{
    $factor = 2;
    $suma = 0;
    for ($i = strlen($cadena) - 1; $i >= 0; $i -= 1) {
        $suma += ((int)$cadena[$i]) * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $dv = 11 - ($suma % 11);
    if ($dv === 11) {
        return 0;
    }
    if ($dv === 10) {
        return 1;
    }
    return $dv;
}

function facturacionGenerateAccessKey(string $fecha, string $codDoc, string $ruc, string $ambiente, string $estab, string $ptoEmi, string $secuencial, string $codigoNumerico, string $tipoEmision): string
{
    $fechaBase = preg_replace('/\D+/', '', $fecha);
    if (strlen($fechaBase) === 8 && str_contains($fecha, '-')) {
        $fechaBase = substr($fechaBase, 6, 2) . substr($fechaBase, 4, 2) . substr($fechaBase, 0, 4);
    }
    $fechaBase = str_pad(substr($fechaBase, 0, 8), 8, '0', STR_PAD_RIGHT);
    $base = $fechaBase
        . str_pad($codDoc, 2, '0', STR_PAD_LEFT)
        . str_pad(substr($ruc, 0, 13), 13, '0', STR_PAD_LEFT)
        . $ambiente
        . str_pad($estab, 3, '0', STR_PAD_LEFT)
        . str_pad($ptoEmi, 3, '0', STR_PAD_LEFT)
        . str_pad($secuencial, 9, '0', STR_PAD_LEFT)
        . str_pad(substr($codigoNumerico, 0, 8), 8, '0', STR_PAD_LEFT)
        . $tipoEmision;

    return $base . facturacionModulo11($base);
}

function facturacionMapBuyerIdType(string $type): string
{
    $normalized = strtolower(trim($type));
    return match ($normalized) {
        'ruc' => '04',
        'cedula', 'cédula' => '05',
        'pasaporte' => '06',
        'consumidor final', 'publico', 'público', 'publico en general', 'consumidor' => '07',
        default => '05',
    };
}

function facturacionMapPaymentCode(string $method): string
{
    $normalized = strtolower(trim($method));
    return match ($normalized) {
        'cash', 'efectivo' => '01',
        'card', 'credit_card', 'tarjeta de crédito', 'tarjeta credito' => '19',
        'debit_card', 'tarjeta de débito', 'tarjeta debito' => '16',
        'credit', 'credito', 'a credito' => '20',
        'transfer', 'transferencia' => '15',
        'check', 'cheque' => '20',
        default => '01',
    };
}

function facturacionTaxDefinition(string $iva): array
{
    $normalized = trim($iva);
    if ($normalized === '15%') {
        return ['codigo' => '2', 'codigoPorcentaje' => '4', 'tarifa' => 15.0];
    }
    if ($normalized === '12%') {
        return ['codigo' => '2', 'codigoPorcentaje' => '2', 'tarifa' => 12.0];
    }
    return ['codigo' => '2', 'codigoPorcentaje' => '0', 'tarifa' => 0.0];
}

function facturacionRound(float $value): float
{
    return round($value, 2);
}

function facturacionFormatDecimal(float $value, int $scale = 2): string
{
    return number_format($value, $scale, '.', '');
}

function facturacionBuildInvoiceDocument(array $payload): array
{
    $emitter = facturacionLoadEmitter();
    $points = facturacionLoadPoints();
    $services = facturacionLoadProductServices();
    $documents = facturacionLoadDocuments();

    $pointId = trim((string)($payload['pointId'] ?? ''));
    $point = null;
    foreach ($points as $candidate) {
        if ((string)($candidate['id'] ?? '') === $pointId) {
            $point = $candidate;
            break;
        }
    }
    if ($point === null && !empty($points)) {
        $point = $points[0];
    }
    if ($point === null) {
        throw new RuntimeException('Debe configurar al menos un punto de emision antes de facturar.');
    }

    $issueDateUi = trim((string)($payload['issueDate'] ?? date('d-m-Y')));
    $issueDateKey = preg_match('/^\d{2}-\d{2}-\d{4}$/', $issueDateUi) === 1
        ? substr($issueDateUi, 6, 4) . '-' . substr($issueDateUi, 3, 2) . '-' . substr($issueDateUi, 0, 2)
        : date('Y-m-d');

    $secuencial = str_pad((string)(((int)($point['secuencialActual'] ?? 0)) + 1), 9, '0', STR_PAD_LEFT);
    $codigoNumerico = str_pad((string)random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
    $accessKey = facturacionGenerateAccessKey($issueDateKey, '01', (string)$emitter['ruc'], (string)$emitter['ambiente'], (string)$point['estab'], (string)$point['ptoEmi'], $secuencial, $codigoNumerico, (string)$emitter['tipoEmision']);

    $serviceMap = [];
    foreach ($services as $service) {
        $serviceMap[(string)($service['productId'] ?? '')] = $service;
    }

    $detailItems = [];
    $totalSinImpuestos = 0.0;
    $totalDescuento = 0.0;
    $taxGroups = [];
    $subtotalByRate = ['15%' => 0.0, '12%' => 0.0, '5%' => 0.0, 'especial' => 0.0, '0%' => 0.0, 'no_objeto' => 0.0, 'exento' => 0.0];

    foreach (($payload['details'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $qty = max(0.0, (float)($item['cantidad'] ?? 0));
        $price = max(0.0, (float)($item['precioUnitario'] ?? 0));
        if ($qty <= 0) {
            continue;
        }

        $discount = max(0.0, (float)($item['descuento'] ?? 0));
        $lineBase = facturacionRound(($qty * $price) - $discount);
        $iva = trim((string)($item['iva'] ?? '0%'));
        $taxDef = facturacionTaxDefinition($iva);
        $taxValue = facturacionRound($lineBase * ($taxDef['tarifa'] / 100));
        $service = $serviceMap[(string)($item['productId'] ?? '')] ?? null;
        $iceValue = facturacionRound((float)($item['valorICE'] ?? ($service['iceValue'] ?? 0)));

        $detailItems[] = [
            'productId' => trim((string)($item['productId'] ?? '')),
            'codigoPrincipal' => trim((string)($item['codigoPrincipal'] ?? ($service['codigoPrincipal'] ?? ($item['barcode'] ?? '')))),
            'codigoAuxiliar' => trim((string)($item['codigoAuxiliar'] ?? ($service['codigoAuxiliar'] ?? ($item['barcode'] ?? '')))),
            'descripcion' => trim((string)($item['descripcion'] ?? '')),
            'cantidad' => $qty,
            'precioUnitario' => $price,
            'descuento' => $discount,
            'precioTotalSinImpuesto' => $lineBase,
            'iva' => $iva === '' ? '0%' : $iva,
            'tax' => $taxDef,
            'taxValue' => $taxValue,
            'valorICE' => $iceValue,
        ];

        $totalSinImpuestos += $lineBase;
        $totalDescuento += $discount;

        $groupKey = $taxDef['codigo'] . '-' . $taxDef['codigoPorcentaje'];
        if (!isset($taxGroups[$groupKey])) {
            $taxGroups[$groupKey] = [
                'codigo' => $taxDef['codigo'],
                'codigoPorcentaje' => $taxDef['codigoPorcentaje'],
                'baseImponible' => 0.0,
                'valor' => 0.0,
                'tarifa' => $taxDef['tarifa'],
            ];
        }
        $taxGroups[$groupKey]['baseImponible'] += $lineBase;
        $taxGroups[$groupKey]['valor'] += $taxValue;

        if ($iva === '15%') {
            $subtotalByRate['15%'] += $lineBase;
        } elseif ($iva === '12%') {
            $subtotalByRate['12%'] += $lineBase;
        } else {
            $subtotalByRate['0%'] += $lineBase;
        }
    }

    if ($detailItems === []) {
        throw new RuntimeException('Debe agregar al menos un detalle a la factura.');
    }

    $payments = [];
    foreach (($payload['payments'] ?? []) as $payment) {
        if (!is_array($payment)) {
            continue;
        }
        $amount = facturacionRound((float)($payment['value'] ?? 0));
        if ($amount <= 0) {
            continue;
        }
        $payments[] = [
            'formaPago' => facturacionMapPaymentCode((string)($payment['method'] ?? 'cash')),
            'label' => trim((string)($payment['label'] ?? $payment['method'] ?? 'Efectivo')),
            'total' => $amount,
            'plazo' => max(0, (int)($payment['term'] ?? 0)),
            'unidadTiempo' => trim((string)($payment['timeUnit'] ?? 'dias')) ?: 'dias',
        ];
    }

    if ($payments === []) {
        $payments[] = [
            'formaPago' => '01',
            'label' => 'Efectivo',
            'total' => facturacionRound((float)($payload['importeTotal'] ?? 0)),
            'plazo' => 0,
            'unidadTiempo' => 'dias',
        ];
    }

    $paymentsTotal = array_reduce($payments, static fn($sum, $row) => $sum + (float)$row['total'], 0.0);
    $taxTotal = array_reduce($taxGroups, static fn($sum, $row) => $sum + (float)$row['valor'], 0.0);
    $importeTotal = facturacionRound($totalSinImpuestos + $taxTotal);

    if ($paymentsTotal <= 0) {
        $payments[0]['total'] = $importeTotal;
    }

    $buyer = $payload['buyer'] ?? [];
    $additionalFields = [];
    foreach (($payload['additionalFields'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = trim((string)($field['name'] ?? ''));
        $value = trim((string)($field['value'] ?? ''));
        if ($name === '' || $value === '') {
            continue;
        }
        $additionalFields[] = ['name' => $name, 'value' => $value];
    }

    $documentId = facturacionNextDocumentId($documents);
    return [
        'id' => $documentId,
        'docType' => '01',
        'docName' => 'Factura',
        'status' => 'draft',
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'environment' => (string)$emitter['ambiente'],
        'issueDate' => $issueDateUi,
        'issueDateKey' => $issueDateKey,
        'establishmentId' => (string)$point['id'],
        'estab' => (string)$point['estab'],
        'ptoEmi' => (string)$point['ptoEmi'],
        'secuencial' => $secuencial,
        'codigoNumerico' => $codigoNumerico,
        'accessKey' => $accessKey,
        'buyer' => [
            'identification' => trim((string)($buyer['identification'] ?? '9999999999999')),
            'identificationType' => trim((string)($buyer['identificationType'] ?? 'Consumidor final')),
            'razonSocial' => trim((string)($buyer['razonSocial'] ?? 'Consumidor final')),
            'address' => trim((string)($buyer['address'] ?? '')),
            'phone' => trim((string)($buyer['phone'] ?? '')),
            'email' => trim((string)($buyer['email'] ?? '')),
        ],
        'emitter' => $emitter,
        'point' => $point,
        'details' => $detailItems,
        'totals' => [
            'subtotalSinImpuestos' => facturacionRound($totalSinImpuestos),
            'subtotal15' => facturacionRound($subtotalByRate['15%']),
            'subtotal12' => facturacionRound($subtotalByRate['12%']),
            'subtotal5' => facturacionRound($subtotalByRate['5%']),
            'subtotalTarifaEspecial' => facturacionRound($subtotalByRate['especial']),
            'subtotal0' => facturacionRound($subtotalByRate['0%']),
            'subtotalNoObjetoIva' => facturacionRound($subtotalByRate['no_objeto']),
            'subtotalExentoIva' => facturacionRound($subtotalByRate['exento']),
            'totalDescuento' => facturacionRound($totalDescuento),
            'valorICE' => facturacionRound(array_reduce($detailItems, static fn($sum, $row) => $sum + (float)$row['valorICE'], 0.0)),
            'iva15' => facturacionRound(array_reduce($taxGroups, static fn($sum, $row) => $sum + (((float)$row['tarifa'] === 15.0) ? (float)$row['valor'] : 0.0), 0.0)),
            'iva12' => facturacionRound(array_reduce($taxGroups, static fn($sum, $row) => $sum + (((float)$row['tarifa'] === 12.0) ? (float)$row['valor'] : 0.0), 0.0)),
            'iva5' => 0.0,
            'ivaTarifaEspecial' => 0.0,
            'propina' => (string)($payload['tip'] ?? ''),
            'importeTotal' => $importeTotal,
            'taxGroups' => array_values(array_map(static function ($row) {
                $row['baseImponible'] = facturacionRound((float)$row['baseImponible']);
                $row['valor'] = facturacionRound((float)$row['valor']);
                return $row;
            }, $taxGroups)),
        ],
        'payments' => $payments,
        'additionalFields' => $additionalFields,
        'guideNumber' => trim((string)($payload['guideNumber'] ?? '')),
        'isNegotiable' => !empty($payload['isNegotiable']),
        'originSaleId' => trim((string)($payload['saleId'] ?? '')),
        'files' => [
            'generatedXml' => null,
            'signedXml' => null,
            'authorizedXml' => null,
            'pdf' => null,
        ],
        'sri' => [
            'receptionStatus' => null,
            'authorizationStatus' => null,
            'authorizationNumber' => null,
            'authorizationDate' => null,
            'response' => null,
        ],
    ];
}

function facturacionPersistDocument(array $document): array
{
    $rows = facturacionLoadDocuments();
    $updated = false;
    foreach ($rows as $index => $row) {
        if ((string)($row['id'] ?? '') !== (string)$document['id']) {
            continue;
        }
        $document['updatedAt'] = date('c');
        $rows[$index] = $document;
        $updated = true;
        break;
    }
    if (!$updated) {
        $rows[] = $document;
    }
    facturacionSaveDocuments($rows);
    return $document;
}

function facturacionFindDocument(string $id): ?array
{
    foreach (facturacionLoadDocuments() as $row) {
        if ((string)($row['id'] ?? '') === $id) {
            return $row;
        }
    }
    return null;
}

