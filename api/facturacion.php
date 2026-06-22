<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../facturacion/helpers.php';
require_once __DIR__ . '/../models/legacy_store.php';
require_once __DIR__ . '/../facturacion/generar_xml.php';
require_once __DIR__ . '/../facturacion/firmar_xml.php';
require_once __DIR__ . '/../facturacion/enviar_sri.php';
require_once __DIR__ . '/../facturacion/autorizar.php';
require_once __DIR__ . '/../facturacion/generar_pdf.php';
require_once __DIR__ . '/../facturacion/enviar_email.php';

session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    errorResponse('Sesion no valida', 401);
}

$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));

function facturacionProductSnapshot(): array
{
    $products = function_exists('legacyReadProducts')
        ? legacyReadProducts()
        : readJsonFile(storagePath('products.json'));
    $services = facturacionLoadProductServices();
    $serviceMap = [];
    foreach ($services as $service) {
        $serviceMap[(string)($service['productId'] ?? '')] = $service;
    }

    return array_map(static function ($product) use ($serviceMap) {
        $productId = (string)($product['id'] ?? '');
        $service = $serviceMap[$productId] ?? [];
        return [
            'id' => $productId,
            'barcode' => $product['barcode'] ?? '',
            'name' => $product['name'] ?? '',
            'price' => (float)($product['price'] ?? 0),
            'iva' => $product['iva'] ?? 'No',
            'service' => $service,
        ];
    }, $products);
}

function facturacionEmailAlreadyQueued(string $documentId): bool
{
    if ($documentId === '') {
        return false;
    }
    $jobs = facturacionReadJson('emails.json', []);
    foreach ($jobs as $job) {
        if ((string)($job['documentId'] ?? '') !== $documentId) {
            continue;
        }
        $status = strtolower((string)($job['status'] ?? ''));
        if (in_array($status, ['queued_mock', 'sent_brevo'], true)) {
            return true;
        }
    }
    return false;
}

function facturacionInferIdentificationType(string $identification): string
{
    $digits = preg_replace('/\D+/', '', $identification) ?? '';
    if (strlen($digits) === 13) {
        return 'RUC';
    }
    if (strlen($digits) === 10) {
        return 'Cedula';
    }
    return 'Consumidor final';
}

