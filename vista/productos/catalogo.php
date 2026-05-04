<?php
declare(strict_types=1);
?>
<section class="productos-wrap" id="prod-catalog-module">
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
    <button class="btn-tab" type="button" data-prod-nav="import">Importar</button>
    <button class="btn-tab active" type="button">Catálogo</button>
  </div>

  <div class="productos-body">
    <div class="productos-header">
      <h3>CATÁLOGO DE PRODUCTOS</h3>
    </div>

    <div class="catalog-toolbar">
      <div class="catalog-toolbar-left">
        <input type="text" id="catalog-search" placeholder="Buscar" autocomplete="off">
      </div>
      <div class="catalog-toolbar-right">
        <label class="catalog-filter">
          <span>Departamento</span>
          <select id="catalog-department-filter"></select>
        </label>
        <button class="btn-secondary" type="button" id="catalog-refresh-btn">Actualizar varios...</button>
        <button class="btn-secondary" type="button" id="catalog-modify-btn" disabled>Modificar ...</button>
        <button class="btn-secondary" type="button" id="catalog-export-btn">Exportar</button>
      </div>
    </div>

    <div class="catalog-table-wrap">
      <table class="grid grid-compact catalog-table">
        <thead>
          <tr>
            <th style="width:34px"><input type="checkbox" id="catalog-select-all" aria-label="Seleccionar todos"></th>
            <th style="width:110px">Código</th>
            <th style="width:270px">Descripción del Producto</th>
            <th style="width:160px">Departamento</th>
            <th style="width:90px">Costo</th>
            <th style="width:90px">Precio Venta</th>
            <th style="width:100px">Precio Mayoreo</th>
            <th style="width:85px">Existencia</th>
            <th style="width:95px">Inv. Mínimo</th>
            <th style="width:95px">Inv. Máximo</th>
            <th style="width:100px">Tipo Venta</th>
            <th style="width:120px">Proveedor</th>
            <th style="width:70px">IVA</th>
          </tr>
        </thead>
        <tbody id="catalog-body"></tbody>
      </table>
    </div>
    <div class="table-pager" id="catalog-pager"></div>
  </div>
  <div class="modal" id="catalog-bulk-modal" aria-hidden="true">
    <div class="modal-card modal-card--wide catalog-bulk-card">
      <header>Actualizar varios productos</header>
      <section>
        <div class="catalog-bulk-feedback" id="catalog-bulk-feedback" hidden></div>

        <div class="catalog-bulk-step" id="catalog-bulk-step-config">
          <p class="muted" id="catalog-bulk-selected-text">Seleccionaste 0 productos, deseas...</p>
          <div class="catalog-bulk-actions">
            <label><input type="radio" name="catalog-bulk-action" value="price_delta"> Aumentar o disminuir sus precios</label>
            <label><input type="radio" name="catalog-bulk-action" value="margin"> Actualizar la ganancia/utilidad</label>
            <label><input type="radio" name="catalog-bulk-action" value="tax"> Actualizar sus impuestos</label>
            <label><input type="radio" name="catalog-bulk-action" value="unit_type"> Actualizar tipo de venta (unidad/granel)</label>
            <label><input type="radio" name="catalog-bulk-action" value="department"> Cambiar su departamento</label>
            <label><input type="radio" name="catalog-bulk-action" value="inventory_delta"> Aumentar o disminuir su inventario</label>
            <label><input type="radio" name="catalog-bulk-action" value="provider"> Cambiar su proveedor</label>
            <label><input type="radio" name="catalog-bulk-action" value="delete"> Eliminarlos</label>
          </div>
          <div class="catalog-bulk-config" id="catalog-bulk-config-area"></div>
        </div>

        <div class="catalog-bulk-step" id="catalog-bulk-step-summary" hidden>
          <h4>Resumen</h4>
          <p id="catalog-bulk-summary-title"></p>
          <p id="catalog-bulk-summary-value"></p>
          <div class="catalog-bulk-summary-table-wrap">
            <table class="grid grid-compact">
              <thead>
                <tr>
                  <th style="width:150px">Codigo</th>
                  <th>Descripcion del producto</th>
                </tr>
              </thead>
              <tbody id="catalog-bulk-summary-body"></tbody>
            </table>
          </div>
        </div>

        <div class="catalog-bulk-step" id="catalog-bulk-step-progress" hidden>
          <h4>Procesando actualizacion</h4>
          <div class="catalog-bulk-progress-wrap">
            <div class="catalog-bulk-progress-bar">
              <span id="catalog-bulk-progress-fill"></span>
            </div>
            <div class="catalog-bulk-progress-meta">
              <strong id="catalog-bulk-progress-text">0%</strong>
              <span id="catalog-bulk-progress-detail">0 de 0 completados</span>
            </div>
          </div>
          <div class="catalog-bulk-result-list" id="catalog-bulk-result-list"></div>
        </div>
      </section>
      <footer>
        <button class="btn-secondary" type="button" id="catalog-bulk-back-btn">Atras</button>
        <button class="btn-primary" type="button" id="catalog-bulk-next-btn">Siguiente</button>
        <button class="btn-secondary" type="button" id="catalog-bulk-cancel-btn">Cancelar</button>
      </footer>
    </div>
  </div>
</section>
