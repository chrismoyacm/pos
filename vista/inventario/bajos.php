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
    <button class="btn-tab active" type="button">Productos bajos de inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="reporte">Reporte de Inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="movimientos">Reporte de Movimientos</button>
    <button class="btn-tab" type="button" data-inv-nav="kardex">Kardex de inventario</button>
  </div>

  <div class="inventario-body">
    <div class="inventario-header">
      <h3>PRODUCTOS BAJOS DE INVENTARIO</h3>
    </div>

    <div class="inventario-table-wrap">
      <table class="grid grid-compact inventario-table">
        <thead>
          <tr>
            <th style="width:110px">Código</th>
            <th>Descripción del Producto</th>
            <th style="width:150px">Departamento</th>
            <th style="width:90px">Existencia</th>
            <th style="width:100px">Inv. Mínimo</th>
            <th style="width:110px">Faltante</th>
          </tr>
        </thead>
        <tbody id="inv-low-body"></tbody>
      </table>
    </div>
  </div>
</section>
