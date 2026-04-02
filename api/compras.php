<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

$productsPath = storagePath('products.json');
$inventoryMovementsPath = storagePath('inventory_movements.json');
$purchaseListPath = storagePath('purchase_list.json');
$purchaseOrdersPath = storagePath('purchase_orders.json');
$providersPath = storagePath('providers.json');
$purchaseHistoryPath = storagePath('purchase_history.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

/**
 * @param array<int, array<string, mixed>> $rows
 */
function nextSequentialIdBuy(array $rows, string $prefix, int $pad = 6): string
{
    $max = 0;
    $regex = '/^' . preg_quote($prefix, '/') . '-(\\d+)$/i';
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        if (preg_match($regex, $id, $m) === 1) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . '-' . str_pad((string)($max + 1), $pad, '0', STR_PAD_LEFT);
}

/**
 * @param array<int, array<string, mixed>> $movements
 */
function nextInventoryMovementIdBuy(array $movements): string
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
function appendInventoryMovementBuy(array $movements, array $entry): array
{
    $entry['id'] = nextInventoryMovementIdBuy($movements);
    $entry['createdAt'] = (string)($entry['createdAt'] ?? date('c'));
    $movements[] = $entry;
    if (count($movements) > 20000) {
        $movements = array_slice($movements, -20000);
    }
    return $movements;
}

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    if ($action === 'products') {
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

    if ($action === 'providers') {
        $providers = readJsonFile($providersPath);
        usort($providers, static function ($a, $b) {
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        ok($providers);
    }

    if ($action === 'purchase_list') {
        $list = readJsonFile($purchaseListPath);
        usort($list, static function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });
        ok($list);
    }

    if ($action === 'purchase_orders') {
        $orders = readJsonFile($purchaseOrdersPath);
        usort($orders, static function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });
        ok($orders);
    }

    if ($action === 'purchase_history') {
        $history = readJsonFile($purchaseHistoryPath);
        usort($history, static function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });
        ok($history);
    }

    errorResponse('Acción no soportada', 400);
}

