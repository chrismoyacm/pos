<?php
declare(strict_types=1);
?>
<section class="inventario-wrap" id="inv-report-module">
  <div class="inventario-titlebar">
    <h2>INVENTARIO</h2>
  </div>

  <div class="inventario-actions">
    <button class="btn-tab" type="button" data-inv-nav="agregar">Agregar</button>
    <button class="btn-tab active" type="button">Ajustes</button>
    <button class="btn-tab" type="button" data-inv-nav="bajos">Productos bajos de inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="reporte">Reporte de Inventario</button>
    <button class="btn-tab" type="button" data-inv-nav="movimientos">Reporte de Movimientos</button>
    <button class="btn-tab" type="button" data-inv-nav="kardex">Kardex de inventario</button>
  </div>

  <div class="inventario-body">
    <div class="inventario-header">
      <h3>AJUSTES DE INVENTARIO</h3>
    </div>

    <div class="inv-form-card">
      <div class="inv-form-grid inv-form-grid--adjust">
        <label class="catalog-filter inv-field-grow">
          <span>Codigo del Producto</span>
          <input type="text" id="inv-adjust-code" placeholder="Escanee o escriba solo el codigo" autocomplete="off">
        </label>
        <div class="catalog-filter">
          <span>&nbsp;</span>
          <button class="btn-secondary" type="button" id="inv-adjust-load-btn">Buscar (F10)</button>
        </div>
        <label class="catalog-filter inv-field-grow">
          <span>Descripcion</span>
          <input type="text" id="inv-adjust-name" readonly>
        </label>
        <label class="catalog-filter">
          <span>Cantidad actual</span>
          <input type="text" id="inv-adjust-current-stock" readonly>
        </label>
        <label class="catalog-filter">
          <span>+ / -</span>
          <select id="inv-adjust-type">
            <option value="entry">Entrada</option>
            <option value="exit">Salida</option>
          </select>
        </label>
        <label class="catalog-filter">
          <span>Cantidad</span>
          <input type="number" id="inv-adjust-qty" min="1" step="1" value="1">
        </label>
        <label class="catalog-filter">
          <span>Nueva cantidad</span>
          <input type="text" id="inv-adjust-new-stock" readonly>
        </label>
        <label class="catalog-filter">
          <span>Costo entrada</span>
          <input type="number" id="inv-adjust-entry-cost" min="0" step="0.01" value="0.00">
        </label>
        <label class="catalog-filter">
          <span>Costo promedio</span>
          <input type="text" id="inv-adjust-new-cost" readonly>
        </label>
        <label class="catalog-filter">
          <span>% Ganancia</span>
          <input type="number" id="inv-adjust-margin" min="0" step="0.01" value="0.00">
        </label>
        <label class="catalog-filter">
          <span>Precio venta</span>
          <input type="text" id="inv-adjust-new-price" readonly>
        </label>
        <label class="catalog-filter inv-field-grow">
          <span>Motivo</span>
          <input type="text" id="inv-adjust-note" placeholder="Ajuste por merma, conteo fisico, etc." autocomplete="off">
        </label>
      </div>
      <div class="inv-form-actions">
        <button class="btn-primary" type="button" id="inv-adjust-save-btn">Aplicar Ajuste</button>
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
