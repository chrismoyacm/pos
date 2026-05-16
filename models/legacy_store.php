<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/db.php';

/**
 * @return array<int|string, mixed>|null
 */
function legacyMappedRead(string $fileName): ?array
{
    if (!dbEnabled()) {
        return null;
    }

    $key = normalizeLegacyFileKey($fileName);
    $base = basename(str_replace('\\', '/', $key));

    try {
        return match ($base) {
            'users.json' => legacyReadEntityWithOverlay($base, 'legacyReadUsers'),
            'sales.json' => legacyReadSalesFromTickets(),
            'products.json' => legacyReadProducts(),
            'customers.json' => legacyReadCustomers(),
            'departments.json' => legacyReadEntityWithOverlay($base, 'legacyReadDepartments'),
            'promotions.json' => legacyReadEntityWithOverlay($base, 'legacyReadPromotions'),
            'inventory_movements.json' => legacyReadInventoryMovements(),
            'cash_movements.json' => legacyReadEntityWithOverlay($base, 'legacyReadCashMovements'),
            'credit_payments.json' => legacyReadEntityWithOverlay($base, 'legacyReadCreditPayments'),
            'providers.json' => legacyReadProviders(),
            'purchase_list.json' => legacyReadPurchaseList(),
            'purchase_orders.json' => legacyReadPurchaseOrders(),
            'purchase_history.json' => legacyReadPurchaseHistory(),
            default => isLegacyDocumentFile($key) ? legacyReadDocumentStore($key) : null,
        };
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array<int|string, mixed> */
function legacyReadDocumentStoreSalesCompat(): array
{
    $cached = legacyReadDocumentStore('app/sales.json');
    if ($cached !== []) {
        return $cached;
    }

    return legacyReadDocumentStore('sales.json');
}

function legacyMappedWrite(string $fileName, array $data): bool
{
    if (!dbEnabled()) {
        return false;
    }

    $key = normalizeLegacyFileKey($fileName);
    $base = basename(str_replace('\\', '/', $key));

    try {
        return match ($base) {
            'users.json' => legacyWriteEntitySync($base, $data, 'legacyWriteUsers'),
            'sales.json' => false,
            'products.json' => legacyWriteEntitySync($base, $data, 'legacyWriteProducts'),
            'customers.json' => legacyWriteEntitySync($base, $data, 'legacyWriteCustomers'),
            'departments.json' => legacyWriteEntitySync($base, $data, 'legacyWriteDepartments'),
            'promotions.json' => legacyWriteEntitySync($base, $data, 'legacyWritePromotions'),
            'inventory_movements.json' => legacyWriteEntitySync($base, $data, 'legacyWriteInventoryMovements'),
            'cash_movements.json' => legacyWriteEntitySync($base, $data, 'legacyWriteCashMovements'),
            'credit_payments.json' => legacyWriteEntitySync($base, $data, 'legacyWriteCreditPayments'),
            'providers.json' => legacyWriteEntitySync($base, $data, 'legacyWriteProviders'),
            'purchase_list.json' => legacyWriteEntitySync($base, $data, 'legacyWritePurchaseList'),
            'purchase_orders.json' => legacyWriteEntitySync($base, $data, 'legacyWritePurchaseOrders'),
            'purchase_history.json' => legacyWriteEntitySync($base, $data, 'legacyWritePurchaseHistory'),
            default => isLegacyDocumentFile($key) ? legacyWriteDocumentStore($key, $data) : false,
        };
    } catch (Throwable $e) {
        return false;
    }
}

function legacyTicketRowStatus(array $ticketRow): string
{
    $activo = strtolower(trim((string)($ticketRow['ACTIVO'] ?? '1')));
    $cancelado = strtolower(trim((string)($ticketRow['ESTA_CANCELADO'] ?? '0')));
    if (in_array($activo, ['0', 'false', 'f', 'no', 'n'], true) || in_array($cancelado, ['1', 'true', 't', 'si', 's'], true)) {
        return 'cancelled';
    }

    $abierto = strtolower(trim((string)($ticketRow['ESTA_ABIERTO'] ?? '0')));
    $modificable = strtolower(trim((string)($ticketRow['ES_MODIFICABLE'] ?? '0')));
    if (in_array($abierto, ['1', 'true', 't', 'si', 's'], true) || in_array($modificable, ['1', 'true', 't', 'si', 's'], true)) {
        return 'pending';
    }

    return 'completed';
}

/** @return array<string, mixed> */
function legacyReadTicketFooterMeta(array $ticketRow): array
{
    $raw = trim((string)($ticketRow['NOTAS_AL_PIE'] ?? ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function legacyPaymentMethodFromMixedCode(string $code): string
{
    return match (strtoupper(trim($code))) {
        'E' => 'cash',
        'T' => 'transfer',
        'C', 'R' => 'credit',
        'V' => 'voucher',
        'K', 'Q' => 'check',
        default => 'cash',
    };
}

/**
 * @param array<int, string> $ticketIds
 * @return array<string, array{cash:float, transfer:float, credit:float}>
 */
function legacyReadMixedPaymentsByTicket(array $ticketIds): array
{
    if ($ticketIds === [] || !legacyTableExists('pagos_mixtos')) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($ticketIds), '?'));
    $stmt = db()->prepare('SELECT * FROM PAGOS_MIXTOS WHERE TICKET_ID IN (' . $ph . ')');
    $stmt->execute($ticketIds);
    $rows = $stmt->fetchAll();

        $out = [];
    foreach ($rows as $row) {
        $ticketId = trim((string)($row['TICKET_ID'] ?? ''));
        if ($ticketId === '') {
            continue;
        }
        $out[$ticketId] = $out[$ticketId] ?? ['cash' => 0.0, 'transfer' => 0.0, 'credit' => 0.0];
        $method = legacyPaymentMethodFromMixedCode((string)($row['FORMA_DE_PAGO'] ?? ''));
        $amount = safeFloat($row['MONTO'] ?? 0);
        if (array_key_exists($method, $out[$ticketId])) {
            $out[$ticketId][$method] = round((float)$out[$ticketId][$method] + $amount, 2);
        }
    }

    return $out;
}

/** @return array<int, array<string, mixed>> */
function legacyReadSalesFromTickets(): array
{
    if (!legacyTableExists('ventatickets') || !legacyTableExists('ventatickets_articulos')) {
        return legacyReadDocumentStoreSalesCompat();
    }

    $tickets = db()->query(
        "SELECT * FROM VENTATICKETS
         WHERE COALESCE(NULLIF(ACTIVO, ''), '1') <> '0'
           AND COALESCE(NULLIF(ESTA_CANCELADO, ''), '0') <> '1'
         ORDER BY CREADO_EN ASC, ID ASC"
    )->fetchAll();

    if (!is_array($tickets) || $tickets === []) {
        return [];
    }

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
        $stmt = db()->prepare(
            'SELECT * FROM VENTATICKETS_ARTICULOS WHERE TICKET_ID IN (' . $ph . ') ORDER BY AGREGADO_EN ASC, ID ASC'
        );
        $stmt->execute($ticketIds);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $ticketId = trim((string)($row['TICKET_ID'] ?? ''));
            if ($ticketId === '') {
                continue;
            }
            $itemsByTicket[$ticketId] = $itemsByTicket[$ticketId] ?? [];
            $itemsByTicket[$ticketId][] = $row;
        }
    }

    $mixedPaymentsByTicket = legacyReadMixedPaymentsByTicket($ticketIds);
    $sales = [];
    foreach ($tickets as $ticketRow) {
        $ticketId = trim((string)($ticketRow['ID'] ?? ''));
        if ($ticketId === '') {
            $ticketId = trim((string)($ticketRow['FOLIO'] ?? ''));
        }
        if ($ticketId === '') {
            continue;
        }

        $detailRows = $itemsByTicket[$ticketId] ?? [];
        $saleItems = [];
        $returns = [];
        foreach ($detailRows as $detail) {
            $itemId = trim((string)($detail['ID'] ?? ''));
            if ($itemId === '') {
                $itemId = trim((string)($detail['PRODUCTO_CODIGO'] ?? ''));
            }
            $qty = safeFloat($detail['CANTIDAD'] ?? 0);
            $returnedQty = safeFloat($detail['CANTIDAD_DEVUELTA'] ?? 0);
            $price = safeFloat($detail['PRECIO_USADO'] ?? $detail['PAGADO_EN'] ?? 0);

            $saleItems[] = [
                'id' => $itemId,
                'productId' => trim((string)($detail['PRODUCTO_CODIGO'] ?? '')),
                'barcode' => trim((string)($detail['PRODUCTO_CODIGO'] ?? '')),
                'name' => trim((string)($detail['PRODUCTO_NOMBRE'] ?? '')),
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
                    'createdAt' => legacyToIsoDateTime((string)($detail['AGREGADO_EN'] ?? $ticketRow['CREADO_EN'] ?? date('c'))),
                ];
            }
        }

        $footerMeta = legacyReadTicketFooterMeta($ticketRow);
        $paymentMethod = strtolower(trim((string)($ticketRow['FORMA_PAGO'] ?? 'cash')));
        $status = legacyTicketRowStatus($ticketRow);
        $mixedPayments = $mixedPaymentsByTicket[$ticketId] ?? null;
        if ($mixedPayments === null && is_array($footerMeta['mixedPayments'] ?? null)) {
            $mixedPayments = [
                'cash' => safeFloat($footerMeta['mixedPayments']['cash'] ?? 0),
                'transfer' => safeFloat($footerMeta['mixedPayments']['transfer'] ?? 0),
                'credit' => safeFloat($footerMeta['mixedPayments']['credit'] ?? 0),
            ];
        }

        $sales[] = [
            'ticketId' => $ticketId,
            'createdAt' => legacyToIsoDateTime((string)($ticketRow['CREADO_EN'] ?? date('c'))),
            'updatedAt' => legacyToIsoDateTime((string)($ticketRow['VENDIDO_EN'] ?? $ticketRow['CREADO_EN'] ?? date('c'))),
            'status' => $status,
            'items' => $saleItems,
            'subtotal' => safeFloat($ticketRow['SUBTOTAL'] ?? 0),
            'total' => safeFloat($ticketRow['TOTAL'] ?? 0),
            'paidWith' => safeFloat($ticketRow['PAGO_CON'] ?? 0),
            'change' => safeFloat($ticketRow['PAGADO_EN'] ?? 0),
            'paymentMethod' => $paymentMethod !== '' ? $paymentMethod : 'cash',
            'mixedPayments' => $mixedPayments,
            'paymentNote' => trim((string)($ticketRow['NOTAS'] ?? '')),
            'customerId' => trim((string)($ticketRow['CLIENTE_ID'] ?? '')),
            'customerName' => trim((string)($ticketRow['NOMBRE'] ?? 'Publico en general')),
            'amountPending' => safeFloat($ticketRow['TOTAL_CREDITO'] ?? 0),
            'cashier' => trim((string)($ticketRow['CAJERO_ID'] ?? 'Cajero')),
            'clientRequestId' => trim((string)($ticketRow['REFERENCIA'] ?? '')),
            'discountPct' => safeFloat($footerMeta['discountPct'] ?? 0),
            'discountAmount' => safeFloat($footerMeta['discountAmount'] ?? 0),
            'transferMeta' => is_array($footerMeta['transferMeta'] ?? null) ? $footerMeta['transferMeta'] : ['reference' => '', 'phone' => ''],
            'returns' => $returns,
        ];
    }

    return $sales;
}

function legacyEntityOverlayKey(string $baseFileName): string
{
    return 'app/' . strtolower($baseFileName);
}

/** @return array<int|string, mixed> */
function legacyReadEntityWithOverlay(string $baseFileName, callable $legacyReader): array
{
    $overlayKey = legacyEntityOverlayKey($baseFileName);
    $cached = legacyReadDocumentStore($overlayKey);
    if ($cached !== []) {
        return $cached;
    }

    $legacy = $legacyReader();
    if (is_array($legacy) && $legacy !== []) {
        legacyWriteDocumentStore($overlayKey, $legacy);
    }
    return is_array($legacy) ? $legacy : [];
}

function legacyWriteEntityOverlay(string $baseFileName, array $data): bool
{
    $overlayKey = legacyEntityOverlayKey($baseFileName);
    return legacyWriteDocumentStore($overlayKey, $data);
}

function legacyWriteEntitySync(string $baseFileName, array $data, callable $legacyWriter): bool
{
    $ok = $legacyWriter($data);
    if (!$ok) {
        return false;
    }
    return legacyWriteEntityOverlay($baseFileName, $data);
}

/** @return array<string, array<string, mixed>> */
function legacyReadProductsOverlayIndex(): array
{
    $overlay = legacyReadDocumentStore('app/products.json');
    if (!is_array($overlay)) {
        return [];
    }

    $byId = [];
    foreach ($overlay as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $byId[$id] = $row;
    }

    return $byId;
}

function legacyWriteProductsOverlay(array $products): bool
{
    return legacyWriteDocumentStore('app/products.json', $products);
}

