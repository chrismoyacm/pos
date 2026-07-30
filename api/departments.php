<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/persistence.php';

$departmentsPath = storagePath('departments.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

function parseDepartmentDbId(string $id): ?int
{
    $raw = trim($id);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^dep-(\d+)$/i', $raw, $m) === 1) {
        return (int)$m[1];
    }
    if (preg_match('/^\d+$/', $raw) === 1) {
        return (int)$raw;
    }
    return null;
}

function formatDepartmentId(int $id): string
{
    return 'dep-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

/**
 * @return array<int, array<string, mixed>>
 */
function readDepartmentsBackup(string $departmentsPath): array
{
    $rows = readJsonFile($departmentsPath);
    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['id'] ?? '');
        $name = trim((string)($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $normalized[] = ['id' => $id, 'name' => $name];
    }

    if ($normalized === []) {
        $normalized = [
            ['id' => 'dep-025', 'name' => '- Sin Departamento -'],
            ['id' => 'dep-003', 'name' => 'ABARROTES'],
            ['id' => 'dep-007', 'name' => 'BEBIDAS'],
            ['id' => 'dep-002', 'name' => 'ASEO Y LIMPIEZA'],
        ];
        writeJsonBackupFile($departmentsPath, $normalized);
    }

    return $normalized;
}

/**
 * @return array<int, array<string, mixed>>
 */
function readDepartmentsFromDb(): array
{
    $rows = legacyReadDepartments();
    usort($rows, static function (array $a, array $b): int {
        return (parseDepartmentDbId((string)($a['id'] ?? '')) ?? 0) <=> (parseDepartmentDbId((string)($b['id'] ?? '')) ?? 0);
    });
    return $rows;
}

function syncDepartmentsBackupFromDb(string $departmentsPath): void
{
    writeJsonBackupFile($departmentsPath, readDepartmentsFromDb());
}

/**
 * @param array<int, array<string, mixed>> $departments
 */
function nextDepartmentBackupId(array $departments): string
{
    $max = 0;
    foreach ($departments as $department) {
        $id = parseDepartmentDbId((string)($department['id'] ?? ''));
        if ($id !== null) {
            $max = max($max, $id);
        }
    }
    return formatDepartmentId($max + 1);
}

function nextDepartmentDbId(): int
{
    return (int)db()->query('SELECT COALESCE(MAX(CAST(ID AS UNSIGNED)), 0) + 1 FROM DEPARTAMENTOS')->fetchColumn();
}

function upsertDepartmentInDb(string $name, ?int $forcedId = null): array
{
    $pdo = db();
    $id = $forcedId;
    if ($id === null || $id <= 0) {
        $id = nextDepartmentDbId();
    }
    $id = max(1, min(127, $id));
    $safeName = function_exists('legacyFitTableValue')
        ? legacyFitTableValue('DEPARTAMENTOS', 'NOMBRE', $name)
        : $name;

    $exists = $pdo->prepare('SELECT COUNT(*) FROM DEPARTAMENTOS WHERE ID = :id');
    $exists->execute([':id' => $id]);
    if ((int)$exists->fetchColumn() > 0) {
        $stmt = $pdo->prepare('UPDATE DEPARTAMENTOS SET NOMBRE = :name, ACTIVO = 1 WHERE ID = :id');
        $stmt->execute([':id' => $id, ':name' => $safeName]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO DEPARTAMENTOS (ID, NOMBRE, PORCENTAJE_IMPUESTO, ACTIVO) VALUES (:id, :name, 0, 1)');
        $stmt->execute([':id' => $id, ':name' => $safeName]);
    }

    return ['id' => formatDepartmentId($id), 'name' => $safeName];
}

function departmentNameExistsInDb(string $name, ?int $exceptId = null): bool
{
    $sql = 'SELECT ID FROM DEPARTAMENTOS WHERE LOWER(TRIM(NOMBRE)) = LOWER(TRIM(:name)) AND COALESCE(ACTIVO, 1) = 1';
    $params = [':name' => $name];
    if ($exceptId !== null) {
        $sql .= ' AND ID <> :except_id';
        $params[':except_id'] = $exceptId;
    }
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetchColumn() !== false;
}

function deactivateDepartmentInDb(string $id): array
{
    $dbId = parseDepartmentDbId($id);
    if ($dbId === null || $dbId <= 0) {
        errorResponse('Departamento no encontrado', 404);
    }

    $stmt = db()->prepare('SELECT ID, NOMBRE FROM DEPARTAMENTOS WHERE ID = :id AND COALESCE(ACTIVO, 1) = 1 LIMIT 1');
    $stmt->execute([':id' => $dbId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        errorResponse('Departamento no encontrado', 404);
    }

    $name = trim((string)($row['NOMBRE'] ?? ''));
    if (strcasecmp($name, 'Sin Departamento') === 0 || strcasecmp($name, '- Sin Departamento -') === 0 || $dbId === 25) {
        errorResponse('No se puede eliminar el departamento base', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE DEPARTAMENTOS SET ACTIVO = 0 WHERE ID = :id');
        $upd->execute([':id' => $dbId]);

        if (function_exists('legacyTableExists') && legacyTableExists('PRODUCTOS')) {
            $products = $pdo->prepare('UPDATE PRODUCTOS SET DEPT = 25 WHERE DEPT = :id');
            $products->execute([':id' => $dbId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['id' => formatDepartmentId($dbId), 'name' => $name];
}

if ($method === 'GET') {
    $q = strtolower(trim((string)($_GET['q'] ?? '')));
    if (dbEnabled()) {
        try {
            $departments = readDepartmentsFromDb();
            persistenceMarkDbHealthy('departments_read');
            syncDepartmentsBackupFromDb($departmentsPath);
        } catch (Throwable) {
            persistenceMarkDbFallback('departments_read');
            $departments = readDepartmentsBackup($departmentsPath);
        }
    } else {
        $departments = readDepartmentsBackup($departmentsPath);
    }

    if ($q !== '') {
        $departments = array_values(array_filter($departments, function ($department) use ($q) {
            $name = strtolower((string)($department['name'] ?? ''));
            return str_contains($name, $q);
        }));
    }
    ok($departments);
}

if ($method === 'POST') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo invalido', 400);
    }

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        errorResponse('Nombre requerido', 400);
    }

    if (dbEnabled()) {
        try {
            if (departmentNameExistsInDb($name)) {
                errorResponse('El departamento ya existe', 409);
            }
            $department = upsertDepartmentInDb($name);
            persistenceMarkDbHealthy('departments_create');
            syncDepartmentsBackupFromDb($departmentsPath);
            ok($department);
        } catch (Throwable) {
            persistenceMarkDbFallback('departments_create');
        }
    }

    $departments = readDepartmentsBackup($departmentsPath);
    foreach ($departments as $department) {
        if (strcasecmp((string)$department['name'], $name) === 0) {
            errorResponse('El departamento ya existe', 409);
        }
    }

    $department = [
        'id' => nextDepartmentBackupId($departments),
        'name' => $name,
    ];
    $departments[] = $department;
    writeJsonBackupFile($departmentsPath, $departments);
    persistenceMarkDbFallback('departments_create');
    ok($department);
}

if ($method === 'DELETE') {
    $body = $request['body'];
    if (!is_array($body)) {
        errorResponse('Cuerpo invalido', 400);
    }

    $id = (string)($body['id'] ?? '');
    if ($id === '') {
        errorResponse('ID requerido', 400);
    }

    if (dbEnabled()) {
        try {
            $department = deactivateDepartmentInDb($id);
            persistenceMarkDbHealthy('departments_delete');
            syncDepartmentsBackupFromDb($departmentsPath);
            ok(['id' => $department['id']]);
        } catch (Throwable) {
            persistenceMarkDbFallback('departments_delete');
        }
    }

    $departments = readDepartmentsBackup($departmentsPath);
    $selected = null;
    foreach ($departments as $department) {
        if ((string)($department['id'] ?? '') === $id) {
            $selected = $department;
            break;
        }
    }

    if ($selected === null) {
        errorResponse('Departamento no encontrado', 404);
    }

    if (strcasecmp((string)$selected['name'], 'Sin Departamento') === 0 || strcasecmp((string)$selected['name'], '- Sin Departamento -') === 0) {
        errorResponse('No se puede eliminar el departamento base', 409);
    }

    $departments = array_values(array_filter($departments, function ($department) use ($id) {
        return (string)($department['id'] ?? '') !== $id;
    }));
    writeJsonBackupFile($departmentsPath, $departments);
    persistenceMarkDbFallback('departments_delete');
    ok(['id' => $id]);
}

errorResponse('Metodo no soportado', 405);
