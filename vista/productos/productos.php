<?php
declare(strict_types=1);
?>
<section class="productos-wrap" id="prod-module">
  <div class="productos-titlebar">
    <h2>PRODUCTOS</h2>
  </div>

  <div class="productos-actions">
    <button class="btn-tab active" type="button" data-prod-mode="new">Nuevo</button>
    <button class="btn-tab" type="button" data-prod-mode="modify">Modificar</button>
    <button class="btn-tab" type="button" data-prod-mode="delete">Eliminar</button>
    <button class="btn-tab" type="button" data-prod-nav="departments">Departamentos</button>
    <button class="btn-tab" type="button" data-prod-nav="periods">Ventas por Periodo</button>
    <button class="btn-tab" type="button" data-prod-nav="promotions">Promociones</button>
    <button class="btn-tab" type="button" data-prod-nav="import">Importar</button>
    <button class="btn-tab" type="button" data-prod-nav="catalog">Catálogo</button>
  </div>

  <div class="productos-body">
    <div class="productos-header">
      <h3 id="prod-title">NUEVO PRODUCTO</h3>
      <div class="productos-search" id="prod-search-wrap" hidden>
        <input type="text" id="prod-search" placeholder="Buscar por código, nombre o folio" autocomplete="off">
      </div>
    </div>

    <div id="prod-editor-section">
      <div class="productos-main">
        <aside class="productos-list-panel" id="prod-list-panel" aria-label="Listado de productos" hidden>
          <div class="productos-list-head">Productos</div>
          <div class="productos-list">
            <table class="grid grid-compact">
              <thead>
                <tr>
                  <th style="width:110px">Código</th>
                  <th>Descripción</th>
                  <th style="width:90px">Venta</th>
                </tr>
              </thead>
              <tbody id="prod-list-body"></tbody>
            </table>
          </div>
        </aside>

        <div class="productos-form-panel">
          <div class="productos-tabs">
            
          </div>

          <form id="prod-form" class="productos-form">
            <div class="prod-grid">
              <label class="prod-field prod-field--barcode">
                <span>Código de Barras</span>
                <input type="text" id="prod-barcode" autocomplete="off">
              </label>

              <label class="prod-field prod-field--description">
                <span>Descripción</span>
                <input type="text" id="prod-name" autocomplete="off">
              </label>

              <div class="prod-field prod-field--selltype">
                <span>Se vende</span>
                <div class="prod-radio-row">
                  <label><input type="radio" name="prod-unit-type" value="unit" checked> Por Unidad/Pza</label>
                  <label><input type="radio" name="prod-unit-type" value="bulk"> A Granel (Usa Decimales)</label>
                  <label><input type="radio" name="prod-unit-type" value="package"> Como paquete (kit)</label>
                </div>
              </div>

              <div class="prod-config-layout">
                <div class="prod-prices">
                  <label class="prod-field">
                    <span>Precio Costo</span>
                    <input type="number" step="0.01" min="0" id="prod-cost">
                  </label>
                  <label class="prod-field prod-field--with-suffix">
                    <span>Ganancia</span>
                    <div class="prod-inline-input">
                      <input type="number" step="0.01" min="0" id="prod-margin">
                      <strong>%</strong>
                    </div>
                  </label>
                  <label class="prod-field">
                    <span>Precio Venta</span>
                    <input type="number" step="0.01" min="0" id="prod-price">
                  </label>
                  <label class="prod-field">
                    <span>Precio Mayoreo</span>
                    <input type="number" step="0.01" min="0" id="prod-wholesale">
                  </label>
                  <label class="prod-field prod-field--department">
                    <span>Departamento</span>
                    <select id="prod-department"></select>
                  </label>
                  <label class="prod-field prod-field--tax">
                    <span>Impuesto</span>
                    <select id="prod-iva">
                      <option value="No">Sin IVA</option>
                      <option value="12%">IVA 12%</option>
                      <option value="15%">IVA 15%</option>
                    </select>
                  </label>
                </div>

                <aside class="prod-package-panel" id="prod-package-panel" hidden>
                  <div class="prod-package-title">Contenido del paquete</div>
                  <p class="prod-package-help">Ingrese el codigo y la cantidad de cada articulo que incluye este producto.</p>

                  <div class="prod-package-form">
                    <label class="prod-field">
                      <span>Codigo de barra</span>
                      <input type="text" id="prod-package-code" autocomplete="off">
                      <div class="prod-package-preview" id="prod-package-preview">Escriba un codigo para ver la previsualizacion del articulo.</div>
                    </label>
                    <label class="prod-field prod-package-qty-field">
                      <span>Cantidad</span>
                      <input type="number" step="1" min="1" id="prod-package-qty" value="1">
                    </label>
                  </div>

                  <div class="productos-departments-actions">
                    <button class="btn-primary" type="button" id="prod-package-add-btn">Agregar</button>
                    <button class="btn-secondary" type="button" id="prod-package-remove-btn" disabled>Remover seleccionado</button>
                  </div>

                  <div class="prod-package-table-wrap">
                    <table class="grid grid-compact">
                      <thead>
                        <tr>
                          <th>Producto</th>
                          <th style="width:90px">Cantidad</th>
                        </tr>
                      </thead>
                      <tbody id="prod-package-body"></tbody>
                    </table>
                  </div>
                </aside>
              </div>

              <div class="prod-inventory-box">
                <div class="prod-section-tag">Inventario</div>
                <label class="prod-check-row">
                  <input type="checkbox" id="prod-inventory-enabled" checked>
                  <span>Este producto sí utiliza inventario.</span>
                </label>

                <div class="prod-stock-grid">
                  <label class="prod-field">
                    <span>Hay</span>
                    <input type="number" step="1" id="prod-stock">
                  </label>
                  <label class="prod-field">
                    <span>Mínimo</span>
                    <input type="number" step="1" id="prod-min-stock">
                  </label>
                  <label class="prod-field">
                    <span>Máximo</span>
                    <input type="number" step="1" id="prod-max-stock">
                  </label>
                </div>
              </div>

              <div class="prod-delete-box" id="prod-delete-box" hidden>
                <div class="prod-delete-title">Eliminar producto</div>
                <p class="muted">Selecciona un producto de la lista para eliminarlo del catálogo.</p>
                <div class="prod-delete-summary" id="prod-delete-summary">No hay producto seleccionado.</div>
              </div>
            </div>
          </form>
        </div>
      </div>

      <div class="productos-footer" id="prod-editor-footer">
        <button class="btn-primary" type="button" id="prod-save-btn">Guardar Producto</button>
        <button class="btn-danger" type="button" id="prod-delete-btn" hidden>Eliminar Producto</button>
        <button class="btn-secondary" type="button" id="prod-cancel-btn">Cancelar</button>
      </div>
    </div>

  </div>
</section>
