<?php
declare(strict_types=1);
?>
<section class="productos-wrap" id="prod-departments-module">
  <div class="productos-titlebar">
    <h2>PRODUCTOS</h2>
  </div>

  <div class="productos-actions">
    <button class="btn-tab" type="button" data-prod-nav="new">Nuevo</button>
    <button class="btn-tab" type="button" data-prod-nav="modify">Modificar</button>
    <button class="btn-tab" type="button" data-prod-nav="delete">Eliminar</button>
    <button class="btn-tab active" type="button">Departamentos</button>
    <button class="btn-tab" type="button" data-prod-nav="periods">Ventas por Periodo</button>
    <button class="btn-tab" type="button" data-prod-nav="promotions">Promociones</button>
    <button class="btn-tab" type="button" data-prod-nav="import">Importar</button>
    <button class="btn-tab" type="button" data-prod-nav="catalog">Catálogo</button>
  </div>

  <div class="productos-body">
    <div class="productos-header">
      <h3>DEPARTAMENTOS</h3>
    </div>

    <div class="productos-departments-main">
      <aside class="productos-list-panel productos-departments-list-panel">
        <div class="productos-departments-search">
          <input type="text" id="prod-department-search" placeholder="Buscar ..." autocomplete="off">
        </div>
        <div class="productos-list">
          <table class="grid grid-compact">
            <thead>
              <tr>
                <th>Departamento</th>
              </tr>
            </thead>
            <tbody id="prod-department-list-body"></tbody>
          </table>
        </div>
      </aside>

      <div class="productos-form-panel productos-departments-panel">
        <div class="productos-departments-toolbar">
          <button class="btn-secondary" type="button" id="prod-department-new-btn">Nuevo Departamento</button>
          <button class="btn-secondary" type="button" id="prod-department-delete-btn" disabled>Eliminar</button>
        </div>

        <div class="productos-departments-form">
          <h3 id="prod-department-title">NUEVO DEPARTAMENTO</h3>
          <label class="prod-field prod-field--department-name">
            <span>Nombre</span>
            <input type="text" id="prod-department-name" autocomplete="off">
          </label>

          <div class="productos-departments-actions">
            <button class="btn-primary" type="button" id="prod-department-save-btn">Guardar Departamento</button>
            <button class="btn-secondary" type="button" id="prod-department-cancel-btn">Cancelar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
