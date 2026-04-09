<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';

session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    errorResponse('No autenticado', 401);
}

/**
 * @return array<string, bool>
 */
function defaultPermissionsForRoleConfig(string $role): array
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
function normalizePermissionsConfig(mixed $raw, string $role): array
{
    $defaults = defaultPermissionsForRoleConfig($role);
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

/**
 * @return array<string, mixed>
 */
function defaultSettings(): array
{
    return [
        'enabledOptions' => [
            'inventory_control' => true,
            'offer_credit' => true,
            'allow_common_product' => true,
            'auto_sale_price' => true,
            'auto_sale_margin' => 20,
        ],
        'folios' => [
            'invoice_prefix' => '001-001',
            'next_invoice' => 1,
            'credit_note_prefix' => '001-001',
            'next_credit_note' => 1,
        ],
        'boxes' => [
            'require_opening' => true,
            'allow_close_with_difference' => true,
        ],
        'branding' => [
            'store_name' => 'POS Minimarket',
            'logo_text' => 'POS',
        ],
        'ticket' => [
            'header' => 'Gracias por su compra',
            'footer' => 'Vuelva pronto',
            'show_customer' => true,
            'include_unit_price' => true,
            'full_description' => false,
            'extra_top_line' => '',
            'extra_bottom_line' => '',
            'logo_url' => '',
        ],
        'taxes' => [
            'enabled' => true,
            'country' => 'EC',
            'default_vat' => 15,
            'vat_name' => 'IVA',
            'included_new_products' => false,
            'breakdown_on_ticket' => true,
            'prices_include_taxes' => false,
            'withholding_mode' => 'none',
        ],
        'corte' => [
            'allow_negative_close' => false,
            'print_summary' => true,
        ],
        'units' => [
            'enabled_list' => ['PZA'],
            'default_unit' => 'PZA',
            'allow_decimal' => true,
        ],
        'devices' => [
            'ticket_printer' => [
                'enabled' => true,
                'name' => 'Impresora tickets',
                'font_family' => 'Consolas',
                'font_size' => 10,
                'columns' => 45,
                'use_normal_for_totals' => false,
                'bold_letters' => true,
            ],
            'barcode_reader' => [
                'enabled' => true,
                'name' => 'Lector codigo barras',
                'serial_enabled' => false,
            ],
        ],
    ];
}

function requireAdmin(): void
{
    $role = (string)($_SESSION['role'] ?? 'user');
    if ($role !== 'admin') {
        errorResponse('Permiso denegado', 403);
    }
}

function requestBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function usersPath(): string
{
    return storagePath('users.json');
}

function settingsPath(): string
{
    return storagePath('settings.json');
}

/**
 * @return array<int, array<string, mixed>>
 */
function readUsers(): array
{
    $users = readJsonFile(usersPath());
    return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
}

/**
 * @return array<string, mixed>
 */
function readSettings(): array
{
    $settings = readJsonFile(settingsPath());
    if (!is_array($settings) || $settings === []) {
        $settings = defaultSettings();
        writeJsonFile(settingsPath(), $settings);
    }
    return $settings;
}

/**
 * @param array<int, array<string, mixed>> $users
 * @return array<int, array<string, mixed>>
 */
function sanitizeUsers(array $users): array
{
    return array_values(array_map(static function (array $u): array {
        $role = (string)($u['role'] ?? 'cashier');
        return [
            'id' => (int)($u['id'] ?? 0),
            'username' => (string)($u['username'] ?? ''),
            'name' => (string)($u['name'] ?? ''),
            'role' => $role,
            'active' => (bool)($u['active'] ?? true),
            'permissions' => normalizePermissionsConfig($u['permissions'] ?? null, $role),
            'createdAt' => (string)($u['createdAt'] ?? ''),
        ];
    }, $users));
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'dashboard');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'dashboard' || $action === 'settings') {
        ok([
            'settings' => readSettings(),
            'users' => sanitizeUsers(readUsers()),
            'currentUser' => [
                'id' => (int)($_SESSION['user_id'] ?? 0),
                'role' => (string)($_SESSION['role'] ?? 'user'),
                'permissions' => normalizePermissionsConfig($_SESSION['permissions'] ?? null, (string)($_SESSION['role'] ?? 'user')),
            ],
        ]);
    }

    errorResponse('Accion no soportada', 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Metodo no permitido', 405);
}

$payload = requestBody();

if ($action === 'save_settings') {
    requireAdmin();
    $settings = readSettings();
    $incoming = $payload['settings'] ?? null;
    if (!is_array($incoming)) {
        errorResponse('Configuracion invalida', 400);
    }

    foreach ($settings as $section => $value) {
        if (array_key_exists($section, $incoming)) {
            $settings[$section] = $incoming[$section];
        }
    }

    writeJsonFile(settingsPath(), $settings);
    ok(['message' => 'Configuracion guardada', 'settings' => $settings]);
}

if ($action === 'save_user') {
    requireAdmin();

    $users = readUsers();
    $id = (int)($payload['id'] ?? 0);
    $username = trim((string)($payload['username'] ?? ''));
    $name = trim((string)($payload['name'] ?? ''));
    $role = (string)($payload['role'] ?? 'cashier');
    $active = (bool)($payload['active'] ?? true);
    $password = (string)($payload['password'] ?? '');
    $permissions = normalizePermissionsConfig($payload['permissions'] ?? null, $role);

    if ($username === '' || $name === '') {
        errorResponse('Usuario y nombre son obligatorios', 400);
    }
    if (!in_array($role, ['admin', 'cashier'], true)) {
        errorResponse('Rol invalido', 400);
    }

    foreach ($users as $existing) {
        if ((int)($existing['id'] ?? 0) !== $id && strcasecmp((string)($existing['username'] ?? ''), $username) === 0) {
            errorResponse('El nombre de usuario ya existe', 409);
        }
    }

    if ($id > 0) {
        $found = false;
        foreach ($users as &$user) {
            if ((int)($user['id'] ?? 0) !== $id) {
                continue;
            }
            $user['username'] = $username;
            $user['name'] = $name;
            $user['role'] = $role;
            $user['active'] = $active;
            $user['permissions'] = $permissions;
            if ($password !== '') {
                $user['password'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $found = true;
            break;
        }
        unset($user);
        if (!$found) {
            errorResponse('Usuario no encontrado', 404);
        }
    } else {
        if ($password === '') {
            errorResponse('La contrasena es obligatoria para usuarios nuevos', 400);
        }
        $maxId = 0;
        foreach ($users as $u) {
            $maxId = max($maxId, (int)($u['id'] ?? 0));
        }
        $users[] = [
            'id' => $maxId + 1,
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'name' => $name,
            'role' => $role,
            'active' => $active,
            'permissions' => $permissions,
            'createdAt' => gmdate('c'),
        ];
    }

    writeJsonFile(usersPath(), $users);
    ok(['message' => 'Usuario guardado', 'users' => sanitizeUsers($users)]);
}

if ($action === 'toggle_user') {
    requireAdmin();
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        errorResponse('Usuario invalido', 400);
    }

    $users = readUsers();
    $found = false;
    foreach ($users as &$user) {
        if ((int)($user['id'] ?? 0) !== $id) {
            continue;
        }
        $user['active'] = !((bool)($user['active'] ?? true));
        $found = true;
        break;
    }
    unset($user);

    if (!$found) {
        errorResponse('Usuario no encontrado', 404);
    }

    writeJsonFile(usersPath(), $users);
    ok(['message' => 'Estado de usuario actualizado', 'users' => sanitizeUsers($users)]);
}

errorResponse('Accion no soportada', 400);
