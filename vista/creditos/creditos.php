<?php
declare(strict_types=1);
?>
<section class="creditos-wrap">
  <div class="creditos-titlebar">
    <h2>CREDITO A CLIENTES</h2>
  </div>

  <div class="creditos-actions">
    <button class="btn-tab active" id="cred-btn-estado" type="button">Estado de Cuenta</button>
    <button class="btn-tab" id="cred-btn-reporte" type="button">Reporte de Saldos</button>
  </div>

  <div class="creditos-body">
    <!-- Estado de Cuenta -->
    <div class="creditos-panel creditos-panel--estado" id="cred-panel-estado">
      <div class="creditos-card" role="dialog" aria-label="Estado de Cuenta">
        <div class="creditos-card-head">
          <div class="creditos-card-title">Estado de Cuenta</div>
          <div class="muted creditos-card-sub">Ingresa el folio o nombre del cliente</div>
        </div>

        <div class="creditos-search">
          <input id="cred-search" type="text" placeholder="Buscar..." autocomplete="off">
        </div>

        <div class="creditos-list">
          <table class="grid grid-compact">
            <tbody id="cred-tbody"></tbody>
          </table>
        </div>

        <div class="creditos-card-footer">
          <button class="btn-secondary" id="cred-accept" type="button" disabled>Aceptar</button>
        </div>
      </div>
    </div>
  </div>
</section>

