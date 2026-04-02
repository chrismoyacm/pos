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
          <span>Producto</span>
          <select id="inv-adjust-product"></select>
        </label>
        <label class="catalog-filter">
          <span>Tipo</span>
          <select id="inv-adjust-type">
            <option value="entry">Entrada</option>
            <option value="exit">Salida</option>
          </select>
        </label>
        <label class="catalog-filter">
          <span>Cantidad</span>
          <input type="number" id="inv-adjust-qty" min="1" step="1" value="1">
        </label>
        <label class="catalog-filter inv-field-grow">
          <span>Motivo</span>
          <input type="text" id="inv-adjust-note" placeholder="Ajuste por merma, conteo físico, etc." autocomplete="off">
        </label>
      </div>
      <div class="inv-form-actions">
        <button class="btn-primary" type="button" id="inv-adjust-save-btn">Aplicar Ajuste</button>
      </div>
    </div>
  </div>
</section>
