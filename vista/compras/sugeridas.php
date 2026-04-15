<?php
declare(strict_types=1);
?>
<section class="compras-wrap" id="buy-suggested-module">
  <div class="compras-titlebar">
    <h2>COMPRAS</h2>
  </div>

  <div class="compras-actions">
    <button class="btn-tab active" type="button">Compras sugeridas</button>
    <button class="btn-tab" type="button" data-buy-nav="list">Lista de compras</button>
    <button class="btn-tab" type="button" data-buy-nav="orders">Órdenes de compra</button>
    <button class="btn-tab" type="button" data-buy-nav="providers">Proveedores</button>
    <button class="btn-tab" type="button" data-buy-nav="history">Históricos de compras</button>
  </div>

  <div class="compras-body">
    <div class="compras-header">
      <h3>COMPRAS SUGERIDAS</h3>
      <p class="muted">Los siguientes productos tienen bajo inventario o bien, dado su ritmo de venta están por agotarse.</p>
    </div>

    <div class="compras-toolbar">
      <label class="catalog-filter">
        <span>Filtrar por Departamento</span>
        <select id="buy-department-filter"></select>
      </label>
      <label class="catalog-filter">
        <span>Filtrar por Proveedor</span>
        <select id="buy-provider-filter"></select>
      </label>
    </div>

    <div class="compras-table-wrap">
      <table class="grid grid-compact compras-table">
        <thead>
          <tr>
            <th style="width:42px"></th>
            <th style="width:140px">Código</th>
            <th>Producto</th>
            <th style="width:170px">Departamento</th>
            <th style="width:90px">Existencia</th>
            <th style="width:90px">Mínimo</th>
            <th style="width:95px">Comprar</th>
            <th style="width:170px">Proveedor</th>
          </tr>
        </thead>
        <tbody id="buy-suggested-body"></tbody>
      </table>
    </div>
    <div class="table-pager" id="buy-suggested-pager"></div>

    <div class="compras-footer">
      <button class="btn-secondary" type="button" id="buy-create-order-btn" disabled>Crear orden de compra</button>
      <button class="btn-secondary" type="button" id="buy-send-list-btn" disabled>Enviar a lista de compra</button>
    </div>
  </div>
</section>
