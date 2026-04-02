<?php
declare(strict_types=1);
?>
<section class="productos-wrap" id="prod-module">
  <div class="productos-titlebar">
    <h2>PRODUCTOS</h2>
  </div>

  <div class="productos-actions">
    <button class="btn-tab" type="button" data-prod-nav="new">Nuevo</button>
    <button class="btn-tab" type="button" data-prod-nav="modify">Modificar</button>
    <button class="btn-tab" type="button" data-prod-nav="delete">Eliminar</button>
    <button class="btn-tab" type="button" data-prod-nav="departments">Departamentos</button>
    <button class="btn-tab active" type="button">Ventas por Periodo</button>
    <button class="btn-tab" type="button" data-prod-nav="promotions">Promociones</button>
    <button class="btn-tab" type="button" data-prod-nav="import">Importar</button>
    <button class="btn-tab" type="button" data-prod-nav="catalog">Catálogo</button>
  </div>

  <div class="productos-body">
    <div class="productos-header">
      <h3>VENTAS DE PRODUCTOS POR PERIODO</h3>
    </div>

    <div class="prod-period-toolbar">
      <label class="catalog-filter">
        <span>Desde</span>
        <input type="date" id="prod-period-from">
      </label>
      <label class="catalog-filter">
        <span>Hasta</span>
        <input type="date" id="prod-period-to">
      </label>
      <label class="catalog-filter">
        <span>Departamento</span>
        <select id="prod-period-department">
          <option value="">-Todos-</option>
        </select>
      </label>
      <label class="catalog-filter prod-period-search">
        <span>Producto</span>
        <input type="text" id="prod-period-q" placeholder="Código o descripción" autocomplete="off">
      </label>
      <button class="btn-secondary" type="button" id="prod-period-refresh-btn">Consultar</button>
      <button class="btn-secondary" type="button" id="prod-period-export-btn">Exportar</button>
    </div>

    <div class="prod-period-summary">
      <div class="prod-period-card">
        <div class="prod-period-label">Unidades vendidas</div>
        <div class="prod-period-value" id="prod-period-units">0</div>
      </div>
      <div class="prod-period-card">
        <div class="prod-period-label">Venta total</div>
        <div class="prod-period-value" id="prod-period-total">$0.00</div>
      </div>
      <div class="prod-period-card">
        <div class="prod-period-label">Utilidad estimada</div>
        <div class="prod-period-value" id="prod-period-profit">$0.00</div>
      </div>
    </div>

    <div class="catalog-table-wrap">
      <table class="grid grid-compact catalog-table">
        <thead>
          <tr>
            <th style="width:110px">Código</th>
            <th>Descripción del Producto</th>
            <th style="width:150px">Departamento</th>
            <th style="width:90px">Cant.</th>
            <th style="width:110px">Venta</th>
            <th style="width:110px">Utilidad</th>
            <th style="width:90px">Tickets</th>
          </tr>
        </thead>
        <tbody id="prod-period-body"></tbody>
      </table>
    </div>
  </div>
</section>
