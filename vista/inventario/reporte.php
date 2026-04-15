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
    <button class="btn-tab active" type="button">Reporte de Inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="movimientos">Reporte de Movimientos</button>
    <button class="btn-tab" type="button" data-inv-nav="kardex">Kardex de inventario</button>
  </div>

  <div class="inventario-body">
    <div class="inventario-header">
      <h3>REPORTE DE INVENTARIO</h3>
    </div>

    <div class="inventario-summary">
      <div class="inventario-summary-card">
        <div class="inventario-summary-label">Costo del Inventario</div>
        <div class="inventario-summary-value" id="inv-total-cost">$0.00</div>
      </div>
      <div class="inventario-summary-card">
        <div class="inventario-summary-label">Cantidad de productos en Inventario</div>
        <div class="inventario-summary-value" id="inv-total-stock">0</div>
      </div>
    </div>

    <div class="inventario-toolbar">
      <div class="inventario-toolbar-left">
        <label class="catalog-filter">
          <span>Departamento</span>
          <select id="inv-department-filter"></select>
        </label>
      </div>
      <div class="inventario-toolbar-right">
        <button class="btn-secondary" type="button" id="inv-modify-btn" disabled>Modificar Producto</button>
        <button class="btn-secondary" type="button" id="inv-export-btn">Exportar...</button>
        <button class="btn-secondary" type="button" id="inv-print-btn">Imprimir...</button>
      </div>
    </div>

    <div class="inventario-table-wrap">
      <table class="grid grid-compact inventario-table">
        <thead>
          <tr>
            <th style="width:110px">Código</th>
            <th>Descripción del Producto</th>
            <th style="width:90px">Costo</th>
            <th style="width:100px">Precio Venta</th>
            <th style="width:90px">Existencia</th>
            <th style="width:100px">Inventario Mínimo</th>
            <th style="width:100px">Inventario Máximo</th>
          </tr>
        </thead>
        <tbody id="inv-report-body"></tbody>
      </table>
    </div>
    <div class="table-pager" id="inv-report-pager"></div>
  </div>
</section>
