<?php
declare(strict_types=1);
?>
<section class="inventario-wrap" id="inv-report-module">
  <div class="inventario-titlebar">
    <h2>INVENTARIO</h2>
  </div>

  <div class="inventario-actions">
    <button class="btn-tab active" type="button">Agregar</button>
    <button class="btn-tab" type="button" data-inv-nav="ajustes">Ajustes</button>
    <button class="btn-tab" type="button" data-inv-nav="bajos">Productos bajos de inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="reporte">Reporte de Inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="movimientos">Reporte de Movimientos</button>
    <button class="btn-tab" type="button" data-inv-nav="kardex">Kardex de inventario</button>
  </div>

  <div class="inventario-body">
    <div class="inventario-header">
      <h3>AGREGAR INVENTARIO</h3>
    </div>

    <div class="inv-form-card">
      <div class="inv-form-grid inv-form-grid--adjust">
        <label class="catalog-filter inv-field-grow">
          <span>Codigo del Producto</span>
          <input type="text" id="inv-add-code" placeholder="Escanee o escriba solo el codigo" autocomplete="off">
        </label>
        <div class="catalog-filter">
          <span>&nbsp;</span>
          <button class="btn-secondary" type="button" id="inv-add-load-btn">Buscar (F10)</button>
        </div>
        <label class="catalog-filter inv-field-grow">
          <span>Descripcion</span>
          <input type="text" id="inv-add-name">
        </label>
        <label class="catalog-filter">
          <span>Hay</span>
          <input type="text" id="inv-add-stock" readonly>
        </label>
        <label class="catalog-filter">
          <span>Agregar</span>
          <input type="number" id="inv-add-qty" min="1" step="1" value="1">
        </label>
        <label class="catalog-filter">
          <span>Precio costo</span>
          <input type="number" id="inv-add-cost" min="0" step="0.01" value="0.00">
        </label>
        <label class="catalog-filter">
          <span>% de ganancia</span>
          <input type="number" id="inv-add-margin" min="0" step="0.01" value="0.00">
        </label>
        <label class="catalog-filter">
          <span>Precio venta</span>
          <input type="number" id="inv-add-price" min="0" step="0.01" value="0.00">
        </label>
        <label class="catalog-filter">
          <span>Precio mayoreo</span>
          <input type="number" id="inv-add-wholesale" min="0" step="0.01" placeholder="0.00">
        </label>
        <label class="catalog-filter inv-field-grow">
          <span>Motivo</span>
          <input type="text" id="inv-add-note" placeholder="Compra, devolucion proveedor, etc." autocomplete="off">
        </label>
      </div>
      <div class="inv-form-actions">
        <button class="btn-primary" type="button" id="inv-add-save-btn">Agregar cantidad a inventario</button>
      </div>
    </div>
  </div>
</section>

<div class="modal" id="inv-search-modal">
  <div class="modal-card modal-card--search" role="dialog" aria-modal="true" aria-labelledby="inv-search-title">
    <header>
      <span id="inv-search-title">Buscar producto</span>
    </header>
    <section>
      <label class="field-col">
        <span>Buscar producto</span>
        <input type="text" id="inv-search-query" autocomplete="off" placeholder="Codigo, nombre o ID">
      </label>
      <div class="muted">Click en un resultado para cargarlo en el formulario.</div>
      <div class="search-results-table-wrap">
        <table class="grid search-results-table">
          <thead>
            <tr>
              <th style="width:140px">Codigo</th>
              <th>Producto</th>
              <th style="width:110px">Stock</th>
              <th style="width:120px">Costo</th>
              <th style="width:120px">Precio</th>
            </tr>
          </thead>
          <tbody id="inv-search-results"></tbody>
        </table>
      </div>
    </section>
    <footer>
      <button type="button" class="btn-secondary" id="inv-search-close-btn">Cerrar</button>
    </footer>
  </div>
</div>
