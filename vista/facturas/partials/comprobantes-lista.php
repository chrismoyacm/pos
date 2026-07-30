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
  <?php if ($currentView === 'administracion'): ?>
  <div class="facturacion-report-panel" id="facturacion-report-panel">
    <div class="facturacion-report-head">
      <div>
        <strong>Reporte de facturas</strong>
        <span>Revise cuanto se ha facturado por rango de fechas.</span>
      </div>
      <div class="facturacion-report-filters">
        <label>Desde
          <input type="date" id="facturacion-report-date-from">
        </label>
        <label>Hasta
          <input type="date" id="facturacion-report-date-to">
        </label>
        <label>Estado
          <select id="facturacion-report-status">
            <option value="">Todos</option>
            <option value="authorized">Autorizados</option>
          </select>
        </label>
        <button class="btn-secondary" type="button" id="facturacion-report-clear">Limpiar filtros</button>
      </div>
    </div>
    <div class="facturacion-report-grid">
      <div class="facturacion-report-card"><span>Total de facturas</span><strong data-invoice-report="count">0</strong></div>
      <div class="facturacion-report-card"><span>Total facturado</span><strong data-invoice-report="total">$0.00</strong></div>
      <div class="facturacion-report-card"><span>Total facturado IVA 0%</span><strong data-invoice-report="total0">$0.00</strong></div>
      <div class="facturacion-report-card"><span>Subtotal IVA 0%</span><strong data-invoice-report="subtotal0">$0.00</strong></div>
      <div class="facturacion-report-card"><span>Total facturado IVA 15%</span><strong data-invoice-report="total15">$0.00</strong></div>
      <div class="facturacion-report-card"><span>Subtotal IVA 15%</span><strong data-invoice-report="subtotal15">$0.00</strong></div>
    </div>
  </div>
  <?php endif; ?>
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
