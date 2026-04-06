<?php
// Simple front controller for module routing
declare(strict_types=1);

// Start session
session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: ../vista/login.php');
    exit;
}

if (isset($_SESSION['pending_cash_opening']) && $_SESSION['pending_cash_opening']) {
    header('Location: ../vista/caja-inicial.php');
    exit;
}

// Basic autoload for utils if needed later
setlocale(LC_ALL, 'es_ES.UTF-8');
header('X-Content-Type-Options: nosniff');

$mod = $_GET['mod'] ?? 'ventas';

$baseDir = dirname(__DIR__);
$vistaDir = $baseDir . DIRECTORY_SEPARATOR . 'vista';

function safeInclude(string $path): void {
    if (is_file($path)) {
        include_once $path;
    } else {
        http_response_code(404);
        echo "<!doctype html><meta charset='utf-8'><title>No encontrado</title><h1>Vista no encontrada</h1><p>" .
             htmlspecialchars($path) . "</p>";
    }
}

// Layout parts
$header = $vistaDir . '/layout/header.php';
$menu   = $vistaDir . '/layout/menu.php';

// Module paths
switch ($mod) {
    case 'ventas':
        $content = $vistaDir . '/ventas/venta.php';
        break;
    case 'creditos':
        $sub = (string)($_GET['sub'] ?? 'estado');
        if ($sub === 'reporte') {
            $content = $vistaDir . '/creditos/reporte-saldos.php';
        } elseif ($sub === 'estado-cliente') {
            $content = $vistaDir . '/creditos/estado-cliente.php';
        } else {
            $content = $vistaDir . '/creditos/creditos.php';
        }
        break;
    case 'clientes':
        $content = $vistaDir . '/clientes/clientes.php';
        break;
    case 'productos':
        $sub = (string)($_GET['sub'] ?? 'nuevo');
        if ($sub === 'departamentos') {
            $content = $vistaDir . '/productos/departamento.php';
        } elseif ($sub === 'catalogo') {
            $content = $vistaDir . '/productos/catalogo.php';
        } elseif ($sub === 'periodos') {
            $content = $vistaDir . '/productos/periodos.php';
        } elseif ($sub === 'promociones') {
            $content = $vistaDir . '/productos/promociones.php';
        } elseif ($sub === 'importar') {
            $content = $vistaDir . '/productos/importar.php';
        } else {
            $content = $vistaDir . '/productos/productos.php';
        }
        break;
    case 'inventario':
        $sub = (string)($_GET['sub'] ?? 'reporte');
        $allowedInvSubs = ['agregar', 'ajustes', 'bajos', 'reporte', 'movimientos', 'kardex'];
        if (!in_array($sub, $allowedInvSubs, true)) {
            $sub = 'reporte';
        }
        $content = $vistaDir . '/inventario/' . $sub . '.php';
        break;
    case 'compras':
        $sub = (string)($_GET['sub'] ?? 'suggested');
        $allowedBuySubs = ['suggested', 'list', 'orders', 'providers', 'history'];
        if (!in_array($sub, $allowedBuySubs, true)) {
            $sub = 'suggested';
        }
        $subMap = [
            'suggested' => 'sugeridas',
            'list'      => 'lista',
            'orders'    => 'ordenes',
            'providers' => 'proveedores',
            'history'   => 'historicos',
        ];
        $content = $vistaDir . '/compras/' . $subMap[$sub] . '.php';
        break;
    case 'corte':
        $content = $vistaDir . '/corte/corte.php';
        break;
    case 'facturas':
        $content = $vistaDir . '/facturas/modulo.php';
        break;
    default:
        $content = $vistaDir . '/en-construccion.php';
        break;
}

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>POS Minimarket</title>
  <link rel="stylesheet" href="assets/css/pos.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
  <script src="assets/js/pos.js" defer></script>
  <script src="assets/js/clientes.js" defer></script>
  <script src="assets/js/creditos.js" defer></script>
  <script src="assets/js/creditos_estado_cliente.js" defer></script>
  <script src="assets/js/productos.js" defer></script>
  <script src="assets/js/compras.js" defer></script>
    <script src="assets/js/inventario.js" defer></script>
  <script src="assets/js/corte.js" defer></script>
  <script src="assets/js/facturas.js" defer></script>
</head>
<body>
<div class="app-chrome">
<?php safeInclude($header); ?>
<?php safeInclude($menu); ?>
</div>
<main class="pos-main">
  <?php safeInclude($content); ?>
</main>
</body>
</html>

