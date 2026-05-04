<section class="config-wrap" id="config-module">
    <header class="config-titlebar">
        <h2>Configuración</h2>
    </header>

    <div class="config-grid">
        <aside class="config-sidebar">
            <section class="config-group">
                <h3>General</h3>
                <button type="button" class="config-nav active" data-view="opciones_habilitadas">Opciones habilitadas</button>
                <button type="button" class="config-nav" data-view="cajeros">Cajeros</button>
                <button type="button" class="config-nav" data-view="modificar_folios">Modificar folios</button>
                <button type="button" class="config-nav" data-view="administrar_cajas">Administrar cajas</button>
            </section>

            <section class="config-group">
                <h3>Personalización</h3>
                <button type="button" class="config-nav" data-view="logo_programa">Logotipo del programa</button>
                <button type="button" class="config-nav" data-view="ticket">Ticket</button>
                <button type="button" class="config-nav" data-view="impuestos">Impuestos</button>
                <button type="button" class="config-nav" data-view="corte">Corte</button>
                <button type="button" class="config-nav" data-view="unidades_medida">Unidades de medida</button>
            </section>

            <section class="config-group">
                <h3>Dispositivos</h3>
                <button type="button" class="config-nav" data-view="impresora_tickets">Impresora de tickets</button>
                <button type="button" class="config-nav" data-view="lector_codigo">Lector de codigo</button>
            </section>
        </aside>

        <div class="config-content">
            <section class="config-panel active" data-panel="opciones_habilitadas">
                <h3>Opciones habilitadas</h3>
                <div class="config-form-grid">
                    <label class="config-check-row"><input type="checkbox" id="cfg-inventory-control"> Utilizar inventarios para mis productos</label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-offer-credit"> Deseo ofrecer crédito a mis clientes</label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-common-product"> Habilitar venta de producto común</label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-auto-price"> Calcular automáticamente precio de venta</label>
                </div>
                <div class="config-inline-field">
                    <label for="cfg-auto-margin">Margen automático (%)</label>
                    <input type="number" id="cfg-auto-margin" min="0" max="100" step="1">
                </div>
                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-options">Guardar cambios</button>
                </div>
            </section>

            <section class="config-panel" data-panel="cajeros">
                <h3>Administración de cajeros y permisos</h3>
                <div class="config-users-layout">
                    <div class="config-users-list">
                        <div class="config-users-toolbar">
                            <input type="text" id="cfg-user-search" placeholder="Buscar cajero...">
                            <button class="btn-secondary" type="button" id="cfg-user-new">Nuevo cajero</button>
                            <button class="btn-secondary" type="button" id="cfg-user-toggle">Dar de baja cajero</button>
                        </div>
                        <div class="config-users-table-wrap">
                            <table class="grid" id="cfg-users-table">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Usuario</th>
                                    <th>Nombre</th>
                                    <th>Rol</th>
                                    <th>Estado</th>
                                </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="config-user-editor">
                        <h4 id="cfg-user-editor-title">Nuevo cajero</h4>
                        <div class="config-form-grid">
                            <label>Usuario<input type="text" id="cfg-user-username"></label>
                            <label>Nombre completo<input type="text" id="cfg-user-name"></label>
                            <label>Contraseña<input type="password" id="cfg-user-password" placeholder="Dejar vacio para mantener"></label>
                            <label>Rol
                                <select id="cfg-user-role">
                                    <option value="cashier">Cajero</option>
                                    <option value="admin">Administrador</option>
                                </select>
                            </label>
                            <label class="config-check-row"><input type="checkbox" id="cfg-user-active"> Usuario activo</label>
                        </div>

                        <h5>Permisos por módulo</h5>
                        <div class="config-perm-tabs" id="cfg-perm-tabs">
                            <button type="button" class="config-perm-tab active" data-perm-tab="ventas">Ventas</button>
                            <button type="button" class="config-perm-tab" data-perm-tab="clientes">Clientes</button>
                            <button type="button" class="config-perm-tab" data-perm-tab="productos">Productos</button>
                            <button type="button" class="config-perm-tab" data-perm-tab="inventario">Inventario</button>
                            <button type="button" class="config-perm-tab" data-perm-tab="otros">Otros</button>
                        </div>

                        <div class="config-permissions-grid" id="cfg-permissions-grid">
                            <div class="config-perm-panel active" data-perm-panel="ventas">
                                <label><input type="checkbox" data-perm="ventas_use_common_product"> Utilizar producto común</label>
                                <label><input type="checkbox" data-perm="ventas_apply_wholesale"> Aplicar mayoreo</label>
                                <label><input type="checkbox" data-perm="ventas_apply_discount"> Aplicar descuento</label>
                                <label><input type="checkbox" data-perm="ventas_view_sales_history"> Revisar historial de ventas</label>
                                <label><input type="checkbox" data-perm="ventas_register_cash_in"> Registrar entradas de efectivo</label>
                                <label><input type="checkbox" data-perm="ventas_register_cash_out"> Registrar salidas de efectivo</label>
                                <label><input type="checkbox" data-perm="ventas_charge_ticket"> Cobrar un ticket</label>
                                <label><input type="checkbox" data-perm="ventas_charge_credit"> Cobrar a crédito</label>
                                <label><input type="checkbox" data-perm="ventas_cancel_tickets"> Cancelar tickets y devolver artículos</label>
                                <label><input type="checkbox" data-perm="ventas_delete_sale_items"> Eliminar artículos de venta</label>
                                <label><input type="checkbox" data-perm="ventas_invoice"> Facturar / Ver facturas</label>
                                <label><input type="checkbox" data-perm="ventas_sell_service"> Vender un pago de servicio</label>
                                <label><input type="checkbox" data-perm="ventas_sell_recharges"> Vender recargas electrónicas</label>
                                <label><input type="checkbox" data-perm="ventas_use_product_search"> Usar buscador de productos</label>
                            </div>

                            <div class="config-perm-panel" data-perm-panel="clientes">
                                <label><input type="checkbox" data-perm="clientes_create_edit_delete"> Crear, modificar o eliminar clientes</label>
                                <label><input type="checkbox" data-perm="clientes_assign_to_sale"> Asignar cliente a una venta</label>
                                <label><input type="checkbox" data-perm="clientes_assign_credit"> Asignar o remover crédito a clientes</label>
                                <label><input type="checkbox" data-perm="clientes_view_credit_accounts"> Ver cuenta, recibir abonos y reportes de clientes a crédito</label>
                            </div>

                            <div class="config-perm-panel" data-perm-panel="productos">
                                <label><input type="checkbox" data-perm="productos_create"> Crear nuevos productos</label>
                                <label><input type="checkbox" data-perm="productos_edit"> Modificar productos</label>
                                <label><input type="checkbox" data-perm="productos_delete"> Eliminar productos</label>
                                <label><input type="checkbox" data-perm="productos_view_reports"> Ver reporte de ventas</label>
                                <label><input type="checkbox" data-perm="productos_create_promotions"> Crear promociones</label>
                                <label><input type="checkbox" data-perm="productos_modify_varios"> Modificar varios</label>
                            </div>

                            <div class="config-perm-panel" data-perm-panel="inventario">
                                <label><input type="checkbox" data-perm="inventario_add_stock"> Agregar mercancía</label>
                                <label><input type="checkbox" data-perm="inventario_view_minimum_reports"> Ver reportes de existencias y mínimos</label>
                                <label><input type="checkbox" data-perm="inventario_view_movements"> Ver movimiento de inventarios</label>
                                <label><input type="checkbox" data-perm="inventario_adjust"> Ajustar el inventario</label>
                            </div>

                            <div class="config-perm-panel" data-perm-panel="otros">
                                <label><input type="checkbox" data-perm="otros_access_reports"> Acceder a reportes</label>
                                <label><input type="checkbox" data-perm="otros_access_facturas"> Acceder a facturas</label>
                                <label><input type="checkbox" data-perm="otros_access_corte"> Acceder a corte</label>

                                <hr class="config-perm-separator">
                                <label><input type="checkbox" data-perm="config_options_enabled"> Configuración: opciones habilitadas</label>
                                <label><input type="checkbox" data-perm="config_cashiers"> Configuración: cajeros</label>
                                <label><input type="checkbox" data-perm="config_modify_folios"> Configuración: modificar folios</label>
                                <label><input type="checkbox" data-perm="config_manage_boxes"> Configuración: administrar cajas</label>
                                <label><input type="checkbox" data-perm="config_logo"> Configuración: logotipo</label>
                                <label><input type="checkbox" data-perm="config_ticket"> Configuración: ticket</label>
                                <label><input type="checkbox" data-perm="config_taxes"> Configuración: impuestos</label>
                                <label><input type="checkbox" data-perm="config_corte"> Configuración: corte</label>
                                <label><input type="checkbox" data-perm="config_units"> Configuración: unidades de medida</label>
                                <label><input type="checkbox" data-perm="config_ticket_printer"> Configuración: impresora de tickets</label>
                                <label><input type="checkbox" data-perm="config_barcode_reader"> Configuración: lector de código</label>
                            </div>
                        </div>

                        <div class="config-actions">
                            <button class="btn-primary" type="button" id="cfg-user-save">Guardar cajero y permisos</button>
                            <button class="btn-secondary" type="button" id="cfg-user-cancel">Cancelar</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="config-panel" data-panel="modificar_folios">
                <h3>Modificar folios</h3>
                <div class="config-form-grid">
                    <label>Prefijo factura<input type="text" id="cfg-folio-invoice-prefix"></label>
                    <label>Siguiente factura<input type="number" id="cfg-folio-next-invoice" min="1"></label>
                    <label>Prefijo nota de crédito<input type="text" id="cfg-folio-credit-prefix"></label>
                    <label>Siguiente nota de crédito<input type="number" id="cfg-folio-next-credit" min="1"></label>
                </div>
                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-folios">Guardar folios</button>
                </div>
            </section>

            <section class="config-panel" data-panel="administrar_cajas">
                <h3>Administrar cajas</h3>
                <div class="config-form-grid">
                    <label class="config-check-row"><input type="checkbox" id="cfg-box-require-opening"> Requerir apertura de caja</label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-box-close-diff"> Permitir cierre con diferencia</label>
                </div>

                <div class="config-device-card">
                    <h4>CAJON / GAVETA DE DINERO</h4>
                    <p class="muted">Elige la impresora a la cual esta conectado el cajon y el tipo de conexion.</p>
                    <div class="config-form-grid">
                        <label>Modelo de impresora para cajon
                            <select id="cfg-box-drawer-printer-model">
                                <option value="">Seleccionar...</option>
                                <option value="Epson TM-U220">Epson TM-U220</option>
                                <option value="POS-80C">POS-80C</option>
                                <option value="Generic ESC/POS">Generic ESC/POS</option>
                            </select>
                        </label>
                        <label>Tipo de conexion
                            <select id="cfg-box-drawer-connection">
                                <option value="USB">USB</option>
                                <option value="SERIAL">SERIAL</option>
                                <option value="LAN">LAN</option>
                            </select>
                        </label>
                    </div>
                    <div class="config-reader-test-controls">
                        <button class="btn-secondary" type="button" id="cfg-box-drawer-test">Probar apertura del cajon</button>
                    </div>
                    <div id="cfg-box-drawer-status" class="config-device-status"></div>
                </div>

                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-boxes">Guardar cajas</button>
                </div>
            </section>

            <section class="config-panel" data-panel="logo_programa">
                <h3>Logotipo del programa</h3>
                <div class="config-form-grid">
                    <label>Nombre negocio<input type="text" id="cfg-brand-store-name"></label>
                    <label>Texto corto logo<input type="text" id="cfg-brand-logo-text"></label>
                </div>
                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-branding">Guardar logotipo</button>
                </div>
            </section>

            <section class="config-panel" data-panel="ticket">
                <h3>Personalización del ticket de venta</h3>
                <div class="config-ticket-layout">
                    <div class="config-ticket-preview-wrap">
                        <div class="config-ticket-preview" id="cfg-ticket-preview"></div>
                    </div>

                    <div class="config-ticket-controls">
                        <div class="config-form-grid">
                            <label>Encabezado<input type="text" id="cfg-ticket-header"></label>
                            <label>Pie de página<input type="text" id="cfg-ticket-footer"></label>
                            <label>Línea adicional superior<input type="text" id="cfg-ticket-extra-top"></label>
                            <label>Línea adicional inferior<input type="text" id="cfg-ticket-extra-bottom"></label>
                            <label>URL logo ticket<input type="text" id="cfg-ticket-logo-url" placeholder="https://..."></label>
                            <label class="config-check-row"><input type="checkbox" id="cfg-ticket-show-customer"> Imprimir datos del cliente</label>
                            <label class="config-check-row"><input type="checkbox" id="cfg-ticket-include-unit-price"> Incluir precio unitario</label>
                            <label class="config-check-row"><input type="checkbox" id="cfg-ticket-full-description"> Imprimir descripción completa</label>
                        </div>

                        <div class="config-actions config-actions--left">
                            <button class="btn-secondary" type="button" id="cfg-ticket-test-print">Probar impresión con último ticket</button>
                            <button class="btn-primary" type="button" id="cfg-save-ticket">Guardar ticket</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="config-panel" data-panel="impuestos">
                <h3>Impuestos</h3>
                <div class="config-form-grid">
                    <label class="config-check-row"><input type="checkbox" id="cfg-tax-enabled"> Mis productos manejan impuestos</label>
                    <label>País fiscal
                        <select id="cfg-tax-country">
                            <option value="EC">Ecuador</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </label>
                </div>

                <div class="config-mini-table-wrap">
                    <table class="grid" id="cfg-tax-table">
                        <thead>
                        <tr>
                            <th>Nombre del impuesto</th>
                            <th>Porcentaje</th>
                            <th>Predeterminado</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                        </thead>
                        <tbody id="cfg-tax-list-body"></tbody>
                    </table>
                </div>

                <div class="config-form-grid config-form-grid--tax-editor">
                    <input type="hidden" id="cfg-tax-edit-index" value="-1">
                    <label>Nombre del impuesto
                        <input type="text" id="cfg-tax-name" value="IVA">
                    </label>
                    <label>Porcentaje
                        <input type="number" id="cfg-tax-rate" min="0" max="100" step="0.01">
                    </label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-tax-include-new"> Usar como predeterminado en nuevos productos</label>
                </div>
                <div class="config-actions config-actions--left">
                    <button class="btn-secondary" type="button" id="cfg-tax-add-btn">Guardar impuesto</button>
                    <button class="btn-secondary" type="button" id="cfg-tax-clear-btn">Limpiar</button>
                    <span id="cfg-tax-status" class="cfg-inline-status"></span>
                </div>

                <div class="config-form-grid config-form-grid--single">
                    <label class="config-check-row"><input type="checkbox" id="cfg-tax-breakdown-ticket"> Desglosar impuestos en el ticket de venta</label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-tax-prices-include"> Ingresar precios de venta con impuestos incluidos</label>
                    <label>Los precios de mis productos
                        <select id="cfg-tax-withholding-mode">
                            <option value="none">No incluyen impuesto retenido</option>
                            <option value="included">Incluyen impuesto retenido</option>
                        </select>
                    </label>
                </div>
                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-tax">Guardar preferencias de impuestos</button>
                </div>
            </section>

            <section class="config-panel" data-panel="corte">
                <h3>Corte</h3>
                <div class="config-form-grid">
                    <label class="config-check-row"><input type="checkbox" id="cfg-corte-negative"> Permitir cierre con faltante</label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-corte-print"> Imprimir resumen de corte</label>
                </div>
                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-corte">Guardar corte</button>
                </div>
            </section>

            <section class="config-panel" data-panel="unidades_medida">
                <h3>Unidades de medida</h3>
                <p class="muted">Elige las unidades de medida que usas en tu negocio.</p>
                <div class="config-units-list" id="cfg-units-list">
                    <label class="config-check-row config-unit-item" data-unit-tip="Horas y minutos. Util para servicios por tiempo, mano de obra o renta por duracion."><input type="checkbox" value="H/MIN" id="cfg-unit-hmin"> H / MIN</label>
                    <label class="config-check-row config-unit-item" data-unit-tip="Kilogramos y gramos. Util para peso de alimentos, granos, carnes y productos a granel."><input type="checkbox" value="KG/G" id="cfg-unit-kgg"> KG / G</label>
                    <label class="config-check-row config-unit-item" data-unit-tip="Litros y mililitros. Util para bebidas, liquidos y productos de volumen."><input type="checkbox" value="L/ML" id="cfg-unit-lml"> L / ML</label>
                    <label class="config-check-row config-unit-item" data-unit-tip="Metros y centimetros. Util para telas, cables, mangueras o venta por longitud."><input type="checkbox" value="M/CM" id="cfg-unit-mcm"> M / CM</label>
                    <label class="config-check-row config-unit-item" data-unit-tip="No aplica unidad. Util para productos o servicios que no requieren medida especifica."><input type="checkbox" value="NO_APLICA" id="cfg-unit-na"> NO APLICA</label>
                    <label class="config-check-row config-unit-item" data-unit-tip="Piezas. Util para productos unitarios como botellas, paquetes, cajas o unidades sueltas."><input type="checkbox" value="PZA" id="cfg-unit-pza"> PZA</label>
                </div>
                <div class="config-form-grid">
                    <label>Unidad por defecto
                        <select id="cfg-units-default">
                            <option value="PZA">PZA</option>
                            <option value="KG/G">KG / G</option>
                            <option value="L/ML">L / ML</option>
                            <option value="M/CM">M / CM</option>
                            <option value="H/MIN">H / MIN</option>
                            <option value="NO_APLICA">NO APLICA</option>
                        </select>
                    </label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-units-decimal"> Permitir cantidades decimales</label>
                </div>
                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-units">Guardar unidades</button>
                </div>
            </section>

            <section class="config-panel" data-panel="impresora_tickets">
                <h3>Impresora de tickets</h3>
                <div class="config-form-grid">
                    <label class="config-check-row"><input type="checkbox" id="cfg-printer-enabled"> Habilitada</label>
                    <label>Impresora de tickets
                        <select id="cfg-printer-model">
                            <option value="POS-80C">POS-80C</option>
                            <option value="Epson TM-U220">Epson TM-U220</option>
                            <option value="Generic ESC/POS">Generic ESC/POS</option>
                        </select>
                    </label>
                    <label>Conexion
                        <select id="cfg-printer-connection">
                            <option value="USB">USB</option>
                            <option value="SERIAL">SERIAL</option>
                            <option value="LAN">LAN</option>
                        </select>
                    </label>
                    <label>Nombre de cola del sistema<input type="text" id="cfg-printer-name" placeholder="Ej. POS-80C"></label>
                    <label>Fuente de impresion normal
                        <select id="cfg-printer-font-family">
                            <option value="Consolas">Consolas</option>
                            <option value="HoloLens MDL2 Assets">HoloLens MDL2 Assets</option>
                            <option value="Courier New">Courier New</option>
                            <option value="Lucida Console">Lucida Console</option>
                        </select>
                    </label>
                    <label>Tamaño de fuente<input type="number" id="cfg-printer-font-size" min="8" max="20"></label>
                    <label>Columnas<input type="number" id="cfg-printer-columns" min="20" max="80"></label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-printer-use-normal-totals"> Usar fuente normal para los totales</label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-printer-bold-letters"> Poner todas las letras en negrita</label>
                </div>
                <div class="config-reader-test-controls">
                    <button class="btn-secondary" type="button" id="cfg-printer-test">Probar impresion</button>
                </div>
                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-printer">Guardar impresora</button>
                </div>
            </section>

            <section class="config-panel" data-panel="lector_codigo">
                <h3>Lector de código</h3>
                <p class="muted">Si cuentas con lector de código de barras con emulación de teclado, no es necesario configurar el lector.</p>
                <div class="config-form-grid">
                    <label class="config-check-row"><input type="checkbox" id="cfg-reader-enabled"> Habilitado</label>
                    <label>Modelo del lector
                        <select id="cfg-reader-model">
                            <option value="SU13">SU13</option>
                            <option value="H-300">H-300</option>
                            <option value="GENERIC_2D">Generico 2D</option>
                            <option value="GENERIC_1D">Generico 1D</option>
                        </select>
                    </label>
                    <label>Nombre del dispositivo<input type="text" id="cfg-reader-name" placeholder="Ej. Barcode Scanner"></label>
                    <label>Tecla de cierre del escaneo
                        <select id="cfg-reader-suffix-key">
                            <option value="ENTER">Enter</option>
                            <option value="TAB">Tab</option>
                            <option value="NONE">Ninguna</option>
                        </select>
                    </label>
                    <label class="config-check-row"><input type="checkbox" id="cfg-reader-serial-enabled"> Utilizo un lector de código de barras serial</label>
                    <label>Puerto serial (opcional)<input type="text" id="cfg-reader-serial-port" placeholder="Ej. COM3"></label>
                    <label>Baudios serial
                        <select id="cfg-reader-serial-baud">
                            <option value="9600">9600</option>
                            <option value="19200">19200</option>
                            <option value="38400">38400</option>
                            <option value="115200">115200</option>
                        </select>
                    </label>
                </div>

                <div class="config-reader-test" id="cfg-reader-test">
                    <h4>Prueba de lector de código de barras</h4>
                    <p class="muted">Haz click en Iniciar prueba, escanea un código y verificamos si la captura parece lector (rápida) o teclado manual.</p>
                    <div class="config-reader-test-controls">
                        <button class="btn-secondary" type="button" id="cfg-reader-start-test">Iniciar prueba</button>
                        <button class="btn-secondary" type="button" id="cfg-reader-clear-test">Limpiar resultados</button>
                    </div>
                    <label>Campo de prueba
                        <input type="text" id="cfg-reader-test-input" autocomplete="off" placeholder="Escanea aquí...">
                    </label>
                    <div class="config-reader-test-meta">
                        <span id="cfg-reader-test-status">Esperando lectura...</span>
                        <span id="cfg-reader-test-speed"></span>
                    </div>
                    <div id="cfg-reader-test-last" class="config-reader-test-last"></div>
                    <div class="config-reader-test-log-wrap">
                        <table class="grid" id="cfg-reader-test-log">
                            <thead>
                            <tr>
                                <th>Hora</th>
                                <th>Código</th>
                                <th>Longitud</th>
                                <th>Promedio ms/tecla</th>
                                <th>Clasificación</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div class="config-actions">
                    <button class="btn-primary" type="button" id="cfg-save-reader">Guardar lector</button>
                </div>
            </section>

            <p id="cfg-status" class="muted"></p>
        </div>
    </div>
</section>
