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

function salesToFloat(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }
    return round((float)$value, 2);
}

function salesToInt(mixed $value): int
{
    return (int)round((float)$value);
}

function salesCanUseTicketsDb(): bool
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    if (!function_exists('dbEnabled') || !dbEnabled() || !function_exists('db')) {
        $checked = false;
        return $checked;
    }

    try {
        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tableNames = array_map(static fn($t): string => strtolower((string)$t), $tables);
        $required = ['ventatickets', 'ventatickets_articulos'];
        foreach ($required as $table) {
            if (!in_array($table, $tableNames, true)) {
                $checked = false;
                return $checked;
            }
        }
        $checked = true;
    } catch (Throwable) {
        $checked = false;
    }

    return $checked;
}

/**
 * @return array{items:array<int, array<string,mixed>>, pagination:array<string,int>}
 */
function salesFetchDayFromDb(string $date, string $q, int $page, int $pageSize): array
{
    $pdo = db();
    $offset = ($page - 1) * $pageSize;

    $whereParts = ['LEFT(IFNULL(CREADO_EN, ""), 10) = :date'];
    $params = [':date' => $date];
    if ($q !== '') {
        $whereParts[] = '(LOWER(IFNULL(FOLIO, "")) LIKE :q OR LOWER(IFNULL(NOMBRE, "")) LIKE :q)';
        $params[':q'] = '%' . strtolower($q) . '%';
    }
    $whereSql = implode(' AND ', $whereParts);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ventatickets WHERE ' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listSql = 'SELECT * FROM ventatickets WHERE ' . $whereSql . ' ORDER BY CREADO_EN DESC, ID DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $tickets = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ticketIds = [];
    foreach ($tickets as $ticketRow) {
        $ticketId = trim((string)($ticketRow['ID'] ?? ''));
        if ($ticketId === '') {
            $ticketId = trim((string)($ticketRow['FOLIO'] ?? ''));
        }
        if ($ticketId !== '') {
            $ticketIds[] = $ticketId;
        }
    }

    $itemsByTicket = [];
    if ($ticketIds !== []) {
        $ph = implode(',', array_fill(0, count($ticketIds), '?'));
        $itemsStmt = $pdo->prepare(
            'SELECT * FROM ventatickets_articulos WHERE TICKET_ID IN (' . $ph . ') ORDER BY AGREGADO_EN ASC, ID ASC'
        );
        $itemsStmt->execute($ticketIds);
        $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($itemRows as $itemRow) {
            $ticketId = trim((string)($itemRow['TICKET_ID'] ?? ''));
            if ($ticketId === '') {
                continue;
            }
            $itemsByTicket[$ticketId] = $itemsByTicket[$ticketId] ?? [];
            $itemsByTicket[$ticketId][] = $itemRow;
        }
    }

    $items = [];
    foreach ($tickets as $ticketRow) {
        $ticketId = trim((string)($ticketRow['ID'] ?? ''));
        if ($ticketId === '') {
            $ticketId = trim((string)($ticketRow['FOLIO'] ?? ''));
        }
        $detailRows = $itemsByTicket[$ticketId] ?? [];

        $saleItems = [];
        $returns = [];
        foreach ($detailRows as $detail) {
            $itemId = trim((string)($detail['ID'] ?? ''));
            if ($itemId === '') {
                $itemId = trim((string)($detail['PRODUCTO_CODIGO'] ?? ''));
            }
            $qty = max(0, salesToInt($detail['CANTIDAD'] ?? 0));
            $price = salesToFloat($detail['PRECIO_USADO'] ?? $detail['PAGADO_EN'] ?? 0);
            $code = trim((string)($detail['PRODUCTO_CODIGO'] ?? ''));
            $name = trim((string)($detail['PRODUCTO_NOMBRE'] ?? ''));
            $returnedQty = max(0, salesToInt($detail['CANTIDAD_DEVUELTA'] ?? 0));

            $saleItems[] = [
                'id' => $itemId,
                'barcode' => $code,
                'name' => $name,
                'qty' => $qty,
                'price' => $price,
            ];

            if ($returnedQty > 0) {
                $returns[] = [
                    'id' => 'ret-db-' . $itemId,
                    'itemId' => $itemId,
                    'qty' => $returnedQty,
                    'reason' => 'Devolucion registrada',
                    'amount' => round($returnedQty * $price, 2),
                    'createdAt' => (string)($detail['AGREGADO_EN'] ?? $ticketRow['CREADO_EN'] ?? date('c')),
                ];
            }
        }

        $items[] = [
            'ticketId' => $ticketId,
            'createdAt' => (string)($ticketRow['CREADO_EN'] ?? ''),
            'items' => $saleItems,
            'subtotal' => salesToFloat($ticketRow['SUBTOTAL'] ?? 0),
            'total' => salesToFloat($ticketRow['TOTAL'] ?? 0),
            'paidWith' => salesToFloat($ticketRow['PAGO_CON'] ?? 0),
            'change' => salesToFloat($ticketRow['PAGADO_EN'] ?? 0),
            'paymentMethod' => strtolower(trim((string)($ticketRow['FORMA_PAGO'] ?? 'cash'))),
            'mixedPayments' => null,
            'paymentNote' => (string)($ticketRow['NOTAS'] ?? ''),
            'customerId' => (string)($ticketRow['CLIENTE_ID'] ?? ''),
            'customerName' => (string)($ticketRow['NOMBRE'] ?? 'Publico en general'),
            'amountPending' => salesToFloat($ticketRow['TOTAL_CREDITO'] ?? 0),
            'cashier' => (string)($ticketRow['CAJERO_ID'] ?? 'Cajero'),
            'returns' => $returns,
        ];
    }

    return [
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => max(1, (int)ceil($total / max(1, $pageSize))),
        ],
    ];
}

