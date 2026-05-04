<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/db.php';

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
    // Mantener siempre una copia en disco para depuracion y revisiones manuales,
    // incluso cuando el almacenamiento principal este mapeado a BD.
    facturacionWriteJsonDisk('logs.json', $rows);
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

function facturacionNormalizeSignatureMode(string $mode): string
{
    return in_array($mode, ['mock', 'real'], true) ? $mode : 'mock';
}

function facturacionEnvironmentFromSignatureMode(string $mode): string
{
    return facturacionNormalizeSignatureMode($mode) === 'real' ? '2' : '1';
}

function facturacionResolveCertificatePath(string $path): string
{
    $candidate = trim($path);
    if ($candidate === '') {
        return '';
    }

    if (file_exists($candidate)) {
        return $candidate;
    }

    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
    $marker = DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'facturacion' . DIRECTORY_SEPARATOR . 'certificados' . DIRECTORY_SEPARATOR;
    $markerPos = stripos($normalized, $marker);
    if ($markerPos !== false) {
        $relative = substr($normalized, $markerPos + strlen($marker));
        if (is_string($relative) && $relative !== '') {
            $rebased = facturacionCertificatesDir() . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
            if (file_exists($rebased)) {
                return $rebased;
            }
        }
    }

    $basename = basename($candidate);
    if ($basename !== '' && $basename !== '.' && $basename !== '..') {
        $byName = facturacionCertificatesDir() . DIRECTORY_SEPARATOR . $basename;
        if (file_exists($byName)) {
            return $byName;
        }
    }

    return $candidate;
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
    $signature = array_merge(facturacionDefaultSignature(), facturacionReadJson('firma.json', []));
    $signature['certificatePath'] = facturacionResolveCertificatePath((string)($signature['certificatePath'] ?? ''));
    return $signature;
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
        'signatureMode' => facturacionNormalizeSignatureMode((string)($data['signatureMode'] ?? 'mock')),
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

function facturacionCanUseDb(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = false;
    if (!function_exists('dbEnabled') || !dbEnabled() || !function_exists('db')) {
        return false;
    }

    try {
        facturacionEnsureDbSchema();
        $ready = true;
    } catch (Throwable $e) {
        facturacionAppendLog('warning', 'No se pudo inicializar esquema SQL de facturacion. Se usara JSON.', [
            'error' => $e->getMessage(),
        ]);
        $ready = false;
    }

    return $ready;
}

function facturacionEnsureDbSchema(): void
{
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS FACT_ELEC_DOCUMENTOS (
            ID VARCHAR(32) NOT NULL,
            DOC_TYPE VARCHAR(8) NOT NULL DEFAULT '01',
            DOC_NAME VARCHAR(80) NOT NULL DEFAULT 'Factura',
            STATUS VARCHAR(40) NOT NULL DEFAULT 'draft',
            CREATED_AT DATETIME NULL,
            UPDATED_AT DATETIME NULL,
            ENVIRONMENT VARCHAR(4) NULL,
            ISSUE_DATE VARCHAR(20) NULL,
            ISSUE_DATE_KEY VARCHAR(20) NULL,
            ESTABLISHMENT_ID VARCHAR(40) NULL,
            ESTAB VARCHAR(8) NULL,
            PTO_EMI VARCHAR(8) NULL,
            SECUENCIAL VARCHAR(16) NULL,
            CODIGO_NUMERICO VARCHAR(16) NULL,
            ACCESS_KEY VARCHAR(64) NULL,
            GUIDE_NUMBER VARCHAR(40) NULL,
            IS_NEGOTIABLE TINYINT(1) NOT NULL DEFAULT 0,
            ORIGIN_SALE_ID VARCHAR(64) NULL,
            BUYER_IDENTIFICATION VARCHAR(32) NULL,
            BUYER_IDENTIFICATION_TYPE VARCHAR(40) NULL,
            BUYER_RAZON_SOCIAL VARCHAR(255) NULL,
            BUYER_ADDRESS VARCHAR(255) NULL,
            BUYER_PHONE VARCHAR(64) NULL,
            BUYER_EMAIL VARCHAR(255) NULL,
            EMITTER_JSON LONGTEXT NULL,
            POINT_JSON LONGTEXT NULL,
            TOTALS_JSON LONGTEXT NULL,
            FILES_JSON LONGTEXT NULL,
            SRI_JSON LONGTEXT NULL,
            DOCUMENT_JSON LONGTEXT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY UQ_FACT_ELEC_DOC_ACCESS_KEY (ACCESS_KEY),
            KEY IDX_FACT_ELEC_DOC_STATUS (STATUS),
            KEY IDX_FACT_ELEC_DOC_ISSUE_DATE (ISSUE_DATE_KEY)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS FACT_ELEC_DOCUMENTOS_DETALLE (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            DOCUMENT_ID VARCHAR(32) NOT NULL,
            LINE_NO INT NOT NULL,
            PRODUCT_ID VARCHAR(64) NULL,
            CODIGO_PRINCIPAL VARCHAR(64) NULL,
            CODIGO_AUXILIAR VARCHAR(64) NULL,
            DESCRIPCION VARCHAR(255) NULL,
            CANTIDAD DECIMAL(14,4) NOT NULL DEFAULT 0,
            PRECIO_UNITARIO DECIMAL(14,6) NOT NULL DEFAULT 0,
            DESCUENTO DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRECIO_TOTAL_SIN_IMPUESTO DECIMAL(14,2) NOT NULL DEFAULT 0,
            IVA_LABEL VARCHAR(16) NULL,
            TAX_CODE VARCHAR(8) NULL,
            TAX_PERCENT_CODE VARCHAR(8) NULL,
            TAX_RATE DECIMAL(9,4) NOT NULL DEFAULT 0,
            TAX_VALUE DECIMAL(14,2) NOT NULL DEFAULT 0,
            ICE_VALUE DECIMAL(14,2) NOT NULL DEFAULT 0,
            RAW_JSON LONGTEXT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY UQ_FACT_ELEC_DET_DOC_LINE (DOCUMENT_ID, LINE_NO),
            KEY IDX_FACT_ELEC_DET_DOC (DOCUMENT_ID),
            CONSTRAINT FK_FACT_ELEC_DET_DOC
                FOREIGN KEY (DOCUMENT_ID) REFERENCES FACT_ELEC_DOCUMENTOS(ID)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS FACT_ELEC_DOCUMENTOS_PAGOS (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            DOCUMENT_ID VARCHAR(32) NOT NULL,
            LINE_NO INT NOT NULL,
            FORMA_PAGO VARCHAR(8) NULL,
            LABEL_NAME VARCHAR(80) NULL,
            TOTAL DECIMAL(14,2) NOT NULL DEFAULT 0,
            PLAZO INT NOT NULL DEFAULT 0,
            UNIDAD_TIEMPO VARCHAR(20) NULL,
            RAW_JSON LONGTEXT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY UQ_FACT_ELEC_PAY_DOC_LINE (DOCUMENT_ID, LINE_NO),
            KEY IDX_FACT_ELEC_PAY_DOC (DOCUMENT_ID),
            CONSTRAINT FK_FACT_ELEC_PAY_DOC
                FOREIGN KEY (DOCUMENT_ID) REFERENCES FACT_ELEC_DOCUMENTOS(ID)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS FACT_ELEC_DOCUMENTOS_ADICIONALES (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            DOCUMENT_ID VARCHAR(32) NOT NULL,
            LINE_NO INT NOT NULL,
            FIELD_NAME VARCHAR(255) NOT NULL,
            FIELD_VALUE TEXT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY UQ_FACT_ELEC_ADD_DOC_LINE (DOCUMENT_ID, LINE_NO),
            KEY IDX_FACT_ELEC_ADD_DOC (DOCUMENT_ID),
            CONSTRAINT FK_FACT_ELEC_ADD_DOC
                FOREIGN KEY (DOCUMENT_ID) REFERENCES FACT_ELEC_DOCUMENTOS(ID)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS FACT_ELEC_DOCUMENTOS_IMPUESTOS (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            DOCUMENT_ID VARCHAR(32) NOT NULL,
            LINE_NO INT NOT NULL,
            CODIGO VARCHAR(8) NULL,
            CODIGO_PORCENTAJE VARCHAR(8) NULL,
            TARIFA DECIMAL(9,4) NOT NULL DEFAULT 0,
            BASE_IMPONIBLE DECIMAL(14,2) NOT NULL DEFAULT 0,
            VALOR DECIMAL(14,2) NOT NULL DEFAULT 0,
            RAW_JSON LONGTEXT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY UQ_FACT_ELEC_TAX_DOC_LINE (DOCUMENT_ID, LINE_NO),
            KEY IDX_FACT_ELEC_TAX_DOC (DOCUMENT_ID),
            CONSTRAINT FK_FACT_ELEC_TAX_DOC
                FOREIGN KEY (DOCUMENT_ID) REFERENCES FACT_ELEC_DOCUMENTOS(ID)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function facturacionEncodeJson(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return '{}';
    }
    return $encoded;
}

function facturacionDecodeJson(mixed $value, mixed $default): mixed
{
    if (!is_string($value) || trim($value) === '') {
        return $default;
    }
    $decoded = json_decode($value, true);
    return $decoded === null ? $default : $decoded;
}

function facturacionIsoToDbDateTime(?string $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function facturacionDbDateTimeToIso(?string $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }
    return date('c', $ts);
}

function facturacionDocumentFromDbRow(array $row): array
{
    $snapshot = facturacionDecodeJson($row['DOCUMENT_JSON'] ?? '', []);
    if (is_array($snapshot) && $snapshot !== []) {
        return $snapshot;
    }

    return [
        'id' => (string)($row['ID'] ?? ''),
        'docType' => (string)($row['DOC_TYPE'] ?? '01'),
        'docName' => (string)($row['DOC_NAME'] ?? 'Factura'),
        'status' => (string)($row['STATUS'] ?? 'draft'),
        'createdAt' => facturacionDbDateTimeToIso((string)($row['CREATED_AT'] ?? '')),
        'updatedAt' => facturacionDbDateTimeToIso((string)($row['UPDATED_AT'] ?? '')),
        'environment' => (string)($row['ENVIRONMENT'] ?? ''),
        'issueDate' => (string)($row['ISSUE_DATE'] ?? ''),
        'issueDateKey' => (string)($row['ISSUE_DATE_KEY'] ?? ''),
        'establishmentId' => (string)($row['ESTABLISHMENT_ID'] ?? ''),
        'estab' => (string)($row['ESTAB'] ?? ''),
        'ptoEmi' => (string)($row['PTO_EMI'] ?? ''),
        'secuencial' => (string)($row['SECUENCIAL'] ?? ''),
        'codigoNumerico' => (string)($row['CODIGO_NUMERICO'] ?? ''),
        'accessKey' => (string)($row['ACCESS_KEY'] ?? ''),
        'guideNumber' => (string)($row['GUIDE_NUMBER'] ?? ''),
        'isNegotiable' => (bool)($row['IS_NEGOTIABLE'] ?? false),
        'originSaleId' => (string)($row['ORIGIN_SALE_ID'] ?? ''),
        'buyer' => [
            'identification' => (string)($row['BUYER_IDENTIFICATION'] ?? ''),
            'identificationType' => (string)($row['BUYER_IDENTIFICATION_TYPE'] ?? ''),
            'razonSocial' => (string)($row['BUYER_RAZON_SOCIAL'] ?? ''),
            'address' => (string)($row['BUYER_ADDRESS'] ?? ''),
            'phone' => (string)($row['BUYER_PHONE'] ?? ''),
            'email' => (string)($row['BUYER_EMAIL'] ?? ''),
        ],
        'emitter' => facturacionDecodeJson($row['EMITTER_JSON'] ?? '', []),
        'point' => facturacionDecodeJson($row['POINT_JSON'] ?? '', []),
        'totals' => facturacionDecodeJson($row['TOTALS_JSON'] ?? '', []),
        'files' => facturacionDecodeJson($row['FILES_JSON'] ?? '', []),
        'sri' => facturacionDecodeJson($row['SRI_JSON'] ?? '', []),
        'details' => [],
        'payments' => [],
        'additionalFields' => [],
    ];
}

function facturacionPersistDocumentDb(array $document): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $createdAt = facturacionIsoToDbDateTime((string)($document['createdAt'] ?? date('c')));
        $document['updatedAt'] = date('c');
        $updatedAt = facturacionIsoToDbDateTime((string)$document['updatedAt']);

        $upsert = $pdo->prepare(
            "INSERT INTO FACT_ELEC_DOCUMENTOS (
                ID, DOC_TYPE, DOC_NAME, STATUS, CREATED_AT, UPDATED_AT, ENVIRONMENT, ISSUE_DATE, ISSUE_DATE_KEY,
                ESTABLISHMENT_ID, ESTAB, PTO_EMI, SECUENCIAL, CODIGO_NUMERICO, ACCESS_KEY, GUIDE_NUMBER, IS_NEGOTIABLE,
                ORIGIN_SALE_ID, BUYER_IDENTIFICATION, BUYER_IDENTIFICATION_TYPE, BUYER_RAZON_SOCIAL, BUYER_ADDRESS,
                BUYER_PHONE, BUYER_EMAIL, EMITTER_JSON, POINT_JSON, TOTALS_JSON, FILES_JSON, SRI_JSON, DOCUMENT_JSON
            ) VALUES (
                :id, :doc_type, :doc_name, :status, :created_at, :updated_at, :environment, :issue_date, :issue_date_key,
                :establishment_id, :estab, :pto_emi, :secuencial, :codigo_numerico, :access_key, :guide_number, :is_negotiable,
                :origin_sale_id, :buyer_identification, :buyer_identification_type, :buyer_razon_social, :buyer_address,
                :buyer_phone, :buyer_email, :emitter_json, :point_json, :totals_json, :files_json, :sri_json, :document_json
            )
            ON DUPLICATE KEY UPDATE
                DOC_TYPE = VALUES(DOC_TYPE),
                DOC_NAME = VALUES(DOC_NAME),
                STATUS = VALUES(STATUS),
                UPDATED_AT = VALUES(UPDATED_AT),
                ENVIRONMENT = VALUES(ENVIRONMENT),
                ISSUE_DATE = VALUES(ISSUE_DATE),
                ISSUE_DATE_KEY = VALUES(ISSUE_DATE_KEY),
                ESTABLISHMENT_ID = VALUES(ESTABLISHMENT_ID),
                ESTAB = VALUES(ESTAB),
                PTO_EMI = VALUES(PTO_EMI),
                SECUENCIAL = VALUES(SECUENCIAL),
                CODIGO_NUMERICO = VALUES(CODIGO_NUMERICO),
                ACCESS_KEY = VALUES(ACCESS_KEY),
                GUIDE_NUMBER = VALUES(GUIDE_NUMBER),
                IS_NEGOTIABLE = VALUES(IS_NEGOTIABLE),
                ORIGIN_SALE_ID = VALUES(ORIGIN_SALE_ID),
                BUYER_IDENTIFICATION = VALUES(BUYER_IDENTIFICATION),
                BUYER_IDENTIFICATION_TYPE = VALUES(BUYER_IDENTIFICATION_TYPE),
                BUYER_RAZON_SOCIAL = VALUES(BUYER_RAZON_SOCIAL),
                BUYER_ADDRESS = VALUES(BUYER_ADDRESS),
                BUYER_PHONE = VALUES(BUYER_PHONE),
                BUYER_EMAIL = VALUES(BUYER_EMAIL),
                EMITTER_JSON = VALUES(EMITTER_JSON),
                POINT_JSON = VALUES(POINT_JSON),
                TOTALS_JSON = VALUES(TOTALS_JSON),
                FILES_JSON = VALUES(FILES_JSON),
                SRI_JSON = VALUES(SRI_JSON),
                DOCUMENT_JSON = VALUES(DOCUMENT_JSON)"
        );

        $buyer = is_array($document['buyer'] ?? null) ? $document['buyer'] : [];
        $upsert->execute([
            ':id' => (string)($document['id'] ?? ''),
            ':doc_type' => (string)($document['docType'] ?? '01'),
            ':doc_name' => (string)($document['docName'] ?? 'Factura'),
            ':status' => (string)($document['status'] ?? 'draft'),
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
            ':environment' => (string)($document['environment'] ?? ''),
            ':issue_date' => (string)($document['issueDate'] ?? ''),
            ':issue_date_key' => (string)($document['issueDateKey'] ?? ''),
            ':establishment_id' => (string)($document['establishmentId'] ?? ''),
            ':estab' => (string)($document['estab'] ?? ''),
            ':pto_emi' => (string)($document['ptoEmi'] ?? ''),
            ':secuencial' => (string)($document['secuencial'] ?? ''),
            ':codigo_numerico' => (string)($document['codigoNumerico'] ?? ''),
            ':access_key' => (string)($document['accessKey'] ?? ''),
            ':guide_number' => (string)($document['guideNumber'] ?? ''),
            ':is_negotiable' => !empty($document['isNegotiable']) ? 1 : 0,
            ':origin_sale_id' => (string)($document['originSaleId'] ?? ''),
            ':buyer_identification' => (string)($buyer['identification'] ?? ''),
            ':buyer_identification_type' => (string)($buyer['identificationType'] ?? ''),
            ':buyer_razon_social' => (string)($buyer['razonSocial'] ?? ''),
            ':buyer_address' => (string)($buyer['address'] ?? ''),
            ':buyer_phone' => (string)($buyer['phone'] ?? ''),
            ':buyer_email' => (string)($buyer['email'] ?? ''),
            ':emitter_json' => facturacionEncodeJson($document['emitter'] ?? []),
            ':point_json' => facturacionEncodeJson($document['point'] ?? []),
            ':totals_json' => facturacionEncodeJson($document['totals'] ?? []),
            ':files_json' => facturacionEncodeJson($document['files'] ?? []),
            ':sri_json' => facturacionEncodeJson($document['sri'] ?? []),
            ':document_json' => facturacionEncodeJson($document),
        ]);

        $documentId = (string)($document['id'] ?? '');
        $pdo->prepare("DELETE FROM FACT_ELEC_DOCUMENTOS_DETALLE WHERE DOCUMENT_ID = :id")->execute([':id' => $documentId]);
        $pdo->prepare("DELETE FROM FACT_ELEC_DOCUMENTOS_PAGOS WHERE DOCUMENT_ID = :id")->execute([':id' => $documentId]);
        $pdo->prepare("DELETE FROM FACT_ELEC_DOCUMENTOS_ADICIONALES WHERE DOCUMENT_ID = :id")->execute([':id' => $documentId]);
        $pdo->prepare("DELETE FROM FACT_ELEC_DOCUMENTOS_IMPUESTOS WHERE DOCUMENT_ID = :id")->execute([':id' => $documentId]);

        $insertDetail = $pdo->prepare(
            "INSERT INTO FACT_ELEC_DOCUMENTOS_DETALLE (
                DOCUMENT_ID, LINE_NO, PRODUCT_ID, CODIGO_PRINCIPAL, CODIGO_AUXILIAR, DESCRIPCION,
                CANTIDAD, PRECIO_UNITARIO, DESCUENTO, PRECIO_TOTAL_SIN_IMPUESTO, IVA_LABEL, TAX_CODE,
                TAX_PERCENT_CODE, TAX_RATE, TAX_VALUE, ICE_VALUE, RAW_JSON
            ) VALUES (
                :document_id, :line_no, :product_id, :codigo_principal, :codigo_auxiliar, :descripcion,
                :cantidad, :precio_unitario, :descuento, :precio_total_sin_impuesto, :iva_label, :tax_code,
                :tax_percent_code, :tax_rate, :tax_value, :ice_value, :raw_json
            )"
        );
        $lineNo = 1;
        foreach (($document['details'] ?? []) as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $tax = is_array($detail['tax'] ?? null) ? $detail['tax'] : [];
            $insertDetail->execute([
                ':document_id' => $documentId,
                ':line_no' => $lineNo,
                ':product_id' => (string)($detail['productId'] ?? ''),
                ':codigo_principal' => (string)($detail['codigoPrincipal'] ?? ''),
                ':codigo_auxiliar' => (string)($detail['codigoAuxiliar'] ?? ''),
                ':descripcion' => (string)($detail['descripcion'] ?? ''),
                ':cantidad' => (float)($detail['cantidad'] ?? 0),
                ':precio_unitario' => (float)($detail['precioUnitario'] ?? 0),
                ':descuento' => (float)($detail['descuento'] ?? 0),
                ':precio_total_sin_impuesto' => (float)($detail['precioTotalSinImpuesto'] ?? 0),
                ':iva_label' => (string)($detail['iva'] ?? ''),
                ':tax_code' => (string)($tax['codigo'] ?? ''),
                ':tax_percent_code' => (string)($tax['codigoPorcentaje'] ?? ''),
                ':tax_rate' => (float)($tax['tarifa'] ?? 0),
                ':tax_value' => (float)($detail['taxValue'] ?? 0),
                ':ice_value' => (float)($detail['valorICE'] ?? 0),
                ':raw_json' => facturacionEncodeJson($detail),
            ]);
            $lineNo += 1;
        }

        $insertPayment = $pdo->prepare(
            "INSERT INTO FACT_ELEC_DOCUMENTOS_PAGOS (
                DOCUMENT_ID, LINE_NO, FORMA_PAGO, LABEL_NAME, TOTAL, PLAZO, UNIDAD_TIEMPO, RAW_JSON
            ) VALUES (
                :document_id, :line_no, :forma_pago, :label_name, :total, :plazo, :unidad_tiempo, :raw_json
            )"
        );
        $lineNo = 1;
        foreach (($document['payments'] ?? []) as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $insertPayment->execute([
                ':document_id' => $documentId,
                ':line_no' => $lineNo,
                ':forma_pago' => (string)($payment['formaPago'] ?? ''),
                ':label_name' => (string)($payment['label'] ?? ''),
                ':total' => (float)($payment['total'] ?? 0),
                ':plazo' => (int)($payment['plazo'] ?? 0),
                ':unidad_tiempo' => (string)($payment['unidadTiempo'] ?? ''),
                ':raw_json' => facturacionEncodeJson($payment),
            ]);
            $lineNo += 1;
        }

        $insertAdditional = $pdo->prepare(
            "INSERT INTO FACT_ELEC_DOCUMENTOS_ADICIONALES (
                DOCUMENT_ID, LINE_NO, FIELD_NAME, FIELD_VALUE
            ) VALUES (
                :document_id, :line_no, :field_name, :field_value
            )"
        );
        $lineNo = 1;
        foreach (($document['additionalFields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $insertAdditional->execute([
                ':document_id' => $documentId,
                ':line_no' => $lineNo,
                ':field_name' => (string)($field['name'] ?? ''),
                ':field_value' => (string)($field['value'] ?? ''),
            ]);
            $lineNo += 1;
        }

        $insertTaxGroup = $pdo->prepare(
            "INSERT INTO FACT_ELEC_DOCUMENTOS_IMPUESTOS (
                DOCUMENT_ID, LINE_NO, CODIGO, CODIGO_PORCENTAJE, TARIFA, BASE_IMPONIBLE, VALOR, RAW_JSON
            ) VALUES (
                :document_id, :line_no, :codigo, :codigo_porcentaje, :tarifa, :base_imponible, :valor, :raw_json
            )"
        );
        $lineNo = 1;
        $taxGroups = is_array(($document['totals'] ?? [])['taxGroups'] ?? null) ? $document['totals']['taxGroups'] : [];
        foreach ($taxGroups as $taxGroup) {
            if (!is_array($taxGroup)) {
                continue;
            }
            $insertTaxGroup->execute([
                ':document_id' => $documentId,
                ':line_no' => $lineNo,
                ':codigo' => (string)($taxGroup['codigo'] ?? ''),
                ':codigo_porcentaje' => (string)($taxGroup['codigoPorcentaje'] ?? ''),
                ':tarifa' => (float)($taxGroup['tarifa'] ?? 0),
                ':base_imponible' => (float)($taxGroup['baseImponible'] ?? 0),
                ':valor' => (float)($taxGroup['valor'] ?? 0),
                ':raw_json' => facturacionEncodeJson($taxGroup),
            ]);
            $lineNo += 1;
        }

        $pdo->commit();
        return $document;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function facturacionLoadDocumentsDb(): array
{
    $stmt = db()->query("SELECT * FROM FACT_ELEC_DOCUMENTOS ORDER BY CREATED_AT DESC, ID DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $documents = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $documents[] = facturacionDocumentFromDbRow($row);
    }
    return $documents;
}

function facturacionFindDocumentDb(string $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM FACT_ELEC_DOCUMENTOS WHERE ID = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        return null;
    }
    return facturacionDocumentFromDbRow($row);
}

