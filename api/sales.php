<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

session_start();

$salesPath = storagePath('sales.json');
$productsPath = storagePath('products.json');
$inventoryMovementsPath = storagePath('inventory_movements.json');

$request = getRequestInfo();
$method = $request['method'];

function movementNextId(array $movements): int
{
    $max = 0;
    foreach ($movements as $mv) {
        $mid = (string)($mv['id'] ?? '');
        if (preg_match('/^mov-(\d+)$/i', $mid, $m) === 1) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $max;
}

if ($method === 'GET') {
    $sales = readJsonFile($salesPath);
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    if ($action === 'day') {
        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $daySales = array_values(array_filter($sales, static function ($sale) use ($date): bool {
            $createdAt = (string)($sale['createdAt'] ?? '');
            return strlen($createdAt) >= 10 && substr($createdAt, 0, 10) === $date;
        }));

        ok($daySales);
    }

    $last = (string)($_GET['last'] ?? '');
    if ($last === '1') {
        $lastSale = empty($sales) ? null : $sales[count($sales) - 1];
        ok($lastSale);
    }
    ok($sales);
}

if ($method !== 'POST') {
    errorResponse('Método no soportado', 405);
}

$body = $request['body'];
if (!is_array($body)) {
    errorResponse('Cuerpo inválido', 400);
}

$action = strtolower(trim((string)($body['action'] ?? 'create')));

if ($action === 'return_item') {
    $ticketId = trim((string)($body['ticketId'] ?? ''));
    $itemId = trim((string)($body['itemId'] ?? ''));
    $qty = (int)($body['qty'] ?? 0);
    $reason = trim((string)($body['reason'] ?? ''));

    if ($ticketId === '' || $itemId === '' || $qty <= 0) {
        errorResponse('Datos de devolución inválidos', 400);
    }

    $sales = readJsonFile($salesPath);
    $saleIndex = -1;
    foreach ($sales as $idx => $sale) {
        if ((string)($sale['ticketId'] ?? '') === $ticketId) {
            $saleIndex = (int)$idx;
            break;
        }
    }

    if ($saleIndex < 0) {
        errorResponse('Ticket no encontrado', 404);
    }

    $sale = $sales[$saleIndex];
    $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
    $itemData = null;
    foreach ($items as $it) {
        if ((string)($it['id'] ?? '') === $itemId) {
            $itemData = $it;
            break;
        }
    }

    if (!is_array($itemData)) {
        errorResponse('Artículo no encontrado en ticket', 404);
    }

    $soldQty = (int)($itemData['qty'] ?? 0);
    $returns = is_array($sale['returns'] ?? null) ? $sale['returns'] : [];
    $alreadyReturned = 0;
    foreach ($returns as $ret) {
        if ((string)($ret['itemId'] ?? '') === $itemId) {
            $alreadyReturned += (int)($ret['qty'] ?? 0);
        }
    }

    $available = $soldQty - $alreadyReturned;
    if ($available <= 0) {
        errorResponse('El artículo ya fue devuelto', 409);
    }
    if ($qty > $available) {
        errorResponse('Cantidad a devolver excede lo vendido', 409, ['available' => $available]);
    }

    $products = readJsonFile($productsPath);
    $productIdToIndex = [];
    foreach ($products as $idx => $p) {
        $productIdToIndex[(string)($p['id'] ?? '')] = $idx;
    }

    if (!str_starts_with($itemId, 'tmp-')) {
        if (!array_key_exists($itemId, $productIdToIndex)) {
            errorResponse('Producto no existe para devolución: ' . $itemId, 409);
        }
        $pIdx = $productIdToIndex[$itemId];
        $before = (int)($products[$pIdx]['stock'] ?? 0);
        $after = $before + $qty;
        $products[$pIdx]['stock'] = $after;
        writeJsonFile($productsPath, $products);

        $movements = readJsonFile($inventoryMovementsPath);
        $max = movementNextId($movements);
        $max++;
        $movements[] = [
            'id' => 'mov-' . str_pad((string)$max, 6, '0', STR_PAD_LEFT),
            'type' => 'return',
            'productId' => $itemId,
            'productName' => (string)($itemData['name'] ?? ''),
            'delta' => $qty,
            'before' => $before,
            'after' => $after,
            'note' => 'Devolución ticket #' . $ticketId,
            'source' => 'sales',
            'createdAt' => date('c'),
        ];
        if (count($movements) > 20000) {
            $movements = array_slice($movements, -20000);
        }
        writeJsonFile($inventoryMovementsPath, $movements);
    }

    $returns[] = [
        'id' => 'ret-' . bin2hex(random_bytes(4)),
        'itemId' => $itemId,
        'qty' => $qty,
        'reason' => $reason,
        'amount' => round((float)($itemData['price'] ?? 0) * $qty, 2),
        'createdAt' => date('c'),
    ];

    $sale['returns'] = $returns;
    $sales[$saleIndex] = $sale;
    writeJsonFile($salesPath, $sales);

    ok([
        'ticketId' => $ticketId,
        'itemId' => $itemId,
        'qtyReturned' => $qty,
        'availableAfter' => $available - $qty,
    ]);
}

$items = $body['items'] ?? null;
if (!is_array($items) || count($items) === 0) {
    errorResponse('No hay items', 400);
}

$subtotal = (float)($body['subtotal'] ?? 0);
$total = (float)($body['total'] ?? 0);
$paidWith = (float)($body['paidWith'] ?? 0);
$change = (float)($body['change'] ?? 0);
$customerId = trim((string)($body['customerId'] ?? 'c-001'));
$customerName = trim((string)($body['customerName'] ?? ''));
$paymentMethod = strtolower(trim((string)($body['paymentMethod'] ?? 'cash')));
$mixedPaymentsBody = $body['mixedPayments'] ?? null;
$paymentNote = trim((string)($body['paymentNote'] ?? ''));
$mixedPayments = [
    'cash' => 0.0,
    'credit' => 0.0,
];
$amountPending = round((float)($body['amountPending'] ?? 0), 2);
$cashier = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Cajero'));

if (!in_array($paymentMethod, ['cash', 'card', 'mixed', 'credit', 'voucher', 'transfer', 'check'], true)) {
    $paymentMethod = 'cash';
}

if (is_array($mixedPaymentsBody)) {
    $mixedPayments['cash'] = round((float)($mixedPaymentsBody['cash'] ?? 0), 2);
    $mixedPayments['credit'] = round((float)($mixedPaymentsBody['credit'] ?? ($mixedPaymentsBody['card'] ?? 0)), 2);
}

if ($paymentMethod === 'credit') {
    $paidWith = 0.0;
    $change = 0.0;
    $amountPending = round($total, 2);
    $mixedPayments = ['cash' => 0.0, 'credit' => 0.0];
} elseif ($paymentMethod === 'mixed') {
    $cashPart = max(0.0, round((float)$mixedPayments['cash'], 2));
    $creditPart = max(0.0, round((float)$mixedPayments['credit'], 2));
    $covered = round($cashPart + $creditPart, 2);
    if ($covered + 0.009 < $total) {
        errorResponse('Pago mixto insuficiente: efectivo + crédito debe cubrir el total', 400);
    }

    $paidWith = $cashPart;
    $change = max(0.0, round($covered - $total, 2));
    $amountPending = $creditPart;
} else {
    $amountPending = 0.0;
    $mixedPayments = ['cash' => 0.0, 'credit' => 0.0];
}

if (($paymentMethod === 'credit' || ($paymentMethod === 'mixed' && $amountPending > 0)) && ($customerId === '' || $customerId === 'c-001')) {
    errorResponse('Seleccione un cliente para registrar saldo pendiente', 400);
}

if ($customerName === '') {
    $customerName = $customerId === 'c-001' ? 'Publico en general' : $customerId;
}

// Adjust stock for registered products
$products = readJsonFile($productsPath);
$productIdToIndex = [];
foreach ($products as $idx => $p) {
    $productIdToIndex[(string)($p['id'] ?? '')] = $idx;
}

$nextTicketEstimate = (count(readJsonFile($salesPath)) + 1);

$movementEntries = [];

foreach ($items as $it) {
    if (!is_array($it)) {
        continue;
    }
    $id = (string)($it['id'] ?? '');
    $qty = (int)($it['qty'] ?? 0);
    if ($qty <= 0) {
        continue;
    }
    if (str_starts_with($id, 'tmp-')) {
        continue;
    }
    if (!array_key_exists($id, $productIdToIndex)) {
        errorResponse('Producto no existe: ' . $id, 409);
    }
    $pIdx = $productIdToIndex[$id];
    $inventoryEnabled = (bool)($products[$pIdx]['inventoryEnabled'] ?? true);
    $unitType = strtolower(trim((string)($products[$pIdx]['unitType'] ?? 'unit')));
    if (!$inventoryEnabled || $unitType === 'package') {
        continue;
    }
    $current = (int)($products[$pIdx]['stock'] ?? 0);
    $next = $current - $qty;
    if ($next < 0) {
        errorResponse('Stock insuficiente para: ' . $id, 409, ['current' => $current, 'qty' => $qty]);
    }
    $products[$pIdx]['stock'] = $next;
    $movementEntries[] = [
        'type' => 'sale',
        'productId' => $id,
        'productName' => (string)($products[$pIdx]['name'] ?? ($it['name'] ?? '')),
        'delta' => 0 - $qty,
        'before' => $current,
        'after' => $next,
        'note' => 'Venta ticket #' . (string)$nextTicketEstimate,
        'source' => 'sales',
        'createdAt' => date('c'),
    ];
}

writeJsonFile($productsPath, $products);

if (!empty($movementEntries)) {
    $movements = readJsonFile($inventoryMovementsPath);
    $max = movementNextId($movements);
    foreach ($movementEntries as $entry) {
        $max++;
        $entry['id'] = 'mov-' . str_pad((string)$max, 6, '0', STR_PAD_LEFT);
        $movements[] = $entry;
    }
    if (count($movements) > 20000) {
        $movements = array_slice($movements, -20000);
    }
    writeJsonFile($inventoryMovementsPath, $movements);
}

$sales = readJsonFile($salesPath);
$ticketId = (string)(count($sales) + 1);
$sale = [
    'ticketId' => $ticketId,
    'createdAt' => date('c'),
    'items' => $items,
    'subtotal' => $subtotal,
    'total' => $total,
    'paidWith' => $paidWith,
    'change' => $change,
    'paymentMethod' => $paymentMethod,
    'mixedPayments' => $paymentMethod === 'mixed' ? $mixedPayments : null,
    'paymentNote' => $paymentNote,
    'customerId' => $customerId,
    'customerName' => $customerName,
    'amountPending' => $amountPending,
    'cashier' => $cashier !== '' ? $cashier : 'Cajero',
];
$sales[] = $sale;
writeJsonFile($salesPath, $sales);

ok(['ticketId' => $ticketId]);

