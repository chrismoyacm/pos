<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/db.php';

/**
 * @return array<int|string, mixed>|null
 */
function legacyMappedRead(string $fileName): ?array
{
    if (!dbEnabled()) {
        return null;
    }

    $key = normalizeLegacyFileKey($fileName);
    $base = basename(str_replace('\\', '/', $key));

    try {
        return match ($base) {
            'users.json' => legacyReadUsers(),
            'products.json' => legacyReadProducts(),
            'customers.json' => legacyReadCustomers(),
            'departments.json' => legacyReadDepartments(),
            default => isLegacyDocumentFile($key) ? legacyReadDocumentStore($key) : null,
        };
    } catch (Throwable $e) {
        return null;
    }
}

function legacyMappedWrite(string $fileName, array $data): bool
{
    if (!dbEnabled()) {
        return false;
    }

    $key = normalizeLegacyFileKey($fileName);
    $base = basename(str_replace('\\', '/', $key));

    try {
        return match ($base) {
            'users.json' => legacyWriteUsers($data),
            'products.json' => legacyWriteProducts($data),
            'customers.json' => legacyWriteCustomers($data),
            'departments.json' => legacyWriteDepartments($data),
            default => isLegacyDocumentFile($key) ? legacyWriteDocumentStore($key, $data) : false,
        };
    } catch (Throwable $e) {
        return false;
    }
}

function normalizeLegacyFileKey(string $fileName): string
{
    return strtolower(str_replace('\\', '/', trim($fileName)));
}

/** @return array<int, string> */
function legacyDocumentFiles(): array
{
    return [
        'sales.json',
        'cash_openings.json',
        'cash_movements.json',
        'credit_payments.json',
        'inventory_movements.json',
        'promotions.json',
        'settings.json',
        'facturacion/documentos.json',
        'facturacion/emails.json',
        'facturacion/emisor.json',
        'facturacion/firma.json',
        'facturacion/logs.json',
        'facturacion/puntos.json',
    ];
}

function isLegacyDocumentFile(string $fileName): bool
{
    $key = normalizeLegacyFileKey($fileName);
    if (in_array($key, legacyDocumentFiles(), true)) {
        return true;
    }

    $base = basename($key);
    return in_array($base, legacyDocumentFiles(), true);
}

function ensureLegacyDocumentStoreTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $sql = 'CREATE TABLE IF NOT EXISTS POS_APP_JSON_STORE (
        FILE_NAME VARCHAR(120) NOT NULL PRIMARY KEY,
        PAYLOAD LONGTEXT NOT NULL,
        UPDATED_AT DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    db()->exec($sql);
    $ready = true;
}

/** @return array<int|string, mixed> */
function readRawJsonDiskFile(string $fileName): array
{
    $relative = str_replace('/', DIRECTORY_SEPARATOR, normalizeLegacyFileKey($fileName));
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

/** @return array<int|string, mixed> */
function legacyReadDocumentStore(string $fileName): array
{
    ensureLegacyDocumentStoreTable();
    $file = strtolower($fileName);
    $stmt = db()->prepare('SELECT PAYLOAD FROM POS_APP_JSON_STORE WHERE FILE_NAME = :file LIMIT 1');
    $stmt->execute([':file' => $file]);
    $payload = $stmt->fetchColumn();

    if (is_string($payload) && trim($payload) !== '') {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    // Bootstrap inicial: si no existe en SQL, toma lo que haya en storage y lo sube.
    $diskData = readRawJsonDiskFile($file);
    if ($diskData !== []) {
        legacyWriteDocumentStore($file, $diskData);
    }
    return $diskData;
}

function legacyWriteDocumentStore(string $fileName, array $data): bool
{
    ensureLegacyDocumentStoreTable();
    $file = strtolower($fileName);
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        return false;
    }

    $stmt = db()->prepare(
        'INSERT INTO POS_APP_JSON_STORE (FILE_NAME, PAYLOAD, UPDATED_AT)
         VALUES (:file, :payload, NOW())
         ON DUPLICATE KEY UPDATE PAYLOAD = VALUES(PAYLOAD), UPDATED_AT = NOW()'
    );
    $stmt->execute([
        ':file' => $file,
        ':payload' => $encoded,
    ]);
    return true;
}

function normalizeBoolString(mixed $value, string $trueValue = '1', string $falseValue = '0'): string
{
    if (is_bool($value)) {
        return $value ? $trueValue : $falseValue;
    }

    $s = strtolower(trim((string)$value));
    return in_array($s, ['1', 'true', 't', 'yes', 'y', 'si', 's'], true) ? $trueValue : $falseValue;
}

function parseIntId(string $value, string $prefix = ''): ?int
{
    $v = trim($value);
    if ($v === '') {
        return null;
    }

    if ($prefix !== '' && str_starts_with(strtolower($v), strtolower($prefix))) {
        $v = substr($v, strlen($prefix));
    }

    if (!preg_match('/\d+/', $v, $m)) {
        return null;
    }

    return (int)$m[0];
}

function safeFloat(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }
    return round((float)$value, 4);
}

