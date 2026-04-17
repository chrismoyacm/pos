<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/cash_shift.php';
require_once __DIR__ . '/../utils/db.php';

session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    errorResponse('Sesion no valida', 401);
}

$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));
$userId = $_SESSION['user_id'] ?? null;
$rows = readCashOpenings();
$shift = findOpenShiftForUser($rows, $userId);

if ($method === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? 'status')));
    if ($action === 'movements') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;
        $movementType = strtolower(trim((string)($_GET['movementType'] ?? '')));
        $typeFilter = in_array($movementType, ['entry', 'exit'], true) ? $movementType : '';
        $shiftIdFilter = trim((string)($_GET['shiftId'] ?? ''));
        $dateFrom = trim((string)($_GET['dateFrom'] ?? ''));
        $dateTo = trim((string)($_GET['dateTo'] ?? ''));

        $canUseDb = false;
        if (function_exists('dbEnabled') && dbEnabled() && function_exists('db')) {
            try {
                $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $tableNames = array_map(static fn($t): string => strtolower((string)$t), $tables);
                $canUseDb = in_array('movimientos', $tableNames, true);
            } catch (Throwable) {
                $canUseDb = false;
            }
        }

        if ($canUseDb) {
            $params = [];
            $where = ["TIPO IN ('cash_entry','cash_exit')"];
            if ($typeFilter !== '') {
                $where[] = 'TIPO = :tipo';
                $params[':tipo'] = $typeFilter === 'entry' ? 'cash_entry' : 'cash_exit';
            }
            if ($shiftIdFilter !== '') {
                $where[] = 'OPERACION_ID = :shift_id';
                $params[':shift_id'] = $shiftIdFilter;
            }
            if ($dateFrom !== '') {
                $where[] = 'CUANDO_FUE >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== '') {
                $where[] = 'CUANDO_FUE <= :date_to';
                $params[':date_to'] = $dateTo . ' 23:59:59';
            }
            $whereSql = implode(' AND ', $where);

            $countStmt = db()->prepare('SELECT COUNT(*) FROM MOVIMIENTOS WHERE ' . $whereSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listSql = 'SELECT ID, OPERACION_ID, MONTO, CUANDO_FUE, COMENTARIOS, TIPO, CAJERO_ID FROM MOVIMIENTOS WHERE ' . $whereSql
                . ' ORDER BY CUANDO_FUE DESC, ID DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
            $listStmt = db()->prepare($listSql);
            $listStmt->execute($params);
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $items = array_map(static function (array $row): array {
                $tipo = strtolower(trim((string)($row['TIPO'] ?? '')));
                $type = $tipo === 'cash_exit' ? 'exit' : 'entry';
                $createdAtRaw = trim((string)($row['CUANDO_FUE'] ?? ''));
                $createdAt = $createdAtRaw;
                if ($createdAtRaw !== '') {
                    $ts = strtotime($createdAtRaw);
                    if ($ts !== false) {
                        $createdAt = date('c', $ts);
                    }
                }
                return [
                    'id' => (string)($row['ID'] ?? ''),
                    'shiftId' => (string)($row['OPERACION_ID'] ?? ''),
                    'userId' => (string)($row['CAJERO_ID'] ?? ''),
                    'type' => $type,
                    'amount' => round((float)($row['MONTO'] ?? 0), 2),
                    'note' => (string)($row['COMENTARIOS'] ?? ''),
                    'createdAt' => $createdAt,
                ];
            }, $rows);

            $sumSql = <<<SQL
SELECT
    COALESCE(SUM(CASE WHEN TIPO='cash_entry' THEN CAST(MONTO AS DECIMAL(12,2)) ELSE 0 END), 0) AS ENTRADAS,
    COALESCE(SUM(CASE WHEN TIPO='cash_exit' THEN CAST(MONTO AS DECIMAL(12,2)) ELSE 0 END), 0) AS SALIDAS
FROM MOVIMIENTOS
WHERE {$whereSql}
SQL;
            $sumStmt = db()->prepare($sumSql);
            $sumStmt->execute($params);
            $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $entriesTotal = round((float)($sumRow['ENTRADAS'] ?? 0), 2);
            $exitsTotal = round((float)($sumRow['SALIDAS'] ?? 0), 2);

            ok([
                'items' => $items,
                'summary' => [
                    'entriesTotal' => $entriesTotal,
                    'exitsTotal' => $exitsTotal,
                    'netTotal' => round($entriesTotal - $exitsTotal, 2),
                ],
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => max(1, (int)ceil($total / max(1, $pageSize))),
                ],
            ]);
        }

        $movements = readCashMovements();
        if ($shiftIdFilter !== '') {
            $movements = array_values(array_filter($movements, static function ($row) use ($shiftIdFilter): bool {
                if (!is_array($row)) {
                    return false;
                }
                return (string)($row['shiftId'] ?? '') === $shiftIdFilter;
            }));
        }
        if ($dateFrom !== '' || $dateTo !== '') {
            $movements = array_values(array_filter($movements, static function ($row) use ($dateFrom, $dateTo): bool {
                if (!is_array($row)) {
                    return false;
                }
                $createdAt = trim((string)($row['createdAt'] ?? ''));
                if ($createdAt === '') {
                    return false;
                }
                $d = substr($createdAt, 0, 10);
                if ($dateFrom !== '' && strcmp($d, $dateFrom) < 0) {
                    return false;
                }
                if ($dateTo !== '' && strcmp($d, $dateTo) > 0) {
                    return false;
                }
                return true;
            }));
        }
        if ($typeFilter !== '') {
            $movements = array_values(array_filter($movements, static function ($row) use ($typeFilter): bool {
                if (!is_array($row)) {
                    return false;
                }
                return strtolower((string)($row['type'] ?? '')) === $typeFilter;
            }));
        }
        usort($movements, static function ($a, $b): int {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });
        $entriesTotal = 0.0;
        $exitsTotal = 0.0;
        foreach ($movements as $mv) {
            if (!is_array($mv)) {
                continue;
            }
            $amount = round((float)($mv['amount'] ?? 0), 2);
            $type = strtolower(trim((string)($mv['type'] ?? '')));
            if ($type === 'entry') {
                $entriesTotal += $amount;
            } elseif ($type === 'exit') {
                $exitsTotal += $amount;
            }
        }
        $total = count($movements);
        $items = array_slice($movements, $offset, $pageSize);
        ok([
            'items' => $items,
            'summary' => [
                'entriesTotal' => round($entriesTotal, 2),
                'exitsTotal' => round($exitsTotal, 2),
                'netTotal' => round($entriesTotal - $exitsTotal, 2),
            ],
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => max(1, (int)ceil($total / max(1, $pageSize))),
            ],
        ]);
    }

    if ($action === 'credit_payments') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        $offset = ($page - 1) * $pageSize;
        $cashierFilter = trim((string)($_GET['cashier'] ?? ''));
        $dateFrom = trim((string)($_GET['dateFrom'] ?? ''));
        $dateTo = trim((string)($_GET['dateTo'] ?? ''));
        $datetimeFrom = trim((string)($_GET['datetimeFrom'] ?? ''));
        $datetimeTo = trim((string)($_GET['datetimeTo'] ?? ''));

        $canUseDb = false;
        if (function_exists('dbEnabled') && dbEnabled() && function_exists('db')) {
            try {
                $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $tableNames = array_map(static fn($t): string => strtolower((string)$t), $tables);
                $canUseDb = in_array('movimientos', $tableNames, true);
            } catch (Throwable) {
                $canUseDb = false;
            }
        }

        if ($canUseDb) {
            $params = [];
            $where = ["TIPO LIKE 'credit_payment%'"];
            if ($cashierFilter !== '') {
                $where[] = 'CAJERO_ID = :cashier';
                $params[':cashier'] = $cashierFilter;
            }
            if ($datetimeFrom !== '') {
                $where[] = 'CUANDO_FUE >= :datetime_from';
                $params[':datetime_from'] = $datetimeFrom;
            } elseif ($dateFrom !== '') {
                $where[] = 'CUANDO_FUE >= :date_from';
                $params[':date_from'] = $dateFrom . ' 00:00:00';
            }
            if ($datetimeTo !== '') {
                $where[] = 'CUANDO_FUE <= :datetime_to';
                $params[':datetime_to'] = $datetimeTo;
            } elseif ($dateTo !== '') {
                $where[] = 'CUANDO_FUE <= :date_to';
                $params[':date_to'] = $dateTo . ' 23:59:59';
            }
            $whereSql = implode(' AND ', $where);

            $countStmt = db()->prepare('SELECT COUNT(*) FROM MOVIMIENTOS WHERE ' . $whereSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listSql = 'SELECT ID, ABONO_ID, CLIENTE_ID, MONTO, CUANDO_FUE, COMENTARIOS, TIPO, CAJERO_ID FROM MOVIMIENTOS WHERE ' . $whereSql
                . ' ORDER BY CUANDO_FUE DESC, ID DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
            $listStmt = db()->prepare($listSql);
            $listStmt->execute($params);
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $items = array_map(static function (array $row): array {
                $tipo = strtolower(trim((string)($row['TIPO'] ?? 'credit_payment_cash')));
                $method = 'cash';
                $parts = explode('_', $tipo);
                if (count($parts) >= 3) {
                    $method = trim((string)$parts[count($parts) - 1]);
                    if ($method === '') {
                        $method = 'cash';
                    }
                }
                $createdAtRaw = trim((string)($row['CUANDO_FUE'] ?? ''));
                $createdAt = $createdAtRaw;
                if ($createdAtRaw !== '') {
                    $ts = strtotime($createdAtRaw);
                    if ($ts !== false) {
                        $createdAt = date('c', $ts);
                    }
                }
                return [
                    'id' => (string)($row['ID'] ?? ''),
                    'folio' => (string)($row['ABONO_ID'] ?? ''),
                    'customerId' => (string)($row['CLIENTE_ID'] ?? ''),
                    'amount' => round((float)($row['MONTO'] ?? 0), 2),
                    'paymentMethod' => $method,
                    'note' => (string)($row['COMENTARIOS'] ?? ''),
                    'cashier' => (string)($row['CAJERO_ID'] ?? ''),
                    'createdAt' => $createdAt,
                ];
            }, $rows);

            $sumSql = <<<SQL
SELECT
    COALESCE(SUM(CAST(MONTO AS DECIMAL(12,2))), 0) AS TOTAL_ALL,
    COALESCE(SUM(CASE WHEN TIPO='credit_payment_cash' THEN CAST(MONTO AS DECIMAL(12,2)) ELSE 0 END), 0) AS TOTAL_CASH
FROM MOVIMIENTOS
WHERE {$whereSql}
SQL;
            $sumStmt = db()->prepare($sumSql);
            $sumStmt->execute($params);
            $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalAll = round((float)($sumRow['TOTAL_ALL'] ?? 0), 2);
            $totalCash = round((float)($sumRow['TOTAL_CASH'] ?? 0), 2);

            ok([
                'items' => $items,
                'summary' => [
                    'total' => $totalAll,
                    'cashTotal' => $totalCash,
                ],
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => max(1, (int)ceil($total / max(1, $pageSize))),
                ],
            ]);
        }

        $creditPayments = readJsonFile(storagePath('credit_payments.json'));
        $items = array_values(array_filter($creditPayments, static function ($row) use ($cashierFilter, $dateFrom, $dateTo, $datetimeFrom, $datetimeTo): bool {
            if (!is_array($row)) {
                return false;
            }
            $createdAt = trim((string)($row['createdAt'] ?? ''));
            if ($cashierFilter !== '' && trim((string)($row['cashier'] ?? '')) !== $cashierFilter) {
                return false;
            }
            if ($createdAt !== '') {
                if ($datetimeFrom !== '' && strcmp($createdAt, $datetimeFrom) < 0) {
                    return false;
                }
                if ($datetimeTo !== '' && strcmp($createdAt, $datetimeTo) > 0) {
                    return false;
                }
                $d = substr($createdAt, 0, 10);
                if ($dateFrom !== '' && strcmp($d, $dateFrom) < 0) {
                    return false;
                }
                if ($dateTo !== '' && strcmp($d, $dateTo) > 0) {
                    return false;
                }
            }
            return true;
        }));

        usort($items, static function ($a, $b): int {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });

        $totalAll = 0.0;
        $totalCash = 0.0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $amount = round((float)($it['amount'] ?? 0), 2);
            $totalAll += $amount;
            $method = strtolower(trim((string)($it['paymentMethod'] ?? 'cash')));
            if ($method === '' || $method === 'cash') {
                $totalCash += $amount;
            }
        }

        $total = count($items);
        $paged = array_slice($items, $offset, $pageSize);
        ok([
            'items' => $paged,
            'summary' => [
                'total' => round($totalAll, 2),
                'cashTotal' => round($totalCash, 2),
            ],
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => max(1, (int)ceil($total / max(1, $pageSize))),
            ],
        ]);
    }

    if (!is_array($shift)) {
        ok([
            'hasOpenShift' => false,
            'expectedCash' => 0,
        ]);
    }

    $expectedCash = computeExpectedCashForShift($shift);
    ok([
        'hasOpenShift' => true,
        'shiftId' => $shift['id'] ?? null,
        'expectedCash' => $expectedCash,
        'openingAmount' => (float)($shift['amount'] ?? 0),
        'openedAt' => $shift['createdAt'] ?? null,
    ]);
}

