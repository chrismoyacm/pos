<section class="facturacion-card" id="facturacion-puntos-page">
  <div class="facturacion-card-head">
    <h3>Puntos de emision</h3>
    <p>Administra establecimiento, punto de emision, direccion y secuencial actual.</p>
  </div>
  <div class="facturacion-split">
    <div class="facturacion-card facturacion-card--flat">
      <div class="facturacion-table-wrap">
        <table class="grid grid-compact factura-table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Serie</th>
              <th>Secuencial</th>
            </tr>
          </thead>
          <tbody id="facturacion-puntos-body">
            <tr><td colspan="3" class="factura-empty">No existen puntos de emision</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="facturacion-card facturacion-card--flat">
      <form class="facturacion-form facturacion-grid-2" id="facturacion-punto-form">
        <input type="hidden" name="id">
        <label class="factura-field">
          <span>Nombre</span>
          <input type="text" name="nombre">
        </label>
        <label class="factura-field">
          <span>Establecimiento</span>
          <input type="text" name="estab">
        </label>
        <label class="factura-field">
          <span>Punto de emision</span>
          <input type="text" name="ptoEmi">
        </label>
        <label class="factura-field">
          <span>Secuencial actual</span>
          <input type="number" name="secuencialActual" min="0" step="1">
        </label>
        <label class="factura-field factura-field--wide">
          <span>Direccion del establecimiento</span>
          <input type="text" name="dirEstablecimiento">
        </label>
      </form>
      <div class="facturacion-card-footer">
        <div class="facturacion-status" id="facturacion-punto-status"></div>
        <div class="facturacion-inline-actions">
          <button class="btn-secondary" type="button" id="facturacion-punto-new">Nuevo</button>
          <button class="btn-danger" type="button" id="facturacion-punto-delete">Eliminar</button>
          <button class="btn-primary" type="button" id="facturacion-punto-save">Guardar punto</button>
        </div>
      </div>
    </div>
  </div>
</section>
