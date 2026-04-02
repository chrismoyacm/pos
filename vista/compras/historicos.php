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
    <button class="btn-tab" type="button" data-buy-nav="orders">Órdenes de compra</button>
    <button class="btn-tab" type="button" data-buy-nav="providers">Proveedores</button>
    <button class="btn-tab active" type="button">Históricos de compras</button>
  </div>

  <div class="compras-body">
    <div class="compras-header">
      <h3>HISTÓRICOS DE COMPRAS</h3>
      <p class="muted">Historial de compras recibidas por producto y proveedor.</p>
    </div>

    <div class="compras-toolbar">
      <label class="catalog-filter">
        <span>Desde</span>
        <input type="date" id="buy-history-from">
      </label>
      <label class="catalog-filter">
        <span>Hasta</span>
        <input type="date" id="buy-history-to">
      </label>
      <label class="catalog-filter">
        <span>Producto</span>
        <input type="text" id="buy-history-q" placeholder="Código o nombre" autocomplete="off">
      </label>
      <button class="btn-secondary" type="button" id="buy-history-refresh-btn">Consultar</button>
    </div>

    <div class="compras-table-wrap">
      <table class="grid grid-compact compras-table">
        <thead>
          <tr>
            <th style="width:165px">Fecha</th>
            <th style="width:130px">Código</th>
            <th>Producto</th>
            <th style="width:95px">Cantidad</th>
            <th style="width:100px">Costo U.</th>
            <th style="width:110px">Subtotal</th>
            <th style="width:160px">Proveedor</th>
            <th style="width:105px">Orden</th>
          </tr>
        </thead>
        <tbody id="buy-history-body"></tbody>
      </table>
    </div>
  </div>
</section>
