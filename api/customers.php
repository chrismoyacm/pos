<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

$customersPath = storagePath('customers.json');
$request = getRequestInfo();

function normalizeCustomer(array $c): array
{
    $first = trim((string)($c['firstName'] ?? ''));
    $last = trim((string)($c['lastName'] ?? ''));
    $name = trim((string)($c['name'] ?? ''));
    if ($name === '') {
        $name = trim($first . ' ' . $last);
    }
    $province = (string)($c['province'] ?? ($c['state'] ?? ''));
    $canton = (string)($c['canton'] ?? ($c['city'] ?? ''));
    $parish = (string)($c['parish'] ?? ($c['colonia'] ?? ''));
    $paymentDueDayRaw = $c['paymentDueDay'] ?? null;
    $paymentDueDay = null;
    if ($paymentDueDayRaw !== null && $paymentDueDayRaw !== '') {
        $candidate = (int)$paymentDueDayRaw;
        if ($candidate >= 1 && $candidate <= 31) {
            $paymentDueDay = $candidate;
        }
    }
    return [
        'id' => (string)($c['id'] ?? ''),
        'name' => $name,
        'firstName' => $first,
        'lastName' => $last,
        'phone' => (string)($c['phone'] ?? ''),
        'email' => (string)($c['email'] ?? ''),
        'address1' => (string)($c['address1'] ?? ''),
        'address2' => (string)($c['address2'] ?? ''),
        'province' => trim($province),
        'canton' => trim($canton),
        'parish' => trim($parish),
        'zip' => (string)($c['zip'] ?? ''),
        'notes' => (string)($c['notes'] ?? ''),
        'creditAuthorized' => (bool)($c['creditAuthorized'] ?? false),
        'paymentDueDay' => $paymentDueDay,
    ];
}

function nextCustomerId(array $customers): string
{
    $max = 0;
    foreach ($customers as $c) {
        $id = (string)($c['id'] ?? '');
        if (preg_match('/^c-(\d+)$/i', $id, $m) === 1) {
            $n = (int)$m[1];
            if ($n > $max) {
                $max = $n;
            }
        }
    }
    $next = $max + 1;
    return 'c-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

$method = strtoupper((string)($request['method'] ?? 'GET'));

if ($method === 'GET') {
    $q = strtolower(trim((string)($_GET['q'] ?? '')));
    $customers = readJsonFile($customersPath);

    if ($q === '') {
        ok(array_slice($customers, 0, 50));
    }

    $filtered = array_values(array_filter($customers, function ($c) use ($q) {
        $name = strtolower((string)($c['name'] ?? ''));
        $first = strtolower((string)($c['firstName'] ?? ''));
        $last = strtolower((string)($c['lastName'] ?? ''));
        $id = strtolower((string)($c['id'] ?? ''));
        $province = strtolower((string)($c['province'] ?? ''));
        $canton = strtolower((string)($c['canton'] ?? ''));
        $parish = strtolower((string)($c['parish'] ?? ''));
        return $id === $q
            || str_contains($id, $q)
            || str_contains($name, $q)
            || str_contains($first, $q)
            || str_contains($last, $q)
            || str_contains($province, $q)
            || str_contains($canton, $q)
            || str_contains($parish, $q);
    }));
    ok($filtered);
}

if ($method === 'POST') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inválido', 400);
    }
    $customers = readJsonFile($customersPath);
    $incoming = normalizeCustomer($body);
    $incoming['id'] = nextCustomerId($customers);

    if (trim($incoming['name']) === '') {
        errorResponse('Nombre requerido', 400);
    }
    $customers[] = $incoming;
    writeJsonFile($customersPath, $customers);
    ok($incoming);
}

if ($method === 'PATCH') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inválido', 400);
    }
    $id = (string)($body['id'] ?? '');
    if ($id === '') {
        errorResponse('ID requerido', 400);
    }
    $customers = readJsonFile($customersPath);
    $updated = normalizeCustomer($body);
    $updated['id'] = $id;
    if (trim($updated['name']) === '') {
        errorResponse('Nombre requerido', 400);
    }
    $found = false;
    foreach ($customers as &$c) {
        if ((string)($c['id'] ?? '') === $id) {
            $c = $updated;
            $found = true;
            break;
        }
    }
    unset($c);
    if (!$found) {
        errorResponse('Cliente no encontrado', 404);
    }
    writeJsonFile($customersPath, $customers);
    ok($updated);
}

if ($method === 'DELETE') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo inválido', 400);
    }
    $id = (string)($body['id'] ?? '');
    if ($id === '') {
        errorResponse('ID requerido', 400);
    }
    $customers = readJsonFile($customersPath);
    $before = count($customers);
    $customers = array_values(array_filter($customers, function ($c) use ($id) {
        return (string)($c['id'] ?? '') !== $id;
    }));
    if (count($customers) === $before) {
        errorResponse('Cliente no encontrado', 404);
    }
    writeJsonFile($customersPath, $customers);
    ok(['id' => $id]);
}

errorResponse('Método no soportado', 405);

