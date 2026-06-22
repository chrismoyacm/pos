<?php
$currentView = (string)($_GET['view'] ?? 'administracion');
$titles = [
    'administracion' => 'Administracion de comprobantes',
    'no-autorizados' => 'Comprobantes no autorizados',
    'pendientes-anular' => 'Pendientes de anular',
    'anulados' => 'Historial de anulados',
];
?>
<section class="facturacion-card" id="facturacion-comprobantes-page" data-doc-view="<?php echo htmlspecialchars($currentView); ?>">
  <div class="facturacion-card-head">
    <h3><?php echo htmlspecialchars($titles[$currentView] ?? 'Comprobantes'); ?></h3>
    <p>Seguimiento del estado del flujo SRI, archivos generados y reproceso de documentos rechazados.</p>
  </div>
  <div class="facturacion-toolbar">
    <input type="text" id="facturacion-document-search" placeholder="Buscar por clave, cliente o secuencial">
    <button class="btn-secondary" type="button" id="facturacion-document-refresh">Actualizar</button>
  </div>
  <div class="facturacion-status facturacion-status--wide" id="facturacion-document-status"></div>
  <div class="facturacion-table-wrap">
    <table class="grid grid-compact factura-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Tipo</th>
          <th>Secuencial</th>
          <th>Ambiente</th>
          <th>Marca</th>
          <th>Cliente</th>
          <th>Estado interno</th>
          <th>Recepcion SRI</th>
          <th>Autorizacion SRI</th>
          <th>Clave acceso</th>
          <th>Archivos</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody id="facturacion-document-body">
        <tr><td colspan="12" class="factura-empty">No existen comprobantes</td></tr>
      </tbody>
    </table>
  </div>
</section>