if ($method === 'POST') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inválido', 400);
    }

    $action = (string)($body['action'] ?? '');

    if ($action === 'add_provider') {
        $name = trim((string)($body['name'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));
        if ($name === '') {
            errorResponse('Nombre de proveedor requerido', 400);
        }

        $providers = readJsonFile($providersPath);
        foreach ($providers as $provider) {
            if (mb_strtolower((string)($provider['name'] ?? ''), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                ok($provider);
            }
        }

        $entry = [
            'id' => nextSequentialIdBuy($providers, 'prov', 4),
            'name' => $name,
            'phone' => $phone,
            'notes' => $notes,
            'createdAt' => date('c'),
        ];
        $providers[] = $entry;
        writeJsonFile($providersPath, $providers);
        ok($entry);
    }

    if ($action === 'add_purchase_list_items') {
        $items = $body['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            errorResponse('No hay items para lista de compra', 400);
        }

        $list = readJsonFile($purchaseListPath);
        $added = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = trim((string)($item['productId'] ?? ''));
            if ($productId === '') {
                continue;
            }
            $qty = max(1, (int)($item['qty'] ?? 1));
            $provider = trim((string)($item['provider'] ?? ''));
            $merged = false;
            foreach ($list as &$row) {
                if ((string)($row['productId'] ?? '') === $productId) {
                    $row['qty'] = (int)($row['qty'] ?? 0) + $qty;
                    $row['provider'] = $provider !== '' ? $provider : (string)($row['provider'] ?? '- Sin Proveedor -');
                    $row['updatedAt'] = date('c');
                    $merged = true;
                    break;
                }
            }
            unset($row);

            if (!$merged) {
                $list[] = [
                    'id' => nextSequentialIdBuy($list, 'pl', 5),
                    'productId' => $productId,
                    'barcode' => trim((string)($item['barcode'] ?? '')),
                    'name' => trim((string)($item['name'] ?? '')),
                    'provider' => $provider !== '' ? $provider : '- Sin Proveedor -',
                    'qty' => $qty,
                    'createdAt' => date('c'),
                ];
            }
            $added++;
        }

        writeJsonFile($purchaseListPath, $list);
        ok(['added' => $added, 'total' => count($list)]);
    }

    if ($action === 'remove_purchase_list_item') {
        $itemId = trim((string)($body['itemId'] ?? ''));
        if ($itemId === '') {
            errorResponse('itemId requerido', 400);
        }
        $list = readJsonFile($purchaseListPath);
        $before = count($list);
        $list = array_values(array_filter($list, static function ($row) use ($itemId) {
            return (string)($row['id'] ?? '') !== $itemId;
        }));
        if (count($list) === $before) {
            errorResponse('Item no encontrado', 404);
        }
        writeJsonFile($purchaseListPath, $list);
        ok(['id' => $itemId]);
    }

    if ($action === 'create_purchase_order') {
        $source = trim((string)($body['source'] ?? 'suggested'));
        $products = readJsonFile($productsPath);
        $productsById = [];
        foreach ($products as $p) {
            $productsById[(string)($p['id'] ?? '')] = $p;
        }

        $orders = readJsonFile($purchaseOrdersPath);
        $list = readJsonFile($purchaseListPath);
        $items = [];
        $selectedListIds = [];

        if ($source === 'list') {
            $selectedListIds = is_array($body['listItemIds'] ?? null) ? $body['listItemIds'] : [];
            foreach ($list as $entry) {
                if (!in_array((string)($entry['id'] ?? ''), $selectedListIds, true)) {
                    continue;
                }
                $productId = (string)($entry['productId'] ?? '');
                $product = $productsById[$productId] ?? null;
                $items[] = [
                    'productId' => $productId,
                    'barcode' => (string)($entry['barcode'] ?? ($product['barcode'] ?? '')),
                    'name' => (string)($entry['name'] ?? ($product['name'] ?? '')),
                    'provider' => (string)($entry['provider'] ?? '- Sin Proveedor -'),
                    'qty' => max(1, (int)($entry['qty'] ?? 1)),
                    'cost' => (float)($product['cost'] ?? 0),
                ];
            }
        } else {
            $rawItems = $body['items'] ?? null;
            if (is_array($rawItems)) {
                foreach ($rawItems as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $productId = trim((string)($entry['productId'] ?? ''));
                    if ($productId === '') {
                        continue;
                    }
                    $product = $productsById[$productId] ?? null;
                    $items[] = [
                        'productId' => $productId,
                        'barcode' => trim((string)($entry['barcode'] ?? ($product['barcode'] ?? ''))),
                        'name' => trim((string)($entry['name'] ?? ($product['name'] ?? ''))),
                        'provider' => trim((string)($entry['provider'] ?? '- Sin Proveedor -')),
                        'qty' => max(1, (int)($entry['qty'] ?? 1)),
                        'cost' => (float)($entry['cost'] ?? ($product['cost'] ?? 0)),
                    ];
                }
            }
        }

        if (count($items) === 0) {
            errorResponse('No hay items para crear orden', 400);
        }

        $providerSet = [];
        foreach ($items as $item) {
            $name = trim((string)($item['provider'] ?? ''));
            if ($name !== '' && $name !== '- Sin Proveedor -') {
                $providerSet[$name] = true;
            }
        }
        $providers = array_keys($providerSet);
        $provider = count($providers) === 1 ? $providers[0] : 'Varios';

        $maxFolio = 0;
        foreach ($orders as $order) {
            $folio = (string)($order['folio'] ?? '');
            if (preg_match('/^OC-(\d+)$/i', $folio, $m) === 1) {
                $maxFolio = max($maxFolio, (int)$m[1]);
            }
        }
        $folio = 'OC-' . str_pad((string)($maxFolio + 1), 6, '0', STR_PAD_LEFT);

        $total = 0.0;
        foreach ($items as $item) {
            $total += (float)($item['cost'] ?? 0) * (int)($item['qty'] ?? 0);
        }

        $order = [
            'id' => nextSequentialIdBuy($orders, 'po', 6),
            'folio' => $folio,
            'provider' => $provider,
            'source' => $source,
            'status' => 'pending',
            'total' => round($total, 2),
            'items' => $items,
            'createdAt' => date('c'),
        ];

        $orders[] = $order;

        if ($source === 'list' && count($selectedListIds) > 0) {
            $list = array_values(array_filter($list, static function ($entry) use ($selectedListIds) {
                return !in_array((string)($entry['id'] ?? ''), $selectedListIds, true);
            }));
            writeJsonFile($purchaseListPath, $list);
        }

        writeJsonFile($purchaseOrdersPath, $orders);
        ok($order);
    }

    if ($action === 'receive_purchase_order') {
        $orderId = trim((string)($body['orderId'] ?? ''));
        if ($orderId === '') {
            errorResponse('orderId requerido', 400);
        }

        $orders = readJsonFile($purchaseOrdersPath);
        $products = readJsonFile($productsPath);
        $movements = readJsonFile($inventoryMovementsPath);
        $history = readJsonFile($purchaseHistoryPath);

        $productsById = [];
        foreach ($products as $idx => $product) {
            $productsById[(string)($product['id'] ?? '')] = $idx;
        }

        $orderIndex = null;
        foreach ($orders as $idx => $order) {
            if ((string)($order['id'] ?? '') === $orderId) {
                $orderIndex = $idx;
                break;
            }
        }

        if ($orderIndex === null) {
            errorResponse('Orden no encontrada', 404);
        }

        $order = $orders[$orderIndex];
        if ((string)($order['status'] ?? '') !== 'pending') {
            errorResponse('La orden ya fue recibida', 409);
        }

        foreach (($order['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (string)($item['productId'] ?? '');
            $qty = max(1, (int)($item['qty'] ?? 1));
            if (!array_key_exists($productId, $productsById)) {
                continue;
            }
            $pIdx = $productsById[$productId];
            $before = (int)($products[$pIdx]['stock'] ?? 0);
            $after = $before + $qty;
            $products[$pIdx]['stock'] = $after;

            $movements = appendInventoryMovementBuy($movements, [
                'type' => 'entry',
                'productId' => $productId,
                'productName' => (string)($products[$pIdx]['name'] ?? ($item['name'] ?? '')),
                'delta' => $qty,
                'before' => $before,
                'after' => $after,
                'note' => 'Recepción ' . (string)($order['folio'] ?? ''),
                'source' => 'compras',
            ]);

            $history[] = [
                'id' => nextSequentialIdBuy($history, 'ph', 6),
                'orderId' => (string)($order['id'] ?? ''),
                'orderFolio' => (string)($order['folio'] ?? ''),
                'productId' => $productId,
                'barcode' => (string)($item['barcode'] ?? ($products[$pIdx]['barcode'] ?? '')),
                'name' => (string)($item['name'] ?? ($products[$pIdx]['name'] ?? '')),
                'provider' => (string)($item['provider'] ?? ($order['provider'] ?? '')),
                'qty' => $qty,
                'cost' => (float)($item['cost'] ?? ($products[$pIdx]['cost'] ?? 0)),
                'createdAt' => date('c'),
            ];
        }

        $order['status'] = 'received';
        $order['receivedAt'] = date('c');
        $orders[$orderIndex] = $order;

        writeJsonFile($productsPath, $products);
        writeJsonFile($inventoryMovementsPath, $movements);
        writeJsonFile($purchaseHistoryPath, $history);
        writeJsonFile($purchaseOrdersPath, $orders);
        ok($order);
    }

    errorResponse('Acción no soportada', 400);
}

errorResponse('Método no soportado', 405);
