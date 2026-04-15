<?php
declare(strict_types=1);
?>
<section class="inventario-wrap" id="inv-report-module">
  <div class="inventario-titlebar">
    <h2>INVENTARIO</h2>
  </div>

  <div class="inventario-actions">
    <button class="btn-tab" type="button" data-inv-nav="agregar">Agregar</button>
    <button class="btn-tab" type="button" data-inv-nav="ajustes">Ajustes</button>
    <button class="btn-tab" type="button" data-inv-nav="bajos">Productos bajos de inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="reporte">Reporte de Inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="movimientos">Reporte de Movimientos</button>
    <button class="btn-tab active" type="button">Kardex de inventario</button>
  </div>

  <div class="inventario-body">
    <div class="inventario-header">
      <h3>KARDEX DE INVENTARIO</h3>
    </div>

    <div class="inventario-toolbar">
      <div class="inventario-toolbar-left">
        <label class="catalog-filter inv-field-grow">
          <span>Producto</span>
          <select id="inv-kardex-product"></select>
        </label>
        <label class="catalog-filter">
          <span>Desde</span>
          <input type="date" id="inv-kardex-from">
        </label>
        <label class="catalog-filter">
          <span>Hasta</span>
          <input type="date" id="inv-kardex-to">
        </label>
        <button class="btn-secondary" type="button" id="inv-kardex-refresh-btn">Consultar</button>
      </div>
    </div>

    <div class="inventario-table-wrap">
      <table class="grid grid-compact inventario-table">
        <thead>
          <tr>
            <th style="width:165px">Fecha</th>
            <th style="width:90px">Tipo</th>
            <th style="width:90px">Cambio</th>
            <th style="width:90px">Antes</th>
            <th style="width:90px">Después</th>
            <th style="width:260px">Motivo</th>
          </tr>
        </thead>
        <tbody id="inv-kardex-body"></tbody>
      </table>
    </div>
    <div class="table-pager" id="inv-kardex-pager"></div>
  </div>
</section>
