<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

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

function normalizeIvaValue(mixed $raw, ?string $fallback = null): string
{
    $value = trim((string)$raw);
    if ($value === '' && $fallback !== null) {
        $value = trim($fallback);
    }

    $normalized = strtolower($value);
    if (in_array($normalized, ['12', '12%', 'iva 12', 'iva 12%', 'si', 'sí', 'yes', 'true', '1'], true)) {
        return '12%';
    }
    if (in_array($normalized, ['15', '15%', 'iva 15', 'iva 15%'], true)) {
        return '15%';
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
    $price = round((float)($body['price'] ?? ($existing['price'] ?? 0)), 2);
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
    if ($price < 0 || $cost < 0 || $wholesalePrice < 0) {
        errorResponse('Precios inválidos', 400);
    }

    $product = [
        'id' => (string)($existing['id'] ?? ''),
        'barcode' => $barcode,
        'name' => $name,
        'cost' => $cost,
        'margin' => $margin,
        'price' => $price,
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

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));
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
    $product['id'] = nextProductId($products);
    $products[] = $product;
    writeJsonFile($productsPath, $products);
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
        $nextProduct['id'] = $id;
        $products[$idx] = $nextProduct;
        $updated = $nextProduct;
        break;
    }

    if ($updated === null) {
        errorResponse('Producto no encontrado', 404);
    }

    writeJsonFile($productsPath, $products);
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
