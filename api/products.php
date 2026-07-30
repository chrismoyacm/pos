<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/db.php';
require_once __DIR__ . '/../utils/persistence.php';

$productsPath = storagePath('products.json');
$promotionsPath = storagePath('promotions.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

/**
 * @param array<int, array<string, mixed>> $products
 */
function nextProductId(array $products): string
{
    $max = 1000;
    foreach ($products as $product) {
        $id = (string)($product['id'] ?? '');
        if (preg_match('/^p-(\d+)$/i', $id, $matches) === 1) {
            $max = max($max, (int)$matches[1]);
        }
    }
    return 'p-' . (string)($max + 1);
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function nextPromotionId(array $items): string
{
    $max = 0;
    foreach ($items as $item) {
        $id = (string)($item['id'] ?? '');
        if (preg_match('/^promo-(\d+)$/i', $id, $m) === 1) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'promo-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * @return array<int, array{id:string,name:string,percentage:float,active:bool}>
 */
function readIvaOptionsFromDb(): array
{
    if (!dbEnabled()) {
        return [];
    }

    try {
        $rows = db()->query(
            "SELECT ID, NOMBRE, PORCENTAJE, ACTIVO
             FROM IMPUESTOS
             WHERE ORIGEN = 'productos' AND TIPO = 'iva'
             ORDER BY CAST(ID AS UNSIGNED) ASC, ID ASC"
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $options = [];
    foreach ($rows as $row) {
        $rateRaw = str_replace(['iva', '%', ' '], '', strtolower(trim((string)($row['PORCENTAJE'] ?? ''))));
        if (!is_numeric($rateRaw)) {
            continue;
        }
        $rate = (float)$rateRaw;
        if ($rate < 0) {
            continue;
        }
        $id = trim((string)($row['ID'] ?? ''));
        if ($id === '') {
            continue;
        }

        $options[] = [
            'id' => $id,
            'name' => trim((string)($row['NOMBRE'] ?? ('IVA ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%'))),
            'percentage' => $rate,
            'active' => ((string)($row['ACTIVO'] ?? '1') === '1'),
        ];
    }

    return $options;
}

function parseProductDbId(string $productId): ?int
{
    $id = trim($productId);
    if ($id === '') {
        return null;
    }

    if (preg_match('/^p-(\d+)$/i', $id, $m) === 1) {
        return (int)$m[1];
    }
    if (preg_match('/^\d+$/', $id) === 1) {
        return (int)$id;
    }

    return null;
}

function findProductDbIdByBarcode(string $barcode): ?int
{
    $code = trim($barcode);
    if ($code === '' || !dbEnabled()) {
        return null;
    }

    $stmt = db()->prepare('SELECT ID FROM PRODUCTOS WHERE CODIGO = :code ORDER BY CAST(ID AS UNSIGNED) ASC LIMIT 1');
    $stmt->execute([':code' => $code]);
    $value = $stmt->fetchColumn();
    if ($value === false || $value === null || $value === '') {
        return null;
    }
    return (int)$value;
}

function resolveTaxIdForIvaValue(string $ivaValue): string
{
    $normalized = strtolower(trim($ivaValue));
    if ($normalized === '' || in_array($normalized, ['no', '0', 'false'], true)) {
        return '0';
    }

    $options = readIvaOptionsFromDb();
    if ($options === []) {
        if (is_numeric($normalized) && (float)$normalized > 0) {
            return (string)(int)$normalized;
        }
        return '0';
    }

    foreach ($options as $opt) {
        $id = trim((string)($opt['id'] ?? ''));
        if ($id !== '' && strtolower($id) === $normalized) {
            return $id;
        }
    }

    $rateRaw = str_replace(['iva', '%', ' '], '', $normalized);
    if (is_numeric($rateRaw)) {
        $rate = (float)$rateRaw;
        foreach ($options as $opt) {
            $optRate = (float)($opt['percentage'] ?? 0);
            if ($optRate >= 0 && abs($optRate - $rate) < 0.0001) {
                return (string)$opt['id'];
            }
        }
    }

    if (in_array($normalized, ['si', 'sí', 'yes', 'true'], true)) {
        return (string)($options[0]['id'] ?? '0');
    }

    return (string)($options[0]['id'] ?? '0');
}

function persistProductTaxInDb(string $productJsonId, mixed $ivaValue, ?string $barcode = null): void
{
    if (!dbEnabled()) {
        return;
    }

    $dbId = parseProductDbId($productJsonId);
    if (($dbId === null || $dbId <= 0) && $barcode !== null) {
        $dbId = findProductDbIdByBarcode($barcode);
    }
    if ($dbId === null || $dbId <= 0) {
        return;
    }

    $taxId = resolveTaxIdForIvaValue((string)$ivaValue);
    $stmt = db()->prepare('UPDATE PRODUCTOS SET IMPUESTOS = :tax WHERE ID = :id');
    $stmt->execute([
        ':tax' => $taxId,
        ':id' => $dbId,
    ]);
}

/**
 * @param array<int, array<string, mixed>> $products
 */
function writeProductsSnapshotFast(string $productsPath, array $products): bool
{
    return writeJsonBackupFile($productsPath, $products);
}

function syncProductsBackupFromDb(string $productsPath): bool
{
    return writeProductsSnapshotFast($productsPath, legacyReadProducts());
}

/**
 * @param array<int, array<string, mixed>> $products
 */
function syncProductsOverlaySnapshot(array $products): void
{
    if (function_exists('legacyWriteProductsOverlay')) {
        legacyWriteProductsOverlay($products);
    }
}

function normalizeIvaValue(mixed $raw, ?string $fallback = null): string
{
    $value = trim((string)$raw);
    if ($value === '' && $fallback !== null) {
        $value = trim($fallback);
    }

    $normalized = strtolower(trim($value));
    if ($normalized === '' || in_array($normalized, ['no', '0', 'false'], true)) {
        return 'No';
    }

    $taxOptions = readIvaOptionsFromDb();
    $mapById = [];
    foreach ($taxOptions as $opt) {
        $id = trim((string)($opt['id'] ?? ''));
        $rate = (float)($opt['percentage'] ?? 0);
        if ($id !== '' && $rate >= 0) {
            $mapById[$id] = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
        }
    }
    if (isset($mapById[$value])) {
        return $mapById[$value];
    }

    $rawRate = str_replace(['iva', '%', ' '], '', $normalized);
    if (is_numeric($rawRate)) {
        $rate = (float)$rawRate;
        if ($rate >= 0 && str_contains($normalized, '%')) {
            return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
        }
        if ($rate > 0) {
            return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
        }
    }

    if (in_array($normalized, ['si', 'sí', 'yes', 'true'], true)) {
        return '12%';
    }

    return 'No';
}

function normalizeImportedBooleanValue(mixed $raw, bool $default = true): bool
{
    if ($raw === null) {
        return $default;
    }

    $value = strtolower(trim((string)$raw));
    if ($value === '') {
        return $default;
    }

    if (in_array($value, ['1', 'si', 'sí', 'true', 'yes', 'y'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'no', 'false', 'n'], true)) {
        return false;
    }

    return $default;
}

function normalizeImportedUnitTypeValue(mixed $raw): string
{
    $value = strtolower(trim((string)$raw));
    if ($value === '') {
        return 'unit';
    }

    return in_array($value, ['unit', 'bulk', 'package'], true) ? $value : '__invalid__';
}

function normalizeImportedNumberText(mixed $raw): string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return '';
    }

    $value = str_replace(' ', '', $value);
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $value) === 1) {
        return str_replace(',', '', $value);
    }
    if (preg_match('/^-?\d+,\d+$/', $value) === 1) {
        return str_replace(',', '.', $value);
    }

    return $value;
}

/** @return array<string, string> */
function importHeaderAliasMap(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $map = [
        'codigo' => 'barcode',
        'barcode' => 'barcode',
        'descripcion' => 'name',
        'description' => 'name',
        'name' => 'name',
        'pcosto' => 'cost',
        'cost' => 'cost',
        'pventa' => 'price',
        'price' => 'price',
        'pfinal' => 'finalPrice',
        'tventa' => 'saleType',
        'unittype' => 'saleType',
        'dept' => 'departmentId',
        'department' => 'departmentId',
        'departmentid' => 'departmentId',
        'department_id' => 'departmentId',
        'provid' => 'providerCode',
        'provider' => 'providerCode',
        'mayoreo' => 'wholesalePrice',
        'wholesaleprice' => 'wholesalePrice',
        'pmayoreofinal' => 'finalWholesalePrice',
        'dinventario' => 'stock',
        'stock' => 'stock',
        'dinvminimo' => 'minStock',
        'minstock' => 'minStock',
        'dinvmaximo' => 'maxStock',
        'maxstock' => 'maxStock',
        'porcentaje_ganancia' => 'margin',
        'margin' => 'margin',
        'impuestos' => 'iva',
        'iva' => 'iva',
        'es_kit' => 'isKit',
        'iskit' => 'isKit',
        'usa_inventario' => 'inventoryEnabled',
        'inventoryenabled' => 'inventoryEnabled',
    ];

    return $map;
}

/** @param array<string, mixed> $row
 *  @return array<string, mixed>
 */
function normalizeImportedLegacyProductRow(array $row): array
{
    $normalized = [];
    $aliasMap = importHeaderAliasMap();
    foreach ($row as $key => $value) {
        $canonical = $aliasMap[strtolower(trim((string)$key))] ?? null;
        if ($canonical === null) {
            continue;
        }
        $normalized[$canonical] = $value;
    }

    $price = normalizeImportedNumberText($normalized['finalPrice'] ?? '');
    if ($price === '') {
        $price = normalizeImportedNumberText($normalized['price'] ?? '');
    }

    $wholesalePrice = normalizeImportedNumberText($normalized['finalWholesalePrice'] ?? '');
    if ($wholesalePrice === '') {
        $wholesalePrice = normalizeImportedNumberText($normalized['wholesalePrice'] ?? '');
    }

    $saleType = strtoupper(trim((string)($normalized['saleType'] ?? 'U')));
    $isKitRaw = strtolower(trim((string)($normalized['isKit'] ?? '')));
    $isKit = in_array($isKitRaw, ['1', 'true', 't', 'si', 'sí', 's', 'yes', 'y'], true);
    $unitType = $isKit ? 'package' : ($saleType === 'D' ? 'bulk' : 'unit');

    return [
        'barcode' => trim((string)($normalized['barcode'] ?? '')),
        'name' => trim((string)($normalized['name'] ?? '')),
        'price' => $price,
        'cost' => normalizeImportedNumberText($normalized['cost'] ?? ''),
        'rawSaleType' => $saleType,
        'rawIsKit' => $isKitRaw,
        'departmentId' => normalizeImportedNumberText($normalized['departmentId'] ?? ''),
        'providerCode' => trim((string)($normalized['providerCode'] ?? '')),
        'stock' => normalizeImportedNumberText($normalized['stock'] ?? ''),
        'minStock' => normalizeImportedNumberText($normalized['minStock'] ?? ''),
        'maxStock' => normalizeImportedNumberText($normalized['maxStock'] ?? ''),
        'unitType' => $unitType,
        'wholesalePrice' => $wholesalePrice,
        'margin' => normalizeImportedNumberText($normalized['margin'] ?? ''),
        'iva' => trim((string)($normalized['iva'] ?? 'No')),
        'inventoryEnabled' => normalizeImportedBooleanValue($normalized['inventoryEnabled'] ?? null, !$isKit),
    ];
}

/**
 * @param array<int, array<string, mixed>> $products
 * @return array<string, int>
 */
function buildProductBarcodeIndex(array $products): array
{
    $barcodeMap = [];
    foreach ($products as $idx => $product) {
        $barcode = strtolower(trim((string)($product['barcode'] ?? '')));
        if ($barcode === '') {
            continue;
        }
        if (array_key_exists($barcode, $barcodeMap)) {
            errorResponse('Ya existe un producto duplicado con el codigo "' . ($product['barcode'] ?? '') . '" en la base de datos.', 409);
        }
        $barcodeMap[$barcode] = $idx;
    }

    return $barcodeMap;
}

/**
 * @param array<int, mixed> $rows
 * @param array<int, array<string, mixed>>|null $errors
 * @return array<int, array<string, mixed>>
 */
function validateImportedProductRows(array $rows, ?array &$errors = null): array
{
    if ($errors === null) {
        $errors = [];
    }

    $normalizedRows = [];
    $seenBarcodes = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $row = normalizeImportedLegacyProductRow($row);
        $line = $index + 2;
        $barcode = trim((string)($row['barcode'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        if ($barcode === '') {
            $errors[] = ['line' => $line, 'barcode' => $barcode, 'name' => $name, 'error' => 'No tiene codigo de barras.'];
            continue;
        }
        if ($name === '') {
            $errors[] = ['line' => $line, 'barcode' => $barcode, 'name' => $name, 'error' => 'No tiene nombre de producto.'];
            continue;
        }

        $barcodeKey = strtolower($barcode);
        if (isset($seenBarcodes[$barcodeKey])) {
            $errors[] = [
                'line' => $line,
                'barcode' => $barcode,
                'name' => $name,
                'error' => 'Codigo repetido en el archivo (filas ' . $seenBarcodes[$barcodeKey] . ' y ' . $line . ').',
            ];
            continue;
        }
        $seenBarcodes[$barcodeKey] = $line;

        $numericFields = [
            'price' => 'precio',
            'cost' => 'costo',
            'stock' => 'stock',
            'minStock' => 'stock minimo',
            'maxStock' => 'stock maximo',
            'wholesalePrice' => 'precio por mayor',
            'margin' => 'porcentaje de ganancia',
        ];
        foreach ($numericFields as $field => $label) {
            $text = trim((string)($row[$field] ?? ''));
            if ($text === '') {
                continue;
            }
            if (!is_numeric($text)) {
                $errors[] = ['line' => $line, 'barcode' => $barcode, 'name' => $name, 'error' => 'Tiene un ' . $label . ' invalido.'];
                continue 2;
            }
        }

        $rawSaleType = strtoupper(trim((string)($row['rawSaleType'] ?? '')));
        if ($rawSaleType !== '' && !in_array($rawSaleType, ['U', 'D'], true)) {
            $errors[] = ['line' => $line, 'barcode' => $barcode, 'name' => $name, 'error' => 'Tiene un TVENTA invalido. Usa U o D.'];
            continue;
        }

        $unitType = normalizeImportedUnitTypeValue($row['unitType'] ?? '');
        if ($unitType === '__invalid__') {
            $errors[] = ['line' => $line, 'barcode' => $barcode, 'name' => $name, 'error' => 'Tiene un tipo de venta invalido. Usa unit, bulk o package.'];
            continue;
        }

        $normalizedRows[] = [
            'barcode' => $barcode,
            'name' => $name,
            'price' => $row['price'] ?? '',
            'cost' => $row['cost'] ?? '',
            'departmentId' => trim((string)($row['departmentId'] ?? '')),
            'department' => trim((string)($row['department'] ?? '')),
            'stock' => $row['stock'] ?? '',
            'minStock' => $row['minStock'] ?? '',
            'maxStock' => $row['maxStock'] ?? '',
            'unitType' => $unitType,
            'wholesalePrice' => $row['wholesalePrice'] ?? '',
            'providerCode' => trim((string)($row['providerCode'] ?? '')),
            'provider' => trim((string)($row['provider'] ?? '')),
            'iva' => normalizeIvaValue($row['iva'] ?? 'No', 'No'),
            'margin' => $row['margin'] ?? '',
            'inventoryEnabled' => normalizeImportedBooleanValue($row['inventoryEnabled'] ?? null, true),
        ];
    }

    return $normalizedRows;
}

/**
 * @param array<string, mixed> $body
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function normalizePromotion(array $body, ?array $existing = null): array
{
    $name = trim((string)($body['name'] ?? ($existing['name'] ?? '')));
    $type = trim((string)($body['type'] ?? ($existing['type'] ?? 'percent')));
    $value = (float)($body['value'] ?? ($existing['value'] ?? 0));
    $startDate = trim((string)($body['startDate'] ?? ($existing['startDate'] ?? '')));
    $endDate = trim((string)($body['endDate'] ?? ($existing['endDate'] ?? '')));
    $active = (bool)($body['active'] ?? ($existing['active'] ?? true));
    $notes = trim((string)($body['notes'] ?? ($existing['notes'] ?? '')));
    $rawIds = $body['productIds'] ?? ($existing['productIds'] ?? []);
    $productIds = [];
    if (is_array($rawIds)) {
        foreach ($rawIds as $id) {
            $idStr = trim((string)$id);
            if ($idStr !== '') {
                $productIds[] = $idStr;
            }
        }
    }

    if ($name === '') {
        errorResponse('Nombre de promoción requerido', 400);
    }
    if (!in_array($type, ['percent', 'fixed'], true)) {
        errorResponse('Tipo de promoción inválido', 400);
    }
    if ($value <= 0) {
        errorResponse('Valor de promoción inválido', 400);
    }
    if ($startDate === '' || $endDate === '' || $endDate < $startDate) {
        errorResponse('Rango de fechas inválido', 400);
    }

    return [
        'id' => (string)($existing['id'] ?? ''),
        'name' => $name,
        'type' => $type,
        'value' => round($value, 2),
        'startDate' => $startDate,
        'endDate' => $endDate,
        'active' => $active,
        'productIds' => array_values(array_unique($productIds)),
        'notes' => $notes,
        'updatedAt' => date('c'),
        'createdAt' => (string)($existing['createdAt'] ?? date('c')),
    ];
}

/**
 * @param array<string, mixed> $body
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function normalizeProduct(array $body, ?array $existing = null): array
{
    $barcode = trim((string)($body['barcode'] ?? ($existing['barcode'] ?? '')));
    $name = trim((string)($body['name'] ?? ($existing['name'] ?? '')));
    $cost = round((float)($body['cost'] ?? ($existing['cost'] ?? 0)), 2);
    $margin = round((float)($body['margin'] ?? ($existing['margin'] ?? 20)), 2);
    $price = round((float)($body['finalPrice'] ?? ($body['price'] ?? ($existing['price'] ?? 0))), 2);
    $specialPrice = round((float)($body['specialPrice'] ?? ($existing['specialPrice'] ?? 0)), 2);
    $wholesalePrice = round((float)($body['finalWholesalePrice'] ?? ($body['wholesalePrice'] ?? ($existing['wholesale']['price'] ?? 0))), 2);
    $wholesaleMinQty = (int)($body['wholesaleMinQty'] ?? ($existing['wholesale']['minQty'] ?? 6));
    $stock = (int)($body['stock'] ?? ($existing['stock'] ?? 0));
    $minStock = (int)($body['minStock'] ?? ($existing['minStock'] ?? 0));
    $maxStock = (int)($body['maxStock'] ?? ($existing['maxStock'] ?? 0));
    $inventoryEnabled = (bool)($body['inventoryEnabled'] ?? ($existing['inventoryEnabled'] ?? true));
    $department = trim((string)($body['department'] ?? ($existing['department'] ?? 'Sin Departamento')));
    $departmentId = trim((string)($body['departmentId'] ?? ($existing['departmentId'] ?? '')));
    $unitType = trim((string)($body['unitType'] ?? ($existing['unitType'] ?? 'unit')));
    $provider = trim((string)($body['provider'] ?? ($existing['provider'] ?? '')));
    $providerCode = trim((string)($body['providerCode'] ?? ($existing['providerCode'] ?? $provider)));
    $iva = normalizeIvaValue($body['iva'] ?? null, (string)($existing['iva'] ?? 'No'));
    $rawPackageItems = $body['packageItems'] ?? ($existing['packageItems'] ?? []);
    $packageItems = [];

    if ($barcode !== '' && dbEnabled() && function_exists('legacyTableColumnMaxLengths')) {
        $maxLengths = legacyTableColumnMaxLengths('PRODUCTOS');
        $barcodeMax = (int)($maxLengths['CODIGO'] ?? 0);
        if ($barcodeMax > 0 && mb_strlen($barcode, 'UTF-8') > $barcodeMax) {
            errorResponse('El codigo de barras supera el maximo permitido de ' . $barcodeMax . ' caracteres.', 400);
        }
    }

    if (is_array($rawPackageItems)) {
        foreach ($rawPackageItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = trim((string)($item['productId'] ?? ''));
            $itemBarcode = trim((string)($item['barcode'] ?? ''));
            $itemName = trim((string)($item['name'] ?? ''));
            $qty = (int)($item['qty'] ?? 0);
            $itemStock = (int)($item['stock'] ?? 0);

            if ($qty <= 0 || ($productId === '' && $itemBarcode === '') || $itemName === '') {
                continue;
            }

            $packageItems[] = [
                'productId' => $productId,
                'barcode' => $itemBarcode,
                'name' => $itemName,
                'qty' => $qty,
                'stock' => max(0, $itemStock),
            ];
        }
    }

    if ($name === '') {
        errorResponse('Nombre requerido', 400);
    }
    if ($price < 0 || $specialPrice < 0 || $cost < 0 || $wholesalePrice < 0) {
        errorResponse('Precios inválidos', 400);
    }

    $isKitRaw = strtolower(trim((string)($body['isKit'] ?? '')));
    if (in_array($isKitRaw, ['1', 'true', 't', 'si', 'sí', 's', 'yes', 'y'], true)) {
        $unitType = 'package';
    }

    if ($unitType === 'package') {
        $inventoryEnabled = false;
    }

    $product = [
        'id' => (string)($existing['id'] ?? ''),
        'barcode' => $barcode,
        'name' => $name,
        'cost' => $cost,
        'margin' => $margin,
        'price' => $price,
        'specialPrice' => $specialPrice,
        'stock' => $stock,
        'minStock' => $minStock,
        'maxStock' => $maxStock,
        'inventoryEnabled' => $inventoryEnabled,
        'department' => $department === '' ? 'Sin Departamento' : $department,
        'departmentId' => is_numeric($departmentId) ? (int)$departmentId : 0,
        'unitType' => in_array($unitType, ['unit', 'bulk', 'package'], true) ? $unitType : 'unit',
        'provider' => $provider,
        'providerCode' => $providerCode,
        'iva' => $iva,
        'packageItems' => $unitType === 'package' ? $packageItems : [],
    ];

    if ($wholesalePrice > 0) {
        $product['wholesale'] = [
            'minQty' => max(2, $wholesaleMinQty),
            'price' => $wholesalePrice,
        ];
    } else {
        $product['wholesale'] = $existing['wholesale'] ?? null;
    }

    return $product;
}

/**
 * @param array<int, array<string, mixed>> $products
 */
function barcodeExists(array $products, string $barcode, ?string $excludeId = null): bool
{
    $needle = trim(strtolower($barcode));
    if ($needle === '') {
        return false;
    }

    foreach ($products as $product) {
        $id = (string)($product['id'] ?? '');
        if ($excludeId !== null && $excludeId !== '' && $id === $excludeId) {
            continue;
        }
        $candidate = trim(strtolower((string)($product['barcode'] ?? '')));
        if ($candidate !== '' && $candidate === $needle) {
            return true;
        }
    }

    return false;
}

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
    if ($action === 'taxes') {
        ok(readIvaOptionsFromDb());
    }
    if ($action === 'promotions') {
        $promotions = readJsonFile($promotionsPath);
        usort($promotions, static function ($a, $b) {
            return strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? ''));
        });
        ok($promotions);
    }

    $qRaw = trim((string)($_GET['q'] ?? ''));
    $q = strtolower($qRaw);
    $searchMode = strtolower(trim((string)($_GET['searchMode'] ?? 'contains')));
    $products = readJsonFile($productsPath);

    if ($q === '') {
        usort($products, static function ($a, $b): int {
            $aCode = strtolower((string)($a['barcode'] ?? $a['id'] ?? ''));
            $bCode = strtolower((string)($b['barcode'] ?? $b['id'] ?? ''));
            return strcmp($aCode, $bCode);
        });
        ok($products);
    }

    $filtered = array_values(array_filter($products, function ($p) use ($q, $searchMode) {
        $barcode = strtolower((string)($p['barcode'] ?? ''));
        $name = strtolower((string)($p['name'] ?? ''));
        $id = strtolower((string)($p['id'] ?? ''));
        $prefixMatch = static function (string $haystack) use ($q): bool {
            return $q !== '' && str_starts_with($haystack, $q);
        };
        $containsMatch = static function (string $haystack) use ($q): bool {
            return $q !== '' && str_contains($haystack, $q);
        };

        if ($barcode === $q || $id === $q) {
            return true;
        }

        if ($searchMode === 'prefix') {
            return $prefixMatch($barcode)
                || $prefixMatch($name)
                || $prefixMatch($id);
        }

        return $barcode === $q
            || $id === $q
            || $containsMatch($barcode)
            || $containsMatch($name)
            || $containsMatch($id);
    }));

    usort($filtered, function ($a, $b) use ($q) {
        $aBarcode = strtolower((string)($a['barcode'] ?? ''));
        $bBarcode = strtolower((string)($b['barcode'] ?? ''));
        $aId = strtolower((string)($a['id'] ?? ''));
        $bId = strtolower((string)($b['id'] ?? ''));
        $aExact = ($aBarcode === $q || $aId === $q) ? 0 : 1;
        $bExact = ($bBarcode === $q || $bId === $q) ? 0 : 1;
        if ($aExact !== $bExact) {
            return $aExact <=> $bExact;
        }
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    ok($filtered);
}

if ($method === 'POST') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inv�lido', 400);
    }

    $action = (string)($body['action'] ?? '');
    if ($action === 'create_promotion') {
        $promotions = readJsonFile($promotionsPath);
        $promo = normalizePromotion($body);
        $promo['id'] = nextPromotionId($promotions);
        $promotions[] = $promo;
        writeJsonFile($promotionsPath, $promotions);
        ok($promo);
    }

    if ($action === 'quick_sell_item') {
        $name = trim((string)($body['name'] ?? ''));
        $price = (float)($body['price'] ?? 0);
        $qty = (int)($body['qty'] ?? 1);
        if ($name === '' || $price < 0 || $qty <= 0) {
            errorResponse('Par�metros inv�lidos', 400);
        }
        ok([
            'id' => 'tmp-' . (string)time(),
            'barcode' => '',
            'name' => $name,
            'price' => $price,
            'stock' => null,
            'wholesale' => null,
        ]);
    }

    if ($action === 'update_product_tax') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            errorResponse('ID requerido', 400);
        }

        $products = readJsonFile($productsPath);
        $updated = null;
        foreach ($products as $idx => $product) {
            if ((string)($product['id'] ?? '') !== $id) {
                continue;
            }

            $product['iva'] = normalizeIvaValue($body['iva'] ?? null, (string)($product['iva'] ?? 'No'));
            $products[$idx] = $product;
            $updated = $product;
            break;
        }

        if ($updated === null) {
            errorResponse('Producto no encontrado', 404);
        }

        try {
            persistProductTaxInDb((string)$updated['id'], $updated['iva'] ?? 'No', (string)($updated['barcode'] ?? ''));
            if (dbEnabled()) {
                persistenceMarkDbHealthy('products_tax');
                syncProductsOverlaySnapshot($products);
                syncProductsBackupFromDb($productsPath);
            } else {
                writeProductsSnapshotFast($productsPath, $products);
                persistenceMarkDbFallback('products_tax');
            }
        } catch (Throwable) {
            writeProductsSnapshotFast($productsPath, $products);
            persistenceMarkDbFallback('products_tax');
        }

        ok($updated);
    }

    if ($action === 'update_product') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            errorResponse('ID requerido', 400);
        }

        $products = readJsonFile($productsPath);
        $updated = null;
        foreach ($products as $idx => $product) {
            if ((string)($product['id'] ?? '') !== $id) {
                continue;
            }
            $nextProduct = normalizeProduct($body, $product);
            if (barcodeExists($products, (string)($nextProduct['barcode'] ?? ''), $id)) {
                errorResponse('Ya existe un producto con ese c�digo de barras', 409);
            }
            $nextProduct['id'] = $id;
            $products[$idx] = $nextProduct;
            $updated = $nextProduct;
            break;
        }

        if ($updated === null) {
            errorResponse('Producto no encontrado', 404);
        }

        if (dbEnabled()) {
            if (!legacyUpsertProduct($updated)) {
                errorResponse('No se pudo actualizar el producto en la base de datos legacy', 500);
            }
            persistenceMarkDbHealthy('products_update');
            syncProductsOverlaySnapshot($products);
            syncProductsBackupFromDb($productsPath);
        } else {
            writeJsonFile($productsPath, $products);
            persistenceMarkDbFallback('products_update');
        }

        try {
            persistProductTaxInDb((string)$updated['id'], $updated['iva'] ?? 'No', (string)($updated['barcode'] ?? ''));
        } catch (Throwable) {
            // Ignorar para no bloquear flujo JSON si la tabla no existe.
        }

        ok($updated);
    }

    if ($action === 'delete_product') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            errorResponse('ID requerido', 400);
        }

        $products = readJsonFile($productsPath);
        $before = count($products);
        $products = array_values(array_filter($products, function ($product) use ($id) {
            return (string)($product['id'] ?? '') !== $id;
        }));

        if (count($products) === $before) {
            errorResponse('Producto no encontrado', 404);
        }

        if (dbEnabled()) {
            legacyDeleteProduct($id);
            persistenceMarkDbHealthy('products_delete');
            syncProductsOverlaySnapshot($products);
            syncProductsBackupFromDb($productsPath);
        } else {
            writeJsonFile($productsPath, $products);
            persistenceMarkDbFallback('products_delete');
        }

        ok(['id' => $id]);
    }

    if ($action !== 'create_product') {
        if ($action !== 'import_products') {
            errorResponse('Acci�n no soportada', 400);
        }

        $mode = strtolower(trim((string)($body['mode'] ?? 'merge')));
        $rows = $body['rows'] ?? null;
        if (!is_array($rows) || count($rows) === 0) {
            errorResponse('No hay filas para importar', 400);
        }
        $failedRows = [];
        $rows = validateImportedProductRows($rows, $failedRows);

        $products = $mode === 'replace' ? [] : readJsonFile($productsPath);

        /** @var array<string, int> $barcodeMap */
        $barcodeMap = buildProductBarcodeIndex($products);

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $barcode = strtolower(trim((string)($row['barcode'] ?? '')));
            $existing = null;
            $existingIndex = null;

            if ($barcode !== '' && array_key_exists($barcode, $barcodeMap)) {
                $existingIndex = $barcodeMap[$barcode];
                $existing = $products[$existingIndex] ?? null;
            }

            $normalized = normalizeProduct($row, is_array($existing) ? $existing : null);

            if (is_array($existing) && is_int($existingIndex)) {
                $normalized['id'] = (string)($existing['id'] ?? '');
                $products[$existingIndex] = $normalized;
                $updated++;
            } else {
                $normalized['id'] = nextProductId($products);
                $products[] = $normalized;
                $created++;
                if ($barcode !== '') {
                    $barcodeMap[$barcode] = count($products) - 1;
                }
            }
        }

        if (dbEnabled()) {
            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $id = (string)($product['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $wasInBatch = false;
                foreach ($rows as $row) {
                    if (strtolower(trim((string)($row['barcode'] ?? ''))) === strtolower(trim((string)($product['barcode'] ?? '')))) {
                        $wasInBatch = true;
                        break;
                    }
                }
                if (!$wasInBatch) {
                    continue;
                }
                if (!legacyUpsertProduct($product)) {
                    $failedRows[] = [
                        'line' => null,
                        'barcode' => (string)($product['barcode'] ?? ''),
                        'name' => (string)($product['name'] ?? ''),
                        'error' => 'No se pudo guardar en base de datos.',
                    ];
                }
            }
            persistenceMarkDbHealthy('products_import');
            writeProductsSnapshotFast($productsPath, $products);
            if (!empty($body['finalBatch'])) {
                syncProductsOverlaySnapshot($products);
            }
        } else {
            writeJsonFile($productsPath, $products);
            persistenceMarkDbFallback('products_import');
        }
        ok([
            'created' => $created,
            'updated' => $updated,
            'failed' => count($failedRows),
            'failedRows' => $failedRows,
            'total' => count($products),
            'mode' => $mode,
        ]);
    }

    $products = readJsonFile($productsPath);
    $product = normalizeProduct($body);
    if (barcodeExists($products, (string)($product['barcode'] ?? ''))) {
        errorResponse('Ya existe un producto con ese c�digo de barras', 409);
    }
    $product['id'] = nextProductId($products);
    $products[] = $product;

    if (dbEnabled()) {
        if (!legacyUpsertProduct($product)) {
            errorResponse('No se pudo guardar el producto en la base de datos legacy', 500);
        }
        persistenceMarkDbHealthy('products_create');
        syncProductsOverlaySnapshot($products);
        syncProductsBackupFromDb($productsPath);
    } else {
        writeJsonFile($productsPath, $products);
        persistenceMarkDbFallback('products_create');
    }

    try {
        persistProductTaxInDb((string)$product['id'], $product['iva'] ?? 'No', (string)($product['barcode'] ?? ''));
    } catch (Throwable) {
        // Ignorar para no bloquear flujo JSON si la tabla no existe.
    }
    ok($product);
}

