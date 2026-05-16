<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/persistence.php';

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

function round2Inv(float $value): float
{
    return round($value, 2);
}

function isInventoryManagedProductInv(array $product): bool
{
    $inventoryEnabled = (bool)($product['inventoryEnabled'] ?? true);
    return $inventoryEnabled;
}

function parseInventoryProductDbId(string $productId): ?int
{
    $raw = trim($productId);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^p-(\d+)$/i', $raw, $m) === 1) {
        return (int)$m[1];
    }
    if (preg_match('/^\d+$/', $raw) === 1) {
        return (int)$raw;
    }
    return null;
}

function syncInventoryBackups(string $productsPath, string $inventoryMovementsPath): void
{
    writeJsonBackupFile($productsPath, legacyReadProducts());
    writeJsonBackupFile($inventoryMovementsPath, legacyReadInventoryMovements());
}

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? 'products')));

    if ($action === 'products_report') {
        $department = trim((string)($_GET['department'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 25)));

        $products = array_values(array_filter(readJsonFile($productsPath), static function ($p) use ($department) {
            if (!is_array($p) || !isInventoryManagedProductInv($p)) {
                return false;
            }
            if ($department === '') {
                return true;
            }
            return trim((string)($p['department'] ?? 'Sin Departamento')) === $department;
        }));

        $summaryCost = 0.0;
        $summaryStock = 0;
        foreach ($products as $product) {
            $summaryCost += (float)($product['cost'] ?? 0) * (float)($product['stock'] ?? 0);
            $summaryStock += (int)($product['stock'] ?? 0);
        }

        usort($products, static function ($a, $b): int {
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        $total = count($products);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($products, $offset, $pageSize);

        ok([
            'items' => $items,
            'summary' => [
                'totalCost' => round2Inv($summaryCost),
                'totalStock' => $summaryStock,
            ],
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => max(1, (int)ceil($total / $pageSize)),
            ],
        ]);
    }

    if ($action === 'inventory_movements') {
        $movements = readJsonFile($inventoryMovementsPath);
        usort($movements, static function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });
        ok($movements);
    }

    $q = strtolower(trim((string)($_GET['q'] ?? '')));
    $products = array_values(array_filter(readJsonFile($productsPath), static function ($p) {
        return is_array($p) && isInventoryManagedProductInv($p);
    }));

    if ($q === '') {
        usort($products, static function ($a, $b): int {
            $aCode = strtolower((string)($a['barcode'] ?? $a['id'] ?? ''));
            $bCode = strtolower((string)($b['barcode'] ?? $b['id'] ?? ''));
            return strcmp($aCode, $bCode);
        });
        ok($products);
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

if ($method === 'PATCH' || $method === 'POST') {
    $body = $request['body'];
    if (!is_array($body)) {
        $body = $_POST;
    }
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
    $entryUnitCost = (float)($body['entryUnitCost'] ?? 0);
    $marginPctRaw = $body['marginPct'] ?? null;
    $marginPct = is_numeric($marginPctRaw) ? (float)$marginPctRaw : null;
    $salePriceRaw = $body['salePrice'] ?? null;
    $salePrice = is_numeric($salePriceRaw) ? (float)$salePriceRaw : null;
    $wholesalePriceRaw = $body['wholesalePrice'] ?? null;
    $wholesalePrice = is_numeric($wholesalePriceRaw) ? (float)$wholesalePriceRaw : null;
    $note = trim((string)($body['note'] ?? ''));
    $source = trim((string)($body['source'] ?? 'inventario'));
    if ($productId === '' || $delta === 0) {
        errorResponse('Parámetros inválidos', 400);
    }

    $products = readJsonFile($productsPath);
    $selectedProduct = null;
    foreach ($products as $product) {
        if ((string)($product['id'] ?? '') === $productId) {
            $selectedProduct = is_array($product) ? $product : null;
            break;
        }
    }

    if (!is_array($selectedProduct)) {
        errorResponse('Producto no encontrado', 404);
    }
    if (!isInventoryManagedProductInv($selectedProduct)) {
        errorResponse('El producto no maneja inventario o tiene el inventario desactivado', 409);
    }

    $current = round((float)($selectedProduct['stock'] ?? 0), 3);
    $next = round($current + $delta, 3);
    if ($next < 0) {
        errorResponse('Stock insuficiente', 409, ['current' => $current, 'delta' => $delta]);
    }

    $currentCost = (float)($selectedProduct['cost'] ?? 0);
    $currentPrice = (float)($selectedProduct['price'] ?? 0);
    $marginValue = (float)($selectedProduct['margin'] ?? 0);
    if ($marginPct !== null && $marginPct >= 0) {
        $marginValue = $marginPct;
    }
    if ($marginValue <= 0 && $currentCost > 0 && $currentPrice > 0) {
        $marginValue = (($currentPrice - $currentCost) / $currentCost) * 100;
    }

    $newCost = $currentCost;
    if ($delta > 0 && $entryUnitCost > 0 && $next > 0) {
        $newCost = (($current * $currentCost) + ($delta * $entryUnitCost)) / $next;
    }
    $newPrice = $newCost * (1 + ($marginValue / 100));
    if ($salePrice !== null && $salePrice >= 0) {
        $newPrice = $salePrice;
        if ($newCost > 0) {
            $marginValue = (($newPrice - $newCost) / $newCost) * 100;
        }
    }

    $beforeQty = $current;
    $afterQty = $next;
    $beforeCost = round2Inv($currentCost);
    $afterCost = round2Inv($newCost);
    $beforePrice = round2Inv($currentPrice);
    $afterPrice = round2Inv(max(0, $newPrice));
    $margin = round2Inv(max(0, $marginValue));
    $productName = (string)($selectedProduct['name'] ?? '');
    $type = 'entry';
    if (in_array($movementType, ['entry', 'exit', 'sale'], true)) {
        $type = $movementType;
    } elseif ($delta < 0) {
        $type = 'exit';
    }

    if (dbEnabled()) {
        try {
            $dbId = parseInventoryProductDbId($productId);
            if ($dbId === null || $dbId <= 0) {
                errorResponse('Producto no encontrado', 404);
            }
            $pdo = db();
            $pdo->beginTransaction();
            $wholesaleSql = '';
            $wholesaleParams = [];
            if ($wholesalePrice !== null && $wholesalePrice >= 0) {
                $wholesaleSql = ', MAYOREO = :mayoreo, PMAYOREOFINAL = :pmayoreo';
                $wholesaleParams = [
                    ':mayoreo' => round2Inv($wholesalePrice),
                    ':pmayoreo' => round2Inv($wholesalePrice),
                ];
            }
            $stmt = $pdo->prepare(
                'UPDATE PRODUCTOS
                 SET DINVENTARIO = :stock, PCOSTO = :pcosto, PVENTA = :pventa, PFINAL = :pfinal, PORCENTAJE_GANANCIA = :margen' . $wholesaleSql . '
                 WHERE ID = :id'
            );
            $stmt->execute(array_merge([
                ':stock' => $afterQty,
                ':pcosto' => $afterCost,
                ':pventa' => $afterPrice,
                ':pfinal' => $afterPrice,
                ':margen' => $margin,
                ':id' => $dbId,
            ], $wholesaleParams));

            if (legacyTableExists('inventario_historial')) {
                $histId = 'mov-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $pdo->prepare(
                    'INSERT INTO INVENTARIO_HISTORIAL
                     (ID, PRODUCTO_ID, CUANDO_FUE, CANTIDAD_ANTERIOR, CANTIDAD, DESCRIPCION, COSTO_UNITARIO, COSTO_DESPUES, AJUSTE_ID, RECIBO_INVENTARIO_ID, VENTA_ID, TRANSFERENCIA_ID, CAJA_ID, VENTA_POR_KIT, USUARIO_ID, ALMACEN_ID)
                     VALUES
                     (:id, :producto_id, :cuando_fue, :cantidad_anterior, :cantidad, :descripcion, :costo_unitario, :costo_despues, :ajuste_id, :recibo_id, :venta_id, :transferencia_id, :caja_id, :venta_por_kit, :usuario_id, :almacen_id)'
                )->execute([
                    ':id' => $histId,
                    ':producto_id' => (string)$dbId,
                    ':cuando_fue' => legacyToSqlDateTime(date('c')),
                    ':cantidad_anterior' => $beforeQty,
                    ':cantidad' => round($delta, 3),
                    ':descripcion' => $note !== '' ? $note : ('Ajuste de inventario ' . $type),
                    ':costo_unitario' => $beforeCost,
                    ':costo_despues' => $afterCost,
                    ':ajuste_id' => $source === 'inventario' ? $histId : '',
                    ':recibo_id' => '',
                    ':venta_id' => '',
                    ':transferencia_id' => '',
                    ':caja_id' => '1',
                    ':venta_por_kit' => '0',
                    ':usuario_id' => '',
                    ':almacen_id' => '1',
                ]);
            }
            $pdo->commit();
            persistenceMarkDbHealthy('inventory_adjust');
            syncInventoryBackups($productsPath, $inventoryMovementsPath);
            ok([
                'productId' => $productId,
                'stock' => $afterQty,
                'cost' => $afterCost,
                'price' => $afterPrice,
                'margin' => $margin,
            ]);
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            persistenceMarkDbFallback('inventory_adjust');
        }
    }

    foreach ($products as &$p) {
        if ((string)($p['id'] ?? '') !== $productId) {
            continue;
        }
        $p['stock'] = $afterQty;
        $p['margin'] = $margin;
        $p['cost'] = $afterCost;
        $p['price'] = $afterPrice;
        if ($wholesalePrice !== null && $wholesalePrice >= 0) {
            $currentWholesale = $p['wholesale'] ?? [];
            if (!is_array($currentWholesale)) {
                $currentWholesale = [];
            }
            $minQty = (int)($currentWholesale['minQty'] ?? 0);
            $p['wholesale'] = [
                'minQty' => $minQty > 0 ? $minQty : 1,
                'price' => round2Inv($wholesalePrice),
            ];
        }
        break;
    }
    unset($p);

    writeJsonFile($productsPath, $products);
    $movements = readJsonFile($inventoryMovementsPath);
    $movements = appendInventoryMovementInv($movements, [
        'type' => $type,
        'productId' => $productId,
        'productName' => $productName,
        'delta' => $delta,
        'before' => $beforeQty,
        'after' => $afterQty,
        'beforeCost' => $beforeCost,
        'afterCost' => $afterCost,
        'beforePrice' => $beforePrice,
        'afterPrice' => $afterPrice,
        'margin' => $margin,
        'note' => $note,
        'source' => $source,
    ]);
    writeJsonFile($inventoryMovementsPath, $movements);
    persistenceMarkDbFallback('inventory_adjust');
    ok([
        'productId' => $productId,
        'stock' => $afterQty,
        'cost' => $afterCost,
        'price' => $afterPrice,
        'margin' => $margin,
    ]);
}

errorResponse('Método no soportado', 405);
