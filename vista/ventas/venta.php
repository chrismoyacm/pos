<?php
declare(strict_types=1);
?>
<section class="ventas-wrap">
  <div class="ventas-head">
    <div class="row">
      <label for="codigo">Código del Producto:</label>
      <form id="codigo-form" style="flex:1;display:flex;gap:10px">
        <input id="codigo" type="text" autocomplete="off" placeholder="Ingrese un producto por una cantidad mayor a uno.">
      </form>
    </div>
  </div>

  <?php include __DIR__ . '/../partials/teclado-tips.php'; ?>

  <div style="min-height:360px">
    <table class="grid">
      <thead>
        <tr>
          <th style="width:140px">Código de Barras</th>
          <th>Descripción del Producto</th>
          <th style="width:80px">IVA</th>
          <th style="width:120px">Precio Venta</th>
          <th style="width:70px">Cant.</th>
          <th style="width:120px">Importe</th>
          <th style="width:120px">Existencia</th>
        </tr>
      </thead>
      <tbody id="venta-body">
      </tbody>
    </table>
  </div>

  <div class="venta-footer">
    <div class="left">
      <span><strong id="countItems">0</strong> Productos en la venta actual.</span>
      <button type="button" class="btn" id="btn-cambiar"><span class="kbd">F5</span> Cambiar</button>
      <button type="button" class="btn"><span class="kbd">F6</span> Pendiente</button>
      <button type="button" class="btn" id="btn-eliminar">Eliminar</button>
      <button type="button" class="btn" id="btn-cliente">Asignar cliente</button>
      <span class="muted">Cliente:</span> <span id="clienteNombre" class="badge-blue">Público en general</span>
      <button type="button" class="btn" id="btn-cobrar"><span class="kbd">F12</span> Cobrar</button>
    </div>
    <div class="totales">
      <div class="field"><span class="muted">Subtotal:</span> <span id="subtotal" class="cifra">$0.00</span></div>
      <div class="field"><span class="muted">Total:</span> <span id="total" class="cifra">$0.00</span></div>
      <div class="field"><span class="muted">Pagó con:</span> <input id="pagoConInline" type="text" readonly value=""></div>
      <div class="field"><span class="muted">Cambio:</span> <input id="cambioInline" type="text" readonly value=""></div>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;gap:10px;padding:10px;border-top:1px solid #e5e7eb;background:#fff">
    <button type="button" class="btn" id="btn-reimprimir">Reimprimir Último Ticket</button>
    <button type="button" class="btn" id="btn-ventas-dia">Ventas del día</button>
    <button type="button" class="btn" id="btn-devoluciones">Devoluciones</button>
  </div>
</section>

<?php include __DIR__ . '/../partials/pago-modal.php'; ?>