if ($method === 'PATCH') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inv�lido', 400);
    }

    $action = (string)($body['action'] ?? '');
    if ($action === 'update_promotion') {
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') {
            errorResponse('ID requerido', 400);
        }

        $promotions = readJsonFile($promotionsPath);
        $updated = null;
        foreach ($promotions as $idx => $promo) {
            if ((string)($promo['id'] ?? '') !== $id) {
                continue;
            }
            $next = normalizePromotion($body, $promo);
            $next['id'] = $id;
            $promotions[$idx] = $next;
            $updated = $next;
            break;
        }

        if ($updated === null) {
            errorResponse('Promoci�n no encontrada', 404);
        }

        writeJsonFile($promotionsPath, $promotions);
        ok($updated);
    }

    $products = readJsonFile($productsPath);
    if ($action === 'update_product_tax') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            errorResponse('ID requerido', 400);
        }

        $updated = null;
        foreach ($products as $idx => $product) {
            if ((string)($product['id'] ?? '') !== $id) {
                continue;
            }

            $product['iva'] = normalizeIvaValue($body['iva'] ?? null, (string)($product['iva'] ?? 'No'));
            $products[$idx] = $product;
            $updated = $product;
            break;
        }

        if ($updated === null) {
            errorResponse('Producto no encontrado', 404);
        }

        try {
            persistProductTaxInDb((string)$updated['id'], $updated['iva'] ?? 'No', (string)($updated['barcode'] ?? ''));
            if (dbEnabled()) {
                persistenceMarkDbHealthy('products_tax');
                syncProductsOverlaySnapshot($products);
                syncProductsBackupFromDb($productsPath);
            } else {
                writeProductsSnapshotFast($productsPath, $products);
                persistenceMarkDbFallback('products_tax');
            }
        } catch (Throwable) {
            writeProductsSnapshotFast($productsPath, $products);
            persistenceMarkDbFallback('products_tax');
        }
        ok($updated);
    }

    if ($action !== 'update_product') {
        errorResponse('Acci�n no soportada', 400);
    }

    $id = (string)($body['id'] ?? '');
    if ($id === '') {
        errorResponse('ID requerido', 400);
    }

    $updated = null;
    foreach ($products as $idx => $product) {
        if ((string)($product['id'] ?? '') !== $id) {
            continue;
        }
        $nextProduct = normalizeProduct($body, $product);
        if (barcodeExists($products, (string)($nextProduct['barcode'] ?? ''), $id)) {
            errorResponse('Ya existe un producto con ese c�digo de barras', 409);
        }
        $nextProduct['id'] = $id;
        $products[$idx] = $nextProduct;
        $updated = $nextProduct;
        break;
    }

    if ($updated === null) {
        errorResponse('Producto no encontrado', 404);
    }

    if (dbEnabled()) {
        if (!legacyUpsertProduct($updated)) {
            errorResponse('No se pudo actualizar el producto en la base de datos legacy', 500);
        }
        persistenceMarkDbHealthy('products_update');
        syncProductsOverlaySnapshot($products);
        syncProductsBackupFromDb($productsPath);
    } else {
        writeJsonFile($productsPath, $products);
        persistenceMarkDbFallback('products_update');
    }

    try {
        persistProductTaxInDb((string)$updated['id'], $updated['iva'] ?? 'No', (string)($updated['barcode'] ?? ''));
    } catch (Throwable) {
        // Ignorar para no bloquear flujo JSON si la tabla no existe.
    }
    ok($updated);
}