/** @return array<int, string> */
function legacyTableColumns(string $table): array
{
    static $cache = [];
    if (isset($cache[$table]) && is_array($cache[$table])) {
        return $cache[$table];
    }

    $stmt = db()->query('SHOW COLUMNS FROM `' . $table . '`');
    $rows = $stmt->fetchAll();
    $cols = [];
    foreach ($rows as $row) {
        $col = (string)($row['Field'] ?? '');
        if ($col !== '') {
            $cols[] = $col;
        }
    }
    $cache[$table] = $cols;
    return $cols;
}

/** @return array<string, int|null> */
function legacyTableColumnMaxLengths(string $table): array
{
    static $cache = [];
    if (isset($cache[$table]) && is_array($cache[$table])) {
        return $cache[$table];
    }

    $stmt = db()->query('SHOW COLUMNS FROM `' . $table . '`');
    $rows = $stmt->fetchAll();
    $max = [];
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        $type = strtolower((string)($row['Type'] ?? ''));
        $len = null;
        if (preg_match('/^varchar\((\d+)\)/', $type, $m) === 1) {
            $len = (int)$m[1];
        } elseif (preg_match('/^char\((\d+)\)/', $type, $m) === 1) {
            $len = (int)$m[1];
        }
        $max[$field] = $len;
    }

    $cache[$table] = $max;
    return $max;
}

function legacyFitTableValue(string $table, string $column, mixed $value): string
{
    $maxByCol = legacyTableColumnMaxLengths($table);
    $max = $maxByCol[$column] ?? null;

    if ($value === null) {
        $text = '';
    } elseif (is_bool($value)) {
        $text = $value ? '1' : '0';
    } else {
        $text = trim((string)$value);
    }

    if ($max === 0) {
        return '';
    }
    if (is_int($max) && $max > 0) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    return $text;
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function legacyInsertRows(string $table, array $rows): void
{
    if ($rows === []) {
        return;
    }

    $cols = legacyTableColumns($table);
    if ($cols === []) {
        return;
    }

    $quotedCols = array_map(static fn(string $c): string => '`' . $c . '`', $cols);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedCols) . ') VALUES (' . $placeholders . ')';
    $stmt = db()->prepare($sql);

    foreach ($rows as $row) {
        $values = [];
        foreach ($cols as $col) {
            $values[] = legacyFitTableValue($table, $col, $row[$col] ?? '');
        }
        $stmt->execute($values);
    }
}

/**
 * @param array<string, mixed> $sale
 * @return array<int, array<string, mixed>>
 */
function legacySaleItems(array $sale): array
{
    $items = $sale['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }
    return array_values(array_filter($items, static fn($it): bool => is_array($it)));
}

/** @return array<string, mixed> */
function legacyBuildVentaTicketRow(array $sale, int $index): array
{
    $ticketId = trim((string)($sale['ticketId'] ?? ''));
    if ($ticketId === '') {
        $ticketId = (string)($index + 1);
    }
    $createdAt = trim((string)($sale['createdAt'] ?? ''));
    if ($createdAt === '') {
        $createdAt = date('c');
    }

    $customerName = trim((string)($sale['customerName'] ?? 'Publico en general'));
    $customerId = trim((string)($sale['customerId'] ?? ''));
    $cashier = trim((string)($sale['cashier'] ?? ''));
    $paymentMethod = strtolower(trim((string)($sale['paymentMethod'] ?? 'cash')));
    $items = legacySaleItems($sale);
    $numItems = 0;
    foreach ($items as $it) {
        $numItems += max(0, (int)($it['qty'] ?? 0));
    }

    $amountPending = round((float)($sale['amountPending'] ?? 0), 2);

    return [
        'ID' => $ticketId,
        'FOLIO' => $ticketId,
        'CAJA_ID' => '1',
        'CAJERO_ID' => $cashier,
        'NOMBRE' => $customerName,
        'CREADO_EN' => $createdAt,
        'SUBTOTAL' => round((float)($sale['subtotal'] ?? 0), 2),
        'IMPUESTOS' => '0',
        'TOTAL' => round((float)($sale['total'] ?? 0), 2),
        'GANANCIA' => '0',
        'ESTA_ABIERTO' => '0',
        'CLIENTE_ID' => $customerId,
        'VENDIDO_EN' => $createdAt,
        'ES_MODIFICABLE' => '0',
        'PAGO_CON' => round((float)($sale['paidWith'] ?? 0), 2),
        'MONEDA' => 'USD',
        'NUMERO_ARTICULOS' => $numItems,
        'PAGADO_EN' => round((float)($sale['change'] ?? 0), 2),
        'ESTA_CANCELADO' => '0',
        'OPERACION_ID' => '',
        'OLD_TICKET_ID' => $ticketId,
        'NOTAS' => trim((string)($sale['paymentNote'] ?? '')),
        'IMPRIMIR_NOTA' => '0',
        'FORMA_PAGO' => $paymentMethod,
        'REFERENCIA' => trim((string)($sale['clientRequestId'] ?? '')),
        'FACTURA_ID' => '',
        'TOTAL_DEVUELTO' => '0',
        'TOTAL_AHORRADO' => '0',
        'TURNO_ID' => '',
        'TIPO_DE_CAMBIO' => '1',
        'TOTAL_CREDITO' => $amountPending,
        'NOTAS_AL_PIE' => '',
        'REFRESCAR_TICKET' => '0',
        'TOTAL_FACTURABLE' => round((float)($sale['total'] ?? 0), 2),
        'CLIENTESV2_ID' => $customerId,
        'CLIENTESV2_CREDITO_ID' => '',
        'ACTIVO' => '1',
        'IMPRIMIR_DATOS_CLIENTE' => '0',
        'SALDO_CREDITO' => $amountPending,
        'IMPUESTOS_RETENIDOS' => '0',
    ];
}

/**
 * @param array<string, mixed> $sale
 * @return array<int, array<string, mixed>>
 */
function legacyBuildVentaItemsRows(array $sale, string $ticketId): array
{
    $items = legacySaleItems($sale);
    $rows = [];
    foreach ($items as $idx => $item) {
        $qty = max(0, (int)($item['qty'] ?? 0));
        $price = round((float)($item['price'] ?? 0), 2);
        $code = trim((string)($item['id'] ?? $item['barcode'] ?? ''));
        $name = trim((string)($item['name'] ?? 'Producto'));
        $itemId = $ticketId . '-' . ($idx + 1);
        $totalArticulo = round($qty * $price, 2);
        $createdAt = trim((string)($sale['createdAt'] ?? ''));
        if ($createdAt === '') {
            $createdAt = date('c');
        }

        $rows[] = [
            'ID' => $itemId,
            'TICKET_ID' => $ticketId,
            'PRODUCTO_CODIGO' => $code,
            'PRODUCTO_NOMBRE' => $name,
            'CANTIDAD' => $qty,
            'GANANCIA' => '0',
            'DEPARTAMENTO_ID' => '',
            'PAGADO_EN' => $price,
            'USA_MAYOREO' => '0',
            'PORCENTAJE_DESCUENTO' => '0',
            'COMPONENTES' => '',
            'IMPUESTOS_USADOS' => '',
            'IMPUESTO_UNITARIO' => '0',
            'PRECIO_USADO' => $price,
            'CANTIDAD_DEVUELTA' => '0',
            'FUE_DEVUELTO' => '0',
            'PORCENTAJE_PAGADO' => '100',
            'PRECIO_FINAL' => $price,
            'AGREGADO_EN' => $createdAt,
            'TOTAL_ARTICULO' => $totalArticulo,
        ];
    }

    return $rows;
}

/**
 * @param array<string, mixed> $sale
 * @return array<int, array<string, mixed>>
 */
function legacyBuildVentasRows(array $sale, string $ticketId): array
{
    $items = legacySaleItems($sale);
    $rows = [];
    $createdAt = trim((string)($sale['createdAt'] ?? ''));
    if ($createdAt === '') {
        $createdAt = date('c');
    }

    foreach ($items as $idx => $item) {
        $rows[] = [
            'ID' => $ticketId . '-' . ($idx + 1),
            'PRODUCTO_CODIGO' => trim((string)($item['id'] ?? $item['barcode'] ?? '')),
            'CANTIDAD' => max(0, (int)($item['qty'] ?? 0)),
            'FECHA' => $createdAt,
            'TICKET_ID' => $ticketId,
        ];
    }

    return $rows;
}

/** @param array<int, array<string, mixed>> $sales */
function legacyWriteSales(array $sales): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $required = ['ventas', 'ventatickets', 'ventatickets_articulos'];
        $allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $allTableNames = array_map(static fn($t): string => strtolower((string)$t), $allTables);
        foreach ($required as $table) {
            if (!in_array(strtolower($table), $allTableNames, true)) {
                throw new RuntimeException('No existe la tabla requerida: ' . $table);
            }
        }

        $ticketRows = [];
        $ticketItemRows = [];
        $ventasRows = [];
        foreach ($sales as $idx => $sale) {
            if (!is_array($sale)) {
                continue;
            }
            $ticket = legacyBuildVentaTicketRow($sale, (int)$idx);
            $ticketId = (string)($ticket['ID'] ?? (string)($idx + 1));
            $ticketRows[] = $ticket;
            $ticketItemRows = array_merge($ticketItemRows, legacyBuildVentaItemsRows($sale, $ticketId));
            $ventasRows = array_merge($ventasRows, legacyBuildVentasRows($sale, $ticketId));
        }

        $pdo->exec('DELETE FROM `ventatickets_articulos`');
        $pdo->exec('DELETE FROM `ventas`');
        $pdo->exec('DELETE FROM `ventatickets`');

        legacyInsertRows('ventatickets', $ticketRows);
        legacyInsertRows('ventas', $ventasRows);
        legacyInsertRows('ventatickets_articulos', $ticketItemRows);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function normalizeLegacyFileKey(string $fileName): string
{
    return strtolower(str_replace('\\', '/', trim($fileName)));
}

/** @return array<int, string> */
function legacyDocumentFiles(): array
{
    return [
        'sales.json',
        'cash_openings.json',
        'providers.json',
        'purchase_list.json',
        'purchase_orders.json',
        'purchase_history.json',
        'settings.json',
        'facturacion/documentos.json',
        'facturacion/emails.json',
        'facturacion/emisor.json',
        'facturacion/firma.json',
        'facturacion/logs.json',
        'facturacion/productos_servicios.json',
        'facturacion/puntos.json',
    ];
}

function isLegacyDocumentFile(string $fileName): bool
{
    $key = normalizeLegacyFileKey($fileName);
    if (in_array($key, legacyDocumentFiles(), true)) {
        return true;
    }

    $base = basename($key);
    return in_array($base, legacyDocumentFiles(), true);
}

function ensureLegacyDocumentStoreTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $sql = 'CREATE TABLE IF NOT EXISTS POS_APP_JSON_STORE (
        FILE_NAME VARCHAR(120) NOT NULL PRIMARY KEY,
        PAYLOAD LONGTEXT NOT NULL,
        UPDATED_AT DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    db()->exec($sql);
    $ready = true;
}

/** @return array<int|string, mixed> */
function readRawJsonDiskFile(string $fileName): array
{
    $relative = str_replace('/', DIRECTORY_SEPARATOR, normalizeLegacyFileKey($fileName));
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<int|string, mixed> */
function legacyReadDocumentStore(string $fileName): array
{
    ensureLegacyDocumentStoreTable();
    $file = strtolower($fileName);
    $stmt = db()->prepare('SELECT PAYLOAD FROM POS_APP_JSON_STORE WHERE FILE_NAME = :file LIMIT 1');
    $stmt->execute([':file' => $file]);
    $payload = $stmt->fetchColumn();

    if (is_string($payload) && trim($payload) !== '') {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    // Bootstrap inicial: si no existe en SQL, toma lo que haya en storage y lo sube.
    $diskData = readRawJsonDiskFile($file);
    if ($diskData !== []) {
        legacyWriteDocumentStore($file, $diskData);
    }
    return $diskData;
}

function legacyWriteDocumentStore(string $fileName, array $data): bool
{
    ensureLegacyDocumentStoreTable();
    $file = strtolower($fileName);
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        return false;
    }

    $stmt = db()->prepare(
        'INSERT INTO POS_APP_JSON_STORE (FILE_NAME, PAYLOAD, UPDATED_AT)
         VALUES (:file, :payload, NOW())
         ON DUPLICATE KEY UPDATE PAYLOAD = VALUES(PAYLOAD), UPDATED_AT = NOW()'
    );
    $stmt->execute([
        ':file' => $file,
        ':payload' => $encoded,
    ]);
    return true;
}

function normalizeBoolString(mixed $value, string $trueValue = '1', string $falseValue = '0'): string
{
    if (is_bool($value)) {
        return $value ? $trueValue : $falseValue;
    }

    $s = strtolower(trim((string)$value));
    return in_array($s, ['1', 'true', 't', 'yes', 'y', 'si', 's'], true) ? $trueValue : $falseValue;
}

function parseIntId(string $value, string $prefix = ''): ?int
{
    $v = trim($value);
    if ($v === '') {
        return null;
    }

    if ($prefix !== '' && str_starts_with(strtolower($v), strtolower($prefix))) {
        $v = substr($v, strlen($prefix));
    }

    if (!preg_match('/\d+/', $v, $m)) {
        return null;
    }

    return (int)$m[0];
}

function safeFloat(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }
    return round((float)$value, 4);
}