function safeInt(mixed $value): int
{
    return (int)round((float)$value);
}

function parseLegacyInventoryEnabled(mixed $raw): bool
{
    $v = strtolower(trim((string)$raw));
    if ($v === '' || $v === 'f') {
        return true;
    }
    if (in_array($v, ['0', 'false', 'no', 'n'], true)) {
        return false;
    }
    return true;
}

/** @return array<int, array<string,mixed>> */
function legacyReadUsers(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, NOMBRE_COMPLETO, USUARIO, CLAVE, ACTIVO, PERMISOS, ELIMINADO_EN FROM USUARIOS')->fetchAll();
    $out = [];

    foreach ($rows as $row) {
        $deleted = trim((string)($row['ELIMINADO_EN'] ?? ''));
        if ($deleted !== '') {
            continue;
        }

        $id = (int)($row['ID'] ?? 0);
        $username = trim((string)($row['USUARIO'] ?? ''));
        if ($username === '') {
            continue;
        }
        $name = trim((string)($row['NOMBRE_COMPLETO'] ?? ''));
        $role = strtolower($username) === 'admin' ? 'admin' : 'user';

        $out[] = [
            'id' => $id > 0 ? (string)$id : (string)(count($out) + 1),
            'username' => $username,
            'name' => $name !== '' ? $name : $username,
            'password' => (string)($row['CLAVE'] ?? ''),
            'role' => $role,
            'active' => safeInt($row['ACTIVO'] ?? 0) === 1,
            'permissions' => null,
        ];
    }

    return $out;
}

