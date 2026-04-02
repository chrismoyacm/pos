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
        <div class="ec-k">Límite de Crédito</div>
        <div class="ec-v" id="ec-limit">$0.00</div>
      </div>
      <div class="ec-kv">
        <div class="ec-k">Saldo</div>
        <div class="ec-v ec-v--green" id="ec-saldo">$0.00</div>
      </div>
    </div>
  </div>

  <div class="creditos-detalle-actions">
    <button class="btn-tab" type="button" disabled>Abonar a deuda</button>
    <button class="btn-tab" type="button" disabled>Liquidar</button>
    <button class="btn-tab" type="button" id="ec-btn-consulta">Consultar crédito anterior</button>
    <button class="btn-tab" type="button" id="ec-btn-print">Imprimir Estado de Cuenta</button>
  </div>

  <div class="creditos-detalle-main">
    <div class="creditos-detalle-left">
      <div class="creditos-detalle-summary-row">
        <div class="muted">Movimientos</div>
        <div class="creditos-detalle-summary-total" id="ec-total-mov">$0.00</div>
      </div>

      <div class="creditos-detalle-table">
        <table class="grid" style="table-layout:fixed">
          <thead>
            <tr>
              <th style="width:150px">Fecha/Hora</th>
              <th style="width:90px">Folio</th>
              <th style="width:110px">Movimiento</th>
              <th>Descripción</th>
              <th style="width:110px">Monto</th>
              <th style="width:120px">Saldo actual</th>
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
                <th>Descripción</th>
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
          <button class="btn-primary" type="button" disabled>Re-imprimir</button>
          <button class="btn-secondary" type="button" disabled>Cancelar</button>
        </div>
      </div>
    </div>
  </div>
</section>