function safeInt(mixed $value): int
{
    return (int)round((float)$value);
}

function parseLegacyInventoryEnabled(mixed $raw): bool
{
    $v = strtolower(trim((string)$raw));
    if ($v === '' || $v === 'f') {
        return true;
    }
    if (in_array($v, ['0', 'false', 'no', 'n'], true)) {
        return false;
    }
    return true;
}

/** @return array<int, array<string,mixed>> */
function legacyReadUsers(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, NOMBRE_COMPLETO, USUARIO, CLAVE, ACTIVO, PERMISOS, ELIMINADO_EN FROM USUARIOS')->fetchAll();
    $out = [];

    foreach ($rows as $row) {
        $deleted = trim((string)($row['ELIMINADO_EN'] ?? ''));
        if ($deleted !== '') {
            continue;
        }

        $id = (int)($row['ID'] ?? 0);
        $username = trim((string)($row['USUARIO'] ?? ''));
        if ($username === '') {
            continue;
        }
        $name = trim((string)($row['NOMBRE_COMPLETO'] ?? ''));
        $role = strtolower($username) === 'admin' ? 'admin' : 'user';

        $out[] = [
            'id' => $id > 0 ? (string)$id : (string)(count($out) + 1),
            'username' => $username,
            'name' => $name !== '' ? $name : $username,
            'password' => (string)($row['CLAVE'] ?? ''),
            'role' => $role,
            'active' => safeInt($row['ACTIVO'] ?? 0) === 1,
            'permissions' => null,
        ];
    }

    return $out;
}

function legacyWriteUsers(array $users): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM USUARIOS')->fetchColumn();

        $exists = $pdo->prepare('SELECT COUNT(*) FROM USUARIOS WHERE ID = :id');
        $insert = $pdo->prepare(
            'INSERT INTO USUARIOS (ID, NOMBRE_COMPLETO, USUARIO, CLAVE, ACTIVO, PERMISOS, CREATED_ON, CORREO, ESTA_EN_CAJA_ID, ELIMINADO_EN)
             VALUES (:id, :nombre, :usuario, :clave, :activo, :permisos, :created_on, :correo, :caja, :eliminado)'
        );
        $update = $pdo->prepare(
            'UPDATE USUARIOS
             SET NOMBRE_COMPLETO = :nombre, USUARIO = :usuario, CLAVE = :clave, ACTIVO = :activo, PERMISOS = :permisos, ELIMINADO_EN = :eliminado
             WHERE ID = :id'
        );

        $seenIds = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $id = parseIntId((string)($user['id'] ?? ''));
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $id = max(1, min(127, $id));
            $seenIds[] = $id;

            $usernameRaw = trim((string)($user['username'] ?? ''));
            $username = substr($usernameRaw, 0, 5);
            if ($username === '') {
                $username = 'usr' . str_pad((string)$id, 2, '0', STR_PAD_LEFT);
            }

            $name = trim((string)($user['name'] ?? $usernameRaw ?? 'Usuario'));
            $name = substr($name, 0, 14);
            if ($name === '') {
                $name = 'Usuario ' . $id;
            }

            $passwordRaw = (string)($user['password'] ?? '');
            $passwordInt = (int)(abs(crc32($passwordRaw === '' ? $username : $passwordRaw)) % 8388607);

            $params = [
                ':id' => $id,
                ':nombre' => $name,
                ':usuario' => $username,
                ':clave' => $passwordInt,
                ':activo' => !empty($user['active']) ? 1 : 0,
                ':permisos' => strtolower((string)($user['role'] ?? 'user')) === 'admin' ? 635655159810 : 0,
                ':eliminado' => '',
            ];

            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $update->execute($params);
            } else {
                $insertParams = [
                    ':id' => $params[':id'],
                    ':nombre' => $params[':nombre'],
                    ':usuario' => $params[':usuario'],
                    ':clave' => $params[':clave'],
                    ':activo' => $params[':activo'],
                    ':permisos' => $params[':permisos'],
                    ':eliminado' => $params[':eliminado'],
                    ':created_on' => legacyNow(),
                    ':correo' => '',
                    ':caja' => 1,
                ];
                $insert->execute($insertParams);
            }
        }

        if (!empty($seenIds)) {
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $sql = "UPDATE USUARIOS SET ACTIVO = 0, ELIMINADO_EN = ? WHERE ID NOT IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([''], $seenIds));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<int, array<string,mixed>> */
function legacyReadDepartments(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, NOMBRE, ACTIVO FROM DEPARTAMENTOS ORDER BY ID ASC')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        if (safeInt($row['ACTIVO'] ?? 0) !== 1) {
            continue;
        }
        $id = safeInt($row['ID'] ?? 0);
        $name = trim((string)($row['NOMBRE'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $out[] = [
            'id' => 'dep-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
            'name' => $name,
        ];
    }
    return $out;
}

function legacyWriteDepartments(array $departments): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM DEPARTAMENTOS')->fetchColumn();
        $seen = [];

        $exists = $pdo->prepare('SELECT COUNT(*) FROM DEPARTAMENTOS WHERE ID = :id');
        $update = $pdo->prepare('UPDATE DEPARTAMENTOS SET NOMBRE = :name, ACTIVO = 1 WHERE ID = :id');
        $insert = $pdo->prepare('INSERT INTO DEPARTAMENTOS (ID, NOMBRE, PORCENTAJE_IMPUESTO, ACTIVO) VALUES (:id, :name, 0, 1)');

        foreach ($departments as $dep) {
            if (!is_array($dep)) {
                continue;
            }
            $name = legacyFitTableValue('DEPARTAMENTOS', 'NOMBRE', trim((string)($dep['name'] ?? '')));
            if ($name === '') {
                continue;
            }

            $id = parseIntId((string)($dep['id'] ?? ''), 'dep-');
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $id = max(1, min(127, $id));
            $seen[] = $id;

            $params = [':id' => $id, ':name' => $name];
            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $update->execute($params);
            } else {
                $insert->execute($params);
            }
        }

        if (!empty($seen)) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $stmt = $pdo->prepare("UPDATE DEPARTAMENTOS SET ACTIVO = 0 WHERE ID NOT IN ($placeholders)");
            $stmt->execute($seen);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<string,int> */
function legacyDepartmentNameToIdMap(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, NOMBRE FROM DEPARTAMENTOS WHERE ACTIVO = 1')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $name = strtolower(trim((string)($row['NOMBRE'] ?? '')));
        $id = safeInt($row['ID'] ?? 0);
        if ($name !== '' && $id > 0) {
            $map[$name] = $id;
        }
    }
    return $map;
}

/** @return array<string,float> */
function legacyIvaRateByIdMap(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    try {
        $rows = db()->query(
            "SELECT ID, PORCENTAJE
             FROM IMPUESTOS
             WHERE ORIGEN = 'productos' AND TIPO = 'iva'"
        )->fetchAll();
    } catch (Throwable) {
        return $cache;
    }

    foreach ($rows as $row) {
        $id = trim((string)($row['ID'] ?? ''));
        if ($id === '') {
            continue;
        }
        $raw = str_replace(['iva', '%', ' '], '', strtolower(trim((string)($row['PORCENTAJE'] ?? ''))));
        if (!is_numeric($raw)) {
            continue;
        }
        $rate = (float)$raw;
        if ($rate <= 0) {
            continue;
        }
        $cache[$id] = $rate;
    }

    return $cache;
}

function legacyIvaLabelFromTaxId(mixed $taxId): string
{
    $normalized = trim((string)$taxId);
    if ($normalized === '' || $normalized === '0') {
        return 'No';
    }

    $map = legacyIvaRateByIdMap();
    if (!isset($map[$normalized])) {
        return 'No';
    }

    $rate = $map[$normalized];
    return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
}

function legacyTaxIdFromIvaValue(mixed $ivaValue): string
{
    $normalized = strtolower(trim((string)$ivaValue));
    if ($normalized === '' || in_array($normalized, ['no', '0', 'false'], true)) {
        return '0';
    }

    $map = legacyIvaRateByIdMap();
    if ($map === []) {
        if (is_numeric($normalized) && (float)$normalized > 0) {
            return (string)(int)$normalized;
        }
        return '0';
    }

    foreach ($map as $id => $rate) {
        $idStr = (string)$id;
        if (strtolower($idStr) === $normalized) {
            return $idStr;
        }
    }

    $raw = str_replace(['iva', '%', ' '], '', $normalized);
    if (is_numeric($raw)) {
        $target = (float)$raw;
        foreach ($map as $id => $rate) {
            if (abs($rate - $target) < 0.0001) {
                return (string)$id;
            }
        }
    }

    foreach ($map as $id => $_) {
        return (string)$id;
    }

    return '0';
}

/** @return array<int, array<string,mixed>> */
function legacyReadProducts(): array
{
    $pdo = db();
    $overlayById = legacyReadProductsOverlayIndex();
    $sql = 'SELECT p.ID, p.CODIGO, p.DESCRIPCION, p.PCOSTO, p.PORCENTAJE_GANANCIA, p.PVENTA, p.PFINAL, p.MAYOREO,
                   p.DINVENTARIO, p.DINVMINIMO, p.DINVMAXIMO, p.TVENTA, p.ES_KIT, p.DEPT, p.USA_INVENTARIO, p.IMPUESTOS,
                   p.ELIMINADO_EN, d.NOMBRE AS DEPARTAMENTO
            FROM PRODUCTOS p
            LEFT JOIN DEPARTAMENTOS d ON d.ID = p.DEPT
            WHERE p.ELIMINADO_EN IS NULL OR p.ELIMINADO_EN = ""
            ORDER BY CAST(p.ID AS UNSIGNED) ASC';
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];

    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $price = safeFloat($row['PFINAL'] ?? 0);
        if ($price <= 0) {
            $price = safeFloat($row['PVENTA'] ?? 0);
        }

        $department = trim((string)($row['DEPARTAMENTO'] ?? ''));
        if ($department === '') {
            $department = 'Sin Departamento';
        }

        $kitFlag = strtolower(trim((string)($row['ES_KIT'] ?? 'f')));
        $unitType = in_array($kitFlag, ['1', 'true', 't', 'si', 's', 'y', 'yes'], true)
            ? 'package'
            : (strtoupper(trim((string)($row['TVENTA'] ?? 'U'))) === 'D' ? 'bulk' : 'unit');
        $iva = legacyIvaLabelFromTaxId($row['IMPUESTOS'] ?? '0');

        $rawInventoryFlag = strtolower(trim((string)($row['USA_INVENTARIO'] ?? '1')));
        $inventoryEnabled = !in_array($rawInventoryFlag, ['0', 'false', 'f', 'no', 'n'], true);

        $product = [
            'id' => 'p-' . $id,
            'barcode' => trim((string)($row['CODIGO'] ?? '')),
            'name' => trim((string)($row['DESCRIPCION'] ?? '')),
            'cost' => safeFloat($row['PCOSTO'] ?? 0),
            'margin' => safeFloat($row['PORCENTAJE_GANANCIA'] ?? 0),
            'price' => $price,
            'specialPrice' => $price,
            'stock' => safeInt($row['DINVENTARIO'] ?? 0),
            'minStock' => safeInt($row['DINVMINIMO'] ?? 0),
            'maxStock' => safeInt($row['DINVMAXIMO'] ?? 0),
            'inventoryEnabled' => $inventoryEnabled,
            'department' => $department,
            'unitType' => $unitType,
            'provider' => '',
            'iva' => $iva,
            'packageItems' => [],
            'wholesale' => safeFloat($row['MAYOREO'] ?? 0) > 0
                ? ['minQty' => 2, 'price' => safeFloat($row['MAYOREO'] ?? 0)]
                : null,
        ];

        $overlay = $overlayById[$product['id']] ?? null;
        if (is_array($overlay)) {
            if (isset($overlay['packageItems']) && is_array($overlay['packageItems'])) {
                $product['packageItems'] = $overlay['packageItems'];
            }
            if (isset($overlay['provider']) && trim((string)$overlay['provider']) !== '') {
                $product['provider'] = trim((string)$overlay['provider']);
            }
        }

        $out[] = $product;
    }

    return $out;
}

