<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/credit_ledger.php';

$customersPath = storagePath('customers.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

if ($method !== 'GET') {
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
