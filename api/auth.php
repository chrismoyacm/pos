<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/cash_shift.php';

session_start();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        errorResponse('Método no permitido', 405);
    }
    
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    
    if (!$username || !$password) {
        errorResponse('Usuario y contraseña son requeridos', 400);
    }
    
    // Load users
    $usersPath = storagePath('users.json');
    $users = readJsonFile($usersPath);
    
    // Find user by username
    $user = null;
    foreach ($users as $u) {
        if (isset($u['username']) && $u['username'] === $username && $u['active'] !== false) {
            $user = $u;
            break;
        }
    }
    
    if (!$user) {
        errorResponse('Usuario o contraseña incorrectos', 401);
    }
    
    // Verify password (using bcrypt)
    if (!password_verify($password, $user['password'] ?? '')) {
        errorResponse('Usuario o contraseña incorrectos', 401);
    }
    
    // Set session
    $_SESSION['user_id'] = $user['id'] ?? null;
    $_SESSION['username'] = $user['username'] ?? null;
    $_SESSION['name'] = $user['name'] ?? null;
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    $openShift = findOpenShiftForUser(readCashOpenings(), $user['id'] ?? null);
    if (is_array($openShift)) {
        $_SESSION['pending_cash_opening'] = false;
        $_SESSION['cash_opening_id'] = $openShift['id'] ?? null;
        $_SESSION['cash_opening_amount'] = (float)($openShift['amount'] ?? 0);
        $_SESSION['cash_opened_at'] = $openShift['createdAt'] ?? null;
        $_SESSION['cash_shift_status'] = 'open';
    } else {
        $_SESSION['pending_cash_opening'] = true;
        $_SESSION['cash_shift_status'] = 'pending';
        unset($_SESSION['cash_opening_id'], $_SESSION['cash_opening_amount'], $_SESSION['cash_opened_at']);
    }
    
    ok([
        'message' => 'Login exitoso',
        'redirect' => is_array($openShift) ? '/pos/public/index.php?mod=ventas' : '/pos/vista/caja-inicial.php',
    ]);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ../vista/login.php');
    exit;
}

if ($action === 'check') {
    // Check if user is logged in (API endpoint)
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
        ok([
            'logged_in' => true,
            'user' => [
                'username' => $_SESSION['username'] ?? null,
                'name' => $_SESSION['name'] ?? null,
                'role' => $_SESSION['role'] ?? 'user'
            ]
        ]);
    } else {
        ok(['logged_in' => false]);
    }
    exit;
}

errorResponse('Acción no soportada', 400);