if ($method !== 'POST') {
    errorResponse('Metodo no soportado', 405);
}

$body = $request['body'];
if (!is_array($body)) {
    errorResponse('Cuerpo invalido', 400);
}

$action = trim((string)($body['action'] ?? ''));

if ($action === 'cash_movement') {
    if (!is_array($shift)) {
        errorResponse('No hay un turno abierto para registrar movimiento', 409);
    }

    $movementType = strtolower(trim((string)($body['movementType'] ?? '')));
    if (!in_array($movementType, ['entry', 'exit'], true)) {
        errorResponse('Tipo de movimiento inválido', 400);
    }

    $amount = round((float)($body['amount'] ?? 0), 2);
    if ($amount <= 0) {
        errorResponse('La cantidad debe ser mayor a cero', 400);
    }

    $note = trim((string)($body['note'] ?? ''));
    if ($movementType === 'exit' && $note === '') {
        errorResponse('Ingrese razón o proveedor para la salida', 400);
    }
    if ($movementType === 'entry' && $note === '') {
        $note = 'Entrada de efectivo';
    }

    $movements = readCashMovements();
    $entry = [
        'id' => nextCashMovementId($movements),
        'shiftId' => (string)($shift['id'] ?? ''),
        'userId' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'name' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'Usuario',
        'type' => $movementType,
        'amount' => $amount,
        'note' => $note,
        'createdAt' => date('c'),
    ];
    $movements[] = $entry;
    if (count($movements) > 50000) {
        $movements = array_slice($movements, -50000);
    }
    writeCashMovements($movements);

    $expectedCash = computeExpectedCashForShift($shift);
    ok([
        'message' => $movementType === 'entry' ? 'Entrada de efectivo registrada' : 'Salida de efectivo registrada',
        'movement' => $entry,
        'expectedCash' => $expectedCash,
    ]);
}

