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
if ($method === 'GET') {
    ok([
        'pending' => (bool)($_SESSION['pending_cash_opening'] ?? false),
        'amount' => (float)($_SESSION['cash_opening_amount'] ?? 0),
        'user' => [
            'id' => $_SESSION['user_id'] ?? null,
            'name' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'Usuario',
        ],
    ]);
}

if ($method !== 'POST') {
    errorResponse('Metodo no soportado', 405);
}

$body = $request['body'];
if (!is_array($body)) {
    errorResponse('Cuerpo invalido', 400);
}

$amount = round((float)($body['amount'] ?? 0), 2);
if ($amount < 0) {
    errorResponse('El efectivo inicial no puede ser negativo', 400);
}

$openings = readCashOpenings();
$id = nextCashOpeningId($openings);
$createdAt = date('c');
$entry = [
    'id' => $id,
    'userId' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null,
    'name' => $_SESSION['name'] ?? $_SESSION['username'] ?? 'Usuario',
    'amount' => $amount,
    'status' => 'open',
    'createdAt' => $createdAt,
];
$openings[] = $entry;
writeCashOpenings($openings);

$_SESSION['pending_cash_opening'] = false;
$_SESSION['cash_opening_id'] = $id;
$_SESSION['cash_opening_amount'] = $amount;
$_SESSION['cash_opened_at'] = $createdAt;
$_SESSION['cash_shift_status'] = 'open';

ok([
    'message' => 'Dinero inicial registrado',
    'entry' => $entry,
    'redirect' => '/pos/public/index.php?mod=ventas',
]);