function legacyWriteProducts(array $products): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $clampDecimal = static function (float $value, float $max, int $scale): float {
            if ($value < 0) {
                $value = 0.0;
            }
            if ($value > $max) {
                $value = $max;
            }
            return round($value, $scale);
        };

        $deptMap = legacyDepartmentNameToIdMap();
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM PRODUCTOS')->fetchColumn();

        $exists = $pdo->prepare('SELECT COUNT(*) FROM PRODUCTOS WHERE ID = :id');
        $updateById = $pdo->prepare(
            'UPDATE PRODUCTOS
             SET CODIGO = :codigo, DESCRIPCION = :descripcion, TVENTA = :tventa, PCOSTO = :pcosto,
                 PVENTA = :pventa, PFINAL = :pfinal, DEPT = :dept, MAYOREO = :mayoreo, PMAYOREOFINAL = :pmayoreo,
                 DINVENTARIO = :inventario, DINVMINIMO = :invmin, DINVMAXIMO = :invmax,
                 PORCENTAJE_GANANCIA = :margen, ES_KIT = :es_kit, USA_INVENTARIO = :usa_inventario, IMPUESTOS = :impuestos,
                 ELIMINADO_EN = ""
             WHERE ID = :id'
        );

        $insert = $pdo->prepare(
            'INSERT INTO PRODUCTOS (ID, CODIGO, DESCRIPCION, TVENTA, PCOSTO, PVENTA, DEPT, MAYOREO,
                                    DINVENTARIO, DINVMINIMO, DINVMAXIMO, PORCENTAJE_GANANCIA, MEDIDA_ID,
                                    PFINAL, PMAYOREOFINAL, ES_KIT, USA_INVENTARIO, IMPUESTOS, ELIMINADO_EN)
             VALUES (:id, :codigo, :descripcion, :tventa, :pcosto, :pventa, :dept, :mayoreo,
                     :inventario, :invmin, :invmax, :margen, 1,
                     :pfinal, :pmayoreo, :es_kit, :usa_inventario, :impuestos, "")'
        );

        $seenIds = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $name = trim((string)($product['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $id = parseIntId((string)($product['id'] ?? ''), 'p-');
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $id = max(1, min(32767, $id));
            $seenIds[] = $id;

            $departmentName = strtolower(trim((string)($product['department'] ?? 'sin departamento')));
            $deptId = $deptMap[$departmentName] ?? ($deptMap['- sin departamento -'] ?? 25);
            $deptId = max(0, min(127, $deptId));

            $price = $clampDecimal(safeFloat($product['price'] ?? 0), 99.999, 3);
            $wholesale = $clampDecimal(safeFloat(($product['wholesale']['price'] ?? 0)), 999.999, 3);
            $stock = max(0, safeInt($product['stock'] ?? 0));
            $minStock = max(0, safeInt($product['minStock'] ?? 0));
            $maxStock = max(0, safeInt($product['maxStock'] ?? 0));
            $cost = $clampDecimal(safeFloat($product['cost'] ?? 0), 99.999, 3);
            $margin = $clampDecimal(safeFloat($product['margin'] ?? 0), 999.99, 2);
            $mayoreo = $wholesale > 0 ? $wholesale : $price;
            $pventa = $clampDecimal($price, 99.9999, 4);
            $taxId = legacyTaxIdFromIvaValue($product['iva'] ?? 'No');

            $params = [
                ':id' => $id,
                ':codigo' => legacyFitTableValue('PRODUCTOS', 'CODIGO', trim((string)($product['barcode'] ?? ''))),
                ':descripcion' => legacyFitTableValue('PRODUCTOS', 'DESCRIPCION', $name),
                ':tventa' => (($product['unitType'] ?? 'unit') === 'bulk') ? 'D' : 'U',
                ':pcosto' => $cost,
                ':pventa' => $pventa,
                ':pfinal' => $price,
                ':dept' => $deptId,
                ':mayoreo' => $mayoreo,
                ':pmayoreo' => $mayoreo,
                ':inventario' => legacyFitTableValue('PRODUCTOS', 'DINVENTARIO', $stock),
                ':invmin' => legacyFitTableValue('PRODUCTOS', 'DINVMINIMO', $minStock),
                ':invmax' => legacyFitTableValue('PRODUCTOS', 'DINVMAXIMO', $maxStock),
                ':margen' => $margin,
                ':es_kit' => (($product['unitType'] ?? 'unit') === 'package') ? 't' : 'f',
                ':usa_inventario' => !empty($product['inventoryEnabled']) ? '1' : '0',
                ':impuestos' => $taxId,
            ];

            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $updateById->execute($params);
            } else {
                $insert->execute($params);
            }
        }

        if (!empty($seenIds)) {
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $sql = "UPDATE PRODUCTOS SET ELIMINADO_EN = ? WHERE ID NOT IN ($placeholders) AND (ELIMINADO_EN IS NULL OR ELIMINADO_EN = '')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([legacyNow()], $seenIds));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function legacyUpsertProduct(array $product): bool
{
    if (!dbEnabled()) {
        return false;
    }

    $name = trim((string)($product['name'] ?? ''));
    if ($name === '') {
        return false;
    }

    $clampDecimal = static function (float $value, float $max, int $scale): float {
        if ($value < 0) {
            $value = 0.0;
        }
        if ($value > $max) {
            $value = $max;
        }
        return round($value, $scale);
    };

    $pdo = db();
    $deptMap = legacyDepartmentNameToIdMap();

    $id = parseIntId((string)($product['id'] ?? ''), 'p-');
    if ($id === null || $id <= 0) {
        $id = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) + 1 FROM PRODUCTOS')->fetchColumn();
    }
    $id = max(1, min(32767, $id));

    $departmentName = strtolower(trim((string)($product['department'] ?? 'sin departamento')));
    $deptId = $deptMap[$departmentName] ?? ($deptMap['- sin departamento -'] ?? 25);
    $deptId = max(0, min(127, $deptId));

    $price = $clampDecimal(safeFloat($product['price'] ?? 0), 99.999, 3);
    $wholesale = $clampDecimal(safeFloat(($product['wholesale']['price'] ?? 0)), 999.999, 3);
    $stock = max(0, safeInt($product['stock'] ?? 0));
    $minStock = max(0, safeInt($product['minStock'] ?? 0));
    $maxStock = max(0, safeInt($product['maxStock'] ?? 0));
    $cost = $clampDecimal(safeFloat($product['cost'] ?? 0), 99.999, 3);
    $margin = $clampDecimal(safeFloat($product['margin'] ?? 0), 999.99, 2);
    $mayoreo = $wholesale > 0 ? $wholesale : $price;
    $pventa = $clampDecimal($price, 99.9999, 4);
    $taxId = legacyTaxIdFromIvaValue($product['iva'] ?? 'No');

    $params = [
        ':id' => $id,
        ':codigo' => legacyFitTableValue('PRODUCTOS', 'CODIGO', trim((string)($product['barcode'] ?? ''))),
        ':descripcion' => legacyFitTableValue('PRODUCTOS', 'DESCRIPCION', $name),
        ':tventa' => (($product['unitType'] ?? 'unit') === 'bulk') ? 'D' : 'U',
        ':pcosto' => $cost,
        ':pventa' => $pventa,
        ':pfinal' => $price,
        ':dept' => $deptId,
        ':mayoreo' => $mayoreo,
        ':pmayoreo' => $mayoreo,
        ':inventario' => legacyFitTableValue('PRODUCTOS', 'DINVENTARIO', $stock),
        ':invmin' => legacyFitTableValue('PRODUCTOS', 'DINVMINIMO', $minStock),
        ':invmax' => legacyFitTableValue('PRODUCTOS', 'DINVMAXIMO', $maxStock),
        ':margen' => $margin,
        ':es_kit' => (($product['unitType'] ?? 'unit') === 'package') ? 't' : 'f',
        ':usa_inventario' => !empty($product['inventoryEnabled']) ? '1' : '0',
        ':impuestos' => $taxId,
    ];

    try {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM PRODUCTOS WHERE ID = :id');
        $exists->execute([':id' => $id]);

        if ((int)$exists->fetchColumn() > 0) {
            $updateById = $pdo->prepare(
                'UPDATE PRODUCTOS
                 SET CODIGO = :codigo, DESCRIPCION = :descripcion, TVENTA = :tventa, PCOSTO = :pcosto,
                     PVENTA = :pventa, PFINAL = :pfinal, DEPT = :dept, MAYOREO = :mayoreo, PMAYOREOFINAL = :pmayoreo,
                     DINVENTARIO = :inventario, DINVMINIMO = :invmin, DINVMAXIMO = :invmax,
                     PORCENTAJE_GANANCIA = :margen, ES_KIT = :es_kit, USA_INVENTARIO = :usa_inventario, IMPUESTOS = :impuestos,
                     ELIMINADO_EN = ""
                 WHERE ID = :id'
            );
            return $updateById->execute($params);
        }

        $insert = $pdo->prepare(
            'INSERT INTO PRODUCTOS (ID, CODIGO, DESCRIPCION, TVENTA, PCOSTO, PVENTA, DEPT, MAYOREO,
                                    DINVENTARIO, DINVMINIMO, DINVMAXIMO, PORCENTAJE_GANANCIA, MEDIDA_ID,
                                    PFINAL, PMAYOREOFINAL, ES_KIT, USA_INVENTARIO, IMPUESTOS, ELIMINADO_EN)
             VALUES (:id, :codigo, :descripcion, :tventa, :pcosto, :pventa, :dept, :mayoreo,
                     :inventario, :invmin, :invmax, :margen, 1,
                     :pfinal, :pmayoreo, :es_kit, :usa_inventario, :impuestos, "")'
        );

        return $insert->execute($params);
    } catch (Throwable $e) {
        return false;
    }
}

function legacyDeleteProduct(string $jsonId): bool
{
    if (!dbEnabled()) {
        return false;
    }

    $id = parseIntId($jsonId, 'p-');
    if ($id === null || $id <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            "UPDATE PRODUCTOS SET ELIMINADO_EN = ? WHERE ID = ? AND (ELIMINADO_EN IS NULL OR ELIMINADO_EN = '')"
        );
        return $stmt->execute([legacyNow(), $id]);
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string,string> */
function legacyProductCodeByJsonIdMap(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, CODIGO FROM PRODUCTOS')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $code = trim((string)($row['CODIGO'] ?? ''));
        if ($code === '') {
            continue;
        }
        $map['p-' . $id] = $code;
    }
    return $map;
}

/** @return array<string,string> */
function legacyProductJsonIdByCodeMap(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, CODIGO FROM PRODUCTOS')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $code = trim((string)($row['CODIGO'] ?? ''));
        if ($code === '') {
            continue;
        }
        $map[$code] = 'p-' . $id;
    }
    return $map;
}

/** @return array<int, array<string,mixed>> */
function legacyReadPromotions(): array
{
    $pdo = db();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasTable = false;
    foreach ($tables as $name) {
        if (strcasecmp((string)$name, 'PROMOCIONES_POR_CANTIDAD') === 0) {
            $hasTable = true;
            break;
        }
    }
    if (!$hasTable) {
        return [];
    }

    $rows = $pdo->query('SELECT * FROM PROMOCIONES_POR_CANTIDAD ORDER BY ID ASC, PRODUCTO_CODIGO ASC')->fetchAll();
    $codeToJsonId = legacyProductJsonIdByCodeMap();
    $grouped = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['ID'] ?? ''));
        if ($id === '') {
            continue;
        }
        if (!isset($grouped[$id])) {
            $grouped[$id] = [
                'id' => $id,
                'name' => trim((string)($row['NOMBRE'] ?? 'Promocion')),
                'type' => 'fixed',
                'value' => safeFloat($row['PRECIO_PROMOCION'] ?? 0),
                'startDate' => trim((string)($row['DESDE'] ?? '')),
                'endDate' => trim((string)($row['HASTA'] ?? '')),
                'active' => true,
                'productIds' => [],
                'notes' => '',
                'updatedAt' => date('c'),
                'createdAt' => date('c'),
            ];
        }

        $code = trim((string)($row['PRODUCTO_CODIGO'] ?? ''));
        if ($code !== '') {
            $jsonId = $codeToJsonId[$code] ?? $code;
            if (!in_array($jsonId, $grouped[$id]['productIds'], true)) {
                $grouped[$id]['productIds'][] = $jsonId;
            }
        }
    }

    return array_values($grouped);
}

