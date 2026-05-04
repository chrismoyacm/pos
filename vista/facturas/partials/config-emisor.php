<section class="facturacion-card" id="facturacion-emisor-page">
  <div class="facturacion-card-head">
    <h3>Datos Emisor</h3>
    <p>Configuracion tributaria y datos principales del emisor para la generacion del XML SRI.</p>
  </div>
  <form class="facturacion-form facturacion-grid-3" id="facturacion-emisor-form">
    <label class="factura-field">
      <span>Ambiente</span>
      <select name="ambiente">
        <option value="1">Pruebas</option>
        <option value="2">Produccion</option>
      </select>
    </label>
    <label class="factura-field">
      <span>RUC</span>
      <input type="text" name="ruc">
    </label>
    <label class="factura-field">
      <span>Telefono</span>
      <input type="text" name="telefono">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Razon social</span>
      <input type="text" name="razonSocial">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Nombre comercial</span>
      <input type="text" name="nombreComercial">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Direccion matriz</span>
      <input type="text" name="dirMatriz">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Direccion establecimiento por defecto</span>
      <input type="text" name="dirEstablecimiento">
    </label>
    <label class="factura-field">
      <span>Obligado a llevar contabilidad</span>
      <select name="obligadoContabilidad">
        <option value="NO">NO</option>
        <option value="SI">SI</option>
      </select>
    </label>
    <label class="factura-field">
      <span>Agente de retencion</span>
      <input type="text" name="agenteRetencion">
    </label>
    <label class="factura-field">
      <span>Contribuyente RIMPE</span>
      <input type="text" name="contribuyenteRimpe">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Correo del emisor</span>
      <input type="email" name="email">
    </label>
  </form>
  <div class="facturacion-note">
    El ambiente efectivo del comprobante se toma del <code>Modo SRI</code> configurado en Perfil y firma. Este campo se conserva solo como referencia visual del emisor.
  </div>
  <div class="facturacion-card-footer">
    <div class="facturacion-status" id="facturacion-emisor-status"></div>
    <button class="btn-primary" type="button" id="facturacion-emisor-save">Guardar datos emisor</button>
  </div>
</section>
