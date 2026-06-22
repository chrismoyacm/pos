<section class="facturacion-card" id="facturacion-firma-page">
  <div class="facturacion-card-head">
    <h3>Perfil y firma</h3>
    <p>Configuracion del certificado digital, modo SRI y despacho por correo.</p>
  </div>
  <form class="facturacion-form facturacion-grid-3" id="facturacion-firma-form">
    <label class="factura-field">
      <span>Modo SRI</span>
      <select name="signatureMode">
        <option value="mock">Pruebas SRI</option>
        <option value="real">Produccion SRI</option>
      </select>
    </label>
    <label class="factura-field factura-field--wide">
      <span>Ruta certificado .p12 / .pfx</span>
      <input type="file" name="certificatePath">
      <small class="facturacion-file-hint" id="facturacion-certificate-current">No hay certificado cargado.</small>
    </label>
    <label class="factura-field">
      <span>Clave certificado</span>
      <input type="password" name="certificatePassword">
    </label>
    <label class="factura-field">
      <span>Modo de correo</span>
      <select name="emailMode">
        <option value="mock">Mock / cola interna</option>
        <option value="brevo_api">Brevo API</option>
      </select>
    </label>
    <label class="factura-field">
      <span>Brevo API Key</span>
      <input type="password" name="brevoApiKey" placeholder="xkeysib-...">
    </label>
    <label class="factura-field">
      <span>Endpoint Brevo</span>
      <input type="text" name="brevoEndpoint" placeholder="https://api.brevo.com/v3/smtp/email">
    </label>
    <label class="factura-field">
      <span>Correo remitente</span>
      <input type="email" name="fromEmail">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Nombre remitente</span>
      <input type="text" name="fromName">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Correo para prueba</span>
      <input type="email" name="testEmail" placeholder="cliente@correo.com">
    </label>
  </form>
  <div class="facturacion-note">
    Ambos modos firman y consumen servicios reales del SRI. <code>Pruebas SRI</code> usa certificacion y <code>Produccion SRI</code> usa el ambiente productivo.
  </div>
  <div class="facturacion-card-footer">
    <div class="facturacion-status" id="facturacion-firma-status"></div>
    <button class="btn-secondary" type="button" id="facturacion-firma-test">Probar certificado</button>
    <button class="btn-secondary" type="button" id="facturacion-email-test">Probar correo</button>
    <button class="btn-primary" type="button" id="facturacion-firma-save">Guardar perfil y firma</button>
  </div>
</section>
