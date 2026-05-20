<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/persistence.php';

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

function salesStatus(array $sale): string
{
    $status = strtolower(trim((string)($sale['status'] ?? 'completed')));
    if ($status === 'pending') {
        return 'pending';
    }
    return 'completed';
}

function pendingTicketNextId(array $sales): string
{
    $max = 0;
    foreach ($sales as $sale) {
        $ticketId = strtoupper(trim((string)($sale['ticketId'] ?? '')));
        if (preg_match('/^P-(\d+)$/', $ticketId, $m) === 1) {
            $max = max($max, (int)$m[1]);
        }
    }
    return 'P-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
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
    $rows = array_values(array_filter(legacyReadSalesFromTickets(), static function ($sale) use ($date, $q): bool {
        if (!is_array($sale)) {
            return false;
        }
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

    usort($rows, static function ($a, $b): int {
        return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
    });

    $total = count($rows);
    $offset = ($page - 1) * $pageSize;
    $items = array_slice($rows, $offset, $pageSize);

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

/** @return array<int, array<string, mixed>> */
function salesReadBackupRows(string $path): array
{
    $rows = readDiskJsonFile($path);
    return is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row))) : [];
}

function salesSyncDbBackups(string $salesPath, string $productsPath, string $inventoryMovementsPath): void
{
    writeJsonBackupFile($salesPath, legacyReadSalesFromTickets());
    writeJsonBackupFile($productsPath, legacyReadProducts());
    writeJsonBackupFile($inventoryMovementsPath, legacyReadInventoryMovements());
}

function salesMixedPaymentCode(string $method): string
{
    return match (strtolower(trim($method))) {
        'cash' => 'E',
        'transfer' => 'T',
        'credit', 'card' => 'C',
        'invoice' => 'F',
        'voucher' => 'V',
        'check' => 'K',
        default => 'E',
    };
}

function salesFooterMeta(array $sale): string
{
    $payload = [
        'discountPct' => salesToFloat($sale['discountPct'] ?? 0),
        'discountAmount' => salesToFloat($sale['discountAmount'] ?? 0),
        'transferMeta' => is_array($sale['transferMeta'] ?? null) ? $sale['transferMeta'] : ['reference' => '', 'phone' => ''],
        'mixedPayments' => is_array($sale['mixedPayments'] ?? null) ? $sale['mixedPayments'] : null,
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '';
}

function salesNextTicketIdFromDb(string $prefix = ''): string
{
    $rows = db()->query('SELECT ID, FOLIO FROM VENTATICKETS')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $max = 0;
    foreach ($rows as $row) {
        foreach (['ID', 'FOLIO'] as $column) {
            $ticketId = strtoupper(trim((string)($row[$column] ?? '')));
            if ($ticketId === '') {
                continue;
            }
            if ($prefix !== '') {
                if (preg_match('/^' . preg_quote(strtoupper($prefix), '/') . '(\d+)$/', $ticketId, $m) === 1) {
                    $max = max($max, (int)$m[1]);
                }
                continue;
            }
            if (preg_match('/^\d+$/', $ticketId) === 1) {
                $max = max($max, (int)$ticketId);
            }
        }
    }

    if ($prefix !== '') {
        return strtoupper($prefix) . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
    }

    return (string)($max + 1);
}

function salesFindDbSaleByTicketId(string $ticketId): ?array
{
    foreach (legacyReadSalesFromTickets() as $sale) {
        if (is_array($sale) && (string)($sale['ticketId'] ?? '') === $ticketId) {
            return $sale;
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $sale
 */
function salesReplaceTicketInDb(PDO $pdo, string $ticketId, array $sale, string $status): void
{
    $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
    $createdAtIso = trim((string)($sale['createdAt'] ?? date('c')));
    $createdAtSql = legacyToSqlDateTime($createdAtIso);
    $updatedAtSql = legacyToSqlDateTime((string)($sale['updatedAt'] ?? date('c')));
    $customerName = trim((string)($sale['customerName'] ?? 'Publico en general'));
    $customerId = trim((string)($sale['customerId'] ?? ''));
    $cashier = trim((string)($sale['cashier'] ?? 'Cajero'));
    $paymentMethod = strtolower(trim((string)($sale['paymentMethod'] ?? 'cash')));
    $amountPending = salesToFloat($sale['amountPending'] ?? 0);

    $numItems = 0.0;
    foreach ($items as $item) {
        $numItems += max(0, salesToFloat($item['qty'] ?? 0));
    }

    $stmt = $pdo->prepare(
        'INSERT INTO VENTATICKETS (
            ID, FOLIO, CAJA_ID, CAJERO_ID, NOMBRE, CREADO_EN, SUBTOTAL, IMPUESTOS, TOTAL, GANANCIA,
            ESTA_ABIERTO, CLIENTE_ID, VENDIDO_EN, ES_MODIFICABLE, PAGO_CON, MONEDA, NUMERO_ARTICULOS, PAGADO_EN,
            ESTA_CANCELADO, OPERACION_ID, OLD_TICKET_ID, NOTAS, IMPRIMIR_NOTA, FORMA_PAGO, REFERENCIA, FACTURA_ID,
            TOTAL_DEVUELTO, TOTAL_AHORRADO, TURNO_ID, TIPO_DE_CAMBIO, TOTAL_CREDITO, NOTAS_AL_PIE, REFRESCAR_TICKET,
            TOTAL_FACTURABLE, CLIENTESV2_ID, CLIENTESV2_CREDITO_ID, ACTIVO, IMPRIMIR_DATOS_CLIENTE, SALDO_CREDITO, IMPUESTOS_RETENIDOS
        ) VALUES (
            :id, :folio, :caja_id, :cajero_id, :nombre, :creado_en, :subtotal, :impuestos, :total, :ganancia,
            :esta_abierto, :cliente_id, :vendido_en, :es_modificable, :pago_con, :moneda, :numero_articulos, :pagado_en,
            :esta_cancelado, :operacion_id, :old_ticket_id, :notas, :imprimir_nota, :forma_pago, :referencia, :factura_id,
            :total_devuelto, :total_ahorrado, :turno_id, :tipo_de_cambio, :total_credito, :notas_al_pie, :refrescar_ticket,
            :total_facturable, :clientesv2_id, :clientesv2_credito_id, :activo, :imprimir_datos_cliente, :saldo_credito, :impuestos_retenidos
        )
        ON DUPLICATE KEY UPDATE
            FOLIO = VALUES(FOLIO),
            CAJA_ID = VALUES(CAJA_ID),
            CAJERO_ID = VALUES(CAJERO_ID),
            NOMBRE = VALUES(NOMBRE),
            CREADO_EN = VALUES(CREADO_EN),
            SUBTOTAL = VALUES(SUBTOTAL),
            TOTAL = VALUES(TOTAL),
            ESTA_ABIERTO = VALUES(ESTA_ABIERTO),
            CLIENTE_ID = VALUES(CLIENTE_ID),
            VENDIDO_EN = VALUES(VENDIDO_EN),
            ES_MODIFICABLE = VALUES(ES_MODIFICABLE),
            PAGO_CON = VALUES(PAGO_CON),
            NUMERO_ARTICULOS = VALUES(NUMERO_ARTICULOS),
            PAGADO_EN = VALUES(PAGADO_EN),
            NOTAS = VALUES(NOTAS),
            FORMA_PAGO = VALUES(FORMA_PAGO),
            REFERENCIA = VALUES(REFERENCIA),
            TOTAL_CREDITO = VALUES(TOTAL_CREDITO),
            NOTAS_AL_PIE = VALUES(NOTAS_AL_PIE),
            TOTAL_FACTURABLE = VALUES(TOTAL_FACTURABLE),
            CLIENTESV2_ID = VALUES(CLIENTESV2_ID),
            ACTIVO = VALUES(ACTIVO),
            SALDO_CREDITO = VALUES(SALDO_CREDITO)'
    );

    $stmt->execute([
        ':id' => $ticketId,
        ':folio' => $ticketId,
        ':caja_id' => '1',
        ':cajero_id' => $cashier,
        ':nombre' => $customerName,
        ':creado_en' => $createdAtSql,
        ':subtotal' => (string)salesToFloat($sale['subtotal'] ?? 0),
        ':impuestos' => '0',
        ':total' => (string)salesToFloat($sale['total'] ?? 0),
        ':ganancia' => '0',
        ':esta_abierto' => $status === 'pending' ? '1' : '0',
        ':cliente_id' => $customerId,
        ':vendido_en' => $updatedAtSql,
        ':es_modificable' => $status === 'pending' ? '1' : '0',
        ':pago_con' => (string)salesToFloat($sale['paidWith'] ?? 0),
        ':moneda' => 'USD',
        ':numero_articulos' => (string)$numItems,
        ':pagado_en' => (string)salesToFloat($sale['change'] ?? 0),
        ':esta_cancelado' => '0',
        ':operacion_id' => '',
        ':old_ticket_id' => $ticketId,
        ':notas' => trim((string)($sale['paymentNote'] ?? '')),
        ':imprimir_nota' => '0',
        ':forma_pago' => $paymentMethod,
        ':referencia' => trim((string)($sale['clientRequestId'] ?? '')),
        ':factura_id' => '',
        ':total_devuelto' => (string)salesToFloat($sale['totalReturned'] ?? 0),
        ':total_ahorrado' => '0',
        ':turno_id' => '',
        ':tipo_de_cambio' => '1',
        ':total_credito' => (string)$amountPending,
        ':notas_al_pie' => salesFooterMeta($sale),
        ':refrescar_ticket' => '0',
        ':total_facturable' => (string)salesToFloat($sale['total'] ?? 0),
        ':clientesv2_id' => $customerId,
        ':clientesv2_credito_id' => '',
        ':activo' => '1',
        ':imprimir_datos_cliente' => '0',
        ':saldo_credito' => (string)$amountPending,
        ':impuestos_retenidos' => '0',
    ]);

    $pdo->prepare('DELETE FROM VENTATICKETS_ARTICULOS WHERE TICKET_ID = :ticket')->execute([':ticket' => $ticketId]);
    $itemStmt = $pdo->prepare(
        'INSERT INTO VENTATICKETS_ARTICULOS (
            ID, TICKET_ID, PRODUCTO_CODIGO, PRODUCTO_NOMBRE, CANTIDAD, GANANCIA, DEPARTAMENTO_ID, PAGADO_EN,
            USA_MAYOREO, PORCENTAJE_DESCUENTO, COMPONENTES, IMPUESTOS_USADOS, IMPUESTO_UNITARIO, PRECIO_USADO,
            CANTIDAD_DEVUELTA, FUE_DEVUELTO, PORCENTAJE_PAGADO, PRECIO_FINAL, AGREGADO_EN, TOTAL_ARTICULO
        ) VALUES (
            :id, :ticket_id, :producto_codigo, :producto_nombre, :cantidad, :ganancia, :departamento_id, :pagado_en,
            :usa_mayoreo, :porcentaje_descuento, :componentes, :impuestos_usados, :impuesto_unitario, :precio_usado,
            :cantidad_devuelta, :fue_devuelto, :porcentaje_pagado, :precio_final, :agregado_en, :total_articulo
        )'
    );

    foreach ($items as $index => $item) {
        $qty = max(0, salesToFloat($item['qty'] ?? 0));
        $price = salesToFloat($item['price'] ?? 0);
        $discountPct = salesToFloat($item['discountPct'] ?? 0);
        $itemStmt->execute([
            ':id' => $ticketId . '-' . ($index + 1),
            ':ticket_id' => $ticketId,
            ':producto_codigo' => trim((string)($item['id'] ?? $item['barcode'] ?? '')),
            ':producto_nombre' => trim((string)($item['name'] ?? 'Producto')),
            ':cantidad' => (string)$qty,
            ':ganancia' => '0',
            ':departamento_id' => '',
            ':pagado_en' => (string)$price,
            ':usa_mayoreo' => !empty($item['wholesaleEnabled']) ? '1' : '0',
            ':porcentaje_descuento' => (string)$discountPct,
            ':componentes' => '',
            ':impuestos_usados' => '',
            ':impuesto_unitario' => '0',
            ':precio_usado' => (string)$price,
            ':cantidad_devuelta' => '0',
            ':fue_devuelto' => '0',
            ':porcentaje_pagado' => '100',
            ':precio_final' => (string)$price,
            ':agregado_en' => $createdAtSql,
            ':total_articulo' => (string)round($qty * $price, 2),
        ]);
    }

    if (legacyTableExists('pagos_mixtos') && preg_match('/^\d+$/', $ticketId) === 1) {
        $pdo->prepare('DELETE FROM PAGOS_MIXTOS WHERE TICKET_ID = :ticket')->execute([':ticket' => $ticketId]);
        $mixedPayments = is_array($sale['mixedPayments'] ?? null) ? $sale['mixedPayments'] : [];
        if (strtolower(trim((string)($sale['paymentMethod'] ?? ''))) === 'mixed') {
            $mixStmt = $pdo->prepare(
                'INSERT INTO PAGOS_MIXTOS (ID, TICKET_ID, ABONO_ID, FORMA_DE_PAGO, MONTO, REFERENCIA, CLIENTE_ID, CLIENTESV2_CREDITO_ID)
                 VALUES (:id, :ticket_id, :abono_id, :forma_de_pago, :monto, :referencia, :cliente_id, :credito_id)'
            );
            $mixIndex = 1;
            foreach (['cash', 'transfer', 'credit'] as $method) {
                $amount = salesToFloat($mixedPayments[$method] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $mixStmt->execute([
                    ':id' => $mixIndex,
                    ':ticket_id' => (int)preg_replace('/\D+/', '', $ticketId),
                    ':abono_id' => '',
                    ':forma_de_pago' => salesMixedPaymentCode($method),
                    ':monto' => $amount,
                    ':referencia' => trim((string)(is_array($sale['transferMeta'] ?? null) ? ($sale['transferMeta']['reference'] ?? '') : '')),
                    ':cliente_id' => $customerId,
                    ':credito_id' => '',
                ]);
                $mixIndex++;
            }
        }
    }
}

function salesMarkPendingCancelledDb(PDO $pdo, string $ticketId): bool
{
    $exists = $pdo->prepare(
        "SELECT ID FROM VENTATICKETS
         WHERE (ID = :ticket_id OR FOLIO = :ticket_folio)
           AND COALESCE(NULLIF(ESTA_ABIERTO, ''), '0') = '1'
           AND COALESCE(NULLIF(ACTIVO, ''), '1') <> '0'
         LIMIT 1"
    );
    $exists->execute([
        ':ticket_id' => $ticketId,
        ':ticket_folio' => $ticketId,
    ]);
    if (!is_array($exists->fetch(PDO::FETCH_ASSOC))) {
        return false;
    }

    $stmt = $pdo->prepare(
        "UPDATE VENTATICKETS
         SET ACTIVO = '0', ESTA_CANCELADO = '1', ES_MODIFICABLE = '0', ESTA_ABIERTO = '0'
         WHERE ID = :ticket_id OR FOLIO = :ticket_folio"
    );
    $stmt->execute([
        ':ticket_id' => $ticketId,
        ':ticket_folio' => $ticketId,
    ]);
    return true;
}

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    if ($action === 'day' || $action === 'pending') {
        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        $q = strtolower(trim((string)($_GET['q'] ?? '')));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $includeCompleted = $action === 'day';
        $includePending = true;
        if (salesCanUseTicketsDb()) {
            persistenceMarkDbHealthy('sales_get');
            $result = salesFetchDayFromDb($date, $q, 1, 5000);
            $rows = array_values(array_filter($result['items'] ?? [], static function ($sale) use ($includeCompleted, $includePending): bool {
                $status = salesStatus(is_array($sale) ? $sale : []);
                return ($status === 'completed' && $includeCompleted) || ($status === 'pending' && $includePending);
            }));
            $total = count($rows);
            $offset = ($page - 1) * $pageSize;
            ok([
                'items' => array_slice($rows, $offset, $pageSize),
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => max(1, (int)ceil($total / max(1, $pageSize))),
                ],
            ]);
        }

        persistenceMarkDbFallback('sales_get');
        $sales = salesReadBackupRows($salesPath);
        $rows = array_values(array_filter($sales, static function ($sale) use ($date, $q, $includeCompleted, $includePending): bool {
            if (!is_array($sale)) {
                return false;
            }
            $status = salesStatus($sale);
            if (($status === 'completed' && !$includeCompleted) || ($status === 'pending' && !$includePending)) {
                return false;
            }
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

        usort($rows, static function ($a, $b): int {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });

        $total = count($rows);
        $offset = ($page - 1) * $pageSize;
        ok([
            'items' => array_slice($rows, $offset, $pageSize),
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => max(1, (int)ceil($total / $pageSize)),
            ],
        ]);
    }

    $sales = salesCanUseTicketsDb() ? legacyReadSalesFromTickets() : salesReadBackupRows($salesPath);
    $last = (string)($_GET['last'] ?? '');
    if ($last === '1') {
        $lastSale = null;
        for ($index = count($sales) - 1; $index >= 0; $index--) {
            $candidate = $sales[$index] ?? null;
            if (is_array($candidate) && salesStatus($candidate) === 'completed') {
                $lastSale = $candidate;
                break;
            }
        }
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

if ($action === 'create_pending' || $action === 'update_pending') {
    $items = $body['items'] ?? null;
    if (!is_array($items) || count($items) === 0) {
        errorResponse('No hay items', 400);
    }

    $subtotal = round((float)($body['subtotal'] ?? 0), 2);
    $total = round((float)($body['total'] ?? 0), 2);
    $paidWith = round((float)($body['paidWith'] ?? 0), 2);
    $change = round((float)($body['change'] ?? 0), 2);
    $customerId = trim((string)($body['customerId'] ?? 'c-001'));
    $customerName = trim((string)($body['customerName'] ?? ''));
    $paymentMethod = strtolower(trim((string)($body['paymentMethod'] ?? 'cash')));
    $paymentNote = trim((string)($body['paymentNote'] ?? ''));
    $amountPending = round((float)($body['amountPending'] ?? 0), 2);
    $discountPct = round((float)($body['discountPct'] ?? 0), 2);
    $discountAmount = round((float)($body['discountAmount'] ?? 0), 2);
    $cashier = trim((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Cajero'));
    $mixedPaymentsBody = $body['mixedPayments'] ?? null;
    $transferMetaBody = $body['transferMeta'] ?? null;

    $mixedPayments = [
        'cash' => 0.0,
        'transfer' => 0.0,
        'credit' => 0.0,
    ];
    if (is_array($mixedPaymentsBody)) {
        $mixedPayments['cash'] = round((float)($mixedPaymentsBody['cash'] ?? 0), 2);
        $mixedPayments['transfer'] = round((float)($mixedPaymentsBody['transfer'] ?? 0), 2);
        $mixedPayments['credit'] = round((float)($mixedPaymentsBody['credit'] ?? ($mixedPaymentsBody['card'] ?? 0)), 2);
    }

    $transferMeta = [
        'reference' => is_array($transferMetaBody) ? trim((string)($transferMetaBody['reference'] ?? '')) : '',
        'phone' => is_array($transferMetaBody) ? trim((string)($transferMetaBody['phone'] ?? '')) : '',
    ];

    if ($customerName === '') {
        $customerName = $customerId === 'c-001' ? 'Publico en general' : $customerId;
    }

    $ticketId = trim((string)($body['ticketId'] ?? ''));
    if (salesCanUseTicketsDb()) {
        try {
            $pdo = db();
            $saleData = [
                'ticketId' => $ticketId,
                'createdAt' => date('c'),
                'updatedAt' => date('c'),
                'status' => 'pending',
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
                'discountPct' => $discountPct,
                'discountAmount' => $discountAmount,
                'transferMeta' => $transferMeta,
                'returns' => [],
            ];

            if ($action === 'update_pending') {
                if ($ticketId === '') {
                    errorResponse('Ticket pendiente invalido', 400);
                }
                $existing = salesFindDbSaleByTicketId($ticketId);
                if (!is_array($existing) || salesStatus($existing) !== 'pending') {
                    errorResponse('Venta pendiente no encontrada', 404);
                }
                $saleData['createdAt'] = (string)($existing['createdAt'] ?? date('c'));
            } else {
                $ticketId = salesNextTicketIdFromDb('P-');
                $saleData['ticketId'] = $ticketId;
            }

            $pdo->beginTransaction();
            salesReplaceTicketInDb($pdo, $ticketId, $saleData, 'pending');
            $pdo->commit();
            persistenceMarkDbHealthy('sales_pending');
            salesSyncDbBackups($salesPath, $productsPath, $inventoryMovementsPath);
            ok(['ticketId' => $ticketId]);
        } catch (Throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            persistenceMarkDbFallback('sales_pending');
        }
    }

    $sales = salesReadBackupRows($salesPath);
    if ($action === 'update_pending') {
        if ($ticketId === '') {
            errorResponse('Ticket pendiente invalido', 400);
        }
        $updated = false;
        foreach ($sales as $index => $sale) {
            if (!is_array($sale) || (string)($sale['ticketId'] ?? '') !== $ticketId || salesStatus($sale) !== 'pending') {
                continue;
            }
            $sales[$index] = array_merge($sale, [
                'updatedAt' => date('c'),
                'status' => 'pending',
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
                'discountPct' => $discountPct,
                'discountAmount' => $discountAmount,
                'transferMeta' => $transferMeta,
            ]);
            $updated = true;
            break;
        }
        if (!$updated) {
            errorResponse('Venta pendiente no encontrada', 404);
        }
        writeJsonFile($salesPath, $sales);
        persistenceMarkDbFallback('sales_pending');
        ok(['ticketId' => $ticketId]);
    }

    $ticketId = pendingTicketNextId($sales);
    $now = date('c');
    $sales[] = [
        'ticketId' => $ticketId,
        'createdAt' => $now,
        'updatedAt' => $now,
        'status' => 'pending',
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
        'discountPct' => $discountPct,
        'discountAmount' => $discountAmount,
        'transferMeta' => $transferMeta,
        'returns' => [],
    ];
    writeJsonFile($salesPath, $sales);
    persistenceMarkDbFallback('sales_pending');

    ok(['ticketId' => $ticketId]);
}

if ($action === 'restore_pending') {
    $ticketId = trim((string)($body['ticketId'] ?? ''));
    if ($ticketId === '') {
        errorResponse('Ticket pendiente invalido', 400);
    }

    $sales = readJsonFile($salesPath);
    $pendingSale = null;
    $nextSales = [];
    foreach ($sales as $sale) {
        if (is_array($sale) && (string)($sale['ticketId'] ?? '') === $ticketId && salesStatus($sale) === 'pending') {
            $pendingSale = $sale;
            continue;
        }
        $nextSales[] = $sale;
    }

    if (!is_array($pendingSale)) {
        errorResponse('Venta pendiente no encontrada', 404);
    }

    writeJsonFile($salesPath, $nextSales);
    ok($pendingSale);
}

if ($action === 'assign_pending_customer') {
    $ticketId = trim((string)($body['ticketId'] ?? ''));
    $customerId = trim((string)($body['customerId'] ?? 'c-001'));
    $customerName = trim((string)($body['customerName'] ?? ''));
    if ($ticketId === '') {
        errorResponse('Ticket pendiente invalido', 400);
    }
    if ($customerName === '') {
        $customerName = $customerId === 'c-001' ? 'Publico en general' : $customerId;
    }

    if (salesCanUseTicketsDb()) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "UPDATE VENTATICKETS
                 SET CLIENTE_ID = :customer_id, CLIENTESV2_ID = :customer_v2_id, NOMBRE = :customer_name, VENDIDO_EN = :updated_at
                 WHERE (ID = :ticket_id OR FOLIO = :ticket_folio) AND COALESCE(NULLIF(ESTA_ABIERTO, ''), '0') = '1' AND COALESCE(NULLIF(ACTIVO, ''), '1') <> '0'"
            );
            $stmt->execute([
                ':customer_id' => $customerId,
                ':customer_v2_id' => $customerId,
                ':customer_name' => $customerName,
                ':updated_at' => legacyToSqlDateTime(date('c')),
                ':ticket_id' => $ticketId,
                ':ticket_folio' => $ticketId,
            ]);
            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('Venta pendiente no encontrada');
            }
            $pdo->commit();
            persistenceMarkDbHealthy('sales_pending');
            salesSyncDbBackups($salesPath, $productsPath, $inventoryMovementsPath);
            ok(['ticketId' => $ticketId, 'customerId' => $customerId, 'customerName' => $customerName]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            persistenceMarkDbFallback('sales_pending');
            if ($e instanceof RuntimeException) {
                errorResponse($e->getMessage(), 404);
            }
        }
    }

    $sales = salesReadBackupRows($salesPath);
    $updated = false;
    foreach ($sales as $index => $sale) {
        if (!is_array($sale) || (string)($sale['ticketId'] ?? '') !== $ticketId || salesStatus($sale) !== 'pending') {
            continue;
        }
        $sale['customerId'] = $customerId;
        $sale['customerName'] = $customerName;
        $sale['updatedAt'] = date('c');
        $sales[$index] = $sale;
        $updated = true;
        break;
    }

    if (!$updated) {
        errorResponse('Venta pendiente no encontrada', 404);
    }

    writeJsonFile($salesPath, $sales);
    persistenceMarkDbFallback('sales_pending');
    ok(['ticketId' => $ticketId, 'customerId' => $customerId, 'customerName' => $customerName]);
}

if ($action === 'cancel_pending') {
    $ticketId = trim((string)($body['ticketId'] ?? ''));
    if ($ticketId === '') {
        errorResponse('Ticket pendiente invalido', 400);
    }

    if (salesCanUseTicketsDb()) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            if (!salesMarkPendingCancelledDb($pdo, $ticketId)) {
                throw new RuntimeException('Venta pendiente no encontrada');
            }
            $pdo->commit();
            persistenceMarkDbHealthy('sales_pending');
            salesSyncDbBackups($salesPath, $productsPath, $inventoryMovementsPath);
            ok(['ticketId' => $ticketId]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            persistenceMarkDbFallback('sales_pending');
            if ($e instanceof RuntimeException) {
                errorResponse($e->getMessage(), 404);
            }
        }
    }

    $sales = salesReadBackupRows($salesPath);
    $removed = false;
    $nextSales = [];
    foreach ($sales as $sale) {
        if (is_array($sale) && (string)($sale['ticketId'] ?? '') === $ticketId && salesStatus($sale) === 'pending') {
            $removed = true;
            continue;
        }
        $nextSales[] = $sale;
    }

    if (!$removed) {
        errorResponse('Venta pendiente no encontrada', 404);
    }

    writeJsonFile($salesPath, $nextSales);
    persistenceMarkDbFallback('sales_pending');
    ok(['ticketId' => $ticketId]);
}

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
            $ticketStmt = $pdo->prepare('SELECT * FROM ventatickets WHERE ID = :ticket_id OR FOLIO = :ticket_folio LIMIT 1');
            $ticketStmt->execute([
                ':ticket_id' => $ticketId,
                ':ticket_folio' => $ticketId,
            ]);
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

            $devolucionId = 'DEV-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            if (legacyTableExists('devoluciones')) {
                $pdo->prepare(
                    'INSERT INTO DEVOLUCIONES
                     (ID, TURNO_ID, TICKET_ID, TIPO_DEVOLUCION, PAGADO_EN, DEVUELTO_EN, CAJERO, CAJA, TOTAL_DEVUELTO, SINCRONIZADO_EN)
                     VALUES
                     (:id, :turno_id, :ticket_id, :tipo_devolucion, :pagado_en, :devuelto_en, :cajero, :caja, :total_devuelto, :sincronizado_en)'
                )->execute([
                    ':id' => $devolucionId,
                    ':turno_id' => '',
                    ':ticket_id' => $ticketKey,
                    ':tipo_devolucion' => $reason !== '' ? $reason : 'Devolucion',
                    ':pagado_en' => (string)$unitPrice,
                    ':devuelto_en' => legacyToSqlDateTime(date('c')),
                    ':cajero' => (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Cajero'),
                    ':caja' => '1',
                    ':total_devuelto' => (string)$amountReturn,
                    ':sincronizado_en' => '',
                ]);
            }

            if (legacyTableExists('devoluciones_articulos')) {
                $pdo->prepare(
                    'INSERT INTO DEVOLUCIONES_ARTICULOS
                     (ID, DEVOLUCION_ID, TICKET_ID, CODIGO_PRODUCTO, DESCRIPCION_PRODUCTO, CANTIDAD_DEVUELTA, DINERO_DEVUELTO, INVENTARIO_POST_DEVOLUCION)
                     VALUES
                     (:id, :devolucion_id, :ticket_id, :codigo_producto, :descripcion_producto, :cantidad_devuelta, :dinero_devuelto, :inventario_post_devolucion)'
                )->execute([
                    ':id' => $devolucionId . '-1',
                    ':devolucion_id' => $devolucionId,
                    ':ticket_id' => $ticketKey,
                    ':codigo_producto' => trim((string)($itemRow['PRODUCTO_CODIGO'] ?? '')),
                    ':descripcion_producto' => trim((string)($itemRow['PRODUCTO_NOMBRE'] ?? '')),
                    ':cantidad_devuelta' => (string)$qty,
                    ':dinero_devuelto' => (string)$amountReturn,
                    ':inventario_post_devolucion' => '',
                ]);
            }

            $productId = trim((string)($itemRow['PRODUCTO_CODIGO'] ?? ''));
            if ($productId !== '' && !str_starts_with($productId, 'tmp-')) {
                $productDbId = parseIntId($productId, 'p-');
                if ($productDbId !== null && $productDbId > 0) {
                    $prodStmt = $pdo->prepare('SELECT DINVENTARIO, PCOSTO FROM PRODUCTOS WHERE ID = :id LIMIT 1');
                    $prodStmt->execute([':id' => $productDbId]);
                    $productRow = $prodStmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($productRow)) {
                        $before = round((float)($productRow['DINVENTARIO'] ?? 0), 3);
                        $after = round($before + $qty, 3);
                        $pdo->prepare('UPDATE PRODUCTOS SET DINVENTARIO = :stock WHERE ID = :id')->execute([
                            ':stock' => $after,
                            ':id' => $productDbId,
                        ]);
                        if (legacyTableExists('inventario_historial')) {
                            $histId = 'mov-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                            $pdo->prepare(
                                'INSERT INTO INVENTARIO_HISTORIAL
                                 (ID, PRODUCTO_ID, CUANDO_FUE, CANTIDAD_ANTERIOR, CANTIDAD, DESCRIPCION, COSTO_UNITARIO, COSTO_DESPUES, AJUSTE_ID, RECIBO_INVENTARIO_ID, VENTA_ID, TRANSFERENCIA_ID, CAJA_ID, VENTA_POR_KIT, USUARIO_ID, ALMACEN_ID)
                                 VALUES
                                 (:id, :producto_id, :cuando_fue, :cantidad_anterior, :cantidad, :descripcion, :costo_unitario, :costo_despues, :ajuste_id, :recibo_id, :venta_id, :transferencia_id, :caja_id, :venta_por_kit, :usuario_id, :almacen_id)'
                            )->execute([
                                ':id' => $histId,
                                ':producto_id' => (string)$productDbId,
                                ':cuando_fue' => legacyToSqlDateTime(date('c')),
                                ':cantidad_anterior' => $before,
                                ':cantidad' => $qty,
                                ':descripcion' => 'Devolucion ticket #' . $ticketId,
                                ':costo_unitario' => round((float)($productRow['PCOSTO'] ?? 0), 4),
                                ':costo_despues' => round((float)($productRow['PCOSTO'] ?? 0), 4),
                                ':ajuste_id' => '',
                                ':recibo_id' => '',
                                ':venta_id' => $ticketKey,
                                ':transferencia_id' => '',
                                ':caja_id' => '1',
                                ':venta_por_kit' => '0',
                                ':usuario_id' => '',
                                ':almacen_id' => '1',
                            ]);
                        }
                    }
                }
            }
            $pdo->commit();
            persistenceMarkDbHealthy('sales_return');
            salesSyncDbBackups($salesPath, $productsPath, $inventoryMovementsPath);

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
            persistenceMarkDbFallback('sales_return');
            errorResponse($e->getMessage(), 404);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            persistenceMarkDbFallback('sales_return');
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
$pendingTicketId = trim((string)($body['ticketId'] ?? ''));
$pendingSaleIndex = -1;
$pendingCreatedAt = '';

if (!in_array($paymentMethod, ['cash', 'card', 'mixed', 'credit', 'voucher', 'transfer', 'check', 'invoice'], true)) {
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

$sales = salesCanUseTicketsDb() ? legacyReadSalesFromTickets() : salesReadBackupRows($salesPath);
if ($action === 'complete_pending') {
    if ($pendingTicketId === '') {
        errorResponse('Ticket pendiente invalido', 400);
    }
    foreach ($sales as $index => $existingSale) {
        if (!is_array($existingSale) || (string)($existingSale['ticketId'] ?? '') !== $pendingTicketId || salesStatus($existingSale) !== 'pending') {
            continue;
        }
        $pendingSaleIndex = (int)$index;
        $pendingCreatedAt = (string)($existingSale['createdAt'] ?? '');
        break;
    }
    if ($pendingSaleIndex < 0) {
        errorResponse('Venta pendiente no encontrada', 404);
    }
}

if ($clientRequestId !== '') {
    foreach ($sales as $existingSale) {
        if ($action === 'complete_pending' && (string)($existingSale['ticketId'] ?? '') === $pendingTicketId) {
            continue;
        }
        if ((string)($existingSale['clientRequestId'] ?? '') !== $clientRequestId) {
            continue;
        }

        ok([
            'ticketId' => (string)($existingSale['ticketId'] ?? ''),
            'duplicate' => true,
        ]);
    }
}

$products = readJsonFile($productsPath);
$productIdToIndex = [];
$productBarcodeToIndex = [];
foreach ($products as $idx => $p) {
    $productId = (string)($p['id'] ?? '');
    $productIdToIndex[$productId] = $idx;
    $barcode = trim((string)($p['barcode'] ?? ''));
    if ($barcode !== '') {
        $productBarcodeToIndex[$barcode] = $idx;
    }
}

$ticketId = $action === 'complete_pending' ? $pendingTicketId : (salesCanUseTicketsDb() ? salesNextTicketIdFromDb() : (string)(count($sales) + 1));
$movementEntries = [];

foreach ($items as $it) {
    if (!is_array($it)) {
        continue;
    }
    $id = (string)($it['id'] ?? '');
    $qty = salesToFloat($it['qty'] ?? 0);
    if ($qty <= 0 || str_starts_with($id, 'tmp-')) {
        continue;
    }
    if (!array_key_exists($id, $productIdToIndex)) {
        errorResponse('Producto no existe: ' . $id, 409);
    }
    $pIdx = $productIdToIndex[$id];
    $inventoryEnabled = (bool)($products[$pIdx]['inventoryEnabled'] ?? true);
    $unitType = strtolower(trim((string)($products[$pIdx]['unitType'] ?? 'unit')));
    if ($unitType === 'package') {
        $packageItems = is_array($products[$pIdx]['packageItems'] ?? null) ? $products[$pIdx]['packageItems'] : [];
        if ($packageItems === []) {
            errorResponse('No se puede armar el kit ' . (string)($products[$pIdx]['name'] ?? $id) . ' porque no tiene componentes configurados.', 409);
        }

        foreach ($packageItems as $component) {
            if (!is_array($component)) {
                continue;
            }
            $componentQty = salesToFloat($component['qty'] ?? 0) * $qty;
            if ($componentQty <= 0) {
                continue;
            }

            $componentId = trim((string)($component['productId'] ?? ''));
            $componentBarcode = trim((string)($component['barcode'] ?? ''));
            $componentIndex = null;
            if ($componentId !== '' && array_key_exists($componentId, $productIdToIndex)) {
                $componentIndex = $productIdToIndex[$componentId];
            } elseif ($componentBarcode !== '' && array_key_exists($componentBarcode, $productBarcodeToIndex)) {
                $componentIndex = $productBarcodeToIndex[$componentBarcode];
                $componentId = (string)($products[$componentIndex]['id'] ?? $componentId);
            }

            if (!is_int($componentIndex)) {
                errorResponse('No se puede armar el kit ' . (string)($products[$pIdx]['name'] ?? $id) . ' porque falta uno de sus componentes.', 409);
            }

            $componentCurrent = round((float)($products[$componentIndex]['stock'] ?? 0), 3);
            $componentNext = round($componentCurrent - $componentQty, 3);
            if ($componentNext < 0) {
                errorResponse(
                    'No se puede armar el kit ' . (string)($products[$pIdx]['name'] ?? $id) .
                    '. Stock insuficiente en ' . (string)($products[$componentIndex]['name'] ?? ($component['name'] ?? 'componente')),
                    409,
                    ['current' => $componentCurrent, 'qty' => $componentQty]
                );
            }

            $products[$componentIndex]['stock'] = $componentNext;
            $movementEntries[] = [
                'productId' => $componentId,
                'productName' => (string)($products[$componentIndex]['name'] ?? ($component['name'] ?? '')),
                'before' => $componentCurrent,
                'after' => $componentNext,
                'delta' => 0 - $componentQty,
                'ventaPorKit' => true,
                'kitName' => (string)($products[$pIdx]['name'] ?? ($it['name'] ?? 'kit')),
            ];
        }
        continue;
    }

    if (!$inventoryEnabled) {
        continue;
    }
    $current = round((float)($products[$pIdx]['stock'] ?? 0), 3);
    $next = round($current - $qty, 3);
    if ($next < 0) {
        errorResponse('Stock insuficiente para: ' . $id, 409, ['current' => $current, 'qty' => $qty]);
    }
    $products[$pIdx]['stock'] = $next;
    $movementEntries[] = [
        'productId' => $id,
        'productName' => (string)($products[$pIdx]['name'] ?? ($it['name'] ?? '')),
        'before' => $current,
        'after' => $next,
        'delta' => 0 - $qty,
        'ventaPorKit' => false,
        'kitName' => '',
    ];
}

$sale = [
    'ticketId' => $ticketId,
    'createdAt' => $action === 'complete_pending' && $pendingCreatedAt !== '' ? $pendingCreatedAt : date('c'),
    'updatedAt' => date('c'),
    'status' => 'completed',
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
    'discountPct' => salesToFloat($body['discountPct'] ?? 0),
    'discountAmount' => salesToFloat($body['discountAmount'] ?? 0),
    'transferMeta' => is_array($body['transferMeta'] ?? null) ? $body['transferMeta'] : ['reference' => '', 'phone' => ''],
    'returns' => [],
];

if (salesCanUseTicketsDb()) {
    try {
        $pdo = db();
        $pdo->beginTransaction();
        salesReplaceTicketInDb($pdo, $ticketId, $sale, 'completed');
        foreach ($movementEntries as $index => $entry) {
            $productDbId = parseIntId((string)$entry['productId'], 'p-');
            if ($productDbId === null || $productDbId <= 0) {
                continue;
            }
            $prodStmt = $pdo->prepare('SELECT DINVENTARIO, PCOSTO FROM PRODUCTOS WHERE ID = :id LIMIT 1');
            $prodStmt->execute([':id' => $productDbId]);
            $productRow = $prodStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($productRow)) {
                throw new RuntimeException('Producto no existe: ' . (string)$entry['productId']);
            }
            $currentStock = round((float)($productRow['DINVENTARIO'] ?? 0), 3);
            $expectedCurrent = round((float)$entry['before'], 3);
            if ($currentStock < $expectedCurrent - 0.001) {
                throw new RuntimeException('Stock insuficiente para: ' . (string)$entry['productId']);
            }
            $nextStock = round($currentStock + (float)$entry['delta'], 3);
            if ($nextStock < -0.001) {
                throw new RuntimeException('Stock insuficiente para: ' . (string)$entry['productId']);
            }
            $pdo->prepare('UPDATE PRODUCTOS SET DINVENTARIO = :stock WHERE ID = :id')->execute([
                ':stock' => $nextStock,
                ':id' => $productDbId,
            ]);
            if (legacyTableExists('inventario_historial')) {
                $histId = $ticketId . '-S' . ($index + 1);
                $isKitMovement = !empty($entry['ventaPorKit']);
                $movementDescription = $isKitMovement
                    ? ('Venta ticket #' . $ticketId . ' (kit: ' . (string)($entry['kitName'] ?? '') . ')')
                    : ('Venta ticket #' . $ticketId);
                $pdo->prepare(
                    'INSERT INTO INVENTARIO_HISTORIAL
                     (ID, PRODUCTO_ID, CUANDO_FUE, CANTIDAD_ANTERIOR, CANTIDAD, DESCRIPCION, COSTO_UNITARIO, COSTO_DESPUES, AJUSTE_ID, RECIBO_INVENTARIO_ID, VENTA_ID, TRANSFERENCIA_ID, CAJA_ID, VENTA_POR_KIT, USUARIO_ID, ALMACEN_ID)
                     VALUES
                     (:id, :producto_id, :cuando_fue, :cantidad_anterior, :cantidad, :descripcion, :costo_unitario, :costo_despues, :ajuste_id, :recibo_id, :venta_id, :transferencia_id, :caja_id, :venta_por_kit, :usuario_id, :almacen_id)'
                )->execute([
                    ':id' => $histId,
                    ':producto_id' => (string)$productDbId,
                    ':cuando_fue' => legacyToSqlDateTime(date('c')),
                    ':cantidad_anterior' => $currentStock,
                    ':cantidad' => round((float)$entry['delta'], 3),
                    ':descripcion' => $movementDescription,
                    ':costo_unitario' => round((float)($productRow['PCOSTO'] ?? 0), 4),
                    ':costo_despues' => round((float)($productRow['PCOSTO'] ?? 0), 4),
                    ':ajuste_id' => '',
                    ':recibo_id' => '',
                    ':venta_id' => $ticketId,
                    ':transferencia_id' => '',
                    ':caja_id' => '1',
                    ':venta_por_kit' => $isKitMovement ? '1' : '0',
                    ':usuario_id' => '',
                    ':almacen_id' => '1',
                ]);
            }
        }
        $pdo->commit();
        persistenceMarkDbHealthy('sales_create');
        salesSyncDbBackups($salesPath, $productsPath, $inventoryMovementsPath);
        ok(['ticketId' => $ticketId]);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        persistenceMarkDbFallback('sales_create');
    }
}

$sales[] = $sale;
if ($action === 'complete_pending' && $pendingSaleIndex >= 0) {
    $sales[$pendingSaleIndex] = $sale;
    array_pop($sales);
}
writeJsonFile($salesPath, $sales);
writeJsonFile($productsPath, $products);

if (!empty($movementEntries)) {
    $movements = readJsonFile($inventoryMovementsPath);
    $max = movementNextId($movements);
    foreach ($movementEntries as $entry) {
        $max++;
        $movements[] = [
            'id' => 'mov-' . str_pad((string)$max, 6, '0', STR_PAD_LEFT),
            'type' => 'sale',
            'productId' => (string)$entry['productId'],
            'productName' => (string)$entry['productName'],
            'delta' => round((float)$entry['delta'], 3),
            'before' => round((float)$entry['before'], 3),
            'after' => round((float)$entry['after'], 3),
            'note' => !empty($entry['ventaPorKit'])
                ? ('Venta ticket #' . $ticketId . ' (kit: ' . (string)($entry['kitName'] ?? '') . ')')
                : ('Venta ticket #' . $ticketId),
            'source' => 'sales',
            'ventaPorKit' => !empty($entry['ventaPorKit']),
            'createdAt' => date('c'),
        ];
    }
    if (count($movements) > 20000) {
        $movements = array_slice($movements, -20000);
    }
    writeJsonFile($inventoryMovementsPath, $movements);
}

persistenceMarkDbFallback('sales_create');
ok(['ticketId' => $ticketId]);

