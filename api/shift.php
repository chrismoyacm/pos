<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/cash_shift.php';

session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    errorResponse('Sesion no valida', 401);
}

$request = getRequestInfo();
$method = strtoupper((string)($request['method'] ?? 'GET'));
$userId = $_SESSION['user_id'] ?? null;
$rows = readCashOpenings();
$shift = findOpenShiftForUser($rows, $userId);

if ($method === 'GET') {
    if (!is_array($shift)) {
        ok([
            'hasOpenShift' => false,
            'expectedCash' => 0,
        ]);
    }

    $expectedCash = computeExpectedCashForShift($shift);
    ok([
        'hasOpenShift' => true,
        'shiftId' => $shift['id'] ?? null,
        'expectedCash' => $expectedCash,
        'openingAmount' => (float)($shift['amount'] ?? 0),
        'openedAt' => $shift['createdAt'] ?? null,
    ]);
}

if ($method !== 'POST') {
    errorResponse('Metodo no soportado', 405);
}

$body = $request['body'];
if (!is_array($body)) {
    errorResponse('Cuerpo invalido', 400);
}

$action = trim((string)($body['action'] ?? ''));

if ($action === 'cash_movement') {
    if (!is_array($shift)) {
        errorResponse('No hay un turno abierto para registrar movimiento', 409);
    }

    $movementType = strtolower(trim((string)($body['movementType'] ?? '')));
    if (!in_array($movementType, ['entry', 'exit'], true)) {
        errorResponse('Tipo de movimiento inválido', 400);
    }

    $amount = round((float)($body['amount'] ?? 0), 2);
    if ($amount <= 0) {
        errorResponse('La cantidad debe ser mayor a cero', 400);
    }

    $note = trim((string)($body['note'] ?? ''));
    if ($movementType === 'exit' && $note === '') {
        errorResponse('Ingrese razón o proveedor para la salida', 400);
    }
    if ($movementType === 'entry' && $note === '') {
        $note = 'Entrada de efectivo';
    }

    $movements = readCashMovements();
    $entry = [
        'id' => nextCashMovementId($movements),
        'shiftId' => (string)($shift['id'] ?? ''),
        'userId' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'name' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'Usuario',
        'type' => $movementType,
        'amount' => $amount,
        'note' => $note,
        'createdAt' => date('c'),
    ];
    $movements[] = $entry;
    if (count($movements) > 50000) {
        $movements = array_slice($movements, -50000);
    }
    writeCashMovements($movements);

    $expectedCash = computeExpectedCashForShift($shift);
    ok([
        'message' => $movementType === 'entry' ? 'Entrada de efectivo registrada' : 'Salida de efectivo registrada',
        'movement' => $entry,
        'expectedCash' => $expectedCash,
    ]);
}

if ($action === 'leave_open') {
    ok([
        'message' => 'Turno dejado abierto',
        'redirect' => '../api/auth.php?action=logout',
    ]);
}

if ($action !== 'close_shift') {
    errorResponse('Accion no soportada', 400);
}

if (!is_array($shift)) {
    errorResponse('No hay un turno abierto para cerrar', 409);
}

$actualCash = round((float)($body['actualCash'] ?? 0), 2);
if ($actualCash < 0) {
    errorResponse('El efectivo en caja no puede ser negativo', 400);
}

$expectedCash = computeExpectedCashForShift($shift);
$difference = round($actualCash - $expectedCash, 2);
$closedAt = date('c');
$shiftId = (string)($shift['id'] ?? '');

foreach ($rows as $index => $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string)($row['id'] ?? '') !== $shiftId) {
        continue;
    }

    $rows[$index]['status'] = 'closed';
    $rows[$index]['expectedCash'] = $expectedCash;
    $rows[$index]['actualCash'] = $actualCash;
    $rows[$index]['difference'] = $difference;
    $rows[$index]['closedAt'] = $closedAt;
    break;
}

writeCashOpenings($rows);

$_SESSION['cash_shift_status'] = 'closed';
unset($_SESSION['cash_opening_id'], $_SESSION['cash_opening_amount'], $_SESSION['cash_opened_at'], $_SESSION['pending_cash_opening']);

ok([
    'message' => 'Turno cerrado correctamente',
    'expectedCash' => $expectedCash,
    'actualCash' => $actualCash,
    'difference' => $difference,
    'redirect' => '../api/auth.php?action=logout',
]);
