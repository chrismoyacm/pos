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
    <button class="btn-tab" type="button" data-prod-nav="catalog">Catalogo</button>
  </div>

  <div class="productos-body">
    <div class="productos-header">
      <h3>IMPORTAR PRODUCTOS DESDE CSV</h3>
    </div>

    <div class="prod-import-card">
      <p class="muted">
        Campos obligatorios: <strong>CODIGO</strong> y <strong>DESCRIPCION</strong>.
        Opcionales: TVENTA, DEPT, PROVID, MAYOREO, DINVENTARIO, DINVMINIMO, DINVMAXIMO, PORCENTAJE_GANANCIA, IMPUESTOS, PFINAL, PMAYOREOFINAL, ES_KIT y USA_INVENTARIO.
        Se acepta CSV separado por coma o por punto y coma.
      </p>
      <p class="muted">
        Si el <strong>CODIGO</strong> ya existe, el producto se actualizara. Si no existe, se creara como nuevo.
      </p>

      <div class="prod-import-example" aria-label="Ejemplo de formato CSV">
        <table class="prod-import-example-table">
          <thead>
            <tr>
              <th>CODIGO</th>
              <th>DESCRIPCION</th>
              <th>DINVENTARIO</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>7501000012345</td>
              <td>Arroz</td>
              <td>20</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="prod-import-toolbar">
        <input type="file" id="prod-import-file" accept=".csv,text/csv">
        <label class="catalog-filter">
          <span>Modo de importacion</span>
          <select id="prod-import-mode">
            <option value="merge">Mezclar (actualiza por codigo)</option>
          </select>
        </label>
        <button class="btn-secondary" type="button" id="prod-import-preview-btn">Vista previa</button>
        <button class="btn-primary" type="button" id="prod-import-run-btn" disabled>Importar</button>
      </div>

      <div class="prod-import-result" id="prod-import-result">Sin archivo cargado.</div>
      <div class="prod-import-progress" id="prod-import-progress" hidden>
        <div class="prod-import-progress-bar">
          <div class="prod-import-progress-fill" id="prod-import-progress-fill"></div>
        </div>
        <div class="prod-import-progress-meta" id="prod-import-progress-meta">0%</div>
      </div>

      <div class="catalog-table-wrap prod-import-table-wrap">
        <table class="grid grid-compact catalog-table">
          <thead>
            <tr>
              <th style="width:120px">CODIGO</th>
              <th>DESCRIPCION</th>
              <th style="width:100px">PVENTA</th>
              <th style="width:100px">PCOSTO</th>
              <th style="width:90px">DEPT</th>
              <th style="width:90px">DINVENTARIO</th>
              <th style="width:90px">PROVID</th>
              <th style="width:110px">IMPUESTOS</th>
              <th style="width:150px">Estado</th>
            </tr>
          </thead>
          <tbody id="prod-import-preview-body"></tbody>
        </table>
      </div>
      <div class="prod-import-result" id="prod-import-failed" hidden></div>
    </div>
  </div>
</section>
