<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/credit_ledger.php';
require_once __DIR__ . '/../utils/db.php';

$customersPath = storagePath('customers.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

if ($method !== 'GET') {
    errorResponse('Metodo no soportado', 405);
}

function canUseCreditsReportDb(): bool
{
    if (!function_exists('dbEnabled') || !dbEnabled()) {
        return false;
    }
    try {
        $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tableNames = array_map(static fn($t): string => strtolower((string)$t), $tables);
        foreach (['clientesv2', 'ventatickets', 'movimientos'] as $required) {
            if (!in_array($required, $tableNames, true)) {
                return false;
            }
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array{paymentDate:string,paymentStatus:string,isOverdue:bool} */
function reportScheduleStatus(array $customer, float $balance, bool $hasPaymentCurrentMonth): array
{
    $today = new DateTimeImmutable('now');
    $currentDay = (int)$today->format('d');
    $todayDate = $today->format('Y-m-d');

    $paymentDueDateRaw = trim((string)($customer['paymentDueDate'] ?? ''));
    $paymentDueDate = null;
    if ($paymentDueDateRaw !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $paymentDueDateRaw);
        if ($dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $paymentDueDateRaw) {
            $paymentDueDate = $dt;
        }
    }

    $paymentDueDayRaw = $customer['paymentDueDay'] ?? null;
    $paymentDueDay = is_numeric($paymentDueDayRaw) ? (int)$paymentDueDayRaw : null;
    if ($paymentDueDay !== null && ($paymentDueDay < 1 || $paymentDueDay > 31)) {
        $paymentDueDay = null;
    }

    $isOverdue = false;
    $paymentStatus = '';
    if ($paymentDueDate instanceof DateTimeImmutable) {
        $dueDateStr = $paymentDueDate->format('Y-m-d');
        if ($balance > 0 && strcmp($todayDate, $dueDateStr) > 0) {
            $isOverdue = true;
            $paymentStatus = 'Vencido';
        } elseif ($balance <= 0) {
            $paymentStatus = 'Liquidado';
        } else {
            $paymentStatus = 'Pendiente';
        }
    } elseif ($paymentDueDay !== null) {
        if ($hasPaymentCurrentMonth) {
            $paymentStatus = 'Abonado este mes';
        } else {
            $effectiveDueDay = min($paymentDueDay, (int)$today->format('t'));
            if ($balance > 0 && $currentDay > $effectiveDueDay) {
                $isOverdue = true;
                $paymentStatus = 'Vencido';
            } else {
                $paymentStatus = 'Pendiente del mes';
            }
        }
    }

    $paymentDateLabel = 'No definido';
    if ($paymentDueDate instanceof DateTimeImmutable) {
        $paymentDateLabel = $paymentDueDate->format('d/m/Y');
    } elseif ($paymentDueDay !== null) {
        $paymentDateLabel = 'Dia ' . str_pad((string)$paymentDueDay, 2, '0', STR_PAD_LEFT);
    }

    return [
        'paymentDate' => $paymentDateLabel,
        'paymentStatus' => $paymentStatus,
        'isOverdue' => $isOverdue,
    ];
}

/** @return array<string, array<string,mixed>> */
function customersScheduleMap(array $customers): array
{
    $map = [];
    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $id = trim((string)($customer['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $map[$id] = $customer;
    }
    return $map;
}

/** @return array{totalPending:float,rows:array<int,array<string,mixed>>,pagination:array<string,int>} */
function creditReportFetchPagedDb(int $page, int $pageSize, array $scheduleMap, bool $all = false): array
{
    $pdo = db();

    $countStmt = $pdo->query('SELECT COUNT(*) FROM CLIENTESV2');
    $total = (int)$countStmt->fetchColumn();
    $effectivePageSize = $all ? max(1, $total) : $pageSize;
    $offset = $all ? 0 : (($page - 1) * $effectivePageSize);

    $sql = <<<SQL
SELECT
    c.ID AS ID_NUM,
    CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0')) AS CID,
    TRIM(CONCAT(IFNULL(c.NOMBRES, ''), ' ', IFNULL(c.APELLIDOS, ''))) AS NOMBRE_COMPLETO,
    IFNULL(c.TELEFONO, '') AS TELEFONO,
    IFNULL(c.DOMICILIO1, '') AS DOM1,
    IFNULL(c.DOMICILIO2, '') AS DOM2,
    IFNULL(c.PARROQUIA, '') AS PARROQUIA,
    IFNULL(c.CANTON, '') AS CANTON,
    IFNULL(c.PROVINCIA, '') AS PROVINCIA,
    CASE WHEN TRIM(IFNULL(cr.LIMITE_CREDITO, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(cr.LIMITE_CREDITO) AS DECIMAL(12,2)) ELSE 1000.00 END AS LIMITE_CREDITO,
    CASE WHEN TRIM(IFNULL(cr.SALDO_ACTUAL, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(cr.SALDO_ACTUAL) AS DECIMAL(12,2)) ELSE 0.00 END AS SALDO_INICIAL,
    COALESCE((
        SELECT SUM(CASE WHEN TRIM(IFNULL(vt.TOTAL_CREDITO, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(vt.TOTAL_CREDITO) AS DECIMAL(12,2)) ELSE 0.00 END)
        FROM VENTATICKETS vt
        WHERE vt.CLIENTE_ID = CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0'))
    ), 0.00) AS CREDITO_VENTAS,
    COALESCE((
        SELECT SUM(CASE WHEN TRIM(IFNULL(m.MONTO, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(m.MONTO) AS DECIMAL(12,2)) ELSE 0.00 END)
        FROM MOVIMIENTOS m
        WHERE m.CLIENTE_ID = CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0'))
          AND m.TIPO LIKE 'credit_payment%'
    ), 0.00) AS ABONOS,
    (
        SELECT MAX(m2.CUANDO_FUE)
        FROM MOVIMIENTOS m2
        WHERE m2.CLIENTE_ID = CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0'))
          AND m2.TIPO LIKE 'credit_payment%'
    ) AS ULTIMO_ABONO
FROM CLIENTESV2 c
LEFT JOIN CLIENTESV2_CREDITO cr ON cr.CLIENTESV2_ID = c.ID
ORDER BY (CASE WHEN TRIM(IFNULL(cr.SALDO_ACTUAL, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(cr.SALDO_ACTUAL) AS DECIMAL(12,2)) ELSE 0.00 END
       + COALESCE((SELECT SUM(CASE WHEN TRIM(IFNULL(vt.TOTAL_CREDITO, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(vt.TOTAL_CREDITO) AS DECIMAL(12,2)) ELSE 0.00 END) FROM VENTATICKETS vt WHERE vt.CLIENTE_ID = CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0'))), 0.00)
       - COALESCE((SELECT SUM(CASE WHEN TRIM(IFNULL(m.MONTO, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(m.MONTO) AS DECIMAL(12,2)) ELSE 0.00 END) FROM MOVIMIENTOS m WHERE m.CLIENTE_ID = CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0')) AND m.TIPO LIKE 'credit_payment%'), 0.00)
      ) DESC,
      NOMBRE_COMPLETO ASC
SQL;

    if (!$all) {
        $sql .= "\nLIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    if (!$all) {
        $stmt->bindValue(':limit', $effectivePageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    $currentYearMonth = (new DateTimeImmutable('now'))->format('Y-m');
    foreach ($dbRows as $idx => $r) {
        $cid = trim((string)($r['CID'] ?? ''));
        if ($cid === '') {
            continue;
        }

        $name = trim((string)($r['NOMBRE_COMPLETO'] ?? ''));
        if ($name === '') {
            $name = 'Cliente ' . trim((string)($r['ID_NUM'] ?? ''));
        }

        $addressParts = [];
        foreach (['DOM1', 'DOM2', 'PARROQUIA', 'CANTON', 'PROVINCIA'] as $field) {
            $part = trim((string)($r[$field] ?? ''));
            if ($part !== '') {
                $addressParts[] = $part;
            }
        }
        $displayName = $addressParts === [] ? $name : ($name . "\n" . implode(', ', $addressParts));

        $balance = (float)($r['SALDO_INICIAL'] ?? 0) + (float)($r['CREDITO_VENTAS'] ?? 0) - (float)($r['ABONOS'] ?? 0);
        $balance = round(max(0, $balance), 2);

        $lastPayment = '';
        $lastRaw = trim((string)($r['ULTIMO_ABONO'] ?? ''));
        $hasPaymentCurrentMonth = false;
        if ($lastRaw !== '') {
            try {
                $dt = new DateTimeImmutable($lastRaw);
                $lastPayment = 'Ultimo pago: ' . $dt->format('d/m/Y H:i');
                $hasPaymentCurrentMonth = $dt->format('Y-m') === $currentYearMonth;
            } catch (Throwable) {
                $lastPayment = '';
            }
        }

        $scheduleCustomer = $scheduleMap[$cid] ?? ['id' => $cid];
        $schedule = reportScheduleStatus($scheduleCustomer, $balance, $hasPaymentCurrentMonth);
        $creditLimit = round((float)($r['LIMITE_CREDITO'] ?? 1000), 2);
        if ($creditLimit <= 0) {
            $creditLimit = 1000;
        }

        $rows[] = [
            'number' => $offset + $idx + 1,
            'nameAddress' => $displayName,
            'phone' => trim((string)($r['TELEFONO'] ?? '')),
            'creditLimit' => $creditLimit > 0 ? ('$' . number_format($creditLimit, 2, '.', ',')) : 'Sin Limite',
            'balance' => $balance,
            'paymentDate' => $schedule['paymentDate'],
            'paymentStatus' => $schedule['paymentStatus'],
            'isOverdue' => $schedule['isOverdue'],
            'lastPayment' => $lastPayment,
        ];
    }

    $sumSql = <<<SQL
SELECT COALESCE(SUM(GREATEST(
    CASE WHEN TRIM(IFNULL(cr.SALDO_ACTUAL, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(cr.SALDO_ACTUAL) AS DECIMAL(12,2)) ELSE 0.00 END
  + COALESCE((SELECT SUM(CASE WHEN TRIM(IFNULL(vt.TOTAL_CREDITO, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(vt.TOTAL_CREDITO) AS DECIMAL(12,2)) ELSE 0.00 END) FROM VENTATICKETS vt WHERE vt.CLIENTE_ID = CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0'))), 0.00)
  - COALESCE((SELECT SUM(CASE WHEN TRIM(IFNULL(m.MONTO, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(m.MONTO) AS DECIMAL(12,2)) ELSE 0.00 END) FROM MOVIMIENTOS m WHERE m.CLIENTE_ID = CONCAT('c-', LPAD(CAST(c.ID AS UNSIGNED), 3, '0')) AND m.TIPO LIKE 'credit_payment%'), 0.00)
, 0)), 0.00) AS TOTAL_PENDIENTE
FROM CLIENTESV2 c
LEFT JOIN CLIENTESV2_CREDITO cr ON cr.CLIENTESV2_ID = c.ID
SQL;
    $totalPendingDb = (float)($pdo->query($sumSql)->fetchColumn() ?: 0.0);

    return [
        'totalPending' => round($totalPendingDb, 2),
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'pageSize' => $effectivePageSize,
            'total' => $total,
            'totalPages' => $all ? 1 : max(1, (int)ceil($total / max(1, $effectivePageSize))),
        ],
    ];
}

/** @return array{totalPending:float,rows:array<int,array<string,mixed>>,pagination:array<string,int>} */
function creditReportFetchPagedLegacy(int $page, int $pageSize, bool $all = false): array
{
    $customers = readJsonFile(storagePath('customers.json'));
    $ledgers = buildCreditLedgers();
    $rows = [];
    $totalPending = 0.0;
    $number = 0;
    $today = new DateTimeImmutable('now');
    $currentYearMonth = $today->format('Y-m');
    $currentDay = (int)$today->format('d');
    $todayDate = $today->format('Y-m-d');

    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }

        $id = (string)($customer['id'] ?? '');
        if ($id === '') {
            continue;
        }

        $number++;
        $ledger = $ledgers[$id] ?? null;
        $name = creditCustomerName($customer);
        $address = creditCustomerAddress($customer);
        $displayName = $address !== '' ? ($name . "\n" . $address) : $name;
        $balance = (float)($ledger['summary']['balance'] ?? 0);
        $creditLimit = (float)($ledger['client']['limit'] ?? ($customer['creditLimit'] ?? 1000));
        $lastPayment = (string)($ledger['summary']['lastPaymentText'] ?? '');
        $paymentDueDateRaw = trim((string)($customer['paymentDueDate'] ?? ''));
        $paymentDueDate = null;
        if ($paymentDueDateRaw !== '') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $paymentDueDateRaw);
            if ($dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $paymentDueDateRaw) {
                $paymentDueDate = $dt;
            }
        }
        $paymentDueDayRaw = $customer['paymentDueDay'] ?? null;
        $paymentDueDay = is_numeric($paymentDueDayRaw) ? (int)$paymentDueDayRaw : null;
        if ($paymentDueDay !== null && ($paymentDueDay < 1 || $paymentDueDay > 31)) {
            $paymentDueDay = null;
        }

        $hasPaymentCurrentMonth = false;
        foreach (($ledger['movements'] ?? []) as $movement) {
            if (!is_array($movement)) {
                continue;
            }
            if (strtoupper((string)($movement['movimiento'] ?? '')) !== 'LIQUIDAR') {
                continue;
            }
            $createdAt = (string)($movement['createdAt'] ?? '');
            if (strlen($createdAt) < 7 || substr($createdAt, 0, 7) !== $currentYearMonth) {
                continue;
            }
            $hasPaymentCurrentMonth = true;
            break;
        }

        $isOverdue = false;
        $paymentStatus = '';
        if ($paymentDueDate instanceof DateTimeImmutable) {
            $dueDateStr = $paymentDueDate->format('Y-m-d');
            if ($balance > 0 && strcmp($todayDate, $dueDateStr) > 0) {
                $isOverdue = true;
                $paymentStatus = 'Vencido';
            } elseif ($balance <= 0) {
                $paymentStatus = 'Liquidado';
            } else {
                $paymentStatus = 'Pendiente';
            }
        } elseif ($paymentDueDay !== null) {
            if ($hasPaymentCurrentMonth) {
                $paymentStatus = 'Abonado este mes';
            } else {
                $effectiveDueDay = min($paymentDueDay, (int)$today->format('t'));
                if ($balance > 0 && $currentDay > $effectiveDueDay) {
                    $isOverdue = true;
                    $paymentStatus = 'Vencido';
                } else {
                    $paymentStatus = 'Pendiente del mes';
                }
            }
        }

        $paymentDateLabel = 'No definido';
        if ($paymentDueDate instanceof DateTimeImmutable) {
            $paymentDateLabel = $paymentDueDate->format('d/m/Y');
        } elseif ($paymentDueDay !== null) {
            $paymentDateLabel = 'Dia ' . str_pad((string)$paymentDueDay, 2, '0', STR_PAD_LEFT);
        }

        $totalPending += $balance;

        $rows[] = [
            'number' => $number,
            'nameAddress' => $displayName,
            'phone' => (string)($customer['phone'] ?? ''),
            'creditLimit' => $creditLimit > 0 ? ('$' . number_format($creditLimit, 2, '.', ',')) : 'Sin Limite',
            'balance' => $balance,
            'paymentDate' => $paymentDateLabel,
            'paymentStatus' => $paymentStatus,
            'isOverdue' => $isOverdue,
            'lastPayment' => $lastPayment,
        ];
    }

    usort($rows, static function ($a, $b) {
        $balanceDiff = (float)($b['balance'] ?? 0) <=> (float)($a['balance'] ?? 0);
        if ($balanceDiff !== 0) {
            return $balanceDiff;
        }

        return strcmp((string)($a['nameAddress'] ?? ''), (string)($b['nameAddress'] ?? ''));
    });

    foreach ($rows as $index => &$row) {
        $row['number'] = $index + 1;
    }
    unset($row);

    $total = count($rows);
    $effectivePageSize = $all ? max(1, $total) : $pageSize;
    $offset = $all ? 0 : (($page - 1) * $effectivePageSize);
    $items = $all ? $rows : array_slice($rows, $offset, $effectivePageSize);

    return [
        'totalPending' => round($totalPending, 2),
        'rows' => $items,
        'pagination' => [
            'page' => $page,
            'pageSize' => $effectivePageSize,
            'total' => $total,
            'totalPages' => $all ? 1 : max(1, (int)ceil($total / max(1, $effectivePageSize))),
        ],
    ];
}

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 15)));
$loadAll = in_array(strtolower((string)($_GET['all'] ?? '0')), ['1', 'true', 'yes', 'si'], true);

if (canUseCreditsReportDb()) {
    $scheduleMap = customersScheduleMap(readJsonFile($customersPath));
    ok(creditReportFetchPagedDb($page, $pageSize, $scheduleMap, $loadAll));
}

ok(creditReportFetchPagedLegacy($page, $pageSize, $loadAll));
