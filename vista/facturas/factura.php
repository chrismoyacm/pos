<?php
declare(strict_types=1);

$issueDate = date('d-m-Y');
?>
<section class="factura-wrap" id="factura-module">
  <div class="factura-titlebar">
    <h2>FACTURA</h2>
    <button class="btn-secondary" type="button">Pendientes</button>
  </div>

  <div class="factura-body">
    <section class="factura-card">
      <div class="factura-grid factura-grid--top">
        <label class="factura-field">
          <span>Establecimiento</span>
          <select>
            <option>Seleccione</option>
          </select>
        </label>

        <label class="factura-field">
          <span>Nombre comercial</span>
          <input type="text" value="">
        </label>

        <label class="factura-field">
          <span>Fecha de emision</span>
          <input type="text" value="<?php echo htmlspecialchars($issueDate); ?>" readonly>
        </label>

        <label class="factura-field">
          <span>Punto de emision</span>
          <select>
            <option>Seleccione</option>
          </select>
        </label>

        <label class="factura-field">
          <span>Guia de remision</span>
          <input type="text" value="">
        </label>

        <label class="factura-check">
          <input type="checkbox">
          <span>Factura comercial negociable</span>
        </label>
      </div>
    </section>

    <section class="factura-card">
      <div class="factura-section-head">
        <h3>Adquirente</h3>
      </div>

      <div class="factura-grid factura-grid--buyer">
        <label class="factura-field">
          <span>Identificacion</span>
          <div class="factura-inline-field">
            <input type="text" value="1004478234001">
            <button class="btn-secondary" type="button">Buscar</button>
          </div>
        </label>

        <label class="factura-field">
          <span>Tipo identificacion</span>
          <input type="text" value="RUC">
        </label>

        <label class="factura-field factura-field--wide">
          <span>Razon social</span>
          <input type="text" value="MOYA YEPEZ CHRISTYAN ANDERSON">
        </label>

        <label class="factura-field factura-field--wide">
          <span>Direccion</span>
          <input type="text" value="">
        </label>

        <label class="factura-field">
          <span>Telefono</span>
          <input type="text" value="">
        </label>

        <label class="factura-field factura-field--wide">
          <span>Correo electronico</span>
          <input type="email" value="crissmoya.cm@gmail.com">
        </label>
      </div>

      <p class="factura-help">Recuerde revisar el correo electronico para garantizar que el comprobante sea entregado exitosamente.</p>
    </section>

    <section class="factura-card">
      <div class="factura-section-head">
        <h3>Detalle</h3>
      </div>

      <div class="factura-detail-toolbar">
        <label class="factura-field factura-field--grow">
          <span>Codigo / Descripcion</span>
          <input type="text" placeholder="Escriba una letra o palabra, despues seleccione el producto">
        </label>
        <button class="btn-secondary" type="button">Buscar en listado de productos</button>
      </div>

      <div class="factura-table-wrap">
        <table class="grid grid-compact factura-table">
          <thead>
            <tr>
              <th style="width:120px">Codigo Principal</th>
              <th style="width:120px">Codigo Auxiliar</th>
              <th style="width:90px">Cantidad</th>
              <th>Descripcion</th>
              <th style="width:110px">Precio unitario</th>
              <th style="width:90px">Tarifa</th>
              <th style="width:100px">Descuento</th>
              <th style="width:110px">Valor total</th>
              <th style="width:100px">Valor ICE</th>
              <th style="width:100px">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colspan="10" class="factura-empty">No existen productos</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div class="factura-bottom-grid">
      <section class="factura-card">
        <div class="factura-section-head">
          <h3>Totales</h3>
        </div>

        <div class="factura-table-wrap">
          <table class="grid grid-compact factura-totals-table">
            <thead>
              <tr>
                <th>Detalle</th>
                <th style="width:120px">Valores</th>
              </tr>
            </thead>
            <tbody>
              <tr><td>Subtotal sin impuesto:</td><td class="catalog-money">0.00</td></tr>
              <tr><td>Descuento:</td><td class="catalog-money">0.00</td></tr>
              <tr><td>Subtotal IVA 0%:</td><td class="catalog-money">0.00</td></tr>
              <tr><td>Subtotal IVA 15%:</td><td class="catalog-money">0.00</td></tr>
              <tr><td>IVA 15%:</td><td class="catalog-money">0.00</td></tr>
              <tr><td>Propina 10%:</td><td class="catalog-money"></td></tr>
              <tr class="factura-total-row"><td>Valor a pagar:</td><td class="catalog-money">0.00</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="factura-side-stack">
        <section class="factura-card">
          <div class="factura-section-head">
            <h3>Formas de pago</h3>
          </div>

          <div class="factura-table-wrap">
            <table class="grid grid-compact factura-table">
              <thead>
                <tr>
                  <th>Forma de Pago</th>
                  <th style="width:90px">Valor</th>
                  <th style="width:80px">Plazo</th>
                  <th style="width:90px">Tiempo</th>
                  <th style="width:90px">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colspan="5" class="factura-empty">No existen formas de pago</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="factura-payment-actions">
            <button class="btn-secondary" type="button">Efectivo</button>
            <button class="btn-secondary" type="button">Tarjeta de debito</button>
            <button class="btn-secondary" type="button">Tarjeta de credito</button>
            <button class="btn-primary" type="button">Anadir forma de pago</button>
          </div>
        </section>

        <section class="factura-card">
          <div class="factura-section-head">
            <h3>Campos adicionales</h3>
          </div>

          <div class="factura-table-wrap">
            <table class="grid grid-compact factura-table">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Descripcion</th>
                  <th style="width:90px">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colspan="3" class="factura-empty">No existen campos adicionales</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="factura-actions-inline">
            <button class="btn-secondary" type="button">Anadir campo adicional</button>
          </div>
        </section>
      </div>
    </div>

    <div class="factura-footer">
      <button class="btn-primary" type="button">Firmar y enviar</button>
      <button class="btn-secondary" type="button">Guardar sin firmar</button>
    </div>
  </div>
</section>
