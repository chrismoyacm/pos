<?php
declare(strict_types=1);
?>
<section class="creditos-wrap">
  <div class="creditos-titlebar">
    <h2>CREDITO A CLIENTES</h2>
  </div>

  <div class="creditos-actions">
    <button class="btn-tab" id="cred-btn-estado" type="button">Estado de Cuenta</button>
    <button class="btn-tab active" id="cred-btn-reporte" type="button">Reporte de Saldos</button>
  </div>

  <div class="creditos-body">
    <div class="creditos-panel creditos-panel--reporte">
      <div class="creditos-report-wrap">
        <div class="creditos-report-head">
          <div>
            <div class="creditos-report-title">REPORTE DE SALDOS</div>
            <div class="creditos-report-sub">Total de Creditos Pendientes</div>
            <div class="creditos-report-total" id="cred-total-pendiente">$0.00</div>
          </div>
          <div class="creditos-report-actions">
            <button class="btn-secondary" id="cred-print" type="button">Imprimir Reporte</button>
          </div>
        </div>

        <div class="creditos-report-table">
          <table class="grid">
            <thead>
              <tr>
                <th style="width:90px" data-cred-reporte-sort="number">Numero</th>
                <th data-cred-reporte-sort="nameAddress">Nombre / Direccion del Cliente</th>
                <th style="width:140px" data-cred-reporte-sort="phone">Telefono</th>
                <th style="width:140px" data-cred-reporte-sort="creditLimit">Limite de Credito</th>
                <th style="width:140px" data-cred-reporte-sort="balance">Saldo Actual</th>
                <th style="width:160px" data-cred-reporte-sort="paymentDate">Fecha Pago</th>
                <th style="width:170px" data-cred-reporte-sort="lastPayment">Ultimo Pago</th>
              </tr>
            </thead>
            <tbody id="cred-reporte-tbody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>
