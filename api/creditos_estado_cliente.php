<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/credit_ledger.php';

function buildSafeDate(int $year, int $month, int $day): DateTimeImmutable
{
    $month = max(1, min(12, $month));
    $day = max(1, min(31, $day));
    $lastDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $safeDay = min($day, $lastDay);
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $safeDay));
}

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
    errorResponse('Metodo no soportado', 405);
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
        errorResponse('Cuerpo invalido', 400);
    }

    $action = strtolower(trim((string)($body['action'] ?? '')));
    if (!in_array($action, ['abonar', 'liquidar'], true)) {
        errorResponse('Accion no soportada', 400);
    }

    $ledgers = buildCreditLedgers();
    $ledger = $ledgers[$cid] ?? null;
    $balance = round((float)($ledger['summary']['balance'] ?? 0), 2);
    if ($balance <= 0) {
        errorResponse('El cliente no tiene deuda pendiente', 409);
    }

    $amount = round((float)($body['amount'] ?? 0), 2);
    if ($amount <= 0) {
        errorResponse('Monto invalido', 400);
    }

    if ($action === 'abonar' && $amount >= $balance) {
        errorResponse('El abono debe ser menor que la deuda total. Use Liquidar.', 400);
    }

    $allocations = [];
    if ($action === 'abonar') {
        $destination = strtolower(trim((string)($body['destination'] ?? 'selected_only')));
        if (!in_array($destination, ['selected_only', 'all_equal'], true)) {
            $destination = 'selected_only';
        }
        $selectedSaleKey = trim((string)($body['selectedSaleKey'] ?? ''));
        if ($destination === 'selected_only' && $selectedSaleKey === '') {
            errorResponse('Seleccione la venta a la que desea abonar.', 400);
        }

        $openSales = array_values(array_filter(
            is_array($ledger['openSales'] ?? null) ? $ledger['openSales'] : [],
            static function ($sale): bool {
                return is_array($sale) && (float)($sale['pending'] ?? 0) > 0;
            }
        ));

        if ($destination === 'all_equal') {
            $allocations = creditPlanEqualPayment($openSales, $amount);
            if ($allocations === []) {
                errorResponse('No hay ventas pendientes a las que se pueda aplicar el abono.', 409);
            }
        } else {
            $preview = creditPreviewTargetedPayment($openSales, $selectedSaleKey, $amount);
            $allocations = $preview['allocations'];

            if ($allocations === []) {
                errorResponse('La venta seleccionada ya no tiene saldo pendiente.', 409);
            }

            $overflowAmount = (float)($preview['overflowAmount'] ?? 0);
            if ($overflowAmount > 0.009) {
                errorResponse('El monto supera el pendiente de la venta seleccionada. Cambie el destino o reduzca el abono.', 409, [
                    'selectedSaleKey' => $selectedSaleKey,
                    'selectedPending' => (float)($preview['selectedPending'] ?? 0),
                    'overflowAmount' => $overflowAmount,
                ]);
            }
        }
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
        'description' => trim((string)($body['description'] ?? ($action === 'liquidar' ? 'Liquidacion de deuda' : 'Abono a deuda'))),
        'cashier' => (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Cajero'),
        'createdAt' => date('c'),
        'allocations' => $allocations,
    ];
    $payments[] = $payment;
    writeJsonFile($creditPaymentsPath, $payments);

    $nextPaymentDueDate = null;
    $nextPaymentDueDay = null;
    foreach ($customers as &$customer) {
        if (!is_array($customer)) {
            continue;
        }
        if ((string)($customer['id'] ?? '') !== $cid) {
            continue;
        }
        $customer['paymentDueDate'] = nextDueDateForCustomer($customer);
        $dueDate = trim((string)($customer['paymentDueDate'] ?? ''));
        $customer['paymentDueDay'] = $dueDate !== '' ? (int)substr($dueDate, 8, 2) : ($customer['paymentDueDay'] ?? null);
        $nextPaymentDueDate = $dueDate !== '' ? $dueDate : null;
        $nextPaymentDueDay = isset($customer['paymentDueDay']) && is_numeric($customer['paymentDueDay']) ? (int)$customer['paymentDueDay'] : null;
        break;
    }
    unset($customer);

    $customersPersisted = false;
    if (dbEnabled() && function_exists('legacyUpdateCustomerCreditSchedule')) {
        $customersPersisted = legacyUpdateCustomerCreditSchedule($cid, $nextPaymentDueDate, $nextPaymentDueDay);
    }
    if (!$customersPersisted && function_exists('legacyWriteEntityOverlay')) {
        $customersPersisted = legacyWriteEntityOverlay('customers.json', $customers);
    }
    if (!$customersPersisted) {
        $customersPersisted = writeJsonBackupFile($customersPath, $customers);
    }
    if (!$customersPersisted) {
        errorResponse('No se pudo guardar la fecha de pago del cliente', 500);
    }

    if (dbEnabled()) {
        writeJsonBackupFile($customersPath, legacyReadCustomers());
    }

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
    'openSales' => array_values(is_array($ledger['openSales'] ?? null) ? $ledger['openSales'] : []),
    'movimientos' => $movements,
]);
