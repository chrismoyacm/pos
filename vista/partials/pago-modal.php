<div id="modal-pago" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header>Pago (F12)</header>
    <section>
      <label class="field">Total
        <input type="text" name="total" readonly>
      </label>
      <label class="field">Metodo de pago
        <select name="metodoPago">
          <option value="cash">Efectivo</option>
          <option value="card">Tarjeta de Credito</option>
          <option value="credit">Credito</option>
          <option value="voucher">Vales de Despensa</option>
          <option value="transfer">Transferencia</option>
          <option value="check">Cheque</option>
        </select>
      </label>
      <label class="field">Pago con
        <input type="number" step="0.01" name="pagoCon" placeholder="0.00">
      </label>
      <div><strong>Cambio:</strong> <span data-cambio>$0.00</span></div>
    </section>
    <footer>
      <button type="button" class="btn-secondary">Cancelar</button>
      <button type="button" class="btn-primary">Confirmar</button>
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
    <header>Movimiento de inventario</header>
    <section>
      <div class="muted">Entradas (F7) suma stock, Salidas (F8) resta stock.</div>
      <input type="hidden" name="tipo" value="">
      <label class="field">Codigo/ID
        <input type="text" name="codigo" placeholder="Codigo de barras o ID">
      </label>
      <label class="field">Cantidad
        <input type="number" name="cantidad" value="1" min="1" step="1">
      </label>
    </section>
    <footer>
      <button type="button" class="btn-secondary">Cancelar</button>
      <button type="button" class="btn-primary">Aplicar</button>
    </footer>
  </div>
</div>
