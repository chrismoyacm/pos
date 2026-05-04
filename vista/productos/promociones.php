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
    <button class="btn-tab active" type="button">Promociones</button>
    <button class="btn-tab" type="button" data-prod-nav="import">Importar</button>
    <button class="btn-tab" type="button" data-prod-nav="catalog">Catálogo</button>
  </div>

  <div class="productos-body">
    <div class="productos-header">
      <h3>PROMOCIONES DE PRODUCTOS</h3>
    </div>

    <div class="productos-main promociones-main">
      <aside class="productos-list-panel promociones-list-panel" aria-label="Listado de promociones">
        <div class="productos-departments-search">
          <input type="text" id="promo-search" placeholder="Buscar promoción" autocomplete="off">
        </div>
        <div class="productos-list">
          <table class="grid grid-compact">
            <thead>
              <tr>
                <th>Promoción</th>
                <th style="width:90px">Estado</th>
              </tr>
            </thead>
            <tbody id="promo-list-body"></tbody>
          </table>
        </div>
      </aside>

      <div class="productos-form-panel promociones-form-panel">
        <div class="productos-departments-toolbar">
          <button class="btn-secondary" type="button" id="promo-new-btn">Nueva Promoción</button>
          <button class="btn-danger" type="button" id="promo-delete-btn" disabled>Eliminar</button>
        </div>

        <form id="promo-form" class="productos-form">
          <div class="prod-grid">
            <label class="prod-field prod-field--description">
              <span>Nombre</span>
              <input type="text" id="promo-name" autocomplete="off">
            </label>

            <div class="prod-prices promociones-prices">
              <label class="prod-field">
                <span>Tipo</span>
                <select id="promo-type">
                  <option value="percent">Porcentaje (%)</option>
                  <option value="fixed">Monto fijo ($)</option>
                </select>
              </label>
              <label class="prod-field">
                <span>Valor</span>
                <input type="number" step="0.01" min="0" id="promo-value">
              </label>
              <label class="prod-field">
                <span>Fecha inicio</span>
                <input type="date" id="promo-start-date">
              </label>
              <label class="prod-field">
                <span>Fecha fin</span>
                <input type="date" id="promo-end-date">
              </label>
            </div>

            <label class="prod-check-row">
              <input type="checkbox" id="promo-active" checked>
              <span>Promoción activa</span>
            </label>

            <label class="prod-field">
              <span>Buscar productos</span>
              <input type="text" id="promo-products-search" placeholder="Buscar por codigo, nombre o ID" autocomplete="off">
            </label>

            <label class="prod-field">
              <span>Productos incluidos (Ctrl/Cmd para seleccionar varios)</span>
              <select id="promo-products" multiple size="12"></select>
            </label>

            <div class="prod-field">
              <span>Productos seleccionados</span>
              <div class="promo-selected-box" id="promo-selected-products"></div>
            </div>

            <label class="prod-field prod-field--description">
              <span>Notas</span>
              <input type="text" id="promo-notes" autocomplete="off">
            </label>
          </div>
        </form>

        <div class="productos-footer promociones-footer">
          <button class="btn-primary" type="button" id="promo-save-btn">Guardar Promoción</button>
          <button class="btn-secondary" type="button" id="promo-cancel-btn">Cancelar</button>
        </div>
      </div>
    </div>
  </div>
</section>
