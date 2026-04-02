<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/json_store.php';

$departmentsPath = storagePath('departments.json');
$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));

/**
 * @return array<int, array<string, mixed>>
 */
function readDepartments(string $departmentsPath): array
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
            ['id' => 'dep-001', 'name' => 'Sin Departamento'],
            ['id' => 'dep-002', 'name' => 'Abarrotes'],
            ['id' => 'dep-003', 'name' => 'Bebidas'],
            ['id' => 'dep-004', 'name' => 'Limpieza'],
        ];
        writeJsonFile($departmentsPath, $normalized);
    }

    return $normalized;
}

/**
 * @param array<int, array<string, mixed>> $departments
 */
function nextDepartmentId(array $departments): string
{
    $max = 0;
    foreach ($departments as $department) {
        $id = (string)($department['id'] ?? '');
        if (preg_match('/^dep-(\d+)$/i', $id, $matches) === 1) {
            $max = max($max, (int)$matches[1]);
        }
    }
    return 'dep-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

if ($method === 'GET') {
    $q = strtolower(trim((string)($_GET['q'] ?? '')));
    $departments = readDepartments($departmentsPath);
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
        errorResponse('Cuerpo inválido', 400);
    }

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        errorResponse('Nombre requerido', 400);
    }

    $departments = readDepartments($departmentsPath);
    foreach ($departments as $department) {
        if (strcasecmp((string)$department['name'], $name) === 0) {
            errorResponse('El departamento ya existe', 409);
        }
    }

    $department = [
        'id' => nextDepartmentId($departments),
        'name' => $name,
    ];
    $departments[] = $department;
    writeJsonFile($departmentsPath, $departments);
    ok($department);
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

    $departments = readDepartments($departmentsPath);
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

    if (strcasecmp((string)$selected['name'], 'Sin Departamento') === 0) {
        errorResponse('No se puede eliminar el departamento base', 409);
    }

    $departments = array_values(array_filter($departments, function ($department) use ($id) {
        return (string)($department['id'] ?? '') !== $id;
    }));
    writeJsonFile($departmentsPath, $departments);

    $productsPath = storagePath('products.json');
    $products = readJsonFile($productsPath);
    foreach ($products as &$product) {
        if (strcasecmp((string)($product['department'] ?? ''), (string)$selected['name']) === 0) {
            $product['department'] = 'Sin Departamento';
        }
    }
    unset($product);
    writeJsonFile($productsPath, $products);

    ok(['id' => $id]);
}

errorResponse('Método no soportado', 405);