function legacyWritePromotions(array $promotions): bool
{
    $pdo = db();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasTable = false;
    foreach ($tables as $name) {
        if (strcasecmp((string)$name, 'PROMOCIONES_POR_CANTIDAD') === 0) {
            $hasTable = true;
            break;
        }
    }
    if (!$hasTable) {
        return false;
    }

    $idToCode = legacyProductCodeByJsonIdMap();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM PROMOCIONES_POR_CANTIDAD');
        $insert = $pdo->prepare(
            'INSERT INTO PROMOCIONES_POR_CANTIDAD
             (ID, NOMBRE, PRODUCTO_CODIGO, DESDE, HASTA, PRECIO_PROMOCION, PRECIO_PROMOCION_CON_IMPUESTOS)
             VALUES (:id, :nombre, :codigo, :desde, :hasta, :precio, :precio_imp)'
        );

        foreach ($promotions as $promo) {
            if (!is_array($promo)) {
                continue;
            }
            $promoId = trim((string)($promo['id'] ?? ''));
            if ($promoId === '') {
                continue;
            }
            $promoName = trim((string)($promo['name'] ?? 'Promocion'));
            $desde = trim((string)($promo['startDate'] ?? ''));
            $hasta = trim((string)($promo['endDate'] ?? ''));
            $price = safeFloat($promo['value'] ?? 0);
            if ($price < 0) {
                $price = 0;
            }

            $productIds = $promo['productIds'] ?? [];
            if (!is_array($productIds) || $productIds === []) {
                $productIds = [''];
            }

            foreach ($productIds as $pidRaw) {
                $pid = trim((string)$pidRaw);
                $productCode = $idToCode[$pid] ?? $pid;
                $insert->execute([
                    ':id' => legacyFitTableValue('PROMOCIONES_POR_CANTIDAD', 'ID', $promoId),
                    ':nombre' => legacyFitTableValue('PROMOCIONES_POR_CANTIDAD', 'NOMBRE', $promoName),
                    ':codigo' => legacyFitTableValue('PROMOCIONES_POR_CANTIDAD', 'PRODUCTO_CODIGO', $productCode),
                    ':desde' => legacyFitTableValue('PROMOCIONES_POR_CANTIDAD', 'DESDE', $desde),
                    ':hasta' => legacyFitTableValue('PROMOCIONES_POR_CANTIDAD', 'HASTA', $hasta),
                    ':precio' => legacyFitTableValue('PROMOCIONES_POR_CANTIDAD', 'PRECIO_PROMOCION', $price),
                    ':precio_imp' => legacyFitTableValue('PROMOCIONES_POR_CANTIDAD', 'PRECIO_PROMOCION_CON_IMPUESTOS', $price),
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function legacyTableExists(string $table): bool
{
    static $tables = null;
    if (!is_array($tables)) {
        $rows = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tables = [];
        foreach ($rows as $name) {
            $tables[strtolower((string)$name)] = true;
        }
    }
    return isset($tables[strtolower($table)]);
}

/** @return array<int, array<string,mixed>> */
function legacyReadCashMovements(): array
{
    if (!legacyTableExists('MOVIMIENTOS')) {
        return legacyReadDocumentStore('cash_movements.json');
    }

    $rows = db()->query(
        "SELECT ID, OPERACION_ID, MONTO, CUANDO_FUE, COMENTARIOS, TIPO, CAJERO_ID
         FROM MOVIMIENTOS
         WHERE TIPO IN ('cash_entry', 'cash_exit')
         ORDER BY CUANDO_FUE ASC, ID ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['ID'] ?? ''));
        if ($id === '') {
            continue;
        }
        $tipo = trim((string)($row['TIPO'] ?? ''));
        $type = $tipo === 'cash_exit' ? 'exit' : 'entry';

        $out[] = [
            'id' => $id,
            'shiftId' => trim((string)($row['OPERACION_ID'] ?? '')),
            'userId' => trim((string)($row['CAJERO_ID'] ?? '')),
            'username' => '',
            'name' => '',
            'type' => $type,
            'amount' => safeFloat($row['MONTO'] ?? 0),
            'note' => trim((string)($row['COMENTARIOS'] ?? '')),
            'createdAt' => legacyToIsoDateTime((string)($row['CUANDO_FUE'] ?? '')),
        ];
    }

    return $out;
}

/** @param array<int, array<string,mixed>> $movements */
function legacyWriteCashMovements(array $movements): bool
{
    if (!legacyTableExists('MOVIMIENTOS')) {
        return legacyWriteDocumentStore('cash_movements.json', $movements);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM MOVIMIENTOS WHERE TIPO IN ('cash_entry', 'cash_exit')");
        $insert = $pdo->prepare(
            'INSERT INTO MOVIMIENTOS
             (ID, OPERACION_ID, MONTO, CUANDO_FUE, COMENTARIOS, TIPO, CLIENTE_ID, CAJA_ID, CAJERO_ID, ABONO_ID, CLIENTESV2_CREDITO_ID)
             VALUES
             (:id, :operacion_id, :monto, :cuando_fue, :comentarios, :tipo, :cliente_id, :caja_id, :cajero_id, :abono_id, :credito_id)'
        );

        foreach ($movements as $mv) {
            if (!is_array($mv)) {
                continue;
            }
            $id = trim((string)($mv['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $type = strtolower(trim((string)($mv['type'] ?? 'entry')));
            $tipo = $type === 'exit' ? 'cash_exit' : 'cash_entry';
            $createdAt = legacyToSqlDateTime((string)($mv['createdAt'] ?? ''));

            $insert->execute([
                ':id' => legacyFitTableValue('MOVIMIENTOS', 'ID', $id),
                ':operacion_id' => legacyFitTableValue('MOVIMIENTOS', 'OPERACION_ID', trim((string)($mv['shiftId'] ?? ''))),
                ':monto' => legacyFitTableValue('MOVIMIENTOS', 'MONTO', safeFloat($mv['amount'] ?? 0)),
                ':cuando_fue' => legacyFitTableValue('MOVIMIENTOS', 'CUANDO_FUE', $createdAt),
                ':comentarios' => legacyFitTableValue('MOVIMIENTOS', 'COMENTARIOS', trim((string)($mv['note'] ?? ''))),
                ':tipo' => legacyFitTableValue('MOVIMIENTOS', 'TIPO', $tipo),
                ':cliente_id' => legacyFitTableValue('MOVIMIENTOS', 'CLIENTE_ID', ''),
                ':caja_id' => legacyFitTableValue('MOVIMIENTOS', 'CAJA_ID', '1'),
                ':cajero_id' => legacyFitTableValue('MOVIMIENTOS', 'CAJERO_ID', trim((string)($mv['userId'] ?? ''))),
                ':abono_id' => legacyFitTableValue('MOVIMIENTOS', 'ABONO_ID', ''),
                ':credito_id' => legacyFitTableValue('MOVIMIENTOS', 'CLIENTESV2_CREDITO_ID', ''),
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<int, array<string,mixed>> */
function legacyReadCreditPayments(): array
{
    if (!legacyTableExists('MOVIMIENTOS')) {
        return legacyReadDocumentStore('credit_payments.json');
    }

    $rows = db()->query(
        "SELECT ID, ABONO_ID AS FOLIO, CLIENTE_ID, MONTO, COMENTARIOS, CAJERO_ID, CUANDO_FUE, TIPO
         FROM MOVIMIENTOS
         WHERE TIPO LIKE 'credit_payment%'
         ORDER BY CUANDO_FUE ASC, ID ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['ID'] ?? ''));
        if ($id === '') {
            continue;
        }
        $tipo = trim((string)($row['TIPO'] ?? 'credit_payment_cash'));
        $paymentMethod = 'cash';
        if (str_contains($tipo, '_')) {
            $parts = explode('_', $tipo);
            $paymentMethod = (string)end($parts);
            if ($paymentMethod === '') {
                $paymentMethod = 'cash';
            }
        }

        $out[] = [
            'id' => $id,
            'folio' => trim((string)($row['FOLIO'] ?? '')),
            'customerId' => trim((string)($row['CLIENTE_ID'] ?? '')),
            'amount' => safeFloat($row['MONTO'] ?? 0),
            'paymentMethod' => $paymentMethod,
            'description' => trim((string)($row['COMENTARIOS'] ?? '')),
            'cashier' => trim((string)($row['CAJERO_ID'] ?? '')),
            'createdAt' => legacyToIsoDateTime((string)($row['CUANDO_FUE'] ?? '')),
        ];
    }

    return $out;
}

/** @param array<int, array<string,mixed>> $payments */
function legacyWriteCreditPayments(array $payments): bool
{
    if (!legacyTableExists('MOVIMIENTOS')) {
        return legacyWriteDocumentStore('credit_payments.json', $payments);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM MOVIMIENTOS WHERE TIPO LIKE 'credit_payment%'");
        $insert = $pdo->prepare(
            'INSERT INTO MOVIMIENTOS
             (ID, OPERACION_ID, MONTO, CUANDO_FUE, COMENTARIOS, TIPO, CLIENTE_ID, CAJA_ID, CAJERO_ID, ABONO_ID, CLIENTESV2_CREDITO_ID)
             VALUES
             (:id, :operacion_id, :monto, :cuando_fue, :comentarios, :tipo, :cliente_id, :caja_id, :cajero_id, :abono_id, :credito_id)'
        );

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $id = trim((string)($payment['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $method = strtolower(trim((string)($payment['paymentMethod'] ?? 'cash')));
            if ($method === '') {
                $method = 'cash';
            }
            $tipo = 'credit_payment_' . $method;
            $createdAt = legacyToSqlDateTime((string)($payment['createdAt'] ?? ''));

            $insert->execute([
                ':id' => legacyFitTableValue('MOVIMIENTOS', 'ID', $id),
                ':operacion_id' => legacyFitTableValue('MOVIMIENTOS', 'OPERACION_ID', ''),
                ':monto' => legacyFitTableValue('MOVIMIENTOS', 'MONTO', safeFloat($payment['amount'] ?? 0)),
                ':cuando_fue' => legacyFitTableValue('MOVIMIENTOS', 'CUANDO_FUE', $createdAt),
                ':comentarios' => legacyFitTableValue('MOVIMIENTOS', 'COMENTARIOS', trim((string)($payment['description'] ?? ''))),
                ':tipo' => legacyFitTableValue('MOVIMIENTOS', 'TIPO', $tipo),
                ':cliente_id' => legacyFitTableValue('MOVIMIENTOS', 'CLIENTE_ID', trim((string)($payment['customerId'] ?? ''))),
                ':caja_id' => legacyFitTableValue('MOVIMIENTOS', 'CAJA_ID', '1'),
                ':cajero_id' => legacyFitTableValue('MOVIMIENTOS', 'CAJERO_ID', trim((string)($payment['cashier'] ?? ''))),
                ':abono_id' => legacyFitTableValue('MOVIMIENTOS', 'ABONO_ID', trim((string)($payment['folio'] ?? ''))),
                ':credito_id' => legacyFitTableValue('MOVIMIENTOS', 'CLIENTESV2_CREDITO_ID', trim((string)($payment['creditId'] ?? ''))),
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<string, bool> */
function legacyInventoryTablesAvailability(): array
{
    $pdo = db();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $names = [];
    foreach ($tables as $table) {
        $names[strtolower((string)$table)] = true;
    }

    return [
        'inventario_historial' => isset($names['inventario_historial']),
        'inventario_ajustes' => isset($names['inventario_ajustes']),
        'inventario_balances' => isset($names['inventario_balances']),
    ];
}

function legacyToSqlDateTime(string $value): string
{
    $v = trim($value);
    if ($v === '') {
        return date('Y-m-d H:i:s');
    }
    $ts = strtotime($v);
    if ($ts === false) {
        return date('Y-m-d H:i:s');
    }
    return date('Y-m-d H:i:s', $ts);
}

function legacyToIsoDateTime(string $value): string
{
    $v = trim($value);
    if ($v === '') {
        return date('c');
    }
    $ts = strtotime($v);
    if ($ts === false) {
        return date('c');
    }
    return date('c', $ts);
}

/** @return array<int, array<string,mixed>> */
function legacyReadInventoryMovements(): array
{
    $available = legacyInventoryTablesAvailability();
    if (!$available['inventario_historial']) {
        return [];
    }

    $productNames = [];
    if (legacyTableExists('PRODUCTOS')) {
        $productRows = db()->query('SELECT ID, DESCRIPCION FROM PRODUCTOS')->fetchAll();
        foreach ($productRows as $productRow) {
            $pid = trim((string)($productRow['ID'] ?? ''));
            if ($pid === '') {
                continue;
            }
            $productNames[$pid] = trim((string)($productRow['DESCRIPCION'] ?? ''));
        }
    }

    $rows = db()->query('SELECT * FROM INVENTARIO_HISTORIAL ORDER BY CUANDO_FUE DESC, ID DESC LIMIT 20000')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $id = trim((string)($row['ID'] ?? ''));
        if ($id === '') {
            continue;
        }

        $productIdRaw = trim((string)($row['PRODUCTO_ID'] ?? ''));
        if ($productIdRaw === '') {
            continue;
        }
        $productId = preg_match('/^p-\d+$/i', $productIdRaw) === 1 ? strtolower($productIdRaw) : ('p-' . $productIdRaw);
        $before = safeFloat($row['CANTIDAD_ANTERIOR'] ?? 0);
        $delta = safeFloat($row['CANTIDAD'] ?? 0);
        $after = $before + $delta;
        $source = 'inventario';
        if (trim((string)($row['VENTA_ID'] ?? '')) !== '') {
            $source = 'sales';
        } elseif (trim((string)($row['RECIBO_INVENTARIO_ID'] ?? '')) !== '') {
            $source = 'compras';
        }

        $type = $delta >= 0 ? 'entry' : 'exit';
        if ($source === 'sales') {
            $type = $delta >= 0 ? 'return' : 'sale';
        }

        $out[] = [
            'id' => $id,
            'type' => $type,
            'productId' => $productId,
            'productName' => $productNames[$productIdRaw] ?? '',
            'delta' => $delta,
            'before' => $before,
            'after' => $after,
            'beforeCost' => safeFloat($row['COSTO_UNITARIO'] ?? 0),
            'afterCost' => safeFloat($row['COSTO_DESPUES'] ?? 0),
            'note' => trim((string)($row['DESCRIPCION'] ?? '')),
            'source' => $source,
            'createdAt' => legacyToIsoDateTime((string)($row['CUANDO_FUE'] ?? '')),
        ];
    }

    return $out;
}

/** @param array<int, array<string,mixed>> $movements */
function legacyWriteInventoryMovements(array $movements): bool
{
    $available = legacyInventoryTablesAvailability();
    if (!$available['inventario_historial']) {
        return false;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM INVENTARIO_HISTORIAL');
        if ($available['inventario_ajustes']) {
            $pdo->exec('DELETE FROM INVENTARIO_AJUSTES');
        }
        if ($available['inventario_balances']) {
            $pdo->exec('DELETE FROM INVENTARIO_BALANCES');
        }

        $insertHist = $pdo->prepare(
            'INSERT INTO INVENTARIO_HISTORIAL
             (ID, PRODUCTO_ID, CUANDO_FUE, CANTIDAD_ANTERIOR, CANTIDAD, DESCRIPCION, COSTO_UNITARIO, COSTO_DESPUES,
              AJUSTE_ID, RECIBO_INVENTARIO_ID, VENTA_ID, TRANSFERENCIA_ID, CAJA_ID, VENTA_POR_KIT, USUARIO_ID, ALMACEN_ID)
             VALUES
             (:id, :producto_id, :cuando_fue, :cantidad_anterior, :cantidad, :descripcion, :costo_unitario, :costo_despues,
              :ajuste_id, :recibo_inventario_id, :venta_id, :transferencia_id, :caja_id, :venta_por_kit, :usuario_id, :almacen_id)'
        );

        $insertAjuste = null;
        if ($available['inventario_ajustes']) {
            $insertAjuste = $pdo->prepare(
                'INSERT INTO INVENTARIO_AJUSTES
                 (ID, FOLIO, CUANDO_FUE, PRODUCTO_ID, CANTIDAD, COSTO_UNITARIO, DESCRIPCION, MOTIVO, CAJA_ID, USUARIO_ID, ALMACEN_ID)
                 VALUES
                 (:id, :folio, :cuando_fue, :producto_id, :cantidad, :costo_unitario, :descripcion, :motivo, :caja_id, :usuario_id, :almacen_id)'
            );
        }

        $balances = [];
        $ordered = $movements;
        usort($ordered, static fn(array $a, array $b): int => strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? '')));

        foreach ($ordered as $mv) {
            if (!is_array($mv)) {
                continue;
            }
            $id = trim((string)($mv['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $productId = trim((string)($mv['productId'] ?? ''));
            if ($productId === '') {
                continue;
            }

            $before = safeFloat($mv['before'] ?? 0);
            $delta = safeFloat($mv['delta'] ?? 0);
            $after = safeFloat($mv['after'] ?? ($before + $delta));
            $type = strtolower(trim((string)($mv['type'] ?? '')));
            $source = strtolower(trim((string)($mv['source'] ?? 'inventario')));
            $note = trim((string)($mv['note'] ?? ''));
            $createdAt = legacyToSqlDateTime((string)($mv['createdAt'] ?? ''));
            $beforeCost = safeFloat($mv['beforeCost'] ?? 0);
            $afterCost = safeFloat($mv['afterCost'] ?? $beforeCost);

            $ajusteId = '';
            $reciboId = '';
            $ventaId = '';
            if ($source === 'sales' || in_array($type, ['sale', 'return'], true)) {
                $ventaId = $id;
            } elseif ($source === 'compras') {
                $reciboId = $id;
            } else {
                $ajusteId = $id;
            }

            $insertHist->execute([
                ':id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'ID', $id),
                ':producto_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'PRODUCTO_ID', $productId),
                ':cuando_fue' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'CUANDO_FUE', $createdAt),
                ':cantidad_anterior' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'CANTIDAD_ANTERIOR', $before),
                ':cantidad' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'CANTIDAD', $delta),
                ':descripcion' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'DESCRIPCION', $note),
                ':costo_unitario' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'COSTO_UNITARIO', $beforeCost),
                ':costo_despues' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'COSTO_DESPUES', $afterCost),
                ':ajuste_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'AJUSTE_ID', $ajusteId),
                ':recibo_inventario_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'RECIBO_INVENTARIO_ID', $reciboId),
                ':venta_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'VENTA_ID', $ventaId),
                ':transferencia_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'TRANSFERENCIA_ID', ''),
                ':caja_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'CAJA_ID', '1'),
                ':venta_por_kit' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'VENTA_POR_KIT', '0'),
                ':usuario_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'USUARIO_ID', ''),
                ':almacen_id' => legacyFitTableValue('INVENTARIO_HISTORIAL', 'ALMACEN_ID', '1'),
            ]);

            if ($insertAjuste !== null && $source === 'inventario') {
                $insertAjuste->execute([
                    ':id' => legacyFitTableValue('INVENTARIO_AJUSTES', 'ID', $id),
                    ':folio' => legacyFitTableValue('INVENTARIO_AJUSTES', 'FOLIO', 'AJ-' . $id),
                    ':cuando_fue' => legacyFitTableValue('INVENTARIO_AJUSTES', 'CUANDO_FUE', $createdAt),
                    ':producto_id' => legacyFitTableValue('INVENTARIO_AJUSTES', 'PRODUCTO_ID', $productId),
                    ':cantidad' => legacyFitTableValue('INVENTARIO_AJUSTES', 'CANTIDAD', $delta),
                    ':costo_unitario' => legacyFitTableValue('INVENTARIO_AJUSTES', 'COSTO_UNITARIO', $afterCost),
                    ':descripcion' => legacyFitTableValue('INVENTARIO_AJUSTES', 'DESCRIPCION', $note),
                    ':motivo' => legacyFitTableValue('INVENTARIO_AJUSTES', 'MOTIVO', $type !== '' ? $type : 'adjust'),
                    ':caja_id' => legacyFitTableValue('INVENTARIO_AJUSTES', 'CAJA_ID', '1'),
                    ':usuario_id' => legacyFitTableValue('INVENTARIO_AJUSTES', 'USUARIO_ID', ''),
                    ':almacen_id' => legacyFitTableValue('INVENTARIO_AJUSTES', 'ALMACEN_ID', '1'),
                ]);
            }

            $balances[$productId] = $after;
        }

        if ($available['inventario_balances'] && $balances !== []) {
            $insertBal = $pdo->prepare(
                'INSERT INTO INVENTARIO_BALANCES (PRODUCTO_ID, CANTIDAD_ACTUAL, ALMACEN_ID)
                 VALUES (:producto_id, :cantidad_actual, :almacen_id)'
            );
            foreach ($balances as $productId => $qty) {
                $insertBal->execute([
                    ':producto_id' => legacyFitTableValue('INVENTARIO_BALANCES', 'PRODUCTO_ID', (string)$productId),
                    ':cantidad_actual' => legacyFitTableValue('INVENTARIO_BALANCES', 'CANTIDAD_ACTUAL', $qty),
                    ':almacen_id' => legacyFitTableValue('INVENTARIO_BALANCES', 'ALMACEN_ID', '1'),
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<int, array<string,mixed>> */
function legacyReadCustomers(): array
{
    $pdo = db();
    $sql = 'SELECT c.ID, c.NOMBRES, c.APELLIDOS, c.IDENTIFICACION, c.EMAIL, c.TELEFONO, c.DOMICILIO1, c.DOMICILIO2,
                   c.PARROQUIA, c.CANTON, c.PROVINCIA, c.CODIGO_POSTAL, c.NOTAS,
                   IFNULL(cr.TIENE_CREDITO, 0) AS TIENE_CREDITO,
                   IFNULL(cr.LIMITE_CREDITO, 0) AS LIMITE_CREDITO,
                   IFNULL(cr.SALDO_ACTUAL, 0) AS SALDO_ACTUAL,
                   IFNULL(cr.ULTIMO_ABONO, "") AS ULTIMO_ABONO
            FROM CLIENTESV2 c
            LEFT JOIN CLIENTESV2_CREDITO cr ON cr.CLIENTESV2_ID = c.ID
            ORDER BY CAST(c.ID AS UNSIGNED) ASC';
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];

    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $first = trim((string)($row['NOMBRES'] ?? ''));
        $last = trim((string)($row['APELLIDOS'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = $first !== '' ? $first : ('Cliente ' . $id);
        }

        $creditBalance = safeFloat($row['SALDO_ACTUAL'] ?? 0);
        $taxId = trim((string)($row['IDENTIFICACION'] ?? ''));
        $out[] = [
            'id' => 'c-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'firstName' => $first,
            'lastName' => $last,
            'taxId' => $taxId,
            'identification' => $taxId,
            'phone' => trim((string)($row['TELEFONO'] ?? '')),
            'email' => trim((string)($row['EMAIL'] ?? '')),
            'address1' => trim((string)($row['DOMICILIO1'] ?? '')),
            'address2' => trim((string)($row['DOMICILIO2'] ?? '')),
            'province' => trim((string)($row['PROVINCIA'] ?? '')),
            'canton' => trim((string)($row['CANTON'] ?? '')),
            'parish' => trim((string)($row['PARROQUIA'] ?? '')),
            'zip' => trim((string)($row['CODIGO_POSTAL'] ?? '')),
            'notes' => trim((string)($row['NOTAS'] ?? '')),
            'creditAuthorized' => safeInt($row['TIENE_CREDITO'] ?? 0) === 1 || $creditBalance > 0,
            'creditLimit' => safeFloat($row['LIMITE_CREDITO'] ?? 0),
            'creditBalance' => $creditBalance,
            'lastCreditPaymentAt' => trim((string)($row['ULTIMO_ABONO'] ?? '')),
            'paymentDueDate' => null,
            'paymentDueDay' => null,
        ];
    }

    return $out;
}

function legacyWriteCustomers(array $customers): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM CLIENTESV2')->fetchColumn();
        $seen = [];

        $exists = $pdo->prepare('SELECT COUNT(*) FROM CLIENTESV2 WHERE ID = :id');
        $update = $pdo->prepare(
            'UPDATE CLIENTESV2
             SET NOMBRES = :nombres, APELLIDOS = :apellidos, IDENTIFICACION = :identificacion, EMAIL = :email, TELEFONO = :telefono,
                 DOMICILIO1 = :dom1, DOMICILIO2 = :dom2, PARROQUIA = :parroquia, CANTON = :canton,
                 PROVINCIA = :provincia, CODIGO_POSTAL = :cp, NOTAS = :notas, ACTIVO = 1
             WHERE ID = :id'
        );

        $insert = $pdo->prepare(
            'INSERT INTO CLIENTESV2 (ID, FOLIO, NOMBRES, APELLIDOS, IDENTIFICACION, EMAIL, TELEFONO, DOMICILIO1, DOMICILIO2,
                                     PARROQUIA, CANTON, PROVINCIA, CODIGO_POSTAL, NOTAS,
                                     TOTAL_VENTAS, TOTAL_GANANCIAS, TOTAL_TICKETS, ACTIVO, DE_SISTEMA,
                                     OLD_CLIENTE_ID, OLD_FACTURACION_CLIENTES_ID)
             VALUES (:id, :folio, :nombres, :apellidos, :identificacion, :email, :telefono, :dom1, :dom2,
                     :parroquia, :canton, :provincia, :cp, :notas,
                     0, 0, 0, 1, :de_sistema, :old_cliente_id, :old_facturacion_clientes_id)'
        );

        $existsCredit = $pdo->prepare('SELECT COUNT(*) FROM CLIENTESV2_CREDITO WHERE CLIENTESV2_ID = :id');
        $updateCredit = $pdo->prepare('UPDATE CLIENTESV2_CREDITO SET TIENE_CREDITO = :tiene_credito, ELIMINADO_EN = "" WHERE CLIENTESV2_ID = :id');
        $insertCredit = $pdo->prepare(
            'INSERT INTO CLIENTESV2_CREDITO (CLIENTESV2_ID, TIENE_CREDITO, LIMITE_CREDITO, ULTIMO_ABONO, SALDO_ACTUAL, ELIMINADO_EN)
             VALUES (:id, :tiene_credito, 0, "", "0", "")'
        );

        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }
            $fullName = trim((string)($customer['name'] ?? ''));
            $first = trim((string)($customer['firstName'] ?? ''));
            $last = trim((string)($customer['lastName'] ?? ''));
            if ($first === '' && $last === '' && $fullName !== '') {
                $parts = preg_split('/\s+/', $fullName, 2) ?: [];
                $first = trim((string)($parts[0] ?? ''));
                $last = trim((string)($parts[1] ?? ''));
            }
            if ($first === '' && $last === '') {
                continue;
            }

            $id = parseIntId((string)($customer['id'] ?? ''), 'c-');
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $id = max(1, min(32767, $id));
            $seen[] = $id;

            $nombres = legacyFitTableValue('CLIENTESV2', 'NOMBRES', $first);
            $apellidos = legacyFitTableValue('CLIENTESV2', 'APELLIDOS', $last);
            $identificacion = legacyFitTableValue('CLIENTESV2', 'IDENTIFICACION', trim((string)($customer['taxId'] ?? ($customer['identification'] ?? ''))));
            $email = legacyFitTableValue('CLIENTESV2', 'EMAIL', trim((string)($customer['email'] ?? '')));
            $telefono = legacyFitTableValue('CLIENTESV2', 'TELEFONO', trim((string)($customer['phone'] ?? '')));
            $dom1 = legacyFitTableValue('CLIENTESV2', 'DOMICILIO1', trim((string)($customer['address1'] ?? '')));
            $dom2 = legacyFitTableValue('CLIENTESV2', 'DOMICILIO2', trim((string)($customer['address2'] ?? '')));
            $parroquia = legacyFitTableValue('CLIENTESV2', 'PARROQUIA', trim((string)($customer['parish'] ?? '')));
            $canton = legacyFitTableValue('CLIENTESV2', 'CANTON', trim((string)($customer['canton'] ?? '')));
            $provincia = legacyFitTableValue('CLIENTESV2', 'PROVINCIA', trim((string)($customer['province'] ?? '')));
            $cp = legacyFitTableValue('CLIENTESV2', 'CODIGO_POSTAL', trim((string)($customer['zip'] ?? '')));
            $notas = legacyFitTableValue('CLIENTESV2', 'NOTAS', trim((string)($customer['notes'] ?? '')));
            $folio = legacyFitTableValue('CLIENTESV2', 'FOLIO', (string)$id);
            $deSistema = legacyFitTableValue('CLIENTESV2', 'DE_SISTEMA', '');
            $oldClienteId = legacyFitTableValue('CLIENTESV2', 'OLD_CLIENTE_ID', '');
            $oldFacturacionClienteId = legacyFitTableValue('CLIENTESV2', 'OLD_FACTURACION_CLIENTES_ID', '');

            $params = [
                ':id' => $id,
                ':nombres' => $nombres,
                ':apellidos' => $apellidos,
                ':identificacion' => $identificacion,
                ':email' => $email,
                ':telefono' => $telefono,
                ':dom1' => $dom1,
                ':dom2' => $dom2,
                ':parroquia' => $parroquia,
                ':canton' => $canton,
                ':provincia' => $provincia,
                ':cp' => $cp,
                ':notas' => $notas,
            ];

            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $update->execute($params);
            } else {
                $insert->execute(array_merge($params, [
                    ':folio' => $folio,
                    ':de_sistema' => $deSistema,
                    ':old_cliente_id' => $oldClienteId,
                    ':old_facturacion_clientes_id' => $oldFacturacionClienteId,
                ]));
            }

            $creditParams = [
                ':id' => $id,
                ':tiene_credito' => !empty($customer['creditAuthorized']) ? 1 : 0,
            ];
            $existsCredit->execute([':id' => $id]);
            if ((int)$existsCredit->fetchColumn() > 0) {
                $updateCredit->execute($creditParams);
            } else {
                $insertCredit->execute($creditParams);
            }
        }

        if (!empty($seen)) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $stmt = $pdo->prepare("UPDATE CLIENTESV2 SET ACTIVO = 0 WHERE ID NOT IN ($placeholders)");
            $stmt->execute($seen);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function legacyHasTables(array $required): bool
{
    static $cache = null;
    if (!is_array($cache)) {
        $cache = [];
        try {
            $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($tables as $table) {
                $cache[strtolower((string)$table)] = true;
            }
        } catch (Throwable) {
            $cache = [];
        }
    }

    foreach ($required as $table) {
        if (!isset($cache[strtolower((string)$table)])) {
            return false;
        }
    }
    return true;
}

function legacyIsoDateString(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('c', $ts);
}

function legacySqlDateTime(mixed $value, bool $nullable = false): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return $nullable ? null : date('Y-m-d H:i:s');
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $nullable ? null : date('Y-m-d H:i:s');
    }
    return date('Y-m-d H:i:s', $ts);
}

function legacyReadProviders(): array
{
    if (!legacyHasTables(['proveedores_base'])) {
        return legacyReadDocumentStore('providers.json');
    }

    $rows = db()->query('SELECT ID, NOMBRE, PAGINA_WEB, NOTAS FROM PROVEEDORES_BASE ORDER BY NOMBRE ASC')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['NOMBRE'] ?? ''));
        if ($name === '') {
            continue;
        }
        $id = safeInt($row['ID'] ?? 0);
        $out[] = [
            'id' => $id > 0 ? ('prov-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT)) : '',
            'name' => $name,
            // En este modulo, "Telefono" se guarda en PAGINA_WEB.
            'phone' => trim((string)($row['PAGINA_WEB'] ?? '')),
            'notes' => trim((string)($row['NOTAS'] ?? '')),
            'createdAt' => '',
        ];
    }
    return $out;
}

function legacyWriteProviders(array $providers): bool
{
    if (!legacyHasTables(['proveedores_base'])) {
        return legacyWriteDocumentStore('providers.json', $providers);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM PROVEEDORES_BASE');
        $insert = $pdo->prepare(
            'INSERT INTO PROVEEDORES_BASE (ID, NOMBRE, PAGINA_WEB, NOTAS)
             VALUES (:id, :nombre, :web, :notas)'
        );

        $nextId = 1;
        foreach ($providers as $provider) {
            if (!is_array($provider)) {
                continue;
            }
            $name = trim((string)($provider['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $id = parseIntId((string)($provider['id'] ?? ''), 'prov-');
            if ($id === null || $id <= 0) {
                $id = $nextId;
            }
            $nextId = max($nextId, $id + 1);

            $insert->execute([
                ':id' => legacyFitTableValue('PROVEEDORES_BASE', 'ID', $id),
                ':nombre' => legacyFitTableValue('PROVEEDORES_BASE', 'NOMBRE', $name),
                ':web' => legacyFitTableValue('PROVEEDORES_BASE', 'PAGINA_WEB', trim((string)($provider['phone'] ?? ''))),
                ':notas' => legacyFitTableValue('PROVEEDORES_BASE', 'NOTAS', trim((string)($provider['notes'] ?? ''))),
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function legacyReadPurchaseList(): array
{
    if (!legacyHasTables(['lista_de_compra', 'proveedores_base'])) {
        return legacyReadDocumentStore('purchase_list.json');
    }

    $sql = 'SELECT l.ID, l.ID_PRODUCTO, l.CODIGO_PRODUCTO, l.DESCRIPCION_PRODUCTO, l.DEPARTAMENTO_PRODUCTO,
                   l.ID_PROVEEDOR_ACTUAL, l.ULTIMO_COSTO, l.CANTIDAD_A_ORDENAR, l.AGREGADO_EN,
                   p.NOMBRE AS PROVEEDOR_NOMBRE
            FROM LISTA_DE_COMPRA l
            LEFT JOIN PROVEEDORES_BASE p ON p.ID = l.ID_PROVEEDOR_ACTUAL
            ORDER BY l.AGREGADO_EN DESC, CAST(l.ID AS UNSIGNED) DESC';
    $rows = db()->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        $productId = trim((string)($row['ID_PRODUCTO'] ?? ''));
        if ($productId === '') {
            continue;
        }
        $provider = trim((string)($row['PROVEEDOR_NOMBRE'] ?? ''));
        if ($provider === '') {
            $provider = '- Sin Proveedor -';
        }
        $out[] = [
            'id' => 'pl-' . str_pad((string)max(1, $id), 5, '0', STR_PAD_LEFT),
            'productId' => $productId,
            'barcode' => trim((string)($row['CODIGO_PRODUCTO'] ?? '')),
            'name' => trim((string)($row['DESCRIPCION_PRODUCTO'] ?? '')),
            'department' => trim((string)($row['DEPARTAMENTO_PRODUCTO'] ?? '')),
            'provider' => $provider,
            'qty' => max(1, safeInt($row['CANTIDAD_A_ORDENAR'] ?? 1)),
            'cost' => safeFloat($row['ULTIMO_COSTO'] ?? 0),
            'createdAt' => legacyIsoDateString($row['AGREGADO_EN'] ?? ''),
        ];
    }
    return $out;
}

function legacyWritePurchaseList(array $list): bool
{
    if (!legacyHasTables(['lista_de_compra', 'proveedores_base'])) {
        return legacyWriteDocumentStore('purchase_list.json', $list);
    }

    $providerByName = [];
    foreach (legacyReadProviders() as $provider) {
        if (!is_array($provider)) {
            continue;
        }
        $name = trim((string)($provider['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $id = parseIntId((string)($provider['id'] ?? ''), 'prov-');
        if ($id !== null && $id > 0) {
            $providerByName[mb_strtolower($name, 'UTF-8')] = $id;
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM LISTA_DE_COMPRA');
        $insert = $pdo->prepare(
            'INSERT INTO LISTA_DE_COMPRA
             (ID, ID_PRODUCTO, CODIGO_PRODUCTO, DESCRIPCION_PRODUCTO, DEPARTAMENTO_PRODUCTO, ID_PROVEEDOR_ACTUAL, ULTIMO_COSTO, CANTIDAD_A_ORDENAR, AGREGADO_EN)
             VALUES (:id, :id_producto, :codigo, :descripcion, :departamento, :id_proveedor, :ultimo_costo, :cantidad, :agregado_en)'
        );

        $nextId = 1;
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = trim((string)($row['productId'] ?? ''));
            if ($productId === '') {
                continue;
            }
            $id = parseIntId((string)($row['id'] ?? ''), 'pl-');
            if ($id === null || $id <= 0) {
                $id = $nextId;
            }
            $nextId = max($nextId, $id + 1);

            $providerName = trim((string)($row['provider'] ?? ''));
            $providerId = null;
            if ($providerName !== '' && $providerName !== '- Sin Proveedor -') {
                $key = mb_strtolower($providerName, 'UTF-8');
                $providerId = $providerByName[$key] ?? null;
            }

            $insert->execute([
                ':id' => legacyFitTableValue('LISTA_DE_COMPRA', 'ID', $id),
                ':id_producto' => legacyFitTableValue('LISTA_DE_COMPRA', 'ID_PRODUCTO', $productId),
                ':codigo' => legacyFitTableValue('LISTA_DE_COMPRA', 'CODIGO_PRODUCTO', trim((string)($row['barcode'] ?? ''))),
                ':descripcion' => legacyFitTableValue('LISTA_DE_COMPRA', 'DESCRIPCION_PRODUCTO', trim((string)($row['name'] ?? ''))),
                ':departamento' => legacyFitTableValue('LISTA_DE_COMPRA', 'DEPARTAMENTO_PRODUCTO', trim((string)($row['department'] ?? ''))),
                ':id_proveedor' => $providerId,
                ':ultimo_costo' => legacyFitTableValue('LISTA_DE_COMPRA', 'ULTIMO_COSTO', safeFloat($row['cost'] ?? 0)),
                ':cantidad' => legacyFitTableValue('LISTA_DE_COMPRA', 'CANTIDAD_A_ORDENAR', max(1, safeInt($row['qty'] ?? 1))),
                ':agregado_en' => legacySqlDateTime($row['createdAt'] ?? ''),
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function legacyReadPurchaseOrders(): array
{
    if (!legacyHasTables(['ordenes_de_compra', 'articulos_de_oc', 'proveedores_base'])) {
        return legacyReadDocumentStore('purchase_orders.json');
    }

    $providerRows = db()->query('SELECT ID, NOMBRE FROM PROVEEDORES_BASE')->fetchAll();
    $providerById = [];
    foreach ($providerRows as $row) {
        $providerById[(string)($row['ID'] ?? '')] = trim((string)($row['NOMBRE'] ?? ''));
    }

    $detailRows = db()->query(
        'SELECT ID_ORDEN_DE_COMPRA, ID_PRODUCTO, CODIGO_PRODUCTO, DESCRIPCION_PRODUCTO, DEPARTAMENTO_PRODUCTO, CANTIDAD_ORDENADA, COSTO_AL_ORDENAR
         FROM ARTICULOS_DE_OC
         ORDER BY CAST(ID AS UNSIGNED) ASC'
    )->fetchAll();
    $itemsByOrder = [];
    foreach ($detailRows as $row) {
        $orderId = trim((string)($row['ID_ORDEN_DE_COMPRA'] ?? ''));
        if ($orderId === '') {
            continue;
        }
        if (!isset($itemsByOrder[$orderId])) {
            $itemsByOrder[$orderId] = [];
        }
        $itemsByOrder[$orderId][] = [
            'productId' => trim((string)($row['ID_PRODUCTO'] ?? '')),
            'barcode' => trim((string)($row['CODIGO_PRODUCTO'] ?? '')),
            'name' => trim((string)($row['DESCRIPCION_PRODUCTO'] ?? '')),
            'department' => trim((string)($row['DEPARTAMENTO_PRODUCTO'] ?? '')),
            'provider' => '',
            'qty' => max(1, safeInt($row['CANTIDAD_ORDENADA'] ?? 1)),
            'cost' => safeFloat($row['COSTO_AL_ORDENAR'] ?? 0),
        ];
    }

    $orders = db()->query('SELECT * FROM ORDENES_DE_COMPRA ORDER BY CREADA_EN DESC, CAST(ID AS UNSIGNED) DESC')->fetchAll();
    $out = [];
    foreach ($orders as $order) {
        $id = safeInt($order['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $orderKey = (string)$id;
        $items = $itemsByOrder[$orderKey] ?? [];
        $providerName = trim((string)($providerById[(string)($order['ID_PROVEEDOR'] ?? '')] ?? ''));
        if ($providerName === '') {
            $providerName = '- Sin Proveedor -';
        }
        foreach ($items as $idx => $item) {
            $items[$idx]['provider'] = $providerName;
        }

        $out[] = [
            'id' => 'po-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT),
            'folio' => trim((string)($order['FOLIO_ORDEN_PROVEEDOR'] ?? '')),
            'provider' => $providerName,
            'source' => trim((string)($order['NOTAS'] ?? '')) ?: 'list',
            'status' => trim((string)($order['ESTADO'] ?? 'pending')) ?: 'pending',
            'total' => safeFloat($order['TOTAL_ENVIADO'] ?? 0),
            'items' => $items,
            'createdAt' => legacyIsoDateString($order['CREADA_EN'] ?? ''),
            'receivedAt' => legacyIsoDateString($order['RECIBIDA_EN'] ?? ''),
        ];
    }
    return $out;
}

function legacyWritePurchaseOrders(array $orders): bool
{
    if (!legacyHasTables(['ordenes_de_compra', 'articulos_de_oc', 'proveedores_base'])) {
        return legacyWriteDocumentStore('purchase_orders.json', $orders);
    }

    $providerRows = db()->query('SELECT ID, NOMBRE FROM PROVEEDORES_BASE')->fetchAll();
    $providerByName = [];
    foreach ($providerRows as $row) {
        $name = trim((string)($row['NOMBRE'] ?? ''));
        if ($name === '') {
            continue;
        }
        $providerByName[mb_strtolower($name, 'UTF-8')] = safeInt($row['ID'] ?? 0);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM ARTICULOS_DE_OC');
        $pdo->exec('DELETE FROM ORDENES_DE_COMPRA');

        $insertOrder = $pdo->prepare(
            'INSERT INTO ORDENES_DE_COMPRA
             (ID, ID_PROVEEDOR, FOLIO_ORDEN_PROVEEDOR, CREADA_EN, CANCELADA_EN, ENVIADA_EN, RECIBIDA_EN, ESTADO,
              UTILIDAD_ENVIADO, IMPUESTOS_ENVIADO, SUBTOTAL_ENVIADO, TOTAL_ENVIADO,
              UTILIDAD_RECIBIDO, IMPUESTOS_RECIBIDO, SUBTOTAL_RECIBIDO, TOTAL_RECIBIDO, NOTAS, TOTAL_ARTICULOS)
             VALUES
             (:id, :id_proveedor, :folio, :creada_en, :cancelada_en, :enviada_en, :recibida_en, :estado,
              0, :impuestos_enviado, :subtotal_enviado, :total_enviado,
              0, :impuestos_recibido, :subtotal_recibido, :total_recibido, :notas, :total_articulos)'
        );
        $insertDetail = $pdo->prepare(
            'INSERT INTO ARTICULOS_DE_OC
             (ID, ID_ORDEN_DE_COMPRA, ID_PRODUCTO, CODIGO_PRODUCTO, DESCRIPCION_PRODUCTO, DEPARTAMENTO_PRODUCTO,
              CANTIDAD_ORDENADA, CANTIDAD_RECIBIDA, ID_AGRUPACION, COSTO_AL_ORDENAR, COSTO_AL_RECIBIR,
              DIAS_EN_SURTIR, LISTA_IMPUESTOS, UTILIDAD_ESTIMADA_AL_ORDENAR, UTILIDAD_ESTIMADA_AL_RECIBIR,
              IMPUESTOS_AL_RECIBIR, SUBTOTAL_AL_RECIBIR, TOTAL_AL_ORDENAR, TOTAL_AL_RECIBIR,
              PRECIO_SIN_IMPUESTOS, PRECIO_CON_IMPUESTOS, PRECIO_MAYOREO_CON_IMPUESTOS)
             VALUES
             (:id, :id_orden, :id_producto, :codigo, :descripcion, :departamento,
              :cantidad_ordenada, :cantidad_recibida, :agrupacion, :costo_ordenar, :costo_recibir,
              0, :lista_impuestos, 0, 0, 0, :subtotal_recibir, :total_ordenar, :total_recibir,
              :precio_sin, :precio_con, :precio_mayoreo)'
        );

        $nextOrderId = 1;
        $nextDetailId = 1;
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = parseIntId((string)($order['id'] ?? ''), 'po-');
            if ($orderId === null || $orderId <= 0) {
                $orderId = $nextOrderId;
            }
            $nextOrderId = max($nextOrderId, $orderId + 1);

            $providerName = trim((string)($order['provider'] ?? ''));
            $providerId = null;
            if ($providerName !== '' && $providerName !== '- Sin Proveedor -') {
                $providerId = $providerByName[mb_strtolower($providerName, 'UTF-8')] ?? null;
            }

            $items = $order['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $subtotal = 0.0;
            $totalArticles = 0;
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $qty = max(1, safeInt($it['qty'] ?? 1));
                $cost = safeFloat($it['cost'] ?? 0);
                $subtotal += $qty * $cost;
                $totalArticles += $qty;
            }
            $total = safeFloat($order['total'] ?? $subtotal);
            if ($total <= 0) {
                $total = $subtotal;
            }

            $createdAt = trim((string)($order['createdAt'] ?? date('c')));
            $receivedAt = trim((string)($order['receivedAt'] ?? ''));
            $status = trim((string)($order['status'] ?? 'pending'));

            $insertOrder->execute([
                ':id' => legacyFitTableValue('ORDENES_DE_COMPRA', 'ID', $orderId),
                ':id_proveedor' => $providerId,
                ':folio' => legacyFitTableValue('ORDENES_DE_COMPRA', 'FOLIO_ORDEN_PROVEEDOR', trim((string)($order['folio'] ?? 'OC-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT)))),
                ':creada_en' => legacySqlDateTime($createdAt),
                ':cancelada_en' => legacySqlDateTime('', true),
                ':enviada_en' => legacySqlDateTime($createdAt, true),
                ':recibida_en' => legacySqlDateTime($receivedAt, true),
                ':estado' => legacyFitTableValue('ORDENES_DE_COMPRA', 'ESTADO', $status),
                ':impuestos_enviado' => legacyFitTableValue('ORDENES_DE_COMPRA', 'IMPUESTOS_ENVIADO', 0),
                ':subtotal_enviado' => legacyFitTableValue('ORDENES_DE_COMPRA', 'SUBTOTAL_ENVIADO', $subtotal),
                ':total_enviado' => legacyFitTableValue('ORDENES_DE_COMPRA', 'TOTAL_ENVIADO', $total),
                ':impuestos_recibido' => legacyFitTableValue('ORDENES_DE_COMPRA', 'IMPUESTOS_RECIBIDO', 0),
                ':subtotal_recibido' => legacyFitTableValue('ORDENES_DE_COMPRA', 'SUBTOTAL_RECIBIDO', $status === 'received' ? $subtotal : 0),
                ':total_recibido' => legacyFitTableValue('ORDENES_DE_COMPRA', 'TOTAL_RECIBIDO', $status === 'received' ? $total : 0),
                ':notas' => legacyFitTableValue('ORDENES_DE_COMPRA', 'NOTAS', trim((string)($order['source'] ?? ''))),
                ':total_articulos' => legacyFitTableValue('ORDENES_DE_COMPRA', 'TOTAL_ARTICULOS', $totalArticles),
            ]);

            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $qty = max(1, safeInt($it['qty'] ?? 1));
                $cost = safeFloat($it['cost'] ?? 0);
                $lineTotal = round($qty * $cost, 4);
                $receivedQty = $status === 'received' ? $qty : 0;
                $receivedCost = $status === 'received' ? $cost : 0;
                $receivedTotal = $status === 'received' ? $lineTotal : 0;
                $detailId = $nextDetailId++;
                $insertDetail->execute([
                    ':id' => legacyFitTableValue('ARTICULOS_DE_OC', 'ID', $detailId),
                    ':id_orden' => legacyFitTableValue('ARTICULOS_DE_OC', 'ID_ORDEN_DE_COMPRA', $orderId),
                    ':id_producto' => legacyFitTableValue('ARTICULOS_DE_OC', 'ID_PRODUCTO', trim((string)($it['productId'] ?? ''))),
                    ':codigo' => legacyFitTableValue('ARTICULOS_DE_OC', 'CODIGO_PRODUCTO', trim((string)($it['barcode'] ?? ''))),
                    ':descripcion' => legacyFitTableValue('ARTICULOS_DE_OC', 'DESCRIPCION_PRODUCTO', trim((string)($it['name'] ?? ''))),
                    ':departamento' => legacyFitTableValue('ARTICULOS_DE_OC', 'DEPARTAMENTO_PRODUCTO', trim((string)($it['department'] ?? ''))),
                    ':cantidad_ordenada' => legacyFitTableValue('ARTICULOS_DE_OC', 'CANTIDAD_ORDENADA', $qty),
                    ':cantidad_recibida' => legacyFitTableValue('ARTICULOS_DE_OC', 'CANTIDAD_RECIBIDA', $receivedQty),
                    ':agrupacion' => legacyFitTableValue('ARTICULOS_DE_OC', 'ID_AGRUPACION', ''),
                    ':costo_ordenar' => legacyFitTableValue('ARTICULOS_DE_OC', 'COSTO_AL_ORDENAR', $cost),
                    ':costo_recibir' => legacyFitTableValue('ARTICULOS_DE_OC', 'COSTO_AL_RECIBIR', $receivedCost),
                    ':lista_impuestos' => legacyFitTableValue('ARTICULOS_DE_OC', 'LISTA_IMPUESTOS', ''),
                    ':subtotal_recibir' => legacyFitTableValue('ARTICULOS_DE_OC', 'SUBTOTAL_AL_RECIBIR', $receivedTotal),
                    ':total_ordenar' => legacyFitTableValue('ARTICULOS_DE_OC', 'TOTAL_AL_ORDENAR', $lineTotal),
                    ':total_recibir' => legacyFitTableValue('ARTICULOS_DE_OC', 'TOTAL_AL_RECIBIR', $receivedTotal),
                    ':precio_sin' => legacyFitTableValue('ARTICULOS_DE_OC', 'PRECIO_SIN_IMPUESTOS', $cost),
                    ':precio_con' => legacyFitTableValue('ARTICULOS_DE_OC', 'PRECIO_CON_IMPUESTOS', $cost),
                    ':precio_mayoreo' => legacyFitTableValue('ARTICULOS_DE_OC', 'PRECIO_MAYOREO_CON_IMPUESTOS', $cost),
                ]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function legacyReadPurchaseHistory(): array
{
    if (!legacyHasTables(['ordenes_de_compra', 'articulos_de_oc', 'proveedores_base'])) {
        return legacyReadDocumentStore('purchase_history.json');
    }

    $sql = 'SELECT a.ID, a.ID_ORDEN_DE_COMPRA, a.ID_PRODUCTO, a.CODIGO_PRODUCTO, a.DESCRIPCION_PRODUCTO,
                   a.CANTIDAD_ORDENADA, a.CANTIDAD_RECIBIDA, a.COSTO_AL_ORDENAR, a.COSTO_AL_RECIBIR,
                   o.FOLIO_ORDEN_PROVEEDOR, o.RECIBIDA_EN, o.ID_PROVEEDOR, p.NOMBRE AS PROVEEDOR
            FROM ARTICULOS_DE_OC a
            INNER JOIN ORDENES_DE_COMPRA o ON o.ID = a.ID_ORDEN_DE_COMPRA
            LEFT JOIN PROVEEDORES_BASE p ON p.ID = o.ID_PROVEEDOR
            WHERE (o.ESTADO = "received" OR o.RECIBIDA_EN IS NOT NULL)
            ORDER BY o.RECIBIDA_EN DESC, CAST(a.ID AS UNSIGNED) DESC';
    $rows = db()->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        $qtyReceived = safeFloat($row['CANTIDAD_RECIBIDA'] ?? 0);
        $qtyOrdered = safeFloat($row['CANTIDAD_ORDENADA'] ?? 0);
        $qty = $qtyReceived > 0 ? $qtyReceived : $qtyOrdered;
        $costReceived = safeFloat($row['COSTO_AL_RECIBIR'] ?? 0);
        $costOrdered = safeFloat($row['COSTO_AL_ORDENAR'] ?? 0);
        $cost = $costReceived > 0 ? $costReceived : $costOrdered;
        $out[] = [
            'id' => 'ph-' . str_pad((string)max(1, $id), 6, '0', STR_PAD_LEFT),
            'orderId' => 'po-' . str_pad((string)max(1, safeInt($row['ID_ORDEN_DE_COMPRA'] ?? 0)), 6, '0', STR_PAD_LEFT),
            'orderFolio' => trim((string)($row['FOLIO_ORDEN_PROVEEDOR'] ?? '')),
            'productId' => trim((string)($row['ID_PRODUCTO'] ?? '')),
            'barcode' => trim((string)($row['CODIGO_PRODUCTO'] ?? '')),
            'name' => trim((string)($row['DESCRIPCION_PRODUCTO'] ?? '')),
            'provider' => trim((string)($row['PROVEEDOR'] ?? '')) ?: '- Sin Proveedor -',
            'qty' => $qty,
            'cost' => $cost,
            'createdAt' => legacyIsoDateString($row['RECIBIDA_EN'] ?? ''),
        ];
    }
    return $out;
}

function legacyWritePurchaseHistory(array $history): bool
{
    // El historial se deriva de ORDENES_DE_COMPRA + ARTICULOS_DE_OC.
    // Guardamos tambien el overlay para no perder compatibilidad con exportaciones previas.
    return true;
}
