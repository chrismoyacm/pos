<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

$productsPath = storagePath('products.json');
$inventoryMovementsPath = storagePath('inventory_movements.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

/**
 * @param array<int, array<string, mixed>> $movements
 */
function nextInventoryMovementIdInv(array $movements): string
{
    $max = 0;
    foreach ($movements as $movement) {
        $id = (string)($movement['id'] ?? '');
        if (preg_match('/^mov-(\d+)$/i', $id, $m) === 1) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'mov-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

/**
 * @param array<int, array<string, mixed>> $movements
 * @return array<int, array<string, mixed>>
 */
function appendInventoryMovementInv(array $movements, array $entry): array
{
    $entry['id'] = nextInventoryMovementIdInv($movements);
    $entry['createdAt'] = (string)($entry['createdAt'] ?? date('c'));
    $movements[] = $entry;
    if (count($movements) > 20000) {
        $movements = array_slice($movements, -20000);
    }
    return $movements;
}

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? 'products')));

    if ($action === 'inventory_movements') {
        $movements = readJsonFile($inventoryMovementsPath);
        usort($movements, static function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });
        ok($movements);
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

if ($method === 'PATCH') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inválido', 400);
    }

    $action = (string)($body['action'] ?? '');
    if ($action !== 'adjust_stock') {
        errorResponse('Acción no soportada', 400);
    }

    $productId = (string)($body['productId'] ?? '');
    $delta = (int)($body['delta'] ?? 0);
    $movementType = trim((string)($body['movementType'] ?? ''));
    $note = trim((string)($body['note'] ?? ''));
    $source = trim((string)($body['source'] ?? 'inventario'));
    if ($productId === '' || $delta === 0) {
        errorResponse('Parámetros inválidos', 400);
    }

    $products = readJsonFile($productsPath);
    $found = false;
    $beforeQty = 0;
    $afterQty = 0;
    $productName = '';
    foreach ($products as &$p) {
        if ((string)($p['id'] ?? '') === $productId) {
            $current = (int)($p['stock'] ?? 0);
            $next = $current + $delta;
            if ($next < 0) {
                errorResponse('Stock insuficiente', 409, ['current' => $current, 'delta' => $delta]);
            }
            $p['stock'] = $next;
            $beforeQty = $current;
            $afterQty = $next;
            $productName = (string)($p['name'] ?? '');
            $found = true;
            break;
        }
    }
    unset($p);

    if (!$found) {
        errorResponse('Producto no encontrado', 404);
    }

    writeJsonFile($productsPath, $products);

    $movements = readJsonFile($inventoryMovementsPath);
    $type = 'entry';
    if (in_array($movementType, ['entry', 'exit', 'sale'], true)) {
        $type = $movementType;
    } elseif ($delta < 0) {
        $type = 'exit';
    }

    $movements = appendInventoryMovementInv($movements, [
        'type' => $type,
        'productId' => $productId,
        'productName' => $productName,
        'delta' => $delta,
        'before' => $beforeQty,
        'after' => $afterQty,
        'note' => $note,
        'source' => $source,
    ]);
    writeJsonFile($inventoryMovementsPath, $movements);
    ok(['productId' => $productId]);
}

errorResponse('Método no soportado', 405);
