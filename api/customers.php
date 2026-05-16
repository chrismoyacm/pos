<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/persistence.php';

$customersPath = storagePath('customers.json');
$request = getRequestInfo();

function parseCustomerDbIdFromJsonId(string $id): ?int
{
    $raw = trim($id);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^c-(\d+)$/i', $raw, $m) === 1) {
        return (int)$m[1];
    }
    if (preg_match('/^\d+$/', $raw) === 1) {
        return (int)$raw;
    }
    return null;
}

function syncCustomersBackupFromDb(string $customersPath): void
{
    writeJsonBackupFile($customersPath, legacyReadCustomers());
}

function nextCustomerDbId(): int
{
    return (int)db()->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) + 1 FROM CLIENTESV2')->fetchColumn();
}

/**
 * @param array<string, mixed> $customer
 * @return array<string, mixed>
 */
function upsertCustomerInDb(array $customer, ?int $forcedId = null): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $id = $forcedId ?? parseCustomerDbIdFromJsonId((string)($customer['id'] ?? ''));
        if ($id === null || $id <= 0) {
            $id = nextCustomerDbId();
        }

        $fullName = trim((string)($customer['name'] ?? ''));
        $first = trim((string)($customer['firstName'] ?? ''));
        $last = trim((string)($customer['lastName'] ?? ''));
        if ($first === '' && $last === '' && $fullName !== '') {
            $parts = preg_split('/\s+/', $fullName, 2) ?: [];
            $first = trim((string)($parts[0] ?? ''));
            $last = trim((string)($parts[1] ?? ''));
        }

        $params = [
            ':id' => $id,
            ':folio' => (string)$id,
            ':nombres' => $first,
            ':apellidos' => $last,
            ':identificacion' => trim((string)($customer['taxId'] ?? ($customer['identification'] ?? ''))),
            ':email' => trim((string)($customer['email'] ?? '')),
            ':telefono' => trim((string)($customer['phone'] ?? '')),
            ':dom1' => trim((string)($customer['address1'] ?? '')),
            ':dom2' => trim((string)($customer['address2'] ?? '')),
            ':parroquia' => trim((string)($customer['parish'] ?? '')),
            ':canton' => trim((string)($customer['canton'] ?? '')),
            ':provincia' => trim((string)($customer['province'] ?? '')),
            ':cp' => trim((string)($customer['zip'] ?? '')),
            ':notas' => trim((string)($customer['notes'] ?? '')),
        ];

        $exists = $pdo->prepare('SELECT COUNT(*) FROM CLIENTESV2 WHERE ID = :id');
        $exists->execute([':id' => $id]);
        if ((int)$exists->fetchColumn() > 0) {
            $pdo->prepare(
                'UPDATE CLIENTESV2
                 SET NOMBRES = :nombres, APELLIDOS = :apellidos, IDENTIFICACION = :identificacion, EMAIL = :email, TELEFONO = :telefono,
                     DOMICILIO1 = :dom1, DOMICILIO2 = :dom2, PARROQUIA = :parroquia, CANTON = :canton,
                     PROVINCIA = :provincia, CODIGO_POSTAL = :cp, NOTAS = :notas, ACTIVO = 1
                 WHERE ID = :id'
            )->execute($params);
        } else {
            $pdo->prepare(
                "INSERT INTO CLIENTESV2
                 (ID, FOLIO, NOMBRES, APELLIDOS, IDENTIFICACION, EMAIL, TELEFONO, DOMICILIO1, DOMICILIO2,
                  PARROQUIA, CANTON, PROVINCIA, CODIGO_POSTAL, NOTAS, TOTAL_VENTAS, TOTAL_GANANCIAS, TOTAL_TICKETS,
                  ACTIVO, DE_SISTEMA, OLD_CLIENTE_ID, OLD_FACTURACION_CLIENTES_ID)
                 VALUES
                 (:id, :folio, :nombres, :apellidos, :identificacion, :email, :telefono, :dom1, :dom2,
                  :parroquia, :canton, :provincia, :cp, :notas, 0, 0, 0, 1, '', '', '')"
            )->execute($params);
        }

        if (legacyTableExists('CLIENTESV2_CREDITO')) {
            $hasCredit = !empty($customer['creditAuthorized']) ? 1 : 0;
            $existsCredit = $pdo->prepare('SELECT COUNT(*) FROM CLIENTESV2_CREDITO WHERE CLIENTESV2_ID = :id');
            $existsCredit->execute([':id' => $id]);
            if ((int)$existsCredit->fetchColumn() > 0) {
                $pdo->prepare(
                    'UPDATE CLIENTESV2_CREDITO
                     SET TIENE_CREDITO = :tiene_credito, ELIMINADO_EN = ""
                     WHERE CLIENTESV2_ID = :id'
                )->execute([
                    ':id' => $id,
                    ':tiene_credito' => $hasCredit,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO CLIENTESV2_CREDITO (CLIENTESV2_ID, TIENE_CREDITO, LIMITE_CREDITO, ULTIMO_ABONO, SALDO_ACTUAL, ELIMINADO_EN)
                     VALUES (:id, :tiene_credito, 0, "", "0", "")'
                )->execute([
                    ':id' => $id,
                    ':tiene_credito' => $hasCredit,
                ]);
            }
        }

        $pdo->commit();
        $customer['id'] = 'c-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
        return $customer;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function deleteCustomerInDb(string $id): bool
{
    $dbId = parseCustomerDbIdFromJsonId($id);
    if ($dbId === null || $dbId <= 0) {
        return false;
    }

    $stmt = db()->prepare('UPDATE CLIENTESV2 SET ACTIVO = 0 WHERE ID = :id');
    $stmt->execute([':id' => $dbId]);
    return $stmt->rowCount() > 0;
}

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
        $updated = normalizeCustomer($body);
        $updated['id'] = $id;
        if (trim($updated['name']) === '') {
            errorResponse('Nombre requerido', 400);
        }
        validateCustomerTaxIdOrFail($updated);
        if (dbEnabled()) {
            try {
                $dbId = parseCustomerDbIdFromJsonId($id);
                if ($dbId === null || $dbId <= 0) {
                    errorResponse('Cliente no encontrado', 404);
                }
                $updated = upsertCustomerInDb($updated, $dbId);
                persistenceMarkDbHealthy('customers_update');
                syncCustomersBackupFromDb($customersPath);
                ok($updated);
            } catch (Throwable) {
                persistenceMarkDbFallback('customers_update');
            }
        }
        $customers = readJsonFile($customersPath);
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
        persistenceMarkDbFallback('customers_update');
        ok($updated);
    }

    if ($action === 'delete') {
        $id = (string)($body['id'] ?? '');
        if ($id === '') {
            errorResponse('ID requerido', 400);
        }
        if (dbEnabled()) {
            try {
                if (!deleteCustomerInDb($id)) {
                    errorResponse('Cliente no encontrado', 404);
                }
                persistenceMarkDbHealthy('customers_delete');
                syncCustomersBackupFromDb($customersPath);
                ok(['id' => $id]);
            } catch (Throwable) {
                persistenceMarkDbFallback('customers_delete');
            }
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
        persistenceMarkDbFallback('customers_delete');
        ok(['id' => $id]);
    }

    $incoming = normalizeCustomer($body);

    if (trim($incoming['name']) === '') {
        errorResponse('Nombre requerido', 400);
    }
    validateCustomerTaxIdOrFail($incoming);
    if (dbEnabled()) {
        try {
            $incoming = upsertCustomerInDb($incoming, null);
            persistenceMarkDbHealthy('customers_create');
            syncCustomersBackupFromDb($customersPath);
            ok($incoming);
        } catch (Throwable) {
            persistenceMarkDbFallback('customers_create');
        }
    }
    $customers = readJsonFile($customersPath);
    $incoming['id'] = nextCustomerId($customers);
    $customers[] = $incoming;
    writeJsonFile($customersPath, $customers);
    persistenceMarkDbFallback('customers_create');
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
    $updated = normalizeCustomer($body);
    $updated['id'] = $id;
    if (trim($updated['name']) === '') {
        errorResponse('Nombre requerido', 400);
    }
    validateCustomerTaxIdOrFail($updated);
    if (dbEnabled()) {
        try {
            $dbId = parseCustomerDbIdFromJsonId($id);
            if ($dbId === null || $dbId <= 0) {
                errorResponse('Cliente no encontrado', 404);
            }
            $updated = upsertCustomerInDb($updated, $dbId);
            persistenceMarkDbHealthy('customers_update');
            syncCustomersBackupFromDb($customersPath);
            ok($updated);
        } catch (Throwable) {
            persistenceMarkDbFallback('customers_update');
        }
    }
    $customers = readJsonFile($customersPath);
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
    persistenceMarkDbFallback('customers_update');
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
    if (dbEnabled()) {
        try {
            if (!deleteCustomerInDb($id)) {
                errorResponse('Cliente no encontrado', 404);
            }
            persistenceMarkDbHealthy('customers_delete');
            syncCustomersBackupFromDb($customersPath);
            ok(['id' => $id]);
        } catch (Throwable) {
            persistenceMarkDbFallback('customers_delete');
        }
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
    persistenceMarkDbFallback('customers_delete');
    ok(['id' => $id]);
}

errorResponse('Método no soportado', 405);

