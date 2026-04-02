<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

session_start();

$salesPath = storagePath('sales.json');
$productsPath = storagePath('products.json');
$inventoryMovementsPath = storagePath('inventory_movements.json');

$request = getRequestInfo();
$method = $request['method'];

if ($method === 'GET') {
    $sales = readJsonFile($salesPath);
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
$amountPending = round((float)($body['amountPending'] ?? 0), 2);
$cashier = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Cajero'));

if (!in_array($paymentMethod, ['cash', 'card', 'credit', 'voucher', 'transfer', 'check'], true)) {
    $paymentMethod = 'cash';
}

if ($paymentMethod === 'credit') {
    $paidWith = 0.0;
    $change = 0.0;
    $amountPending = round($total, 2);
} else {
    $amountPending = 0.0;
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
    $max = 0;
    foreach ($movements as $mv) {
        $mid = (string)($mv['id'] ?? '');
        if (preg_match('/^mov-(\d+)$/i', $mid, $m) === 1) {
            $max = max($max, (int)$m[1]);
        }
    }
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
    'customerId' => $customerId,
    'customerName' => $customerName,
    'amountPending' => $amountPending,
    'cashier' => $cashier !== '' ? $cashier : 'Cajero',
];
$sales[] = $sale;
writeJsonFile($salesPath, $sales);

ok(['ticketId' => $ticketId]);

