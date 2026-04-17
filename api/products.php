<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/db.php';

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
        if ($rate <= 0) {
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
            if ($optRate > 0 && abs($optRate - $rate) < 0.0001) {
                return (string)$opt['id'];
            }
        }
    }

    if (in_array($normalized, ['si', 'sÃ­', 'yes', 'true'], true)) {
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
function writeProductsSnapshotFast(string $productsPath, array $products): void
{
    $savedInOverlay = false;
    if (dbEnabled()) {
        try {
            $savedInOverlay = legacyWriteDocumentStore('app/products.json', $products);
        } catch (Throwable) {
            // Continue with disk fallback.
        }
    }

    $dir = dirname($productsPath);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        if ($savedInOverlay) {
            return;
        }
        errorResponse('No se pudo abrir el archivo de almacenamiento', 500);
    }

    $encoded = json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        errorResponse('No se pudo serializar JSON', 500);
    }

    $fp = fopen($productsPath, 'c+');
    if ($fp === false) {
        if ($savedInOverlay) {
            return;
        }
        errorResponse('No se pudo abrir el archivo de almacenamiento', 500);
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            if ($savedInOverlay) {
                return;
            }
            errorResponse('No se pudo bloquear el archivo de almacenamiento', 500);
        }
        ftruncate($fp, 0);
        rewind($fp);
        if (fwrite($fp, $encoded) === false && !$savedInOverlay) {
            errorResponse('No se pudo abrir el archivo de almacenamiento', 500);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
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
        if ($id !== '' && $rate > 0) {
            $mapById[$id] = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
        }
    }
    if (isset($mapById[$value])) {
        return $mapById[$value];
    }

    $rawRate = str_replace(['iva', '%', ' '], '', $normalized);
    if (is_numeric($rawRate)) {
        $rate = (float)$rawRate;
        if ($rate > 0) {
            return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
        }
    }

    if (in_array($normalized, ['si', 'sÃ­', 'yes', 'true'], true)) {
        return '12%';
    }

    return 'No';
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
        errorResponse('Nombre de promociÃ³n requerido', 400);
    }
    if (!in_array($type, ['percent', 'fixed'], true)) {
        errorResponse('Tipo de promociÃ³n invÃ¡lido', 400);
    }
    if ($value <= 0) {
        errorResponse('Valor de promociÃ³n invÃ¡lido', 400);
    }
    if ($startDate === '' || $endDate === '' || $endDate < $startDate) {
        errorResponse('Rango de fechas invÃ¡lido', 400);
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
    $price = round((float)($body['price'] ?? ($existing['price'] ?? 0)), 2);
    $specialPrice = round((float)($body['specialPrice'] ?? ($existing['specialPrice'] ?? 0)), 2);
    $wholesalePrice = round((float)($body['wholesalePrice'] ?? ($existing['wholesale']['price'] ?? 0)), 2);
    $wholesaleMinQty = (int)($body['wholesaleMinQty'] ?? ($existing['wholesale']['minQty'] ?? 6));
    $stock = (int)($body['stock'] ?? ($existing['stock'] ?? 0));
    $minStock = (int)($body['minStock'] ?? ($existing['minStock'] ?? 0));
    $maxStock = (int)($body['maxStock'] ?? ($existing['maxStock'] ?? 0));
    $inventoryEnabled = (bool)($body['inventoryEnabled'] ?? ($existing['inventoryEnabled'] ?? true));
    $department = trim((string)($body['department'] ?? ($existing['department'] ?? 'Sin Departamento')));
    $unitType = trim((string)($body['unitType'] ?? ($existing['unitType'] ?? 'unit')));
    $provider = trim((string)($body['provider'] ?? ($existing['provider'] ?? '')));
    $iva = normalizeIvaValue($body['iva'] ?? null, (string)($existing['iva'] ?? 'No'));
    $rawPackageItems = $body['packageItems'] ?? ($existing['packageItems'] ?? []);
    $packageItems = [];

    if (is_array($rawPackageItems)) {
        foreach ($rawPackageItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = trim((string)($item['productId'] ?? ''));
            $barcode = trim((string)($item['barcode'] ?? ''));
            $itemName = trim((string)($item['name'] ?? ''));
            $qty = (int)($item['qty'] ?? 0);

            if ($qty <= 0 || ($productId === '' && $barcode === '') || $itemName === '') {
                continue;
            }

            $packageItems[] = [
                'productId' => $productId,
                'barcode' => $barcode,
                'name' => $itemName,
                'qty' => $qty,
            ];
        }
    }

    if ($name === '') {
        errorResponse('Nombre requerido', 400);
    }
    if ($price < 0 || $specialPrice < 0 || $cost < 0 || $wholesalePrice < 0) {
        errorResponse('Precios invÃ¡lidos', 400);
    }

    if ($unitType === 'package') {
        $inventoryEnabled = false;
        $stock = 0;
        $minStock = 0;
        $maxStock = 0;
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
        'unitType' => in_array($unitType, ['unit', 'bulk', 'package'], true) ? $unitType : 'unit',
        'provider' => $provider,
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

    $q = strtolower(trim((string)($_GET['q'] ?? '')));
    $products = readJsonFile($productsPath);

    if ($q === '') {
        ok(array_slice($products, 0, 200));
    }

    $filtered = array_values(array_filter($products, function ($p) use ($q) {
        $barcode = strtolower((string)($p['barcode'] ?? ''));
        $name = strtolower((string)($p['name'] ?? ''));
        $id = strtolower((string)($p['id'] ?? ''));
        $department = strtolower((string)($p['department'] ?? ''));
        return $barcode === $q
            || $id === $q
            || str_contains($barcode, $q)
            || str_contains($name, $q)
            || str_contains($department, $q);
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
        errorResponse('Cuerpo inválido', 400);
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
            errorResponse('Parámetros inválidos', 400);
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

    if ($action !== 'create_product') {
        if ($action !== 'import_products') {
            errorResponse('Acción no soportada', 400);
        }

        $mode = strtolower(trim((string)($body['mode'] ?? 'merge')));
        $rows = $body['rows'] ?? null;
        if (!is_array($rows) || count($rows) === 0) {
            errorResponse('No hay filas para importar', 400);
        }

        $products = $mode === 'replace' ? [] : readJsonFile($productsPath);

        /** @var array<string, int> $barcodeMap */
        $barcodeMap = [];
        foreach ($products as $idx => $product) {
            $barcode = strtolower(trim((string)($product['barcode'] ?? '')));
            if ($barcode !== '') {
                $barcodeMap[$barcode] = $idx;
            }
        }

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

        writeJsonFile($productsPath, $products);
        ok([
            'created' => $created,
            'updated' => $updated,
            'total' => count($products),
            'mode' => $mode,
        ]);
    }

    $products = readJsonFile($productsPath);
    $product = normalizeProduct($body);
    if (barcodeExists($products, (string)($product['barcode'] ?? ''))) {
        errorResponse('Ya existe un producto con ese código de barras', 409);
    }
    $product['id'] = nextProductId($products);
    $products[] = $product;
    writeJsonFile($productsPath, $products);
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
        errorResponse('Cuerpo inválido', 400);
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
            errorResponse('Promoción no encontrada', 404);
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

        writeProductsSnapshotFast($productsPath, $products);
        try {
            persistProductTaxInDb((string)$updated['id'], $updated['iva'] ?? 'No', (string)($updated['barcode'] ?? ''));
        } catch (Throwable) {
            // No bloquear UI: el valor ya queda en JSON/overlay.
        }
        ok($updated);
    }

    if ($action !== 'update_product') {
        errorResponse('Acción no soportada', 400);
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
            errorResponse('Ya existe un producto con ese código de barras', 409);
        }
        $nextProduct['id'] = $id;
        $products[$idx] = $nextProduct;
        $updated = $nextProduct;
        break;
    }

    if ($updated === null) {
        errorResponse('Producto no encontrado', 404);
    }

    writeJsonFile($productsPath, $products);
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
        errorResponse('Cuerpo inválido', 400);
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
            errorResponse('Promoción no encontrada', 404);
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

    writeJsonFile($productsPath, $products);
    ok(['id' => $id]);
}

errorResponse('Método no soportado', 405);