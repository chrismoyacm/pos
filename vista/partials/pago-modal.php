<div id="modal-pago" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header>Pago (F12)</header>
    <section>
      <label class="field">Subtotal
        <input type="text" name="subtotal" readonly>
      </label>
      <label class="field">Descuento
        <input type="text" name="discount" readonly>
      </label>
      <label class="field">Total
        <input type="text" name="total" readonly>
      </label>
      <input type="hidden" name="metodoPago" value="cash">

      <div class="pay-method-picker" id="pay-method-picker" role="radiogroup" aria-label="Metodo de pago">
        <button type="button" class="pay-method-option active" data-payment-method="cash" role="radio" aria-checked="true">Efectivo</button>
        <button type="button" class="pay-method-option" data-payment-method="credit" role="radio" aria-checked="false">Crédito</button>
        <button type="button" class="pay-method-option" data-payment-method="mixed" role="radio" aria-checked="false">Mixto</button>
        <button type="button" class="pay-method-option" data-payment-method="transfer" role="radio" aria-checked="false">Transferencia</button>
      </div>

      <label class="field" id="pay-single-field">Pago con
        <input type="number" step="0.01" name="pagoCon" placeholder="0.00">
      </label>
      <div class="pay-mixed-grid" id="pay-mixed-grid" hidden>
        <label class="field">Efectivo
          <input type="number" step="0.01" min="0" name="pagoConEfectivo" placeholder="0.00">
        </label>
        <label class="field">Transferencia
          <input type="number" step="0.01" min="0" name="pagoConTarjeta" placeholder="0.00">
        </label>
      </div>
      <div id="pay-transfer-fields" hidden>
        <label class="field">Referencia
          <input type="text" name="transferRef" placeholder="Número de referencia">
        </label>
        <label class="field">Número de teléfono
          <input type="text" name="transferPhone" placeholder="Opcional">
        </label>
      </div>
      <div class="pay-credit-info" id="pay-credit-info" hidden>
        Venta a crédito: asigne un cliente y la venta se registra como saldo pendiente.
      </div>
      <div class="pay-note-preview" id="pay-note-preview">Nota: -</div>
      <div><strong>Cambio:</strong> <span data-cambio>$0.00</span></div>
    </section>
    <footer>
      <button type="button" class="btn-secondary" id="btn-pay-note">F4 - Ingresar notas</button>
      <button type="button" class="btn-secondary" id="btn-pay-cancel">ESC - Cancelar</button>
      <button type="button" class="btn-primary" id="btn-pay-confirm">F2 - Cobrar sin imprimir</button>
      <button type="button" class="btn-primary" id="btn-pay-confirm-print">F1 - Cobrar e imprimir</button>
    </footer>
  </div>
</div>

<div id="modal-common" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header>Articulo Comun (Ctrl+P)</header>
    <section>
      <label class="field">Descripcion
        <input type="text" name="descripcion" placeholder="Descripcion del producto">
      </label>
      <label class="field">Precio
        <input type="number" step="0.01" name="precio" placeholder="0.00">
      </label>
      <label class="field">Cantidad
        <input type="number" step="1" name="cantidad" value="1">
      </label>
    </section>
    <footer>
      <button type="button" class="btn-secondary">Cancelar</button>
      <button type="button" class="btn-primary">Agregar</button>
    </footer>
  </div>
</div>

<div id="modal-buscar" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header>Buscar producto (F10)</header>
    <section>
      <input type="text" name="q" placeholder="Buscar por codigo o descripcion">
      <div class="muted">Click en un resultado para agregar.</div>
      <div style="max-height:240px;overflow:auto;border:1px solid #e5e7eb;border-radius:6px">
        <table class="grid" style="margin:0">
          <thead>
            <tr>
              <th style="width:140px">Codigo</th>
              <th>Producto</th>
              <th style="width:110px">Precio</th>
              <th style="width:110px">Stock</th>
            </tr>
          </thead>
          <tbody data-results></tbody>
        </table>
      </div>
    </section>
    <footer>
      <button type="button" class="btn-secondary">Cerrar</button>
    </footer>
  </div>