function facturacionMigrateLegacyJsonDocumentsToDb(): void
{
    if (!facturacionCanUseDb()) {
        return;
    }

    static $alreadyChecked = false;
    if ($alreadyChecked) {
        return;
    }
    $alreadyChecked = true;

    $legacy = facturacionReadJson('documentos.json', []);
    if (!is_array($legacy) || $legacy === []) {
        return;
    }

    $countStmt = db()->query("SELECT COUNT(*) FROM FACT_ELEC_DOCUMENTOS");
    $existing = (int)($countStmt ? $countStmt->fetchColumn() : 0);
    if ($existing > 0) {
        return;
    }

    foreach ($legacy as $document) {
        if (!is_array($document) || !isset($document['id'])) {
            continue;
        }
        facturacionPersistDocumentDb($document);
    }
}

function facturacionLoadDocuments(): array
{
    if (facturacionCanUseDb()) {
        facturacionMigrateLegacyJsonDocumentsToDb();
        return facturacionLoadDocumentsDb();
    }
    return facturacionReadJson('documentos.json', []);
}

function facturacionSaveDocuments(array $rows): void
{
    if (facturacionCanUseDb()) {
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            facturacionPersistDocumentDb($row);
        }
        return;
    }
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

/**
 * Extrae base e impuesto desde un valor que ya incluye IVA.
 * Se usa para evitar sumar nuevamente el impuesto al total del producto.
 *
 * @return array{base: float, tax: float}
 */