function facturacionAcquireAutoAuthLock()
{
    $lockPath = facturacionStoragePath('autorizacion_auto.lock');
    $handle = @fopen($lockPath, 'c+');
    if (!is_resource($handle)) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function facturacionReleaseAutoAuthLock($lockHandle): void
{
    if (!is_resource($lockHandle)) {
        return;
    }
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
}

function facturacionShouldRetryAuthorization(array $document, int $minSecondsBetweenChecks = 45): bool
{
    $status = strtoupper(trim((string)($document['status'] ?? '')));
    $auth = strtoupper(trim((string)($document['sri']['authorizationStatus'] ?? '')));
    $reception = strtoupper(trim((string)($document['sri']['receptionStatus'] ?? '')));
    if ($status === 'AUTHORIZED' || $auth === 'AUT') {
        return false;
    }
    if ($reception !== 'RECIBIDA') {
        return false;
    }
    if (!in_array($auth, ['', 'PPR'], true)) {
        return false;
    }

    $lastCheck = (string)($document['sri']['authorizationLastCheckAt'] ?? '');
    if ($lastCheck === '') {
        return true;
    }
    $lastTs = strtotime($lastCheck);
    if ($lastTs === false) {
        return true;
    }
    return (time() - $lastTs) >= $minSecondsBetweenChecks;
}

function facturacionFinalizeIfAuthorized(array $document): array
{
    $auth = strtoupper(trim((string)($document['sri']['authorizationStatus'] ?? '')));
    if ($auth !== 'AUT' && strtolower((string)($document['status'] ?? '')) !== 'authorized') {
        return $document;
    }

    if (!facturacionHasDocumentFile($document, 'pdf')) {
        $document = generarPDF($document);
        $document = facturacionPersistDocument($document);
    }
    if (!facturacionEmailAlreadyQueued((string)($document['id'] ?? ''))) {
        $document = enviarEmailCliente($document);
        $document = facturacionPersistDocument($document);
    }
    return $document;
}

function facturacionAutoProcessPendingAuthorizations(): void
{
    $signature = facturacionLoadSignature();
    $lock = facturacionAcquireAutoAuthLock();
    if ($lock === false) {
        return;
    }

    try {
        $documents = facturacionLoadDocuments();
        if ($documents === []) {
            return;
        }

        $processed = 0;
        $maxPerRun = 4;
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }
            if (!facturacionShouldRetryAuthorization($document)) {
                continue;
            }

            $documentId = (string)($document['id'] ?? '');
            try {
                $document['sri']['authorizationLastCheckAt'] = date('c');
                $attemptsAuto = (int)($document['sri']['authorizationAttemptsAuto'] ?? 0);
                $dynamicAttempts = min(20, max(2, 2 + intdiv($attemptsAuto, 2)));
                $dynamicSleepMicros = min(5000000, 750000 + ($attemptsAuto * 250000));
                $document = consultarAutorizacion($document, [
                    'maxAttempts' => $dynamicAttempts,
                    'sleepMicros' => $dynamicSleepMicros,
                    'logRawResponse' => true,
                ]);
                $document['sri']['authorizationLastCheckAt'] = date('c');
                $document['sri']['authorizationAttemptsAuto'] = (int)($document['sri']['authorizationAttemptsAuto'] ?? 0) + 1;
                $document = facturacionPersistDocument($document);
                $document = facturacionFinalizeIfAuthorized($document);
            } catch (Throwable $e) {
                $document['sri']['authorizationLastCheckAt'] = date('c');
                $document['sri']['authorizationAttemptsAuto'] = (int)($document['sri']['authorizationAttemptsAuto'] ?? 0) + 1;
                $document = facturacionPersistDocument($document);
                facturacionAppendLog('warning', 'Fallo en reintento automatico de autorizacion', [
                    'documentId' => $documentId,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed += 1;
            if ($processed >= $maxPerRun) {
                break;
            }
        }
    } finally {
        facturacionReleaseAutoAuthLock($lock);
    }
}

facturacionAutoProcessPendingAuthorizations();

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? 'overview')));
    $documents = facturacionLoadDocuments();

    if ($action === 'overview') {
        ok([
            'emitter' => facturacionLoadEmitter(),
            'signature' => facturacionLoadSignature(),
            'points' => facturacionLoadPoints(),
            'documentsCount' => count($documents),
        ]);
    }

    if ($action === 'emitter') {
        ok(facturacionLoadEmitter());
    }

    if ($action === 'signature') {
        ok(facturacionLoadSignature());
    }

    if ($action === 'points') {
        ok(facturacionLoadPoints());
    }

    if ($action === 'product_services') {
        ok([
            'services' => facturacionLoadProductServices(),
            'products' => facturacionProductSnapshot(),
        ]);
    }

    if ($action === 'invoice_defaults') {
        $customers = readJsonFile(storagePath('customers.json'));
        $products = function_exists('legacyReadProducts')
            ? legacyReadProducts()
            : readJsonFile(storagePath('products.json'));
        ok([
            'emitter' => facturacionLoadEmitter(),
            'points' => facturacionLoadPoints(),
            'customers' => $customers,
            'products' => $products,
            'signature' => facturacionLoadSignature(),
        ]);
    }

    if ($action === 'sale_context') {
        $ticketId = trim((string)($_GET['ticketId'] ?? ''));
        if ($ticketId === '') {
            errorResponse('Ticket requerido', 400);
        }

        $sales = readJsonFile(storagePath('sales.json'));
        $sale = null;
        foreach ($sales as $row) {
            if ((string)($row['ticketId'] ?? '') === $ticketId) {
                $sale = $row;
                break;
            }
        }
        if ($sale === null) {
            errorResponse('Ticket no encontrado', 404);
        }

        $customers = readJsonFile(storagePath('customers.json'));
        $customer = null;
        $saleCustomerId = trim((string)($sale['customerId'] ?? ''));
        if ($saleCustomerId !== '') {
            foreach ($customers as $row) {
                if ((string)($row['id'] ?? '') === $saleCustomerId) {
                    $customer = $row;
                    break;
                }
            }
        }

        $products = function_exists('legacyReadProducts')
            ? legacyReadProducts()
            : readJsonFile(storagePath('products.json'));
        $productMap = [];
        foreach ($products as $product) {
            $productMap[(string)($product['id'] ?? '')] = $product;
        }

        $services = facturacionLoadProductServices();
        $serviceMap = [];
        foreach ($services as $service) {
            $serviceMap[(string)($service['productId'] ?? '')] = $service;
        }

        $detailItems = [];
        foreach (($sale['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = trim((string)($item['id'] ?? ''));
            if ($productId === '') {
                continue;
            }
            $product = $productMap[$productId] ?? [];
            $service = $serviceMap[$productId] ?? [];
            $detailItems[] = [
                'productId' => $productId,
                'codigoPrincipal' => trim((string)($service['codigoPrincipal'] ?? ($item['barcode'] ?? $product['barcode'] ?? $productId))),
                'codigoAuxiliar' => trim((string)($service['codigoAuxiliar'] ?? ($item['barcode'] ?? $product['barcode'] ?? ''))),
                'cantidad' => max(0.0, (float)($item['qty'] ?? 0)),
                'descripcion' => trim((string)($item['name'] ?? $product['name'] ?? '')),
                'precioUnitario' => max(0.0, (float)($item['price'] ?? $product['price'] ?? 0)),
                'iva' => (string)($item['iva'] ?? ($product['iva'] ?? 'No')),
                'descuento' => 0.0,
                'valorICE' => max(0.0, (float)($service['iceValue'] ?? 0)),
            ];
        }

        $buyerIdentification = trim((string)($customer['taxId'] ?? ''));
        if ($buyerIdentification === '' && !preg_match('/^c-\d+$/i', $saleCustomerId)) {
            $buyerIdentification = $saleCustomerId;
        }
        if ($buyerIdentification === '') {
            $buyerIdentification = '9999999999999';
        }

        $buyerName = trim((string)($customer['name'] ?? ($sale['customerName'] ?? 'Consumidor final')));
        if ($buyerName === '') {
            $buyerName = 'Consumidor final';
        }

        $paymentMethod = strtolower(trim((string)($sale['paymentMethod'] ?? 'cash')));
        $total = max(0.0, (float)($sale['total'] ?? 0));
        $mixed = is_array($sale['mixedPayments'] ?? null) ? $sale['mixedPayments'] : [];
        $paymentMap = [
            'cash' => 'cash',
            'credit' => 'credit',
            'card' => 'credit_card',
            'transfer' => 'transfer',
            'invoice' => 'other',
            'voucher' => 'other',
            'check' => 'other',
        ];
        $payments = [];
        if ($paymentMethod === 'mixed') {
            $cashPart = max(0.0, (float)($mixed['cash'] ?? 0));
            $transferPart = max(0.0, (float)($mixed['transfer'] ?? 0));
            $creditPart = max(0.0, (float)($mixed['credit'] ?? 0));
            if ($cashPart > 0) {
                $payments[] = ['method' => 'cash', 'label' => 'Efectivo', 'value' => $cashPart, 'term' => 0, 'timeUnit' => 'dias'];
            }
            if ($transferPart > 0) {
                $payments[] = ['method' => 'transfer', 'label' => 'Transferencia', 'value' => $transferPart, 'term' => 0, 'timeUnit' => 'dias'];
            }
            if ($creditPart > 0) {
                $payments[] = ['method' => 'credit', 'label' => 'Credito', 'value' => $creditPart, 'term' => 30, 'timeUnit' => 'dias'];
            }
        } else {
            $mappedMethod = $paymentMap[$paymentMethod] ?? 'cash';
            $label = match ($mappedMethod) {
                'cash' => 'Efectivo',
                'transfer' => 'Transferencia',
                'credit' => 'Credito',
                'credit_card' => 'Tarjeta de credito',
                'other' => $paymentMethod === 'invoice' ? 'Factura' : 'Otro',
                default => 'Otro',
            };
            $payments[] = [
                'method' => $mappedMethod,
                'label' => $label,
                'value' => $total,
                'term' => $mappedMethod === 'credit' ? 30 : 0,
                'timeUnit' => 'dias'
            ];
        }

        ok([
            'ticketId' => $ticketId,
            'sale' => $sale,
            'buyer' => [
                'identification' => $buyerIdentification,
                'identificationType' => facturacionInferIdentificationType($buyerIdentification),
                'razonSocial' => $buyerName,
                'address' => trim((string)($customer['address1'] ?? '')),
                'phone' => trim((string)($customer['phone'] ?? '')),
                'email' => trim((string)($customer['email'] ?? '')),
            ],
            'details' => $detailItems,
            'payments' => $payments,
        ]);
    }

    if ($action === 'documents') {
        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        $docType = trim((string)($_GET['docType'] ?? ''));
        $rows = array_values(array_filter($documents, static function ($row) use ($status, $docType) {
            $rowStatus = strtolower((string)($row['status'] ?? ''));
            $rowDocType = (string)($row['docType'] ?? '');
            if ($status !== '' && $rowStatus !== $status) {
                return false;
            }
            if ($docType !== '' && $rowDocType !== $docType) {
                return false;
            }
            return true;
        }));
        usort($rows, static fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
        ok($rows);
    }

    if ($action === 'document') {
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') {
            errorResponse('Documento requerido', 400);
        }
        $document = facturacionFindDocument($id);
        if ($document === null) {
            errorResponse('Documento no encontrado', 404);
        }
        ok($document);
    }

    if ($action === 'document_file') {
        $id = trim((string)($_GET['id'] ?? ''));
        $kind = strtolower(trim((string)($_GET['kind'] ?? 'pdf')));
        if ($id === '') {
            errorResponse('Documento requerido', 400);
        }
        if (!in_array($kind, ['pdf', 'generatedXml', 'signedXml', 'authorizedXml'], true)) {
            errorResponse('Tipo de archivo no soportado', 400);
        }
        $document = facturacionFindDocument($id);
        if ($document === null) {
            errorResponse('Documento no encontrado', 404);
        }
        $payload = facturacionReadDocumentFile($document, $kind);
        if ($payload === null) {
            errorResponse('Archivo no disponible', 404);
        }

        $fileName = (string)($payload['fileName'] ?? ($kind === 'pdf' ? 'documento.pdf' : 'documento.xml'));
        $mimeType = (string)($payload['mimeType'] ?? ($kind === 'pdf' ? 'application/pdf' : 'application/xml'));
        $content = (string)($payload['content'] ?? '');

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . strlen($content));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $fileName) . '"');
        echo $content;
        exit;
    }

    if ($action === 'logs') {
        ok(facturacionReadJson('logs.json', []));
    }

    errorResponse('Accion no soportada', 400);
}