</div>

<div id="modal-cliente" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header>Asignar cliente</header>
    <section>
      <input type="text" name="q" placeholder="Buscar cliente">
      <div style="max-height:220px;overflow:auto;border:1px solid #e5e7eb;border-radius:6px">
        <table class="grid" style="margin:0">
          <thead>
            <tr>
              <th style="width:120px">ID</th>
              <th>Nombre</th>
            </tr>
          </thead>
          <tbody data-results></tbody>
        </table>
      </div>
    </section>
    <footer>
      <button type="button" class="btn-secondary">Cerrar</button>
    </footer>
  </div>
</div>

<div id="modal-stock" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header>Movimiento de efectivo</header>
    <section>
      <div class="muted" id="cash-movement-help">Registre entradas o salidas de efectivo en caja.</div>
      <input type="hidden" name="tipo" value="">
      <label class="field">Cantidad
        <input type="number" name="amount" value="0.00" min="0.01" step="0.01">
      </label>
      <label class="field" id="cash-movement-note-label"><span id="cash-movement-note-title">Comentario</span>
        <input type="text" name="note" placeholder="Entrada de dinero">
      </label>
    </section>
    <footer>
      <button type="button" class="btn-secondary">Cancelar</button>
      <button type="button" class="btn-primary">Guardar</button>
    </footer>
  </div>
</div>

<div id="modal-ventas-dia" class="modal" aria-hidden="true">
  <div class="modal-card modal-card--wide" role="dialog" aria-modal="true">
    <header>Historial de ventas</header>
    <section>
      <div class="modal-sales-toolbar">
        <label class="field">Fecha
          <input type="date" id="sales-day-date">
        </label>
        <label class="field modal-sales-search">Buscar
          <input type="text" id="sales-day-q" placeholder="Folio o cliente">
        </label>
        <button type="button" class="btn-secondary" id="sales-day-refresh">Actualizar</button>
      </div>

      <div class="modal-sales-grid">
        <div class="modal-sales-list">
          <table class="grid grid-compact">
            <thead>
              <tr>
                <th style="width:70px">Folio</th>
                <th style="width:120px">Hora</th>
                <th>Cliente</th>
                <th style="width:110px">Total</th>
              </tr>
            </thead>
            <tbody id="sales-day-body"></tbody>
          </table>
        </div>

        <div class="modal-sales-detail">
          <div class="modal-sales-meta" id="sales-day-meta">Seleccione un ticket.</div>
          <table class="grid grid-compact">
            <thead>
              <tr>
                <th style="width:70px">Cant.</th>
                <th>Descripción</th>
                <th style="width:95px">Devuelto</th>
                <th style="width:110px">Importe</th>
              </tr>
            </thead>
            <tbody id="sales-day-items"></tbody>
          </table>
        </div>
      </div>

      <div class="muted" id="sales-day-help">Puede revisar ventas del día.</div>
    </section>
    <footer>
      <button type="button" class="btn-secondary" id="sales-day-close">Cerrar</button>
      <button type="button" class="btn-danger" id="sales-day-return-btn" disabled>Devolver artículo seleccionado</button>
    </footer>
  </div>
</div>

<div id="modal-discount" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header>Descuento de venta</header>
    <section>
      <label class="field">Descuento (%)
        <input type="number" id="sale-discount-pct" min="0" max="100" step="0.01" placeholder="0.00">
      </label>
      <div class="muted">Se aplicará a todos los productos de esta venta actual.</div>
    </section>
    <footer>
      <button type="button" class="btn-secondary" id="sale-discount-clear">Quitar descuento</button>
      <button type="button" class="btn-secondary" id="sale-discount-cancel">Cancelar</button>
      <button type="button" class="btn-primary" id="sale-discount-apply">Aplicar</button>
    </footer>
  </div>
</div>
