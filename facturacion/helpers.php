<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

date_default_timezone_set('America/Guayaquil');

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

function facturacionReadJsonDisk(string $relative, array $default = []): array
{
    $path = facturacionStoragePath($relative);
    if (!file_exists($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function facturacionWriteJson(string $relative, array $data): void
{
    writeJsonFile(facturacionStoragePath($relative), $data);
}

function facturacionWriteJsonDisk(string $relative, array $data): void
{
    $path = facturacionStoragePath($relative);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        return;
    }
    file_put_contents($path, $encoded);
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
    if (!function_exists('dbEnabled') || !dbEnabled()) {
        facturacionWriteJsonDisk('logs.json', $rows);
    }
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
        'brevoApiKey' => '',
        'brevoEndpoint' => 'https://api.brevo.com/v3/smtp/email',
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

function facturacionCertificatesDir(): string
{
    $dir = facturacionStoragePath('certificados');
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function facturacionStoreUploadedCertificate(array $file, string $previousPath = ''): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $previousPath;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo cargar el certificado digital.');
    }

    $originalName = (string)($file['name'] ?? '');
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['p12', 'pfx'], true)) {
        throw new RuntimeException('El certificado debe estar en formato .p12 o .pfx.');
    }
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Archivo de certificado inválido.');
    }

    $target = facturacionCertificatesDir() . DIRECTORY_SEPARATOR . 'cert-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    if (!move_uploaded_file($tmpPath, $target)) {
        throw new RuntimeException('No se pudo guardar el certificado en el sistema.');
    }

    if ($previousPath !== '' && file_exists($previousPath) && str_starts_with($previousPath, facturacionCertificatesDir())) {
        @unlink($previousPath);
    }

    return $target;
}

