<?php
$issueDate = date('d-m-Y');
?>
<section class="facturacion-card" id="facturacion-emision-page">
  <div class="facturacion-card-head">
    <h3>Factura</h3>
    <p>Venta -> Generar XML -> Firmar XML -> Enviar al SRI -> Consultar autorizacion -> Guardar XML autorizado -> Generar PDF -> Enviar al cliente</p>
  </div>

  <div class="facturacion-body">
    <section class="factura-card factura-card--meta-hidden" aria-hidden="true">
      <div class="factura-grid factura-grid--top">
        <label class="factura-field">
          <span>Establecimiento</span>
          <select id="factura-point-establishment"></select>
        </label>

        <label class="factura-field">
          <span>Nombre comercial</span>
          <input type="text" id="factura-commercial-name" value="">
        </label>

        <label class="factura-field">
          <span>Fecha de emision</span>
          <input type="text" id="factura-issue-date" value="<?php echo htmlspecialchars($issueDate); ?>">
        </label>

        <label class="factura-field">
          <span>Punto de emision</span>
          <select id="factura-point-id"></select>
        </label>

        <label class="factura-field">
          <span>Guia de remision</span>
          <input type="text" id="factura-guide-number" value="">
        </label>

        <label class="factura-check">
          <input type="checkbox" id="factura-is-negotiable">
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
            <input type="text" id="factura-buyer-identification" value="">
            <button class="btn-secondary" type="button" id="factura-buyer-search-btn">Buscar</button>
          </div>
        </label>

        <label class="factura-field">
          <span>Tipo identificacion</span>
          <div class="factura-inline-field">
            <select id="factura-buyer-id-type">
              <option value="RUC">RUC</option>
              <option value="Cedula">Cedula</option>
              <option value="Pasaporte">Pasaporte</option>
              <option value="Consumidor final">Consumidor final</option>
            </select>
            <button class="btn-secondary" type="button" id="factura-save-buyer-id-btn" hidden>Guardar identificacion de cliente</button>
          </div>
        </label>

        <label class="factura-field factura-field--wide">
          <span>Razon social</span>
          <input type="text" id="factura-buyer-name" value="">
        </label>

        <label class="factura-field factura-field--wide">
          <span>Direccion</span>
          <input type="text" id="factura-buyer-address" value="">
        </label>

        <label class="factura-field">
          <span>Telefono</span>
          <input type="text" id="factura-buyer-phone" value="">
        </label>

        <label class="factura-field factura-field--wide">
          <span>Correo electronico</span>
          <input type="email" id="factura-buyer-email" value="">
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
          <input type="text" id="factura-product-search" placeholder="Escriba una letra o palabra, despues seleccione el producto">
        </label>
        <button class="btn-secondary" type="button" id="factura-product-search-btn">Buscar en listado de productos</button>
      </div>

      <div class="facturacion-search-results" id="factura-product-results"></div>

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
          <tbody id="factura-detail-body">
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
            <tbody id="factura-totals-body">
              <tr><td>Subtotal sin impuestos:</td><td class="catalog-money" data-total="subtotalSinImpuestos">0.00</td></tr>
              <tr><td>Subtotal 15.00%:</td><td class="catalog-money" data-total="subtotal15">0.00</td></tr>
              <tr><td>Subtotal 12.00%:</td><td class="catalog-money" data-total="subtotal12">0.00</td></tr>
              <tr><td>Subtotal 5%:</td><td class="catalog-money" data-total="subtotal5">0.00</td></tr>
              <tr><td>Subtotal tarifa especial:</td><td class="catalog-money" data-total="subtotalTarifaEspecial">0.00</td></tr>
              <tr><td>Subtotal 0%:</td><td class="catalog-money" data-total="subtotal0">0.00</td></tr>
              <tr><td>Subtotal no objeto de IVA:</td><td class="catalog-money" data-total="subtotalNoObjetoIva">0.00</td></tr>
              <tr><td>Subtotal exento de IVA:</td><td class="catalog-money" data-total="subtotalExentoIva">0.00</td></tr>
              <tr><td>Total descuento:</td><td class="catalog-money" data-total="totalDescuento">0.00</td></tr>
              <tr><td>Valor ICE:</td><td class="catalog-money" data-total="valorICE">0.00</td></tr>
              <tr><td>IVA 15.00%:</td><td class="catalog-money" data-total="iva15">0.00</td></tr>
              <tr><td>IVA 12.00%:</td><td class="catalog-money" data-total="iva12">0.00</td></tr>
              <tr><td>IVA 5%:</td><td class="catalog-money" data-total="iva5">0.00</td></tr>
              <tr><td>IVA tarifa especial:</td><td class="catalog-money" data-total="ivaTarifaEspecial">0.00</td></tr>
              <tr><td>Propina 10%:</td><td class="catalog-money"><input type="text" id="factura-tip" value=""></td></tr>
              <tr class="factura-total-row"><td>Valor a pagar:</td><td class="catalog-money" data-total="importeTotal">0.00</td></tr>
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
              <tbody id="factura-payments-body">
                <tr>
                  <td colspan="5" class="factura-empty">No existen formas de pago</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="factura-payment-actions" id="factura-payment-actions">
            <button class="btn-secondary" type="button" data-payment-method="cash">Efectivo</button>
            <button class="btn-secondary" type="button" data-payment-method="debit_card">Tarjeta de debito</button>
            <button class="btn-secondary" type="button" data-payment-method="credit_card">Tarjeta de credito</button>
            <button class="btn-primary" type="button" id="factura-add-payment-btn">Anadir forma de pago</button>
          </div>
          <div class="muted" id="factura-payment-origin-note" hidden>La forma de pago se toma desde la venta realizada en caja.</div>
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
              <tbody id="factura-additional-body">
                <tr>
                  <td colspan="3" class="factura-empty">No existen campos adicionales</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="factura-actions-inline">
            <button class="btn-secondary" type="button" id="factura-add-field-btn">Anadir campo adicional</button>
          </div>
        </section>
      </div>
    </div>

    <div class="facturacion-status facturacion-status--wide" id="factura-issue-status"></div>

    <div class="factura-footer">
      <button class="btn-primary" type="button" id="factura-issue-btn">Firmar y enviar</button>
      <button class="btn-secondary" type="button" id="factura-save-draft-btn">Guardar sin firmar</button>
    </div>
  </div>
</section>