function legacyWriteUsers(array $users): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM USUARIOS')->fetchColumn();

        $exists = $pdo->prepare('SELECT COUNT(*) FROM USUARIOS WHERE ID = :id');
        $insert = $pdo->prepare(
            'INSERT INTO USUARIOS (ID, NOMBRE_COMPLETO, USUARIO, CLAVE, ACTIVO, PERMISOS, CREATED_ON, CORREO, ESTA_EN_CAJA_ID, ELIMINADO_EN)
             VALUES (:id, :nombre, :usuario, :clave, :activo, :permisos, :created_on, :correo, :caja, :eliminado)'
        );
        $update = $pdo->prepare(
            'UPDATE USUARIOS
             SET NOMBRE_COMPLETO = :nombre, USUARIO = :usuario, CLAVE = :clave, ACTIVO = :activo, PERMISOS = :permisos, ELIMINADO_EN = :eliminado
             WHERE ID = :id'
        );

        $seenIds = [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $id = parseIntId((string)($user['id'] ?? ''));
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $seenIds[] = $id;

            $params = [
                ':id' => $id,
                ':nombre' => trim((string)($user['name'] ?? $user['username'] ?? 'Usuario')),
                ':usuario' => trim((string)($user['username'] ?? '')),
                ':clave' => (string)($user['password'] ?? ''),
                ':activo' => !empty($user['active']) ? 1 : 0,
                ':permisos' => strtolower((string)($user['role'] ?? 'user')) === 'admin' ? 635655159810 : 0,
                ':eliminado' => '',
            ];

            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $update->execute($params);
            } else {
                $insertParams = [
                    ':id' => $params[':id'],
                    ':nombre' => $params[':nombre'],
                    ':usuario' => $params[':usuario'],
                    ':clave' => $params[':clave'],
                    ':activo' => $params[':activo'],
                    ':permisos' => $params[':permisos'],
                    ':eliminado' => $params[':eliminado'],
                    ':created_on' => legacyNow(),
                    ':correo' => '',
                    ':caja' => 1,
                ];
                $insert->execute($insertParams);
            }
        }

        if (!empty($seenIds)) {
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $sql = "UPDATE USUARIOS SET ACTIVO = 0, ELIMINADO_EN = ? WHERE ID NOT IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([legacyNow()], $seenIds));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<int, array<string,mixed>> */
function legacyReadDepartments(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, NOMBRE, ACTIVO FROM DEPARTAMENTOS ORDER BY ID ASC')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        if (safeInt($row['ACTIVO'] ?? 0) !== 1) {
            continue;
        }
        $id = safeInt($row['ID'] ?? 0);
        $name = trim((string)($row['NOMBRE'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $out[] = [
            'id' => 'dep-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
            'name' => $name,
        ];
    }
    return $out;
}

function legacyWriteDepartments(array $departments): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM DEPARTAMENTOS')->fetchColumn();
        $seen = [];

        $exists = $pdo->prepare('SELECT COUNT(*) FROM DEPARTAMENTOS WHERE ID = :id');
        $update = $pdo->prepare('UPDATE DEPARTAMENTOS SET NOMBRE = :name, ACTIVO = 1 WHERE ID = :id');
        $insert = $pdo->prepare('INSERT INTO DEPARTAMENTOS (ID, NOMBRE, PORCENTAJE_IMPUESTO, ACTIVO) VALUES (:id, :name, 0, 1)');

        foreach ($departments as $dep) {
            if (!is_array($dep)) {
                continue;
            }
            $name = trim((string)($dep['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $id = parseIntId((string)($dep['id'] ?? ''), 'dep-');
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $seen[] = $id;

            $params = [':id' => $id, ':name' => $name];
            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $update->execute($params);
            } else {
                $insert->execute($params);
            }
        }

        if (!empty($seen)) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $stmt = $pdo->prepare("UPDATE DEPARTAMENTOS SET ACTIVO = 0 WHERE ID NOT IN ($placeholders)");
            $stmt->execute($seen);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<string,int> */
function legacyDepartmentNameToIdMap(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT ID, NOMBRE FROM DEPARTAMENTOS WHERE ACTIVO = 1')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $name = strtolower(trim((string)($row['NOMBRE'] ?? '')));
        $id = safeInt($row['ID'] ?? 0);
        if ($name !== '' && $id > 0) {
            $map[$name] = $id;
        }
    }
    return $map;
}

/** @return array<int, array<string,mixed>> */
function legacyReadProducts(): array
{
    $pdo = db();
    $sql = 'SELECT p.ID, p.CODIGO, p.DESCRIPCION, p.PCOSTO, p.PORCENTAJE_GANANCIA, p.PVENTA, p.PFINAL, p.MAYOREO,
                   p.DINVENTARIO, p.DINVMINIMO, p.DINVMAXIMO, p.TVENTA, p.DEPT, p.USA_INVENTARIO, p.IMPUESTOS,
                   p.ELIMINADO_EN, d.NOMBRE AS DEPARTAMENTO
            FROM PRODUCTOS p
            LEFT JOIN DEPARTAMENTOS d ON d.ID = p.DEPT
            WHERE p.ELIMINADO_EN IS NULL OR p.ELIMINADO_EN = ""
            ORDER BY CAST(p.ID AS UNSIGNED) ASC';
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];

    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $price = safeFloat($row['PFINAL'] ?? 0);
        if ($price <= 0) {
            $price = safeFloat($row['PVENTA'] ?? 0);
        }

        $department = trim((string)($row['DEPARTAMENTO'] ?? ''));
        if ($department === '') {
            $department = 'Sin Departamento';
        }

        $unitType = strtoupper(trim((string)($row['TVENTA'] ?? 'U'))) === 'D' ? 'bulk' : 'unit';
        $ivaRaw = trim((string)($row['IMPUESTOS'] ?? ''));
        $iva = ($ivaRaw !== '' && $ivaRaw !== '0') ? '12%' : 'No';

        $out[] = [
            'id' => 'p-' . $id,
            'barcode' => trim((string)($row['CODIGO'] ?? '')),
            'name' => trim((string)($row['DESCRIPCION'] ?? '')),
            'cost' => safeFloat($row['PCOSTO'] ?? 0),
            'margin' => safeFloat($row['PORCENTAJE_GANANCIA'] ?? 0),
            'price' => $price,
            'specialPrice' => $price,
            'stock' => safeInt($row['DINVENTARIO'] ?? 0),
            'minStock' => safeInt($row['DINVMINIMO'] ?? 0),
            'maxStock' => safeInt($row['DINVMAXIMO'] ?? 0),
            // En dumps legacy, este campo suele venir en formato ambiguo ('f'/'0').
            // Para no bloquear el módulo de inventario, habilitamos inventario en productos no-kit.
            'inventoryEnabled' => $unitType !== 'package',
            'department' => $department,
            'unitType' => $unitType,
            'provider' => '',
            'iva' => $iva,
            'packageItems' => [],
            'wholesale' => safeFloat($row['MAYOREO'] ?? 0) > 0
                ? ['minQty' => 2, 'price' => safeFloat($row['MAYOREO'] ?? 0)]
                : null,
        ];
    }

    return $out;
}

function legacyWriteProducts(array $products): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $deptMap = legacyDepartmentNameToIdMap();
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM PRODUCTOS')->fetchColumn();

        $exists = $pdo->prepare('SELECT COUNT(*) FROM PRODUCTOS WHERE ID = :id');
        $updateById = $pdo->prepare(
            'UPDATE PRODUCTOS
             SET CODIGO = :codigo, DESCRIPCION = :descripcion, TVENTA = :tventa, PCOSTO = :pcosto,
                 PVENTA = :pventa, PFINAL = :pfinal, DEPT = :dept, MAYOREO = :mayoreo,
                 DINVENTARIO = :inventario, DINVMINIMO = :invmin, DINVMAXIMO = :invmax,
                 PORCENTAJE_GANANCIA = :margen, USA_INVENTARIO = :usa_inventario, IMPUESTOS = :impuestos,
                 ELIMINADO_EN = ""
             WHERE ID = :id'
        );

        $insert = $pdo->prepare(
            'INSERT INTO PRODUCTOS (ID, CODIGO, DESCRIPCION, TVENTA, PCOSTO, PVENTA, DEPT, MAYOREO,
                                    DINVENTARIO, DINVMINIMO, DINVMAXIMO, PORCENTAJE_GANANCIA, MEDIDA_ID,
                                    PFINAL, PMAYOREOFINAL, ES_KIT, USA_INVENTARIO, IMPUESTOS, ELIMINADO_EN)
             VALUES (:id, :codigo, :descripcion, :tventa, :pcosto, :pventa, :dept, :mayoreo,
                     :inventario, :invmin, :invmax, :margen, 1,
                     :pfinal, :mayoreo, "f", :usa_inventario, :impuestos, "")'
        );

        $seenIds = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $name = trim((string)($product['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $id = parseIntId((string)($product['id'] ?? ''), 'p-');
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $seenIds[] = $id;

            $departmentName = strtolower(trim((string)($product['department'] ?? 'sin departamento')));
            $deptId = $deptMap[$departmentName] ?? ($deptMap['- sin departamento -'] ?? 25);

            $price = safeFloat($product['price'] ?? 0);
            $wholesale = safeFloat(($product['wholesale']['price'] ?? 0));
            $iva = strtolower(trim((string)($product['iva'] ?? 'no')));

            $params = [
                ':id' => $id,
                ':codigo' => trim((string)($product['barcode'] ?? '')),
                ':descripcion' => $name,
                ':tventa' => (($product['unitType'] ?? 'unit') === 'bulk') ? 'D' : 'U',
                ':pcosto' => safeFloat($product['cost'] ?? 0),
                ':pventa' => $price,
                ':pfinal' => $price,
                ':dept' => $deptId,
                ':mayoreo' => $wholesale > 0 ? $wholesale : $price,
                ':inventario' => safeInt($product['stock'] ?? 0),
                ':invmin' => safeInt($product['minStock'] ?? 0),
                ':invmax' => safeInt($product['maxStock'] ?? 0),
                ':margen' => safeFloat($product['margin'] ?? 0),
                ':usa_inventario' => !empty($product['inventoryEnabled']) ? '1' : '0',
                ':impuestos' => ($iva === '12%' || $iva === '15%') ? '1' : '0',
            ];

            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $updateById->execute($params);
            } else {
                $insert->execute($params);
            }
        }

        if (!empty($seenIds)) {
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $sql = "UPDATE PRODUCTOS SET ELIMINADO_EN = ? WHERE ID NOT IN ($placeholders) AND (ELIMINADO_EN IS NULL OR ELIMINADO_EN = '')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([legacyNow()], $seenIds));
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/** @return array<int, array<string,mixed>> */
function legacyReadCustomers(): array
{
    $pdo = db();
    $sql = 'SELECT c.ID, c.NOMBRES, c.APELLIDOS, c.EMAIL, c.TELEFONO, c.DOMICILIO1, c.DOMICILIO2,
                   c.COLONIA, c.MUNICIPIO, c.ESTADO, c.CODIGO_POSTAL, c.NOTAS,
                   IFNULL(cr.TIENE_CREDITO, 0) AS TIENE_CREDITO,
                   IFNULL(cr.LIMITE_CREDITO, 0) AS LIMITE_CREDITO,
                   IFNULL(cr.SALDO_ACTUAL, 0) AS SALDO_ACTUAL,
                   IFNULL(cr.ULTIMO_ABONO, "") AS ULTIMO_ABONO
            FROM CLIENTESV2 c
            LEFT JOIN CLIENTESV2_CREDITO cr ON cr.CLIENTESV2_ID = c.ID
            ORDER BY CAST(c.ID AS UNSIGNED) ASC';
    $rows = $pdo->query($sql)->fetchAll();
    $out = [];

    foreach ($rows as $row) {
        $id = safeInt($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $first = trim((string)($row['NOMBRES'] ?? ''));
        $last = trim((string)($row['APELLIDOS'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = $first !== '' ? $first : ('Cliente ' . $id);
        }

        $creditBalance = safeFloat($row['SALDO_ACTUAL'] ?? 0);
        $out[] = [
            'id' => 'c-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'firstName' => $first,
            'lastName' => $last,
            'phone' => trim((string)($row['TELEFONO'] ?? '')),
            'email' => trim((string)($row['EMAIL'] ?? '')),
            'address1' => trim((string)($row['DOMICILIO1'] ?? '')),
            'address2' => trim((string)($row['DOMICILIO2'] ?? '')),
            'province' => trim((string)($row['ESTADO'] ?? '')),
            'canton' => trim((string)($row['MUNICIPIO'] ?? '')),
            'parish' => trim((string)($row['COLONIA'] ?? '')),
            'zip' => trim((string)($row['CODIGO_POSTAL'] ?? '')),
            'notes' => trim((string)($row['NOTAS'] ?? '')),
            'creditAuthorized' => safeInt($row['TIENE_CREDITO'] ?? 0) === 1 || $creditBalance > 0,
            'creditLimit' => safeFloat($row['LIMITE_CREDITO'] ?? 0),
            'creditBalance' => $creditBalance,
            'lastCreditPaymentAt' => trim((string)($row['ULTIMO_ABONO'] ?? '')),
            'paymentDueDate' => null,
            'paymentDueDay' => null,
        ];
    }

    return $out;
}

function legacyWriteCustomers(array $customers): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) AS mx FROM CLIENTESV2')->fetchColumn();
        $seen = [];

        $exists = $pdo->prepare('SELECT COUNT(*) FROM CLIENTESV2 WHERE ID = :id');
        $update = $pdo->prepare(
            'UPDATE CLIENTESV2
             SET NOMBRES = :nombres, APELLIDOS = :apellidos, EMAIL = :email, TELEFONO = :telefono,
                 DOMICILIO1 = :dom1, DOMICILIO2 = :dom2, COLONIA = :colonia, MUNICIPIO = :municipio,
                 ESTADO = :estado, CODIGO_POSTAL = :cp, NOTAS = :notas, ACTIVO = 1
             WHERE ID = :id'
        );

        $insert = $pdo->prepare(
            'INSERT INTO CLIENTESV2 (ID, FOLIO, NOMBRES, APELLIDOS, EMAIL, TELEFONO, DOMICILIO1, DOMICILIO2,
                                     COLONIA, MUNICIPIO, ESTADO, PAIS, CODIGO_POSTAL, NOTAS,
                                     TOTAL_VENTAS, TOTAL_GANANCIAS, TOTAL_TICKETS, ACTIVO, DE_SISTEMA,
                                     OLD_CLIENTE_ID, OLD_FACTURACION_CLIENTES_ID)
             VALUES (:id, "", :nombres, :apellidos, :email, :telefono, :dom1, :dom2,
                     :colonia, :municipio, :estado, "", :cp, :notas,
                     0, 0, 0, 1, "", "", "")'
        );

        $existsCredit = $pdo->prepare('SELECT COUNT(*) FROM CLIENTESV2_CREDITO WHERE CLIENTESV2_ID = :id');
        $updateCredit = $pdo->prepare('UPDATE CLIENTESV2_CREDITO SET TIENE_CREDITO = :tiene_credito, ELIMINADO_EN = "" WHERE CLIENTESV2_ID = :id');
        $insertCredit = $pdo->prepare(
            'INSERT INTO CLIENTESV2_CREDITO (CLIENTESV2_ID, TIENE_CREDITO, LIMITE_CREDITO, ULTIMO_ABONO, SALDO_ACTUAL, ELIMINADO_EN)
             VALUES (:id, :tiene_credito, 0, "", "0", "")'
        );

        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }
            $fullName = trim((string)($customer['name'] ?? ''));
            $first = trim((string)($customer['firstName'] ?? ''));
            $last = trim((string)($customer['lastName'] ?? ''));
            if ($first === '' && $last === '' && $fullName !== '') {
                $parts = preg_split('/\s+/', $fullName, 2) ?: [];
                $first = trim((string)($parts[0] ?? ''));
                $last = trim((string)($parts[1] ?? ''));
            }
            if ($first === '' && $last === '') {
                continue;
            }

            $id = parseIntId((string)($customer['id'] ?? ''), 'c-');
            if ($id === null || $id <= 0) {
                $maxId++;
                $id = $maxId;
            }
            $seen[] = $id;

            $params = [
                ':id' => $id,
                ':nombres' => $first,
                ':apellidos' => $last,
                ':email' => trim((string)($customer['email'] ?? '')),
                ':telefono' => trim((string)($customer['phone'] ?? '')),
                ':dom1' => trim((string)($customer['address1'] ?? '')),
                ':dom2' => trim((string)($customer['address2'] ?? '')),
                ':colonia' => trim((string)($customer['parish'] ?? '')),
                ':municipio' => trim((string)($customer['canton'] ?? '')),
                ':estado' => trim((string)($customer['province'] ?? '')),
                ':cp' => trim((string)($customer['zip'] ?? '')),
                ':notas' => trim((string)($customer['notes'] ?? '')),
            ];

            $exists->execute([':id' => $id]);
            if ((int)$exists->fetchColumn() > 0) {
                $update->execute($params);
            } else {
                $insert->execute($params);
            }

            $creditParams = [
                ':id' => $id,
                ':tiene_credito' => !empty($customer['creditAuthorized']) ? 1 : 0,
            ];
            $existsCredit->execute([':id' => $id]);
            if ((int)$existsCredit->fetchColumn() > 0) {
                $updateCredit->execute($creditParams);
            } else {
                $insertCredit->execute($creditParams);
            }
        }

        if (!empty($seen)) {
            $placeholders = implode(',', array_fill(0, count($seen), '?'));
            $stmt = $pdo->prepare("UPDATE CLIENTESV2 SET ACTIVO = 0 WHERE ID NOT IN ($placeholders)");
            $stmt->execute($seen);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}