function facturacionSaveSignature(array $data): array
{
    $current = facturacionLoadSignature();
    $incomingCertificatePath = trim((string)($data['certificatePath'] ?? ''));
    $incomingCertificatePassword = (string)($data['certificatePassword'] ?? '');

    $next = array_merge($current, [
        'signatureMode' => in_array((string)($data['signatureMode'] ?? 'mock'), ['mock', 'real'], true) ? (string)$data['signatureMode'] : 'mock',
        // Keep previous certificate data when form submits empty values.
        'certificatePath' => $incomingCertificatePath !== '' ? $incomingCertificatePath : (string)($current['certificatePath'] ?? ''),
        'certificatePassword' => $incomingCertificatePassword !== '' ? $incomingCertificatePassword : (string)($current['certificatePassword'] ?? ''),
        'emailMode' => in_array((string)($data['emailMode'] ?? 'mock'), ['mock', 'brevo_api'], true) ? (string)$data['emailMode'] : 'mock',
        'brevoApiKey' => trim((string)($data['brevoApiKey'] ?? '')),
        'brevoEndpoint' => trim((string)($data['brevoEndpoint'] ?? 'https://api.brevo.com/v3/smtp/email')),
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
    $buyerEmail = trim((string)($buyer['email'] ?? ''));
    $buyerAddress = trim((string)($buyer['address'] ?? ''));
    $buyerPhone = trim((string)($buyer['phone'] ?? ''));
    if ($buyerEmail !== '') {
        $additionalFields[] = ['name' => 'Email', 'value' => $buyerEmail];
    }
    if ($buyerAddress !== '') {
        $additionalFields[] = ['name' => 'Direccion', 'value' => $buyerAddress];
    }
    if ($buyerPhone !== '') {
        $additionalFields[] = ['name' => 'Telefono', 'value' => $buyerPhone];
    }
    foreach (($payload['additionalFields'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = trim((string)($field['name'] ?? ''));
        $value = trim((string)($field['value'] ?? ''));
        if ($name === '' || $value === '') {
            continue;
        }
        $duplicate = false;
        foreach ($additionalFields as $currentField) {
            if (strcasecmp((string)$currentField['name'], $name) === 0) {
                $duplicate = true;
                break;
            }
        }
        if (!$duplicate) {
            $additionalFields[] = ['name' => $name, 'value' => $value];
        }
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

function facturacionOpenSslErrorMessages(): string
{
    $messages = [];
    while (true) {
        $error = openssl_error_string();
        if ($error === false) {
            break;
        }
        $messages[] = $error;
    }
    if ($messages === []) {
        return '';
    }
    return implode(' | ', array_values(array_unique($messages)));
}

function facturacionSoapOptions(): array
{
    $ssl = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        'ciphers' => 'HIGH:!SSLv2:!SSLv3:!TLSv1:!TLSv1.1:!aNULL:!MD5',
    ];

    $cafileCandidates = [
        ini_get('curl.cainfo') ?: '',
        ini_get('openssl.cafile') ?: '',
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem',
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'cacert.pem',
        'C:\\php-8.3.6\\extras\\ssl\\cacert.pem',
        'C:\\php\\extras\\ssl\\cacert.pem',
    ];
    foreach ($cafileCandidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && is_file($candidate)) {
            $ssl['cafile'] = $candidate;
            break;
        }
    }

    return [
        'trace' => true,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
        'connection_timeout' => 20,
        'stream_context' => stream_context_create([
            'http' => [
                'user_agent' => 'POS Facturacion SOAP',
                'timeout' => 30,
            ],
            'ssl' => $ssl,
        ]),
    ];
}

function facturacionCreateSoapClient(string $wsdl): SoapClient
{
    try {
        return new SoapClient($wsdl, facturacionSoapOptions());
    } catch (Throwable $e) {
        $message = strtolower(trim($e->getMessage()));
        $shouldRelaxTls = str_contains($message, 'failed to load external entity')
            || str_contains($message, 'couldn\'t load from')
            || str_contains($message, 'certificate')
            || str_contains($message, 'ssl')
            || str_contains($message, 'tls');

        if (!$shouldRelaxTls) {
            throw $e;
        }

        $fallbackOptions = facturacionSoapOptions();
        $fallbackOptions['stream_context'] = stream_context_create([
            'http' => [
                'user_agent' => 'POS Facturacion SOAP',
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ],
        ]);

        try {
            return new SoapClient($wsdl, $fallbackOptions);
        } catch (Throwable $fallbackError) {
            throw new RuntimeException(
                'No se pudo cargar el WSDL del SRI. Verifique acceso HTTPS/TLS desde el servidor web. Detalle: '
                . $fallbackError->getMessage()
            );
        }
    }
}

function facturacionLegacyOpenSslConfigPath(): string
{
    $path = facturacionStoragePath('openssl-legacy.cnf');
    if (!file_exists($path)) {
        $content = "openssl_conf = openssl_init\n\n"
            . "[openssl_init]\n"
            . "providers = provider_sect\n\n"
            . "[provider_sect]\n"
            . "default = default_sect\n"
            . "legacy = legacy_sect\n\n"
            . "[default_sect]\n"
            . "activate = 1\n\n"
            . "[legacy_sect]\n"
            . "activate = 1\n";
        file_put_contents($path, $content);
    }
    return $path;
}

function facturacionDetectOpenSslModulesDir(): string
{
    $candidates = [
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl',
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'ossl-modules',
        dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ossl-modules',
        dirname(PHP_BINARY),
        'C:\\php-8.3.6\\extras\\ssl',
        'C:\\php-8.3.6\\extras\\ssl\\ossl-modules',
    ];
    foreach ($candidates as $dir) {
        if ($dir !== '' && is_dir($dir) && file_exists($dir . DIRECTORY_SEPARATOR . 'legacy.dll')) {
            return $dir;
        }
    }
    return '';
}

function facturacionReadPkcs12WithLegacySubprocess(string $certificatePath, string $password, string $configPath, string $modulesDir): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'certStore' => [], 'error' => 'proc_open no disponible'];
    }

    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'pkcs12_legacy_probe.php';
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'certStore' => [], 'error' => 'No existe el script de compatibilidad legacy'];
    }

    $pkcs12 = file_get_contents($certificatePath);
    if ($pkcs12 === false) {
        return ['ok' => false, 'certStore' => [], 'error' => 'No se pudo leer el archivo de certificado para la prueba legacy'];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = $_ENV;
    if (!is_array($env)) {
        $env = [];
    }
    $env['OPENSSL_CONF'] = $configPath;
    if ($modulesDir !== '') {
        $env['OPENSSL_MODULES'] = $modulesDir;
    }

    $phpBinary = '';
    $envPhpCli = trim((string) (getenv('FACTURACION_PHP_CLI') ?: ''));
    if ($envPhpCli !== '' && file_exists($envPhpCli) && strtolower(basename($envPhpCli)) === 'php.exe') {
        $phpBinary = $envPhpCli;
    }

    if ($phpBinary === '') {
        $defaultCli = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
        if (file_exists($defaultCli) && strtolower(basename($defaultCli)) === 'php.exe') {
            $phpBinary = $defaultCli;
        }
    }

    if ($phpBinary === '') {
        $binaryCandidates = [
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
            'C:\\php-8.3.6\\php.exe',
            'C:\\xampp\\php\\php.exe',
        ];
        foreach ($binaryCandidates as $candidate) {
            if ($candidate !== '' && file_exists($candidate) && strtolower(basename((string) $candidate)) === 'php.exe') {
                $phpBinary = $candidate;
                break;
            }
        }
    }

    if ($phpBinary === '') {
        return ['ok' => false, 'certStore' => [], 'error' => 'No se encontro php.exe CLI para ejecutar la prueba legacy'];
    }

    $process = proc_open([$phpBinary, $scriptPath, $certificatePath], $descriptors, $pipes, dirname(__DIR__), $env);
    if (!is_resource($process)) {
        return ['ok' => false, 'certStore' => [], 'error' => 'No se pudo iniciar el proceso de prueba legacy (php=' . $phpBinary . ')'];
    }

    $payload = json_encode([
        'password' => $password,
        'pkcs12_b64' => base64_encode($pkcs12),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        $payload = json_encode(['password' => $password], JSON_UNESCAPED_SLASHES);
    }
    fwrite($pipes[0], (string)$payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $decoded = is_string($stdout) ? json_decode($stdout, true) : null;
    if (!is_array($decoded)) {
        $detail = trim((string)$stderr);
        return ['ok' => false, 'certStore' => [], 'error' => ($detail !== '' ? $detail : 'Respuesta inválida del subproceso legacy')];
    }
    if (!empty($decoded['ok']) && is_array($decoded['certStore'] ?? null)) {
        return ['ok' => true, 'certStore' => (array)$decoded['certStore'], 'error' => ''];
    }

    $error = trim((string)($decoded['error'] ?? ''));
    if ($error === '') {
        $error = trim((string)$stderr);
    }
    if ($error === '') {
        $error = 'No se pudo abrir el certificado en modo legacy';
    }
    if ($exitCode !== 0 && $stderr !== '') {
        $error .= ' | ' . trim((string)$stderr);
    }

    return ['ok' => false, 'certStore' => [], 'error' => $error];
}

function facturacionReadPkcs12Store(string $certificatePath, string $certificatePassword): array
{
    if ($certificatePath === '' || !file_exists($certificatePath)) {
        throw new RuntimeException('No se encontro el archivo de certificado para validar.');
    }

    $pkcs12 = file_get_contents($certificatePath);
    if ($pkcs12 === false) {
        throw new RuntimeException('No se pudo leer el archivo de certificado.');
    }

    $attempts = [$certificatePassword];
    $trimmed = trim($certificatePassword);
    if ($trimmed !== $certificatePassword) {
        $attempts[] = $trimmed;
    }

    $readWithAttempts = static function () use ($pkcs12, $attempts): array {
        $certStore = [];
        foreach ($attempts as $candidatePassword) {
            $certStore = [];
            if (openssl_pkcs12_read($pkcs12, $certStore, $candidatePassword)) {
                return [true, $certStore];
            }
        }
        return [false, []];
    };

    [$opened, $certStore] = $readWithAttempts();
    $firstDetail = facturacionOpenSslErrorMessages();
    $usedLegacyProvider = false;
    $legacyProbeErrors = [];

    if (!$opened && stripos($firstDetail, 'unsupported') !== false) {
        $previousOpenSslConf = getenv('OPENSSL_CONF');
        $previousOpenSslModules = getenv('OPENSSL_MODULES');
        $modulesDir = facturacionDetectOpenSslModulesDir();
        $configPath = facturacionLegacyOpenSslConfigPath();
        putenv('OPENSSL_CONF=' . $configPath);
        if ($modulesDir !== '') {
            putenv('OPENSSL_MODULES=' . $modulesDir);
        }
        [$opened, $certStore] = $readWithAttempts();
        if ($opened) {
            $usedLegacyProvider = true;
        } else {
            foreach ($attempts as $candidatePassword) {
                $probe = facturacionReadPkcs12WithLegacySubprocess($certificatePath, (string)$candidatePassword, $configPath, $modulesDir);
                if (!empty($probe['ok'])) {
                    $opened = true;
                    $certStore = (array)($probe['certStore'] ?? []);
                    $usedLegacyProvider = true;
                    break;
                }
                $legacyProbeErrors[] = trim((string)($probe['error'] ?? ''));
            }
        }
        if ($previousOpenSslConf === false || $previousOpenSslConf === '') {
            putenv('OPENSSL_CONF');
        } else {
            putenv('OPENSSL_CONF=' . $previousOpenSslConf);
        }
        if ($previousOpenSslModules === false || $previousOpenSslModules === '') {
            putenv('OPENSSL_MODULES');
        } else {
            putenv('OPENSSL_MODULES=' . $previousOpenSslModules);
        }
    }

    if (!$opened) {
        $detail = facturacionOpenSslErrorMessages();
        if ($detail === '') {
            $detail = $firstDetail;
        }
        $suffix = $detail !== '' ? (' Detalle OpenSSL: ' . $detail) : '';
        $hint = '';
        if (stripos($detail, 'mac verify failure') !== false) {
            $hint = ' Sugerencia: la clave del certificado es incorrecta o el archivo .p12/.pfx esta danado.';
        }
        if (stripos($detail, 'unsupported') !== false) {
            $hint = ' Sugerencia: el .p12 parece usar cifrado legacy; intente reexportarlo con OpenSSL legacy o usar OpenSSL 1.1 para convertirlo.';
        }
        $probeInfo = '';
        $legacyProbeErrors = array_values(array_filter(array_unique($legacyProbeErrors), static fn($e) => $e !== ''));
        if ($legacyProbeErrors !== []) {
            $probeInfo = ' ProbeLegacy: ' . implode(' | ', $legacyProbeErrors);
        }
        throw new RuntimeException('No se pudo abrir el certificado. Revise la clave del archivo .p12/.pfx.' . $suffix . $hint . $probeInfo);
    }

    return [
        'certStore' => $certStore,
        'usedLegacyProvider' => $usedLegacyProvider,
    ];
}

function facturacionReadCertificateMetadata(string $certificatePath, string $certificatePassword): array
{
    $result = facturacionReadPkcs12Store($certificatePath, $certificatePassword);
    $certStore = (array)($result['certStore'] ?? []);
    $usedLegacyProvider = !empty($result['usedLegacyProvider']);

    $certificatePem = (string)($certStore['cert'] ?? '');
    $privateKeyPem = (string)($certStore['pkey'] ?? '');
    if ($certificatePem === '' || $privateKeyPem === '') {
        throw new RuntimeException('El certificado no contiene una llave privada utilizable.');
    }

    $data = openssl_x509_parse($certificatePem);
    if (!is_array($data)) {
        throw new RuntimeException('No se pudo interpretar el contenido del certificado X509.');
    }

    $serial = (string)($data['serialNumber'] ?? '');
    if ($serial === '' && isset($data['serialNumberHex'])) {
        $serial = (string)$data['serialNumberHex'];
    }

    return [
        'subject' => (array)($data['subject'] ?? []),
        'issuer' => (array)($data['issuer'] ?? []),
        'serialNumber' => $serial,
        'validFrom' => (string)date('Y-m-d H:i:s', (int)($data['validFrom_time_t'] ?? 0)),
        'validTo' => (string)date('Y-m-d H:i:s', (int)($data['validTo_time_t'] ?? 0)),
        'certificatePath' => $certificatePath,
        'hasPrivateKey' => true,
        'usedLegacyProvider' => $usedLegacyProvider,
    ];
}

function facturacionValidateSignedXmlStructure(string $xmlPath): array
{
    if ($xmlPath === '' || !file_exists($xmlPath)) {
        return ['ok' => false, 'errors' => ['No existe el XML firmado para validar.']];
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($xmlPath)) {
        return ['ok' => false, 'errors' => ['No se pudo cargar el XML firmado.']];
    }

    $errors = [];
    $root = $dom->documentElement;
    if (!$root || $root->localName !== 'factura') {
        $errors[] = 'El XML no tiene nodo raiz factura.';
    }
    if (!$root || strtolower((string)$root->getAttribute('id')) !== 'comprobante') {
        $errors[] = 'El nodo factura debe tener id=comprobante.';
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
    $xpath->registerNamespace('etsi', 'http://uri.etsi.org/01903/v1.3.2#');

    $requiredXPath = [
        '/factura/infoTributaria' => 'Falta infoTributaria.',
        '/factura/infoFactura' => 'Falta infoFactura.',
        '/factura/detalles' => 'Falta detalles.',
        '/factura/ds:Signature' => 'Falta ds:Signature.',
        '/factura/ds:Signature/ds:SignedInfo' => 'Falta SignedInfo.',
        '/factura/ds:Signature/ds:SignatureValue' => 'Falta SignatureValue.',
        '/factura/ds:Signature/ds:KeyInfo/ds:X509Data/ds:X509Certificate' => 'Falta X509Certificate en KeyInfo.',
        '/factura/ds:Signature/ds:Object/etsi:QualifyingProperties' => 'Falta QualifyingProperties.',
        '/factura/ds:Signature/ds:Object/etsi:QualifyingProperties/etsi:SignedProperties' => 'Falta SignedProperties.',
        '/factura/ds:Signature/ds:Object/etsi:QualifyingProperties/etsi:SignedProperties/etsi:SignedSignatureProperties/etsi:SigningTime' => 'Falta SigningTime.',
        '/factura/ds:Signature/ds:Object/etsi:QualifyingProperties/etsi:SignedProperties/etsi:SignedSignatureProperties/etsi:SigningCertificate' => 'Falta SigningCertificate.',
    ];
    foreach ($requiredXPath as $query => $message) {
        $node = $xpath->query($query);
        if ($node === false || $node->length === 0) {
            $errors[] = $message;
        }
    }

    $signedPropertiesRef = $xpath->query('/factura/ds:Signature/ds:SignedInfo/ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]');
    if ($signedPropertiesRef === false || $signedPropertiesRef->length === 0) {
        $errors[] = 'Falta referencia Type=SignedProperties en SignedInfo.';
    }

    $comprobanteRef = $xpath->query('/factura/ds:Signature/ds:SignedInfo/ds:Reference[@URI="#comprobante"]');
    if ($comprobanteRef === false || $comprobanteRef->length === 0) {
        $errors[] = 'Falta referencia URI=#comprobante en SignedInfo.';
    } else {
        $transform = $xpath->query('./ds:Transforms/ds:Transform[@Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"]', $comprobanteRef->item(0));
        if ($transform === false || $transform->length === 0) {
            $errors[] = 'La referencia del comprobante no tiene transform enveloped-signature.';
        }
    }

    $xmlRaw = file_get_contents($xmlPath);
    if ($xmlRaw === false || stripos($xmlRaw, 'encoding="UTF-8"') === false) {
        $errors[] = 'El XML firmado debe declararse en UTF-8.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
    ];
}

