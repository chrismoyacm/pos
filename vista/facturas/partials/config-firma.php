<section class="facturacion-card" id="facturacion-firma-page">
  <div class="facturacion-card-head">
    <h3>Perfil y firma</h3>
    <p>Configuracion de certificado digital, modo de firma y despacho por correo.</p>
  </div>
  <form class="facturacion-form facturacion-grid-3" id="facturacion-firma-form">
    <label class="factura-field">
      <span>Modo de firma</span>
      <select name="signatureMode">
        <option value="mock">Mock / pruebas</option>
        <option value="real">Real</option>
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
        <option value="smtp">SMTP</option>
      </select>
    </label>
    <label class="factura-field">
      <span>SMTP host</span>
      <input type="text" name="smtpHost">
    </label>
    <label class="factura-field">
      <span>SMTP puerto</span>
      <input type="text" name="smtpPort">
    </label>
    <label class="factura-field">
      <span>SMTP usuario</span>
      <input type="text" name="smtpUser">
    </label>
    <label class="factura-field">
      <span>SMTP clave</span>
      <input type="password" name="smtpPassword">
    </label>
    <label class="factura-field">
      <span>Correo remitente</span>
      <input type="email" name="fromEmail">
    </label>
    <label class="factura-field factura-field--wide">
      <span>Nombre remitente</span>
      <input type="text" name="fromName">
    </label>
  </form>
  <div class="facturacion-note">
    La firma real requiere integrar <code>xmlseclibs</code> con XAdES-BES. Mientras tanto el modo mock permite probar el flujo completo sin salir del POS.
  </div>
  <div class="facturacion-card-footer">
    <div class="facturacion-status" id="facturacion-firma-status"></div>
    <button class="btn-secondary" type="button" id="facturacion-firma-test">Probar certificado</button>
    <button class="btn-primary" type="button" id="facturacion-firma-save">Guardar perfil y firma</button>
  </div>
</section>
