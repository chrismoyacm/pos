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

$customers = readJsonFile($customersPath);
$ledgers = buildCreditLedgers();
$rows = [];
$totalPending = 0.0;
$number = 0;

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
    $totalPending += $balance;

    $rows[] = [
        'number' => $number,
        'nameAddress' => $displayName,
        'phone' => (string)($customer['phone'] ?? ''),
        'creditLimit' => $creditLimit > 0 ? ('$' . number_format($creditLimit, 2, '.', ',')) : 'Sin Límite',
        'balance' => $balance,
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

ok([
    'totalPending' => round($totalPending, 2),
    'rows' => $rows,
]);
