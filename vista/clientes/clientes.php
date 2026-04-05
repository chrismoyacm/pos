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
      <button class="btn btn-danger-soft" id="cli-del" type="button" disabled>Eliminar</button>
      <button class="btn btn-primary-soft" id="cli-save" type="button" disabled>Guardar</button>
    </div>
  </div>

  <div class="clientes-split">
    <div class="clientes-list">
      <table class="grid grid-compact">
        <thead>
          <tr>
            <th style="width:120px">Folio</th>
            <th>Nombre</th>
          </tr>
        </thead>
      </table>
      <div class="clientes-scroll">
        <table class="grid grid-compact">
          <tbody id="cli-tbody"></tbody>
        </table>
      </div>
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
            <label for="cli-payment-day">Fecha pago (día del mes)</label>
            <input id="cli-payment-day" type="number" min="1" max="31" step="1" placeholder="1 - 31">
          </div>
        </div>
      </form>
    </div>
  </div>
</section>

