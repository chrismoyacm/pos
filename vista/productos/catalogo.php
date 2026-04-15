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
            <th style="width:34px"></th>
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
</section>