if ($method === 'GET') {
    $sales = readJsonFile($salesPath);
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    if ($action === 'day') {
        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        $q = strtolower(trim((string)($_GET['q'] ?? '')));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        if ($date === '') {
            $date = date('Y-m-d');
        }

        if (salesCanUseTicketsDb()) {
            $result = salesFetchDayFromDb($date, $q, $page, $pageSize);
            ok($result);
        }

        $daySales = array_values(array_filter($sales, static function ($sale) use ($date, $q): bool {
            $createdAt = (string)($sale['createdAt'] ?? '');
            if (!(strlen($createdAt) >= 10 && substr($createdAt, 0, 10) === $date)) {
                return false;
            }
            if ($q === '') {
                return true;
            }

            $ticketId = strtolower((string)($sale['ticketId'] ?? ''));
            $customerName = strtolower((string)($sale['customerName'] ?? ''));
            return str_contains($ticketId, $q) || str_contains($customerName, $q);
        }));

        usort($daySales, static function ($a, $b): int {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });

        $total = count($daySales);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($daySales, $offset, $pageSize);

        ok([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => max(1, (int)ceil($total / $pageSize)),
            ],
        ]);
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

    if (salesCanUseTicketsDb()) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ticketStmt = $pdo->prepare('SELECT * FROM ventatickets WHERE ID = :ticket OR FOLIO = :ticket LIMIT 1');
            $ticketStmt->execute([':ticket' => $ticketId]);
            $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($ticket)) {
                throw new RuntimeException('Ticket no encontrado');
            }

            $ticketKey = trim((string)($ticket['ID'] ?? ''));
            if ($ticketKey === '') {
                $ticketKey = trim((string)($ticket['FOLIO'] ?? ''));
            }

            $itemStmt = $pdo->prepare('SELECT * FROM ventatickets_articulos WHERE TICKET_ID = :ticket AND ID = :item LIMIT 1');
            $itemStmt->execute([
                ':ticket' => $ticketKey,
                ':item' => $itemId,
            ]);
            $itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($itemRow)) {
                throw new RuntimeException('Articulo no encontrado en ticket');
            }

            $soldQty = max(0, salesToInt($itemRow['CANTIDAD'] ?? 0));
            $alreadyReturned = max(0, salesToInt($itemRow['CANTIDAD_DEVUELTA'] ?? 0));
            $available = $soldQty - $alreadyReturned;
            if ($available <= 0) {
                throw new RuntimeException('El articulo ya fue devuelto');
            }
            if ($qty > $available) {
                errorResponse('Cantidad a devolver excede lo vendido', 409, ['available' => $available]);
            }

            $newReturned = $alreadyReturned + $qty;
            $fueDevuelto = $newReturned >= $soldQty ? '1' : '0';
            $updItem = $pdo->prepare(
                'UPDATE ventatickets_articulos
                 SET CANTIDAD_DEVUELTA = :returned, FUE_DEVUELTO = :fue
                 WHERE TICKET_ID = :ticket AND ID = :item'
            );
            $updItem->execute([
                ':returned' => (string)$newReturned,
                ':fue' => $fueDevuelto,
                ':ticket' => $ticketKey,
                ':item' => $itemId,
            ]);

            $unitPrice = salesToFloat($itemRow['PRECIO_USADO'] ?? $itemRow['PAGADO_EN'] ?? 0);
            $amountReturn = round($unitPrice * $qty, 2);
            $prevTotalReturned = salesToFloat($ticket['TOTAL_DEVUELTO'] ?? 0);
            $updTicket = $pdo->prepare('UPDATE ventatickets SET TOTAL_DEVUELTO = :totalDev WHERE ID = :id');
            $updTicket->execute([
                ':totalDev' => (string)round($prevTotalReturned + $amountReturn, 2),
                ':id' => $ticketKey,
            ]);

            $pdo->commit();

            $productId = trim((string)($itemRow['PRODUCTO_CODIGO'] ?? ''));
            $products = readJsonFile($productsPath);
            $productIdToIndex = [];
            foreach ($products as $idx => $p) {
                $productIdToIndex[(string)($p['id'] ?? '')] = $idx;
            }

            if ($productId !== '' && !str_starts_with($productId, 'tmp-') && array_key_exists($productId, $productIdToIndex)) {
                $pIdx = $productIdToIndex[$productId];
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
                    'productId' => $productId,
                    'productName' => (string)($itemRow['PRODUCTO_NOMBRE'] ?? ''),
                    'delta' => $qty,
                    'before' => $before,
                    'after' => $after,
                    'note' => 'Devolucion ticket #' . $ticketId,
                    'source' => 'sales',
                    'createdAt' => date('c'),
                ];
                if (count($movements) > 20000) {
                    $movements = array_slice($movements, -20000);
                }
                writeJsonFile($inventoryMovementsPath, $movements);
            }

            // Mantiene compatibilidad para modulos que leen sales.json.
            $saleIndex = -1;
            foreach ($sales as $idx => $sale) {
                if ((string)($sale['ticketId'] ?? '') === $ticketId) {
                    $saleIndex = (int)$idx;
                    break;
                }
            }
            if ($saleIndex >= 0) {
                $sale = $sales[$saleIndex];
                $returns = is_array($sale['returns'] ?? null) ? $sale['returns'] : [];
                $returns[] = [
                    'id' => 'ret-' . bin2hex(random_bytes(4)),
                    'itemId' => $itemId,
                    'qty' => $qty,
                    'reason' => $reason,
                    'amount' => $amountReturn,
                    'createdAt' => date('c'),
                ];
                $sale['returns'] = $returns;
                $sales[$saleIndex] = $sale;
                writeJsonFile($salesPath, $sales);
            }

            ok([
                'ticketId' => $ticketId,
                'itemId' => $itemId,
                'qtyReturned' => $qty,
                'availableAfter' => $available - $qty,
            ]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            errorResponse($e->getMessage(), 404);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            errorResponse('No se pudo registrar la devolucion', 500);
        }
    }

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
$clientRequestId = trim((string)($body['clientRequestId'] ?? ''));
$mixedPayments = [
    'cash' => 0.0,
    'transfer' => 0.0,
    'credit' => 0.0,
];
$amountPending = round((float)($body['amountPending'] ?? 0), 2);
$cashier = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Cajero'));

