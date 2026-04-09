<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/credit_ledger.php';

/**
 * Build a valid date clamped to the last day of month when needed.
 */
function buildSafeDate(int $year, int $month, int $day): DateTimeImmutable
{
    $month = max(1, min(12, $month));
    $day = max(1, min(31, $day));
    $lastDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $safeDay = min($day, $lastDay);
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $safeDay));
}

/**
 * Calculate next due date using existing customer schedule.
 *
 * Priority:
 * 1) Existing paymentDueDate day-of-month
 * 2) paymentDueDay recurring day
 * 3) Current day as fallback
 */
function nextDueDateForCustomer(array $customer): string
{
    $today = new DateTimeImmutable('today');

    $paymentDueDateRaw = trim((string)($customer['paymentDueDate'] ?? ''));
    $paymentDueDate = null;
    if ($paymentDueDateRaw !== '') {
        $tmp = DateTimeImmutable::createFromFormat('Y-m-d', $paymentDueDateRaw);
        if ($tmp instanceof DateTimeImmutable && $tmp->format('Y-m-d') === $paymentDueDateRaw) {
            $paymentDueDate = $tmp;
        }
    }

    if ($paymentDueDate instanceof DateTimeImmutable) {
        $year = (int)$paymentDueDate->format('Y');
        $month = (int)$paymentDueDate->format('m') + 1;
        if ($month > 12) {
            $month = 1;
            $year += 1;
        }
        $day = (int)$paymentDueDate->format('d');
        return buildSafeDate($year, $month, $day)->format('Y-m-d');
    }

    $paymentDueDayRaw = $customer['paymentDueDay'] ?? null;
    $paymentDueDay = is_numeric($paymentDueDayRaw) ? (int)$paymentDueDayRaw : null;
    if ($paymentDueDay !== null && $paymentDueDay >= 1 && $paymentDueDay <= 31) {
        $year = (int)$today->format('Y');
        $month = (int)$today->format('m');
        $thisMonthDue = buildSafeDate($year, $month, $paymentDueDay);

        $nextYear = (int)$thisMonthDue->format('Y');
        $nextMonth = (int)$thisMonthDue->format('m') + 1;
        if ($nextMonth > 12) {
            $nextMonth = 1;
            $nextYear += 1;
        }

        return buildSafeDate($nextYear, $nextMonth, $paymentDueDay)->format('Y-m-d');
    }

    $fallbackYear = (int)$today->format('Y');
    $fallbackMonth = (int)$today->format('m') + 1;
    if ($fallbackMonth > 12) {
        $fallbackMonth = 1;
        $fallbackYear += 1;
    }
    $fallbackDay = (int)$today->format('d');
    return buildSafeDate($fallbackYear, $fallbackMonth, $fallbackDay)->format('Y-m-d');
}

$customersPath = storagePath('customers.json');
$creditPaymentsPath = storagePath('credit_payments.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

if (!in_array($method, ['GET', 'POST'], true)) {
    errorResponse('Método no soportado', 405);
}

$cid = trim((string)($_GET['cid'] ?? ''));
$customers = readJsonFile($customersPath);
$client = null;

foreach ($customers as $customer) {
    if (!is_array($customer)) {
        continue;
    }
    if ((string)($customer['id'] ?? '') === $cid) {
        $client = $customer;
        break;
    }
}

if ($client === null) {
    errorResponse('Cliente no encontrado', 404);
}

if ($method === 'POST') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inválido', 400);
    }

    $action = strtolower(trim((string)($body['action'] ?? '')));
    if (!in_array($action, ['abonar', 'liquidar'], true)) {
        errorResponse('Acción no soportada', 400);
    }

    $ledgers = buildCreditLedgers();
    $ledger = $ledgers[$cid] ?? null;
    $balance = round((float)($ledger['summary']['balance'] ?? 0), 2);
    if ($balance <= 0) {
        errorResponse('El cliente no tiene deuda pendiente', 409);
    }

    $amount = round((float)($body['amount'] ?? 0), 2);
    if ($amount <= 0) {
        errorResponse('Monto inválido', 400);
    }

    if ($action === 'abonar' && $amount >= $balance) {
        errorResponse('El abono debe ser menor que la deuda total. Use Liquidar.', 400);
    }

    if ($action === 'liquidar') {
        if (abs($amount - $balance) > 0.009) {
            errorResponse('En liquidar, el monto debe ser igual a la deuda total', 400, ['balance' => $balance]);
        }
        $amount = $balance;
    }

    $payments = readJsonFile($creditPaymentsPath);
    $next = count($payments) + 1;
    $payment = [
        'id' => 'cp-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT),
        'folio' => 'P-' . str_pad((string)$next, 6, '0', STR_PAD_LEFT),
        'customerId' => $cid,
        'amount' => $amount,
        'paymentMethod' => 'cash',
        'description' => trim((string)($body['description'] ?? ($action === 'liquidar' ? 'Liquidación de deuda' : 'Abono a deuda'))),
        'cashier' => (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Cajero'),
        'createdAt' => date('c'),
    ];
    $payments[] = $payment;
    writeJsonFile($creditPaymentsPath, $payments);

    // Move due date to the next month after any registered payment.
    foreach ($customers as &$customer) {
        if (!is_array($customer)) {
            continue;
        }
        if ((string)($customer['id'] ?? '') !== $cid) {
            continue;
        }
        $customer['paymentDueDate'] = nextDueDateForCustomer($customer);
        break;
    }
    unset($customer);
    writeJsonFile($customersPath, $customers);

    $freshLedgers = buildCreditLedgers();
    $freshBalance = round((float)($freshLedgers[$cid]['summary']['balance'] ?? 0), 2);

    ok([
        'payment' => $payment,
        'balance' => $freshBalance,
    ]);
}

$ledgers = buildCreditLedgers();
$ledger = $ledgers[$cid] ?? null;
$clientName = creditCustomerName($client);
$limit = (float)($ledger['client']['limit'] ?? ($client['creditLimit'] ?? 1000));
$balance = (float)($ledger['summary']['balance'] ?? 0);
$movements = is_array($ledger['movements'] ?? null) ? $ledger['movements'] : [];
$lastPaymentText = (string)($ledger['summary']['lastPaymentText'] ?? '');
$totalMovements = (float)($ledger['summary']['movementsTotal'] ?? 0);
$selectedTicket = is_array($movements[0]['ticket'] ?? null) ? $movements[0]['ticket'] : [];

ok([
    'client' => [
        'id' => (string)($client['id'] ?? $cid),
        'name' => $clientName,
        'limit' => $limit,
    ],
    'summary' => [
        'totalMovimientos' => round($totalMovements, 2),
        'pagoTotal' => round((float)($selectedTicket['total'] ?? 0), 2),
        'pendiente' => round((float)($selectedTicket['montoPendiente'] ?? $balance), 2),
        'ultimoPago' => $lastPaymentText,
        'saldoActual' => round($balance, 2),
    ],
    'movimientos' => $movements,
]);
