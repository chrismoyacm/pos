<?php
declare(strict_types=1);
?>
<section class="creditos-detalle-wrap">
  <div class="creditos-titlebar">
    <h2>CREDITO A CLIENTES</h2>
  </div>

  <div class="creditos-actions">
    <button class="btn-tab active" id="ec-btn-estado" type="button">Estado de Cuenta</button>
    <button class="btn-tab" id="ec-btn-reporte" type="button">Reporte de Saldos</button>
  </div>

  <div class="creditos-detalle-head">
    <div class="creditos-detalle-head-left">
      <div class="creditos-detalle-badge" id="ec-nick">EC</div>
      <div>
        <div class="creditos-detalle-client" id="ec-client-name">Cliente</div>
        <div class="creditos-detalle-client-id" id="ec-client-id"></div>
      </div>
    </div>

    <div class="creditos-detalle-head-right">
      <div class="ec-kv">
        <div class="ec-k">Limite de Credito</div>
        <div class="ec-v" id="ec-limit">$0.00</div>
      </div>
      <div class="ec-kv">
        <div class="ec-k">Saldo</div>
        <div class="ec-v ec-v--green" id="ec-saldo">$0.00</div>
      </div>
    </div>
  </div>

  <div class="creditos-detalle-actions">
    <button class="btn-tab" type="button" id="ec-btn-abonar">Abonar a deuda</button>
    <button class="btn-tab" type="button" id="ec-btn-liquidar">Liquidar</button>
    <button class="btn-tab" type="button" id="ec-btn-consulta">Consultar credito anterior</button>
    <button class="btn-tab" type="button" id="ec-btn-print">Imprimir Estado de Cuenta</button>
  </div>

  <div class="creditos-detalle-main">
    <div class="creditos-detalle-left">
      <div class="creditos-detalle-summary-row">
        <div class="muted">Movimientos</div>
        <div class="creditos-detalle-summary-total" id="ec-total-mov">$0.00</div>
      </div>

      <div class="creditos-detalle-filters">
        <label class="ec-period-filter">Movimientos:
          <select id="ec-period-filter">
            <option value="since_last_liquidation">Desde ultima liquidacion</option>
            <option value="this_week">De esta semana</option>
            <option value="this_month">De este mes</option>
            <option value="last_90_days">Ultimos 90 dias</option>
            <option value="all_time">De siempre</option>
          </select>
        </label>
      </div>

      <div class="creditos-detalle-table">
        <table class="grid" style="table-layout:fixed">
          <thead>
            <tr>
              <th style="width:150px" data-ec-sort="fechaHora">Fecha/Hora</th>
              <th style="width:90px">Folio</th>
              <th style="width:140px">
                <div class="ec-th-filter">
                  <span>Movimiento</span>
                  <select id="ec-mov-filter">
                    <option value="all">Todos</option>
                    <option value="venta">Venta</option>
                    <option value="cobro">Cobro</option>
                    <option value="liquidar">Liquidar</option>
                  </select>
                </div>
              </th>
              <th>Descripcion</th>
              <th style="width:115px">Fecha pago</th>
              <th style="width:110px" data-ec-sort="monto">Monto</th>
              <th style="width:120px" data-ec-sort="saldoActual">Saldo actual</th>
              <th style="width:110px">Cajero</th>
            </tr>
          </thead>
          <tbody id="ec-mov-tbody"></tbody>
        </table>
      </div>
    </div>

    <div class="creditos-detalle-right">
      <div class="creditos-ticket">
        <div class="creditos-ticket-meta">
          <div class="ec-ticket-line"><span class="muted">Folio:</span><strong id="ec-ticket-folio"></strong></div>
          <div class="ec-ticket-line"><span class="muted">Cajero:</span><strong id="ec-ticket-cajero"></strong></div>
          <div class="ec-ticket-line"><span class="muted">Cliente:</span><strong id="ec-ticket-cliente"></strong></div>
          <div class="ec-ticket-date" id="ec-ticket-fecha"></div>
        </div>

        <div class="creditos-ticket-table-wrap">
          <table class="grid grid-compact creditos-ticket-table">
            <thead>
              <tr>
                <th style="width:58px">Cant.</th>
                <th>Descripcion</th>
                <th style="width:88px">Importe</th>
              </tr>
            </thead>
            <tbody id="ec-ticket-items"></tbody>
          </table>
        </div>

        <div class="creditos-ticket-totals">
          <div class="ec-panel-line">
            <span class="muted">Total:</span>
            <span class="ec-panel-strong" id="ec-pago-total">$0.00</span>
          </div>
          <div class="ec-panel-line">
            <span class="muted">Pago Con:</span>
            <span class="ec-panel-strong" id="ec-ticket-pago-con"></span>
          </div>
          <div class="ec-panel-line">
            <span class="muted">Monto Pendiente:</span>
            <span class="ec-panel-strong" id="ec-pendiente">$0.00</span>
          </div>
        </div>

        <div class="ec-panel-note muted" id="ec-ultimo-pago"></div>
        <div class="ec-panel-actions">
          <button class="btn-primary" type="button" id="ec-ticket-reprint-btn" disabled>Re-imprimir</button>
          <button class="btn-secondary" type="button" disabled>Cancelar</button>
        </div>
      </div>
    </div>
  </div>
</section>

<div id="modal-ec-pago" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true">
    <header id="ec-pago-title">Pago de deuda</header>
    <section>
      <input type="hidden" id="ec-pago-mode" value="abono">
      <label class="field">Venta seleccionada
        <input type="text" id="ec-pago-venta" readonly>
      </label>
      <label class="field">Pendiente de la venta
        <input type="text" id="ec-pago-venta-pendiente" readonly>
      </label>
      <label class="field">Deuda actual
        <input type="text" id="ec-pago-deuda" readonly>
      </label>
      <label class="field">Monto a pagar
        <input type="number" id="ec-pago-monto" min="0.01" step="0.01" placeholder="0.00">
      </label>
      <label class="field">Destino
        <select id="ec-pago-destino">
          <option value="selected_only">Solamente a la venta seleccionada</option>
          <option value="all_equal">A todas las ventas no liquidadas por igual</option>
        </select>
      </label>
      <label class="field"><span id="ec-pago-pendiente-label">Pendiente</span>
        <input type="text" id="ec-pago-pendiente" readonly>
      </label>
      <label class="field">Comentario
        <input type="text" id="ec-pago-nota" placeholder="Abono a deuda">
      </label>
    </section>
    <footer>
      <button type="button" class="btn-secondary" id="ec-pago-cancel">Cancelar</button>
      <button type="button" class="btn-primary" id="ec-pago-save">Guardar</button>
    </footer>
  </div>
</div>
