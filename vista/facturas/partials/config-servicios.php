<section class="facturacion-card" id="facturacion-servicios-page">
  <div class="facturacion-card-head">
    <h3>Productos y servicios</h3>
    <p>Relacion entre productos del POS y codigos requeridos para facturacion electronica.</p>
  </div>
  <div class="facturacion-toolbar">
    <input type="text" id="facturacion-servicios-search" placeholder="Buscar producto">
    <button class="btn-secondary" type="button" id="facturacion-servicios-refresh">Actualizar</button>
  </div>
  <div class="facturacion-table-wrap">
    <table class="grid grid-compact factura-table">
      <thead>
        <tr>
          <th>Codigo POS</th>
          <th>Producto</th>
          <th>Codigo principal</th>
          <th>Codigo auxiliar</th>
          <th>IVA</th>
          <th>ICE</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody id="facturacion-servicios-body">
        <tr><td colspan="7" class="factura-empty">Cargando productos...</td></tr>
      </tbody>
    </table>
  </div>
</section>
