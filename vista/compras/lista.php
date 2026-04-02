<?php
declare(strict_types=1);
?>
<section class="compras-wrap" id="buy-suggested-module">
  <div class="compras-titlebar">
    <h2>COMPRAS</h2>
  </div>

  <div class="compras-actions">
    <button class="btn-tab" type="button" data-buy-nav="suggested">Compras sugeridas</button>
    <button class="btn-tab active" type="button">Lista de compras</button>
    <button class="btn-tab" type="button" data-buy-nav="orders">Órdenes de compra</button>
    <button class="btn-tab" type="button" data-buy-nav="providers">Proveedores</button>
    <button class="btn-tab" type="button" data-buy-nav="history">Históricos de compras</button>
  </div>

  <div class="compras-body">
    <div class="compras-header">
      <h3>LISTA DE COMPRAS</h3>
      <p class="muted">Productos apartados para reabastecimiento antes de generar órdenes.</p>
    </div>

    <div class="compras-toolbar">
      <button class="btn-secondary" type="button" id="buy-list-refresh-btn">Actualizar</button>
      <button class="btn-primary" type="button" id="buy-list-order-btn" disabled>Crear orden con seleccionados</button>
    </div>

    <div class="compras-table-wrap">
      <table class="grid grid-compact compras-table">
        <thead>
          <tr>
            <th style="width:42px"></th>
            <th style="width:140px">Código</th>
            <th>Producto</th>
            <th style="width:95px">Cantidad</th>
            <th style="width:170px">Proveedor</th>
            <th style="width:110px">Acción</th>
          </tr>
        </thead>
        <tbody id="buy-list-body"></tbody>
      </table>
    </div>
  </div>
</section>
