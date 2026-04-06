<?php
declare(strict_types=1);

$section = (string)($_GET['section'] ?? 'emision');
$view = (string)($_GET['view'] ?? 'factura');

$groups = [
    'configuracion' => [
        'label' => 'Configuracion',
        'items' => [
            'Seleccionar' => 'Seleccionar',
            'emisor' => 'Datos Emisor',
            'firma' => 'Perfil y firma',
            'servicios' => 'Productos y servicios',
            'carga' => 'Carga masiva',
            'puntos' => 'Puntos de emision',
        ],
    ],
    'emision' => [
        'label' => 'Emision',
        'items' => [
            'Seleccionar' => 'Seleccionar',
            'factura' => 'Factura',
            'nota-credito' => 'Nota de credito',
            'nota-debito' => 'Nota de debito',
            'guia-remision' => 'Guia de remision',
            'retencion' => 'Comprobante de retencion',
            'liquidacion' => 'Liquidacion de compra de bienes y prestacion de servicios',
        ],
    ],
    'comprobantes' => [
        'label' => 'Comprobantes',
        'items' => [
            'Seleccionar' => 'Seleccionar',
            'administracion' => 'Administracion',
            'no-autorizados' => 'No autorizados',
            'pendientes-anular' => 'Pendientes de anular',
            'anulados' => 'Historial de anulados',
        ],
    ],
];

$partialMap = [
    'configuracion:emisor' => __DIR__ . '/partials/config-emisor.php',
    'configuracion:firma' => __DIR__ . '/partials/config-firma.php',
    'configuracion:servicios' => __DIR__ . '/partials/config-servicios.php',
    'configuracion:carga' => __DIR__ . '/partials/config-carga.php',
    'configuracion:puntos' => __DIR__ . '/partials/config-puntos.php',
    'emision:factura' => __DIR__ . '/partials/emision-factura.php',
    'emision:nota-credito' => __DIR__ . '/partials/emision-placeholder.php',
    'emision:nota-debito' => __DIR__ . '/partials/emision-placeholder.php',
    'emision:guia-remision' => __DIR__ . '/partials/emision-placeholder.php',
    'emision:retencion' => __DIR__ . '/partials/emision-placeholder.php',
    'emision:liquidacion' => __DIR__ . '/partials/emision-placeholder.php',
    'comprobantes:administracion' => __DIR__ . '/partials/comprobantes-lista.php',
    'comprobantes:no-autorizados' => __DIR__ . '/partials/comprobantes-lista.php',
    'comprobantes:pendientes-anular' => __DIR__ . '/partials/comprobantes-lista.php',
    'comprobantes:anulados' => __DIR__ . '/partials/comprobantes-lista.php',
];

$activeKey = $section . ':' . $view;
$partial = $partialMap[$activeKey] ?? __DIR__ . '/partials/emision-factura.php';

?>
<section class="facturacion-module" id="facturacion-module" data-section="<?php echo htmlspecialchars($section); ?>" data-view="<?php echo htmlspecialchars($view); ?>">
  <div class="facturacion-topbar">
    <h2>FACTURACION ELECTRONICA</h2>
    <div class="facturacion-topbar-actions">
      <span class="muted">Flujo SRI: XML -> Firma -> Recepcion -> Autorizacion -> PDF -> Correo</span>
    </div>
  </div>

  <div class="facturacion-nav">
    <?php foreach ($groups as $groupKey => $group): ?>
      <div class="facturacion-nav-group">
        <div class="facturacion-nav-title"><?php echo htmlspecialchars($group['label']); ?></div>
        <label class="facturacion-nav-select-wrap">
          <select class="facturacion-nav-select" data-facturacion-nav="<?php echo htmlspecialchars($groupKey); ?>">
            <?php foreach ($group['items'] as $viewKey => $label): ?>
              <option value="<?php echo htmlspecialchars($viewKey); ?>" <?php echo ($groupKey === $section && $viewKey === $view) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="facturacion-content">
    <?php include $partial; ?>
  </div>
</section>
