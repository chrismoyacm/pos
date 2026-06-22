<?php
declare(strict_types=1);
?>
<section class="clientes-wrap">
  <div class="module-head">
    <h2>ADMINISTRACIÓN DE CLIENTES</h2>
    <p class="muted">Administra a todos los clientes de tu negocio (crédito, facturación, etc.) de forma centralizada.</p>
  </div>

  <div class="clientes-toolbar">
    <div class="clientes-search">
      <input id="cli-search" type="text" placeholder="Buscar..." autocomplete="off">
    </div>
    <div class="clientes-actions">
      <button class="btn" id="cli-new" type="button">Nuevo Cliente</button>
      <button class="btn btn-secondary" id="cli-import-toggle" type="button">Importar CSV</button>
      <button class="btn btn-danger-soft" id="cli-del" type="button" disabled>Eliminar</button>
      <button class="btn btn-primary-soft" id="cli-save" type="button" disabled>Guardar</button>
    </div>
  </div>

  <div class="clientes-import" id="cli-import-panel" hidden>
    <div class="prod-import-card">
      <h3>Importar clientes desde CSV</h3>
      <p class="muted">
        Campos mínimos: <strong>NOMBRES</strong> o <strong>APELLIDOS</strong>. El campo <strong>ACTIVO</strong> del sistema anterior se ignora para evitar ocultar clientes.
      </p>
      <p class="muted">
        Para Ecuador, el ejemplo usa <strong>CANTON</strong> y <strong>PROVINCIA</strong>. Si el archivo viene del sistema anterior, el importador tambiÃ©n acepta <strong>MUNICIPIO</strong> y <strong>ESTADO</strong>.
      </p>
      <div class="prod-import-example" aria-label="Ejemplo de formato CSV para clientes">
        <table class="prod-import-example-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>NOMBRES</th>
              <th>APELLIDOS</th>
              <th>EMAIL</th>
              <th>TELEFONO</th>
              <th>DOMICILIO1</th>
              <th>CANTON</th>
              <th>PROVINCIA</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>25</td>
              <td>Pedro</td>
              <td>Perez</td>
              <td>correo@ejemplo.com</td>
              <td>0999999999</td>
              <td>Ibarra</td>
              <td>Ibarra</td>
              <td>Imbabura</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="prod-import-toolbar">
        <input type="file" id="cli-import-file" accept=".csv,text/csv">
        <button class="btn-secondary" type="button" id="cli-import-preview-btn">Vista previa</button>
        <button class="btn-primary" type="button" id="cli-import-run-btn" disabled>Importar</button>
      </div>
      <div class="prod-import-result" id="cli-import-result">Sin archivo cargado.</div>
      <div class="prod-import-progress" id="cli-import-progress" hidden>
        <div class="prod-import-progress-bar">
          <div class="prod-import-progress-fill" id="cli-import-progress-fill"></div>
        </div>
        <div class="prod-import-progress-meta" id="cli-import-progress-meta">0%</div>
      </div>
      <div class="catalog-table-wrap prod-import-table-wrap">
        <table class="grid grid-compact">
          <thead>
            <tr>
              <th>Fila</th>
              <th>ID</th>
              <th>Cliente</th>
              <th>Telefono</th>
              <th>Correo</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody id="cli-import-preview-body"></tbody>
        </table>
      </div>
      <div class="prod-import-result" id="cli-import-failed" hidden></div>
    </div>
  </div>

  <div class="clientes-split">
    <div class="clientes-list">
      <table class="grid grid-compact">
        <thead>
          <tr>
            <th style="width:120px">Folio</th>
            <th>Nombre</th>
            <th style="width:160px">Cedula / RUC</th>
          </tr>
        </thead>
      </table>
      <div class="clientes-scroll">
        <table class="grid grid-compact">
          <tbody id="cli-tbody"></tbody>
        </table>
      </div>
      <div class="table-pager" id="cli-pager"></div>
    </div>

    <div class="clientes-form">
      <h3 id="cli-form-title">Nuevo cliente</h3>
      <form id="cli-form">
        <input type="hidden" id="cli-id">

        <div class="form-grid">
          <div class="field-col">
            <label for="cli-first">Nombres</label>
            <input id="cli-first" type="text" autocomplete="off">
          </div>
          <div class="field-col">
            <label for="cli-last">Apellidos</label>
            <input id="cli-last" type="text" autocomplete="off">
          </div>
          <div class="field-col">
            <label for="cli-tax-id">Cedula / RUC</label>
            <input id="cli-tax-id" type="text" autocomplete="off">
            <small id="cli-tax-id-help" class="muted"></small>
          </div>

          <div class="field-col">
            <label for="cli-phone">Teléfono</label>
            <input id="cli-phone" type="text" autocomplete="off">
          </div>
          <div class="field-col">
            <label for="cli-email">Correo electrónico</label>
            <input id="cli-email" type="text" autocomplete="off">
          </div>

          <div class="field-col" style="grid-column:1/-1">
            <label for="cli-address1">Domicilio</label>
            <input id="cli-address1" type="text" autocomplete="off">
          </div>
          <div class="field-col" style="grid-column:1/-1">
            <label for="cli-address2">Domicilio2</label>
            <input id="cli-address2" type="text" autocomplete="off">
          </div>
          <div class="field-col">
            <label for="cli-province">Provincia</label>
            <select id="cli-province"></select>
          </div>
          <div class="field-col">
            <label for="cli-canton">Cantón</label>
            <select id="cli-canton" disabled></select>
          </div>

          <div class="field-col" style="grid-column:1/-1">
            <label for="cli-parish">Parroquia (opcional)</label>
            <input id="cli-parish" type="text" autocomplete="off">
          </div>

          <div class="field-col">
            <label for="cli-zip">Código Postal</label>
            <input id="cli-zip" type="text" autocomplete="off">
          </div>
          <div class="field-col"></div>

          <div class="field-col" style="grid-column:1/-1">
            <label for="cli-notes">Notas / Comentarios</label>
            <textarea id="cli-notes" rows="4"></textarea>
          </div>
        </div>

        <div class="clientes-credit">
          <div class="credit-title">Crédito</div>
          <label class="check-row">
            <input id="cli-credit" type="checkbox">
            <span>Tiene crédito autorizado</span>
          </label>
          <div class="field-col" style="margin-top:10px;max-width:220px">
            <label for="cli-payment-day">Fecha de pago</label>
            <input id="cli-payment-day" type="date">
          </div>
        </div>
      </form>
    </div>
  </div>
</section>

