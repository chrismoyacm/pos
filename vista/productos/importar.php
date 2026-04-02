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
    <button class="btn-tab" type="button" data-prod-nav="periods">Ventas por Periodo</button>
    <button class="btn-tab" type="button" data-prod-nav="promotions">Promociones</button>
    <button class="btn-tab active" type="button">Importar</button>
    <button class="btn-tab" type="button" data-prod-nav="catalog">Catálogo</button>
  </div>

  <div class="productos-body">
    <div class="productos-header">
      <h3>IMPORTAR PRODUCTOS DESDE CSV</h3>
    </div>

    <div class="prod-import-card">
      <p class="muted">
        Formato esperado: <strong>barcode,name,price,cost,department,stock,minStock,maxStock,unitType,wholesalePrice,wholesaleMinQty,provider,iva</strong>.
        En <strong>iva</strong> usa <strong>No</strong>, <strong>12%</strong> o <strong>15%</strong>.
      </p>

      <div class="prod-import-toolbar">
        <input type="file" id="prod-import-file" accept=".csv,text/csv">
        <label class="catalog-filter">
          <span>Modo de importación</span>
          <select id="prod-import-mode">
            <option value="merge">Mezclar (actualiza por código)</option>
            <option value="replace">Reemplazar catálogo completo</option>
          </select>
        </label>
        <button class="btn-secondary" type="button" id="prod-import-preview-btn">Vista previa</button>
        <button class="btn-primary" type="button" id="prod-import-run-btn" disabled>Importar</button>
      </div>

      <div class="prod-import-result" id="prod-import-result">Sin archivo cargado.</div>

      <div class="catalog-table-wrap prod-import-table-wrap">
        <table class="grid grid-compact catalog-table">
          <thead>
            <tr>
              <th style="width:110px">Código</th>
              <th>Descripción del Producto</th>
              <th style="width:100px">Precio</th>
              <th style="width:100px">Costo</th>
              <th style="width:150px">Departamento</th>
              <th style="width:80px">Stock</th>
              <th style="width:130px">Proveedor</th>
              <th style="width:70px">IVA</th>
            </tr>
          </thead>
          <tbody id="prod-import-preview-body"></tbody>
        </table>
      </div>
    </div>
  </div>
</section>
