<?php
declare(strict_types=1);

require_once __DIR__ . '/../utils/response.php';
require_once __DIR__ . '/../utils/json_store.php';
require_once __DIR__ . '/../utils/db.php';

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
            'drawer_printer_model' => 'Epson TM-U220',
            'drawer_connection' => 'USB',
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
                'model' => 'POS-80C',
                'connection' => 'USB',
                'name' => 'Impresora tickets',
                'font_family' => 'Consolas',
                'font_size' => 10,
                'columns' => 45,
                'use_normal_for_totals' => false,
                'bold_letters' => true,
            ],
            'barcode_reader' => [
                'enabled' => true,
                'model' => 'SU13',
                'name' => 'Lector codigo barras',
                'suffix_key' => 'ENTER',
                'serial_enabled' => false,
                'serial_port' => '',
                'serial_baud' => 9600,
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $defaults
 * @param mixed $current
 * @return array<string, mixed>
 */
function mergeSettingsDefaults(array $defaults, mixed $current): array
{
    if (!is_array($current)) {
        return $defaults;
    }

    $merged = $defaults;
    foreach ($defaults as $key => $defaultValue) {
        if (!array_key_exists($key, $current)) {
            continue;
        }

        $currentValue = $current[$key];
        if (is_array($defaultValue)) {
            $merged[$key] = mergeSettingsDefaults($defaultValue, $currentValue);
            continue;
        }

        $merged[$key] = $currentValue;
    }

    return $merged;
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
 * @param array<string, mixed> $settings
 */
function writeSettingsWithoutTaxes(array $settings): void
{
    unset($settings['taxes']);
    writeJsonFile(settingsPath(), $settings);
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
 * @param mixed $value
 */
function parseTaxRate(mixed $value): float
{
    $raw = strtolower(trim((string)$value));
    if ($raw === '') {
        return 0.0;
    }
    $raw = str_replace(['iva', '%', ' '], '', $raw);
    return round((float)$raw, 2);
}

/**
 * @return array<int, array{id:string,name:string,percentage:float,active:bool,is_default:bool}>
 */
function readProductTaxesRowsFromDb(): array
{
    if (!dbEnabled()) {
        return [];
    }

    $rows = db()->query(
        "SELECT ID, NOMBRE, PORCENTAJE, DEFECTO, ACTIVO
         FROM IMPUESTOS
         WHERE ORIGEN = 'productos' AND TIPO = 'iva'
         ORDER BY CAST(ID AS UNSIGNED) ASC, ID ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $rate = parseTaxRate($row['PORCENTAJE'] ?? 0);
        if ($rate <= 0) {
            continue;
        }
        $id = trim((string)($row['ID'] ?? ''));
        if ($id === '') {
            continue;
        }

        $out[] = [
            'id' => $id,
            'name' => trim((string)($row['NOMBRE'] ?? ('IVA ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%'))),
            'percentage' => $rate,
            'active' => ((string)($row['ACTIVO'] ?? '1') === '1'),
            'is_default' => ((string)($row['DEFECTO'] ?? '0') === '1'),
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $fallback
 * @return array<string, mixed>
 */
function readTaxesSettingsFromDb(array $fallback): array
{
    $taxes = $fallback;

    try {
        $rows = readProductTaxesRowsFromDb();
    } catch (Throwable) {
        return $taxes;
    }

    if ($rows === []) {
        return $taxes;
    }

    $options = [];
    $defaultRate = 0.0;
    foreach ($rows as $row) {
        $options[] = [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'percentage' => (float)$row['percentage'],
            'active' => (bool)$row['active'],
        ];
        if ((bool)$row['is_default']) {
            $defaultRate = (float)$row['percentage'];
        }
    }
    if ($defaultRate <= 0 && isset($options[0]['percentage'])) {
        $defaultRate = (float)$options[0]['percentage'];
    }

    $taxes['default_vat'] = $defaultRate > 0 ? $defaultRate : (float)($taxes['default_vat'] ?? 0);
    $taxes['iva_options'] = $options;

    return $taxes;
}

function disableLegacyJsonTaxSyncTriggers(): void
{
    if (!dbEnabled()) {
        return;
    }
    try {
        db()->exec('DROP TRIGGER IF EXISTS TRG_POS_JSON_IVA_AI');
        db()->exec('DROP TRIGGER IF EXISTS TRG_POS_JSON_IVA_AU');
    } catch (Throwable) {
        // Ignorar: no debe bloquear guardado de configuracion.
    }
}

/**
 * @param array<string, mixed> $incoming
 * @param array<string, mixed> $current
 * @return array<string, mixed>
 */
function saveTaxesSettingsToDb(array $incoming, array $current): array
{
    if (!dbEnabled()) {
        throw new RuntimeException('Base de datos deshabilitada para guardar impuestos');
    }

    $merged = mergeSettingsDefaults($current, $incoming);
    $defaultRate = parseTaxRate($merged['default_vat'] ?? 0);

    $existingRows = readProductTaxesRowsFromDb();
    $optionsRaw = $incoming['iva_options'] ?? null;
    if (!is_array($optionsRaw) || $optionsRaw === []) {
        $optionsRaw = array_map(static function (array $row): array {
            return [
                'name' => $row['name'],
                'percentage' => $row['percentage'],
                'active' => $row['active'],
            ];
        }, $existingRows);

        // Si el formulario solo envia default_vat (sin lista), agregar ese IVA nuevo automaticamente.
        if ($defaultRate > 0) {
            $existsDefaultRate = false;
            foreach ($optionsRaw as $opt) {
                $rate = parseTaxRate($opt['percentage'] ?? 0);
                if (abs($rate - $defaultRate) < 0.0001) {
                    $existsDefaultRate = true;
                    break;
                }
            }
            if (!$existsDefaultRate) {
                $vatName = trim((string)($incoming['vat_name'] ?? $current['vat_name'] ?? 'IVA'));
                if ($vatName === '') {
                    $vatName = 'IVA';
                }
                $optionsRaw[] = [
                    'name' => $vatName . ' ' . rtrim(rtrim(number_format($defaultRate, 2, '.', ''), '0'), '.') . '%',
                    'percentage' => $defaultRate,
                    'active' => true,
                ];
            }
        }
    }
    if (!is_array($optionsRaw) || $optionsRaw === []) {
        $optionsRaw = [
            ['name' => 'IVA 12%', 'percentage' => 12, 'active' => true],
            ['name' => 'IVA 15%', 'percentage' => 15, 'active' => true],
        ];
    }

    $normalizedOptions = [];
    foreach ($optionsRaw as $opt) {
        if (!is_array($opt)) {
            continue;
        }
        $rate = parseTaxRate($opt['percentage'] ?? 0);
        if ($rate <= 0) {
            continue;
        }
        $name = trim((string)($opt['name'] ?? ''));
        if ($name === '') {
            $name = 'IVA ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
        }
        $normalizedOptions[] = [
            'name' => $name,
            'percentage' => $rate,
            'active' => array_key_exists('active', $opt) ? (bool)$opt['active'] : true,
        ];
    }

    if ($normalizedOptions === []) {
        $normalizedOptions = [
            ['name' => 'IVA 12%', 'percentage' => 12.0, 'active' => true],
            ['name' => 'IVA 15%', 'percentage' => 15.0, 'active' => true],
        ];
    }

    if ($defaultRate <= 0) {
        $defaultRate = (float)$normalizedOptions[0]['percentage'];
    }

    $oldRateById = [];
    foreach ($existingRows as $row) {
        $oldRateById[(string)$row['id']] = (float)$row['percentage'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM IMPUESTOS WHERE ORIGEN = 'productos' AND TIPO = 'iva'");

        $insert = $pdo->prepare(
            "INSERT INTO IMPUESTOS (ID, NOMBRE, PORCENTAJE, DEFECTO, ACTIVO, ORIGEN, BASE_GRAVABLE, TIPO)
             VALUES (:id, :nombre, :porcentaje, :defecto, :activo, 'productos', '100', 'iva')"
        );

        $newIdByRate = [];
        $validNewIds = [];
        $defaultId = '1';
        $defaultAssigned = false;
        $sequence = 1;
        foreach ($normalizedOptions as $opt) {
            $id = (string)$sequence;
            $rate = (float)$opt['percentage'];
            $isDefault = (!$defaultAssigned && abs($rate - $defaultRate) < 0.0001);
            if ($isDefault) {
                $defaultAssigned = true;
                $defaultId = $id;
            }

            $insert->execute([
                ':id' => $id,
                ':nombre' => (string)$opt['name'],
                ':porcentaje' => number_format($rate, 2, '.', ''),
                ':defecto' => $isDefault ? '1' : '0',
                ':activo' => !empty($opt['active']) ? '1' : '0',
            ]);

            $newIdByRate[number_format($rate, 2, '.', '')] = $id;
            $validNewIds[$id] = true;
            $sequence++;
        }

        if (!$defaultAssigned) {
            $defaultId = '1';
            $pdo->exec("UPDATE IMPUESTOS SET DEFECTO = '0' WHERE ORIGEN = 'productos' AND TIPO = 'iva'");
            $stmtDefault = $pdo->prepare("UPDATE IMPUESTOS SET DEFECTO = '1' WHERE ORIGEN = 'productos' AND TIPO = 'iva' AND ID = :id");
            $stmtDefault->execute([':id' => $defaultId]);
        }

        $productRows = $pdo->query("SELECT ID, IMPUESTOS FROM PRODUCTOS")->fetchAll();
        $updateProduct = $pdo->prepare("UPDATE PRODUCTOS SET IMPUESTOS = :tax WHERE ID = :id");
        foreach ($productRows as $product) {
            $currentTax = trim((string)($product['IMPUESTOS'] ?? ''));
            if ($currentTax === '' || $currentTax === '0') {
                continue;
            }

            $newTaxId = null;
            if (preg_match('/^[0-9]+$/', $currentTax) === 1 && isset($validNewIds[$currentTax])) {
                $newTaxId = $currentTax;
            } elseif (isset($oldRateById[$currentTax])) {
                $rateKey = number_format((float)$oldRateById[$currentTax], 2, '.', '');
                $newTaxId = $newIdByRate[$rateKey] ?? $defaultId;
            } else {
                $parsedRate = parseTaxRate($currentTax);
                if ($parsedRate > 0) {
                    $rateKey = number_format($parsedRate, 2, '.', '');
                    $newTaxId = $newIdByRate[$rateKey] ?? $defaultId;
                }
            }

            if ($newTaxId === null || $newTaxId === $currentTax) {
                continue;
            }
            $updateProduct->execute([
                ':tax' => $newTaxId,
                ':id' => (int)$product['ID'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return readTaxesSettingsFromDb($merged);
}

/**
 * @return array<string, mixed>
 */
function readSettings(): array
{
    $defaults = defaultSettings();
    $settings = readJsonFile(settingsPath());
    if (!is_array($settings) || $settings === []) {
        $settings = $defaults;
        writeSettingsWithoutTaxes($settings);
    } else {
        $merged = mergeSettingsDefaults($defaults, $settings);
        if ($merged !== $settings) {
            writeSettingsWithoutTaxes($merged);
        }
        $settings = $merged;
    }

    $taxDefaults = is_array($settings['taxes'] ?? null) ? $settings['taxes'] : $defaults['taxes'];
    try {
        $settings['taxes'] = readTaxesSettingsFromDb($taxDefaults);
    } catch (Throwable) {
        $settings['taxes'] = $taxDefaults;
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
    disableLegacyJsonTaxSyncTriggers();
    $settings = readSettings();
    $incoming = $payload['settings'] ?? null;
    if (!is_array($incoming)) {
        errorResponse('Configuracion invalida', 400);
    }

    foreach ($settings as $section => $value) {
        if ($section === 'taxes') {
            continue;
        }
        if (array_key_exists($section, $incoming)) {
            $settings[$section] = $incoming[$section];
        }
    }

    if (array_key_exists('taxes', $incoming)) {
        if (!is_array($incoming['taxes'])) {
            errorResponse('Configuracion de impuestos invalida', 400);
        }
        try {
            $settings['taxes'] = saveTaxesSettingsToDb($incoming['taxes'], is_array($settings['taxes'] ?? null) ? $settings['taxes'] : defaultSettings()['taxes']);
        } catch (Throwable $e) {
            errorResponse('No se pudo guardar impuestos en la base de datos: ' . $e->getMessage(), 500);
        }
    } else {
        $settings['taxes'] = readTaxesSettingsFromDb(is_array($settings['taxes'] ?? null) ? $settings['taxes'] : defaultSettings()['taxes']);
    }

    writeSettingsWithoutTaxes($settings);
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