$body = str_contains($contentType, 'multipart/form-data') ? $_POST : $request['body'];
if (!is_array($body)) {
    errorResponse('Cuerpo invalido', 400);
}

if ($method === 'POST') {
    $action = strtolower(trim((string)($body['action'] ?? '')));

    try {
        switch ($action) {
            case 'save_emitter':
                ok(facturacionSaveEmitter($body));
                break;

            case 'save_signature':
                if (str_contains($contentType, 'multipart/form-data')) {
                    if (isset($_FILES['certificatePath']) && is_array($_FILES['certificatePath'])) {
                        $body['certificatePath'] = facturacionStoreUploadedCertificate($_FILES['certificatePath'], (string)(facturacionLoadSignature()['certificatePath'] ?? ''));
                    }
                }
                ok(facturacionSaveSignature($body));
                break;

            case 'test_signature':
                $currentSignature = facturacionLoadSignature();
                $certificatePathInput = trim((string)($body['certificatePath'] ?? ''));
                $certificatePasswordInput = (string)($body['certificatePassword'] ?? '');
                $certificatePath = $certificatePathInput !== ''
                    ? $certificatePathInput
                    : trim((string)($currentSignature['certificatePath'] ?? ''));
                $certificatePassword = $certificatePasswordInput !== ''
                    ? $certificatePasswordInput
                    : (string)($currentSignature['certificatePassword'] ?? '');
                $tempPath = '';

                if (str_contains($contentType, 'multipart/form-data') && isset($_FILES['certificatePath']) && is_array($_FILES['certificatePath'])) {
                    $upload = $_FILES['certificatePath'];
                    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $tempPath = facturacionStoreUploadedCertificate($upload, '');
                        $certificatePath = $tempPath;
                    }
                }

                try {
                    try {
                        $meta = facturacionReadCertificateMetadata($certificatePath, $certificatePassword);
                        ok([
                            'valid' => true,
                            'message' => 'Certificado valido y listo para firma.',
                            'meta' => $meta,
                        ]);
                    } catch (Throwable $e) {
                        facturacionAppendLog('error', $e->getMessage(), [
                            'trace' => $e->getTraceAsString(),
                            'runtime' => [
                                'phpBinary' => PHP_BINARY,
                                'phpSapi' => PHP_SAPI,
                                'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : '',
                            ],
                        ]);
                        ok([
                            'valid' => false,
                            'message' => $e->getMessage(),
                            'runtime' => [
                                'phpBinary' => PHP_BINARY,
                                'phpSapi' => PHP_SAPI,
                                'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : '',
                            ],
                        ]);
                    }
                } finally {
                    if ($tempPath !== '' && file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                }
                break;

            case 'test_email':
                $currentSignature = facturacionLoadSignature();
                $signature = array_merge($currentSignature, [
                    'emailMode' => in_array((string)($body['emailMode'] ?? ($currentSignature['emailMode'] ?? 'mock')), ['mock', 'brevo_api'], true)
                        ? (string)($body['emailMode'] ?? ($currentSignature['emailMode'] ?? 'mock'))
                        : 'mock',
                    'brevoApiKey' => trim((string)($body['brevoApiKey'] ?? ($currentSignature['brevoApiKey'] ?? ''))),
                    'brevoEndpoint' => trim((string)($body['brevoEndpoint'] ?? ($currentSignature['brevoEndpoint'] ?? 'https://api.brevo.com/v3/smtp/email'))),
                    'fromEmail' => trim((string)($body['fromEmail'] ?? ($currentSignature['fromEmail'] ?? ''))),
                    'fromName' => trim((string)($body['fromName'] ?? ($currentSignature['fromName'] ?? ''))),
                ]);
                $toEmail = trim((string)($body['testEmail'] ?? ''));
                if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    errorResponse('Ingrese un correo valido para la prueba.', 400);
                }

                if (($signature['emailMode'] ?? 'mock') === 'mock') {
                    ok([
                        'sent' => false,
                        'message' => 'El modo de correo esta en Mock / cola interna. Cambielo a Brevo API para enviar un correo real.',
                        'mode' => 'mock',
                    ]);
                }

                $document = [
                    'id' => 'test-mail-' . date('YmdHis'),
                    'docName' => 'Prueba de correo',
                    'secuencial' => date('His'),
                    'accessKey' => 'PRUEBA-' . date('YmdHis'),
                    'buyer' => [
                        'razonSocial' => 'Prueba de correo',
                        'email' => $toEmail,
                    ],
                    'files' => [
                        'authorizedXml' => '',
                        'pdf' => '',
                    ],
                ];

                $response = facturacionBrevoSend($signature, $document, $toEmail);
                facturacionAppendLog('info', 'Correo de prueba enviado por Brevo API', [
                    'to' => $toEmail,
                    'response' => $response,
                ]);
                ok([
                    'sent' => true,
                    'message' => 'Correo de prueba enviado correctamente a ' . $toEmail . '.',
                    'mode' => 'brevo_api',
                    'response' => $response,
                ]);
                break;

            case 'save_point':
                ok(facturacionSavePoint($body));
                break;

            case 'save_product_service':
                ok(facturacionSaveProductService($body));
                break;

            case 'sync_product_services':
                ok(facturacionSyncProductServicesFromProducts());
                break;

            case 'save_invoice_draft':
                $document = facturacionBuildInvoiceDocument($body);
                $document['status'] = 'draft';
                ok(facturacionPersistDocument($document));
                break;

            case 'issue_invoice':
                $document = facturacionBuildInvoiceDocument($body);
                $document = facturacionPersistDocument($document);
                $document = generarXMLFactura($document);
                $document = facturacionPersistDocument($document);
                $document = firmarXML($document);
                $document = facturacionPersistDocument($document);
                $document = enviarSRI($document);
                $document = facturacionPersistDocument($document);
                $document = consultarAutorizacion($document, ['maxAttempts' => 3, 'sleepMicros' => 750000, 'logRawResponse' => true]);
                $document = facturacionPersistDocument($document);
                if ((string)($document['sri']['authorizationStatus'] ?? '') === 'AUT' || (string)($document['status'] ?? '') === 'authorized') {
                    $document = generarPDF($document);
                    $document = facturacionPersistDocument($document);
                    $document = enviarEmailCliente($document);
                    $document = facturacionPersistDocument($document);
                } else {
                    facturacionAppendLog('warning', 'Se omite PDF/correo: comprobante aun no autorizado por SRI', [
                        'documentId' => $document['id'] ?? null,
                        'authorizationStatus' => $document['sri']['authorizationStatus'] ?? null,
                        'status' => $document['status'] ?? null,
                    ]);
                }

                $points = facturacionLoadPoints();
                foreach ($points as $index => $point) {
                    if ((string)($point['id'] ?? '') !== (string)($document['establishmentId'] ?? '')) {
                        continue;
                    }
                    $points[$index]['secuencialActual'] = (int)($document['secuencial'] ?? 0);
                    break;
                }
                facturacionWriteJson('puntos.json', $points);

                ok($document);
                break;

            case 'reprocess_document':
                $id = trim((string)($body['id'] ?? ''));
                if ($id === '') {
                    errorResponse('Documento requerido', 400);
                }
                $document = facturacionFindDocument($id);
                if ($document === null) {
                    errorResponse('Documento no encontrado', 404);
                }
                $document = generarXMLFactura($document);
                $document = facturacionPersistDocument($document);
                $document = firmarXML($document);
                $document = facturacionPersistDocument($document);
                $document = enviarSRI($document);
                $document = facturacionPersistDocument($document);
                $document = consultarAutorizacion($document);
                $document = facturacionPersistDocument($document);
                if ((string)($document['sri']['authorizationStatus'] ?? '') === 'AUT' || (string)($document['status'] ?? '') === 'authorized') {
                    $document = generarPDF($document);
                    $document = facturacionPersistDocument($document);
                    $document = enviarEmailCliente($document);
                    $document = facturacionPersistDocument($document);
                } else {
                    facturacionAppendLog('warning', 'Se omite PDF/correo en reproceso: comprobante aun no autorizado por SRI', [
                        'documentId' => $document['id'] ?? null,
                        'authorizationStatus' => $document['sri']['authorizationStatus'] ?? null,
                        'status' => $document['status'] ?? null,
                    ]);
                }
                ok($document);
                break;

            case 'refresh_authorization':
                $id = trim((string)($body['id'] ?? ''));
                if ($id === '') {
                    errorResponse('Documento requerido', 400);
                }
                $document = facturacionFindDocument($id);
                if ($document === null) {
                    errorResponse('Documento no encontrado', 404);
                }

                if (!facturacionHasDocumentFile($document, 'signedXml')) {
                    throw new RuntimeException('No existe XML firmado para consultar autorizacion.');
                }

                $previousAuth = (string)($document['sri']['authorizationStatus'] ?? '');
                $document = consultarAutorizacion($document);
                $document = facturacionPersistDocument($document);

                $currentAuth = (string)($document['sri']['authorizationStatus'] ?? '');
                if ($currentAuth === 'AUT') {
                    if (!facturacionHasDocumentFile($document, 'pdf')) {
                        $document = generarPDF($document);
                        $document = facturacionPersistDocument($document);
                    }
                    if (!facturacionEmailAlreadyQueued((string)($document['id'] ?? ''))) {
                        $document = enviarEmailCliente($document);
                        $document = facturacionPersistDocument($document);
                    }
                    facturacionAppendLog('info', 'Consulta de autorizacion manual completada', [
                        'documentId' => $document['id'] ?? null,
                        'previousAuthorizationStatus' => $previousAuth,
                        'authorizationStatus' => $currentAuth,
                    ]);
                } else {
                    facturacionAppendLog('warning', 'Consulta de autorizacion manual sin cambio a AUT', [
                        'documentId' => $document['id'] ?? null,
                        'previousAuthorizationStatus' => $previousAuth,
                        'authorizationStatus' => $currentAuth,
                    ]);
                }

                ok($document);
                break;
        }
    } catch (Throwable $e) {
        facturacionAppendLog('error', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        errorResponse($e->getMessage(), 500);
    }

    errorResponse('Accion no soportada', 400);
}

if ($method === 'DELETE') {
    $action = strtolower(trim((string)($body['action'] ?? '')));
    if ($action === 'delete_point') {
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') {
            errorResponse('Punto requerido', 400);
        }
        if (!facturacionDeletePoint($id)) {
            errorResponse('Punto no encontrado', 404);
        }
        ok(['id' => $id]);
    }
}

errorResponse('Metodo no soportado', 405);