if ($action === 'leave_open') {
    ok([
        'message' => 'Turno dejado abierto',
        'redirect' => '../api/auth.php?action=logout',
    ]);
}

if ($action !== 'close_shift') {
    errorResponse('Accion no soportada', 400);
}

if (!is_array($shift)) {
    errorResponse('No hay un turno abierto para cerrar', 409);
}

$actualCash = round((float)($body['actualCash'] ?? 0), 2);
if ($actualCash < 0) {
    errorResponse('El efectivo en caja no puede ser negativo', 400);
}

$expectedCash = computeExpectedCashForShift($shift);
$difference = round($actualCash - $expectedCash, 2);
$closedAt = date('c');
$shiftId = (string)($shift['id'] ?? '');

foreach ($rows as $index => $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string)($row['id'] ?? '') !== $shiftId) {
        continue;
    }

    $rows[$index]['status'] = 'closed';
    $rows[$index]['expectedCash'] = $expectedCash;
    $rows[$index]['actualCash'] = $actualCash;
    $rows[$index]['difference'] = $difference;
    $rows[$index]['closedAt'] = $closedAt;
    break;
}

writeCashOpenings($rows);

$_SESSION['cash_shift_status'] = 'closed';
unset($_SESSION['cash_opening_id'], $_SESSION['cash_opening_amount'], $_SESSION['cash_opened_at'], $_SESSION['pending_cash_opening']);

ok([
    'message' => 'Turno cerrado correctamente',
    'expectedCash' => $expectedCash,
    'actualCash' => $actualCash,
    'difference' => $difference,
    'redirect' => '../api/auth.php?action=logout',
]);
