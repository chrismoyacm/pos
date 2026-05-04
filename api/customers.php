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
    $paymentDueDateRaw = trim((string)($c['paymentDueDate'] ?? ''));
    $paymentDueDate = null;
    $paymentDueDay = null;

    if ($paymentDueDateRaw !== '') {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $paymentDueDateRaw);
        if ($dt instanceof \DateTimeImmutable && $dt->format('Y-m-d') === $paymentDueDateRaw) {
            $paymentDueDate = $paymentDueDateRaw;
            $paymentDueDay = (int)$dt->format('j');
        }
    }

    if ($paymentDueDay === null) {
        $paymentDueDayRaw = $c['paymentDueDay'] ?? null;
        if ($paymentDueDayRaw !== null && $paymentDueDayRaw !== '') {
            $candidate = (int)$paymentDueDayRaw;
            if ($candidate >= 1 && $candidate <= 31) {
                $paymentDueDay = $candidate;
            }
        }
    }
    $taxIdRaw = trim((string)($c['taxId'] ?? ($c['identification'] ?? '')));
    $taxId = preg_replace('/\D+/', '', $taxIdRaw) ?? '';

    return [
        'id' => (string)($c['id'] ?? ''),
        'name' => $name,
        'firstName' => $first,
        'lastName' => $last,
        'taxId' => $taxId,
        'identification' => $taxId,
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
        'creditLimit' => (float)($c['creditLimit'] ?? ($c['limit'] ?? 0)),
        'creditBalance' => (float)($c['creditBalance'] ?? 0),
        'lastCreditPaymentAt' => (string)($c['lastCreditPaymentAt'] ?? ''),
        'paymentDueDate' => $paymentDueDate,
        'paymentDueDay' => $paymentDueDay,
    ];
}

function validateCustomerTaxIdOrFail(array $customer): void
{
    $taxId = trim((string)($customer['taxId'] ?? ($customer['identification'] ?? '')));
    if ($taxId === '') {
        return;
    }

    $isValidProvinceCode = static function (string $code): bool {
        $n = (int)$code;
        return $n >= 1 && $n <= 24;
    };

    $modulo10Check = static function (string $digits10): bool {
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $n = (int)$digits10[$i];
            if ($i % 2 === 0) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
        }
        $verifier = (10 - ($sum % 10)) % 10;
        return $verifier === (int)$digits10[9];
    };

    $modulo11Verifier = static function (string $base, array $coeffs, string $verifierDigit): bool {
        $sum = 0;
        foreach ($coeffs as $i => $coeff) {
            $sum += ((int)$base[$i]) * $coeff;
        }
        $mod = 11 - ($sum % 11);
        $expected = $mod === 11 ? 0 : ($mod === 10 ? 0 : $mod);
        return $expected === (int)$verifierDigit;
    };

    $validateCedula = static function (string $digits10) use ($isValidProvinceCode, $modulo10Check): bool {
        if (strlen($digits10) !== 10) {
            return false;
        }
        if (!$isValidProvinceCode(substr($digits10, 0, 2))) {
            return false;
        }
        $third = (int)$digits10[2];
        if ($third < 0 || $third > 5) {
            return false;
        }
        return $modulo10Check($digits10);
    };

    $validateRuc = static function (string $digits13) use ($isValidProvinceCode, $modulo11Verifier, $validateCedula): bool {
        if (strlen($digits13) !== 13) {
            return false;
        }
        if ($digits13 === '9999999999999') {
            return true;
        }
        if (!$isValidProvinceCode(substr($digits13, 0, 2))) {
            return false;
        }

        $third = (int)$digits13[2];
        $estab = substr($digits13, 10, 3);
        if ($estab === '000') {
            return false;
        }

        if ($third >= 0 && $third <= 5) {
            return $validateCedula(substr($digits13, 0, 10));
        }
        if ($third === 6) {
            if (!$modulo11Verifier(substr($digits13, 0, 8), [3, 2, 7, 6, 5, 4, 3, 2], $digits13[8])) {
                return false;
            }
            return substr($digits13, 9, 4) !== '0000';
        }
        if ($third === 9) {
            if (!$modulo11Verifier(substr($digits13, 0, 9), [4, 3, 2, 7, 6, 5, 4, 3, 2], $digits13[9])) {
                return false;
            }
            return $estab !== '000';
        }
        return false;
    };

    $len = strlen($taxId);
    if ($len === 10) {
        if (!$validateCedula($taxId)) {
            errorResponse('Cedula invalida. Revise provincia, tercer digito y digito verificador.', 400);
        }
        return;
    }
    if ($len === 13) {
        if (!$validateRuc($taxId)) {
            errorResponse('RUC invalido. Revise tipo de contribuyente, establecimiento y digito verificador.', 400);
        }
        return;
    }

    errorResponse('La identificacion debe tener 10 digitos (cedula) o 13 digitos (RUC).', 400);
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
        $taxId = strtolower((string)($c['taxId'] ?? ($c['identification'] ?? '')));
        $province = strtolower((string)($c['province'] ?? ''));
        $canton = strtolower((string)($c['canton'] ?? ''));
        $parish = strtolower((string)($c['parish'] ?? ''));
        return $id === $q
            || str_contains($id, $q)
            || str_contains($name, $q)
            || str_contains($first, $q)
            || str_contains($last, $q)
            || str_contains($taxId, $q)
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
    $action = strtolower(trim((string)($body['action'] ?? 'create')));

    if ($action === 'update') {
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
        validateCustomerTaxIdOrFail($updated);
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

    if ($action === 'delete') {
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

    $customers = readJsonFile($customersPath);
    $incoming = normalizeCustomer($body);
    $incoming['id'] = nextCustomerId($customers);

    if (trim($incoming['name']) === '') {
        errorResponse('Nombre requerido', 400);
    }
    validateCustomerTaxIdOrFail($incoming);
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
    validateCustomerTaxIdOrFail($updated);
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

