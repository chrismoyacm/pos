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
    <button class="btn-tab active" type="button">Proveedores</button>
    <button class="btn-tab" type="button" data-buy-nav="history">Históricos de compras</button>
  </div>

  <div class="compras-body">
    <div class="compras-header">
      <h3>PROVEEDORES</h3>
      <p class="muted">Alta rápida y consulta de proveedores registrados.</p>
    </div>

    <div class="compras-toolbar buy-providers-toolbar">
      <label class="catalog-filter">
        <span>Nombre del proveedor</span>
        <input type="text" id="buy-provider-name" placeholder="Proveedor ABC" autocomplete="off">
      </label>
      <label class="catalog-filter">
        <span>Teléfono</span>
        <input type="text" id="buy-provider-phone" placeholder="0999999999" autocomplete="off">
      </label>
      <label class="catalog-filter buy-provider-notes">
        <span>Notas</span>
        <input type="text" id="buy-provider-notes" placeholder="Horario, condiciones, etc." autocomplete="off">
      </label>
      <button class="btn-primary" type="button" id="buy-provider-save-btn">Guardar proveedor</button>
    </div>

    <div class="compras-table-wrap">
      <table class="grid grid-compact compras-table">
        <thead>
          <tr>
            <th>Proveedor</th>
            <th style="width:140px">Teléfono</th>
            <th>Notas</th>
          </tr>
        </thead>
        <tbody id="buy-providers-body"></tbody>
      </table>
    </div>
  </div>
</section>
