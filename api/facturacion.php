<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../facturacion/helpers.php';
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
    $products = readJsonFile(storagePath('products.json'));
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
        $products = readJsonFile(storagePath('products.json'));
        ok([
            'emitter' => facturacionLoadEmitter(),
            'points' => facturacionLoadPoints(),
            'customers' => $customers,
            'products' => $products,
            'signature' => facturacionLoadSignature(),
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
                $certificatePath = trim((string)($body['certificatePath'] ?? $currentSignature['certificatePath'] ?? ''));
                $certificatePassword = (string)($body['certificatePassword'] ?? $currentSignature['certificatePassword'] ?? '');
                $tempPath = '';

                if (str_contains($contentType, 'multipart/form-data') && isset($_FILES['certificatePath']) && is_array($_FILES['certificatePath'])) {
                    $upload = $_FILES['certificatePath'];
                    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $tempPath = facturacionStoreUploadedCertificate($upload, '');
                        $certificatePath = $tempPath;
                    }
                }

                try {
                    $meta = facturacionReadCertificateMetadata($certificatePath, $certificatePassword);
                    ok([
                        'valid' => true,
                        'message' => 'Certificado valido y listo para firma.',
                        'meta' => $meta,
                    ]);
                } finally {
                    if ($tempPath !== '' && file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                }
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
                $document = consultarAutorizacion($document);
                $document = facturacionPersistDocument($document);
                $document = generarPDF($document);
                $document = facturacionPersistDocument($document);
                $document = enviarEmailCliente($document);
                $document = facturacionPersistDocument($document);

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
                $document = generarPDF($document);
                $document = facturacionPersistDocument($document);
                $document = enviarEmailCliente($document);
                $document = facturacionPersistDocument($document);
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