function facturacionSplitTaxIncluded(float $gross, float $ratePercent): array
{
    if ($gross <= 0 || $ratePercent <= 0) {
        return ['base' => facturacionRound(max(0.0, $gross)), 'tax' => 0.0];
    }

    $factor = 1 + ($ratePercent / 100);
    if ($factor <= 0) {
        return ['base' => facturacionRound(max(0.0, $gross)), 'tax' => 0.0];
    }

    $base = facturacionRound($gross / $factor);
    $tax = facturacionRound($gross - $base);
    return ['base' => $base, 'tax' => $tax];
}

function facturacionFormatDecimal(float $value, int $scale = 2): string
{
    return number_format($value, $scale, '.', '');
}

function facturacionBuildInvoiceDocument(array $payload): array
{
    $emitter = facturacionLoadEmitter();
    $signature = facturacionLoadSignature();
    $resolvedEnvironment = facturacionEnvironmentFromSignatureMode((string)($signature['signatureMode'] ?? 'mock'));
    $emitter['ambiente'] = $resolvedEnvironment;
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
    $accessKey = facturacionGenerateAccessKey($issueDateKey, '01', (string)$emitter['ruc'], $resolvedEnvironment, (string)$point['estab'], (string)$point['ptoEmi'], $secuencial, $codigoNumerico, (string)$emitter['tipoEmision']);

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
        // El precio ingresado en UI se trata como precio final (impuesto incluido).
        $inputUnitPrice = max(0.0, (float)($item['precioUnitario'] ?? 0));
        if ($qty <= 0) {
            continue;
        }

        $discount = max(0.0, (float)($item['descuento'] ?? 0));
        $lineGross = facturacionRound(($qty * $inputUnitPrice) - $discount);
        $iva = trim((string)($item['iva'] ?? '0%'));
        $taxDef = facturacionTaxDefinition($iva);
        $split = facturacionSplitTaxIncluded($lineGross, (float)($taxDef['tarifa'] ?? 0));
        $lineBase = (float)($split['base'] ?? 0);
        $taxValue = (float)($split['tax'] ?? 0);
        $unitBasePrice = $qty > 0 ? round($lineBase / $qty, 6) : 0.0;
        $service = $serviceMap[(string)($item['productId'] ?? '')] ?? null;
        $iceValue = facturacionRound((float)($item['valorICE'] ?? ($service['iceValue'] ?? 0)));

        $detailItems[] = [
            'productId' => trim((string)($item['productId'] ?? '')),
            'codigoPrincipal' => trim((string)($item['codigoPrincipal'] ?? ($service['codigoPrincipal'] ?? ($item['barcode'] ?? '')))),
            'codigoAuxiliar' => trim((string)($item['codigoAuxiliar'] ?? ($service['codigoAuxiliar'] ?? ($item['barcode'] ?? '')))),
            'descripcion' => trim((string)($item['descripcion'] ?? '')),
            'cantidad' => $qty,
            // SRI requiere base imponible en detalle, no precio final con IVA.
            'precioUnitario' => $unitBasePrice,
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
        'environment' => $resolvedEnvironment,
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
    if (facturacionCanUseDb()) {
        return facturacionPersistDocumentDb($document);
    }

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
    if (facturacionCanUseDb()) {
        return facturacionFindDocumentDb($id);
    }

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

    $xsdPath = facturacionStoragePath('xsd_factura_sri/XML y XSD Factura/factura_V2.1.0.xsd');
    $xmldsigXsdPath = facturacionStoragePath('xsd_factura_sri/XML y XSD Factura/xmldsig-core-schema.xsd');
    if (!file_exists($xsdPath)) {
        $errors[] = 'No se encontro el XSD oficial factura_V2.1.0.xsd para validar el comprobante.';
    } elseif (!file_exists($xmldsigXsdPath)) {
        $errors[] = 'No se encontro xmldsig-core-schema.xsd requerido por el XSD oficial.';
    } else {
        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $isValidSchema = $dom->schemaValidate($xsdPath);
        if (!$isValidSchema) {
            foreach (libxml_get_errors() as $libxmlError) {
                $msg = trim((string)($libxmlError->message ?? ''));
                if ($msg !== '') {
                    $errors[] = 'XSD: ' . $msg;
                }
            }
            libxml_clear_errors();
        }
        libxml_use_internal_errors($previousUseErrors);
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
    ];
}

