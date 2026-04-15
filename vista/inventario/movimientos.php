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
    <button class="btn-tab active" type="button">Reporte de Movimientos</button>
    <button class="btn-tab" type="button" data-inv-nav="kardex">Kardex de inventario</button>
  </div>

  <div class="inventario-body">
    <div class="inventario-header">
      <h3>REPORTE DE MOVIMIENTOS</h3>
    </div>

    <div class="inventario-toolbar">
      <div class="inventario-toolbar-left">
        <label class="catalog-filter">
          <span>Desde</span>
          <input type="date" id="inv-mov-from">
        </label>
        <label class="catalog-filter">
          <span>Hasta</span>
          <input type="date" id="inv-mov-to">
        </label>
        <label class="catalog-filter">
          <span>Tipo</span>
          <select id="inv-mov-type">
            <option value="">-Todos-</option>
            <option value="entry">Entrada</option>
            <option value="exit">Salida</option>
            <option value="sale">Venta</option>
          </select>
        </label>
        <button class="btn-secondary" type="button" id="inv-mov-refresh-btn">Consultar</button>
      </div>
    </div>

    <div class="inventario-table-wrap">
      <table class="grid grid-compact inventario-table">
        <thead>
          <tr>
            <th style="width:165px">Fecha</th>
            <th style="width:110px">Código</th>
            <th>Descripción del Producto</th>
            <th style="width:90px">Tipo</th>
            <th style="width:90px">Cambio</th>
            <th style="width:90px">Antes</th>
            <th style="width:90px">Después</th>
            <th style="width:220px">Motivo</th>
          </tr>
        </thead>
        <tbody id="inv-mov-body"></tbody>
      </table>
    </div>
    <div class="table-pager" id="inv-mov-pager"></div>
  </div>
</section>