if (!in_array($paymentMethod, ['cash', 'card', 'mixed', 'credit', 'voucher', 'transfer', 'check'], true)) {
    $paymentMethod = 'cash';
}

if (is_array($mixedPaymentsBody)) {
    $mixedPayments['cash'] = round((float)($mixedPaymentsBody['cash'] ?? 0), 2);
    $mixedPayments['transfer'] = round((float)($mixedPaymentsBody['transfer'] ?? 0), 2);
    $mixedPayments['credit'] = round((float)($mixedPaymentsBody['credit'] ?? ($mixedPaymentsBody['card'] ?? 0)), 2);
}

if ($paymentMethod === 'credit') {
    $paidWith = 0.0;
    $change = 0.0;
    $amountPending = round($total, 2);
    $mixedPayments = ['cash' => 0.0, 'transfer' => 0.0, 'credit' => 0.0];
} elseif ($paymentMethod === 'mixed') {
    $cashPart = max(0.0, round((float)$mixedPayments['cash'], 2));
    $transferPart = max(0.0, round((float)$mixedPayments['transfer'], 2));
    $creditPart = max(0.0, round((float)$mixedPayments['credit'], 2));
    $covered = round($cashPart + $transferPart + $creditPart, 2);
    if ($covered + 0.009 < $total) {
        errorResponse('Pago mixto insuficiente: efectivo + transferencia + crédito debe cubrir el total', 400);
    }

    $paidWith = round($cashPart + $transferPart, 2);
    $change = max(0.0, round($covered - $total, 2));
    $amountPending = $creditPart;
} else {
    $amountPending = 0.0;
    $mixedPayments = ['cash' => 0.0, 'transfer' => 0.0, 'credit' => 0.0];
}

if (($paymentMethod === 'credit' || ($paymentMethod === 'mixed' && $amountPending > 0)) && ($customerId === '' || $customerId === 'c-001')) {
    errorResponse('Seleccione un cliente para registrar saldo pendiente', 400);
}

if ($customerName === '') {
    $customerName = $customerId === 'c-001' ? 'Publico en general' : $customerId;
}

$sales = readJsonFile($salesPath);
if ($clientRequestId !== '') {
    foreach ($sales as $existingSale) {
        if ((string)($existingSale['clientRequestId'] ?? '') !== $clientRequestId) {
            continue;
        }

        ok([
            'ticketId' => (string)($existingSale['ticketId'] ?? ''),
            'duplicate' => true,
        ]);
    }
}

// Adjust stock for registered products
$products = readJsonFile($productsPath);
$productIdToIndex = [];
foreach ($products as $idx => $p) {
    $productIdToIndex[(string)($p['id'] ?? '')] = $idx;
}

$nextTicketEstimate = count($sales) + 1;

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
    'clientRequestId' => $clientRequestId,
];
$sales[] = $sale;
writeJsonFile($salesPath, $sales);

ok(['ticketId' => $ticketId]);

