<?php
declare(strict_types=1);
?>
<section class="compras-wrap" id="buy-suggested-module">
  <div class="compras-titlebar">
    <h2>COMPRAS</h2>
  </div>

  <div class="compras-actions">
    <button class="btn-tab" type="button" data-buy-nav="suggested">Compras sugeridas</button>
    <button class="btn-tab" type="button" data-buy-nav="list">Lista de compras</button>
    <button class="btn-tab active" type="button">Órdenes de compra</button>
    <button class="btn-tab" type="button" data-buy-nav="providers">Proveedores</button>
    <button class="btn-tab" type="button" data-buy-nav="history">Históricos de compras</button>
  </div>

  <div class="compras-body">
    <div class="compras-header">
      <h3>ÓRDENES DE COMPRA</h3>
      <p class="muted">Órdenes de compra generadas y su estado de recepción.</p>
    </div>

    <div class="compras-toolbar">
      <button class="btn-secondary" type="button" id="buy-orders-refresh-btn">Actualizar</button>
    </div>

    <div class="compras-table-wrap">
      <table class="grid grid-compact compras-table">
        <thead>
          <tr>
            <th style="width:115px">Folio</th>
            <th style="width:165px">Fecha</th>
            <th style="width:150px">Proveedor</th>
            <th style="width:90px">Items</th>
            <th style="width:105px">Total</th>
            <th style="width:110px">Estado</th>
            <th style="width:140px">Acciones</th>
          </tr>
        </thead>
        <tbody id="buy-orders-body"></tbody>
      </table>
    </div>
    <div class="table-pager" id="buy-orders-pager"></div>
  </div>
</section>
