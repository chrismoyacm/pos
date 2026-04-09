<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/cash_shift.php';

session_start();

/**
 * @return array<string, bool>
 */
function defaultPermissionsForRole(string $role): array
{
    $base = [
        // Configuracion
        'config_options_enabled' => false,
        'config_cashiers' => false,
        'config_modify_folios' => false,
        'config_manage_boxes' => false,
        'config_logo' => false,
        'config_ticket' => false,
        'config_taxes' => false,
        'config_corte' => false,
        'config_units' => false,
        'config_ticket_printer' => false,
        'config_barcode_reader' => false,

        // Ventas
        'ventas_use_common_product' => false,
        'ventas_apply_wholesale' => false,
        'ventas_apply_discount' => false,
        'ventas_view_sales_history' => false,
        'ventas_register_cash_in' => false,
        'ventas_register_cash_out' => false,
        'ventas_charge_ticket' => false,
        'ventas_charge_credit' => false,
        'ventas_cancel_tickets' => false,
        'ventas_delete_sale_items' => false,
        'ventas_invoice' => false,
        'ventas_sell_service' => false,
        'ventas_sell_recharges' => false,
        'ventas_use_product_search' => false,

        // Clientes
        'clientes_create_edit_delete' => false,
        'clientes_assign_to_sale' => false,
        'clientes_assign_credit' => false,
        'clientes_view_credit_accounts' => false,

        // Productos
        'productos_create' => false,
        'productos_edit' => false,
        'productos_delete' => false,
        'productos_view_reports' => false,
        'productos_create_promotions' => false,
        'productos_modify_varios' => false,

        // Inventario
        'inventario_add_stock' => false,
        'inventario_view_minimum_reports' => false,
        'inventario_view_movements' => false,
        'inventario_adjust' => false,

        // Otros
        'otros_access_reports' => false,
        'otros_access_facturas' => false,
        'otros_access_corte' => false,
    ];

    if ($role === 'admin') {
        foreach ($base as $key => $value) {
            $base[$key] = true;
        }
    }

    return $base;
}

/**
 * @param mixed $raw
 * @return array<string, bool>
 */
function normalizePermissions(mixed $raw, string $role): array
{
    $defaults = defaultPermissionsForRole($role);
    if (!is_array($raw)) {
        return $defaults;
    }

    foreach ($defaults as $key => $value) {
        if (array_key_exists($key, $raw)) {
            $defaults[$key] = (bool)$raw[$key];
        }
    }

    return $defaults;
}

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
    $_SESSION['permissions'] = normalizePermissions($user['permissions'] ?? null, (string)($_SESSION['role'] ?? 'user'));
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
                'role' => $_SESSION['role'] ?? 'user',
                'permissions' => is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : defaultPermissionsForRole((string)($_SESSION['role'] ?? 'user')),
            ]
        ]);
    } else {
        ok(['logged_in' => false]);
    }
    exit;
}

errorResponse('Acción no soportada', 400);

