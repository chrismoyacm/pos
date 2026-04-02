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

$q = strtolower(trim((string)($_GET['q'] ?? '')));
$customers = readJsonFile($customersPath);
$ledgers = buildCreditLedgers();
$rows = [];

foreach ($customers as $customer) {
    if (!is_array($customer)) {
        continue;
    }

    $id = (string)($customer['id'] ?? '');
    if ($id === '') {
        continue;
    }

    $ledger = $ledgers[$id] ?? null;
    $rows[] = [
        'id' => $id,
        'name' => creditCustomerName($customer),
        'balance' => (float)($ledger['summary']['balance'] ?? 0),
    ];
}

if ($q !== '') {
    $rows = array_values(array_filter($rows, static function ($customer) use ($q) {
        $id = strtolower((string)($customer['id'] ?? ''));
        $name = strtolower((string)($customer['name'] ?? ''));
        return $id === $q || str_contains($id, $q) || str_contains($name, $q);
    }));
}

usort($rows, static function ($a, $b) {
    $balanceDiff = (float)($b['balance'] ?? 0) <=> (float)($a['balance'] ?? 0);
    if ($balanceDiff !== 0) {
        return $balanceDiff;
    }

    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

ok(array_slice($rows, 0, 80));