if ($method === 'DELETE') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inv�lido', 400);
    }

    $id = (string)($body['id'] ?? '');
    if ($id === '') {
        errorResponse('ID requerido', 400);
    }

    $action = (string)($body['action'] ?? '');
    if ($action === 'delete_promotion') {
        $promotions = readJsonFile($promotionsPath);
        $before = count($promotions);
        $promotions = array_values(array_filter($promotions, static function ($promo) use ($id) {
            return (string)($promo['id'] ?? '') !== $id;
        }));
        if (count($promotions) === $before) {
            errorResponse('Promoci�n no encontrada', 404);
        }
        writeJsonFile($promotionsPath, $promotions);
        ok(['id' => $id]);
    }

    $products = readJsonFile($productsPath);
    $before = count($products);
    $products = array_values(array_filter($products, function ($product) use ($id) {
        return (string)($product['id'] ?? '') !== $id;
    }));

    if (count($products) === $before) {
        errorResponse('Producto no encontrado', 404);
    }

    if (dbEnabled()) {
        legacyDeleteProduct($id);
        persistenceMarkDbHealthy('products_delete');
        syncProductsBackupFromDb($productsPath);
    } else {
        writeJsonFile($productsPath, $products);
        persistenceMarkDbFallback('products_delete');
    }
    ok(['id' => $id]);
}

errorResponse('M�todo no soportado', 405);
