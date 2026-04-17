<?php
declare(strict_types=1);
?>
<section class="corte-wrap" id="cut-module">
  <div class="corte-titlebar">
    <h2>CORTE</h2>
  </div>

  <div class="corte-actions">
    <button class="btn-tab active" type="button" data-cut-mode="cashier">Hacer corte de cajero</button>
    <button class="btn-tab" type="button" data-cut-mode="day">Hacer corte del día</button>
  </div>

  <div class="corte-body">
    <div class="corte-headline">
      <div>
        <h3 id="cut-title">Corte de cajero</h3>
        <div class="corte-range" id="cut-range">De las - a las -</div>
      </div>
      <div class="corte-head-actions">
        <button class="btn-secondary" type="button" id="cut-print-btn">Imprimir</button>
        <button class="btn-secondary" type="button" id="cut-close-btn">Cerrar turno ...</button>
      </div>
    </div>

    <div class="corte-kpis">
      <div class="corte-kpi">
        <div class="corte-kpi-label">Ventas Totales</div>
        <div class="corte-kpi-value" id="cut-total-sales">$0.00</div>
      </div>
      <div class="corte-kpi">
        <div class="corte-kpi-label">Ganancias</div>
        <div class="corte-kpi-value" id="cut-total-profit">$0.00</div>
      </div>
    </div>

    <div class="corte-grid">
      <section class="corte-panel">
        <h4>Dinero en Caja</h4>
        <div class="corte-lines" id="cut-cash-box-lines"></div>
        <div class="corte-total-row"><span>Total</span><strong id="cut-cash-box-total">$0.00</strong></div>
      </section>

      <section class="corte-panel">
        <h4>Ventas</h4>
        <div class="corte-lines" id="cut-sales-lines"></div>
        <div class="corte-total-row"><span>Total</span><strong id="cut-sales-total">$0.00</strong></div>
      </section>

      <section class="corte-panel">
        <h4>Entradas de efectivo</h4>
        <div class="corte-empty" id="cut-cash-in-list">- No hubo Entradas en Efectivo -</div>
      </section>

      <section class="corte-panel">
        <h4>Ingresos de contado</h4>
        <div class="corte-empty" id="cut-cash-income-list">- No hubo ingresos de contado -</div>
        <div class="corte-total-row"><span>Total</span><strong id="cut-cash-income-total">$0.00</strong></div>
      </section>

      <section class="corte-panel">
        <h4>Ventas por Departamento</h4>
        <div class="corte-empty" id="cut-sales-by-department">- No se registró ninguna venta -</div>
      </section>

      <section class="corte-panel">
        <h4>Impuestos</h4>
        <div class="corte-empty" id="cut-taxes">- No hubo ventas -</div>
      </section>

      <section class="corte-panel">
        <h4>Pagos de créditos</h4>
        <div class="corte-empty" id="cut-credit-payments">- No se recibieron pagos de créditos -</div>
      </section>

      <section class="corte-panel">
        <h4>Clientes con más ventas</h4>
        <div class="corte-empty" id="cut-top-customers">- Sin datos de clientes -</div>
      </section>

      <section class="corte-panel">
        <h4>Clientes con más ganancias</h4>
        <div class="corte-empty" id="cut-top-profit-customers">- Sin datos de ganancias -</div>
      </section>
    </div>
  </div>
</section>

<div id="cut-close-modal" class="modal" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="cut-close-modal-title">
    <header id="cut-close-modal-title">Cerrar turno</header>
    <section>
      <div class="field-col">
        <label for="cut-close-expected">Efectivo esperado</label>
        <input id="cut-close-expected" type="text" readonly>
      </div>
      <div class="field-col">
        <label for="cut-close-actual">Efectivo contado</label>
        <input id="cut-close-actual" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00">
      </div>
      <div class="field-col">
        <label for="cut-close-difference">Diferencia</label>
        <input id="cut-close-difference" type="text" readonly>
      </div>
      <div class="muted" id="cut-close-modal-help">Ingrese el valor contado para cerrar el turno.</div>
      <div class="shift-close-error" id="cut-close-modal-error"></div>
    </section>
    <footer>
      <button type="button" class="btn-secondary" id="cut-close-cancel">Cancelar</button>
      <button type="button" class="btn-primary" id="cut-close-confirm">Cerrar turno</button>
    </footer>
  </div>
</div>
