/* global $, window, document */
(function () {
  'use strict';

  function isFacturacionPage() {
    return $('#facturacion-module').length > 0;
  }

  function money(value) {
    return Number(value || 0).toFixed(2);
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, function (s) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[s];
    });
  }

  function normalizeIva(iva) {
    var value = (iva || '').toString().trim();
    if (value === '15%') return '15%';
    if (value === '12%') return '12%';
    return '0%';
  }

  function taxRateFromIva(iva) {
    var normalized = normalizeIva(iva);
    if (normalized === '15%') return 0.15;
    if (normalized === '12%') return 0.12;
    return 0;
  }

  function round2(value) {
    return Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
  }

  function currentDateInputValue() {
    var now = new Date();
    var y = now.getFullYear();
    var m = String(now.getMonth() + 1).padStart(2, '0');
    var d = String(now.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function parseFacturaIssueDateToDate() {
    var raw = ($('#factura-issue-date').val() || '').toString().trim();
    var match = raw.match(/^(\d{2})-(\d{2})-(\d{4})$/);
    if (match) {
      return new Date(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
    }
    var parsed = new Date(raw);
    if (!Number.isNaN(parsed.getTime())) {
      return parsed;
    }
    return new Date();
  }

  function formatDateToInputValue(date) {
    var d = date instanceof Date ? date : new Date();
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function facturaPeriodDays(period) {
    switch ((period || '').toString()) {
      case 'daily': return 1;
      case 'weekly': return 7;
      case 'biweekly': return 15;
      case 'bimonthly': return 60;
      case 'quarterly': return 90;
      case 'semiannual': return 180;
      case 'annual': return 365;
      case 'monthly':
      default:
        return 30;
    }
  }

  function facturaPeriodLabel(period) {
    switch ((period || '').toString()) {
      case 'daily': return 'Diario';
      case 'weekly': return 'Semanal';
      case 'biweekly': return 'Quincenal';
      case 'bimonthly': return 'Bimestral';
      case 'quarterly': return 'Trimestral';
      case 'semiannual': return 'Semestral';
      case 'annual': return 'Anual';
      case 'monthly':
      default:
        return 'Mensual';
    }
  }

  function addDaysToDate(date, days) {
    var base = date instanceof Date ? new Date(date.getTime()) : new Date();
    base.setDate(base.getDate() + Math.max(0, Number(days || 0)));
    return base;
  }

  function diffDaysBetweenDates(startDate, endDate) {
    var start = startDate instanceof Date ? startDate : new Date();
    var end = endDate instanceof Date ? endDate : new Date();
    var ms = end.getTime() - start.getTime();
    return Math.max(0, Math.round(ms / 86400000));
  }

  function normalizeDigits(value) {
    return (value || '').toString().replace(/\D+/g, '');
  }

  function isValidProvinceCode(code) {
    var n = Number(code || 0);
    return n >= 1 && n <= 24;
  }

  function modulo10Check(digits10) {
    var sum = 0;
    for (var i = 0; i < 9; i += 1) {
      var n = Number(digits10.charAt(i));
      if (i % 2 === 0) {
        n *= 2;
        if (n > 9) n -= 9;
      }
      sum += n;
    }
    var verifier = (10 - (sum % 10)) % 10;
    return verifier === Number(digits10.charAt(9));
  }

  function modulo11Verifier(base, coeffs, verifierDigit) {
    var sum = 0;
    for (var i = 0; i < coeffs.length; i += 1) {
      sum += Number(base.charAt(i)) * coeffs[i];
    }
    var mod = 11 - (sum % 11);
    var expected = mod === 11 ? 0 : (mod === 10 ? 0 : mod);
    return expected === Number(verifierDigit);
  }

  function validateCedulaEcuador(digits) {
    if (digits.length !== 10) return false;
    if (!isValidProvinceCode(digits.slice(0, 2))) return false;
    var third = Number(digits.charAt(2));
    if (third < 0 || third > 5) return false;
    return modulo10Check(digits);
  }

  function validateRucEcuador(digits) {
    if (digits.length !== 13) return false;
    if (digits === '9999999999999') return true;
    if (!isValidProvinceCode(digits.slice(0, 2))) return false;

    var third = Number(digits.charAt(2));
    var estab = digits.slice(10, 13);
    if (estab === '000') return false;

    if (third >= 0 && third <= 5) {
      return validateCedulaEcuador(digits.slice(0, 10));
    }
    if (third === 6) {
      if (!modulo11Verifier(digits.slice(0, 8), [3, 2, 7, 6, 5, 4, 3, 2], digits.charAt(8))) return false;
      return digits.slice(9, 13) !== '0000';
    }
    if (third === 9) {
      if (!modulo11Verifier(digits.slice(0, 9), [4, 3, 2, 7, 6, 5, 4, 3, 2], digits.charAt(9))) return false;
      return estab !== '000';
    }
    return false;
  }

  function inferBuyerIdentificationType(value) {
    var digits = normalizeDigits(value);
    if (!digits) return '';
    if (digits === '9999999999999') return 'Consumidor final';
    if (digits.length === 13 && validateRucEcuador(digits)) return 'RUC';
    if (digits.length === 10 && validateCedulaEcuador(digits)) return 'Cedula';
    return '';
  }

  function calcLineTotals(item) {
    var qty = Number(item && item.cantidad ? item.cantidad : 0);
    var unitInput = Number(item && item.precioUnitario ? item.precioUnitario : 0);
    var grossBeforeDiscount = round2(qty * unitInput);
    var discount = Math.min(Math.max(0, Number(item && item.descuento ? item.descuento : 0)), grossBeforeDiscount);
    var rate = taxRateFromIva(item && item.iva ? item.iva : '0%');

    if (grossBeforeDiscount <= 0 || rate <= 0) {
      return {
        gross: Math.max(0, round2(grossBeforeDiscount - discount)),
        base: Math.max(0, round2(grossBeforeDiscount - discount)),
        tax: 0,
        discountBase: discount
      };
    }

    var baseBeforeDiscount = round2(grossBeforeDiscount * (1 - rate));
    var taxBeforeDiscount = round2(grossBeforeDiscount - baseBeforeDiscount);
    var discountBase = round2(discount * (1 - rate));
    var discountTax = round2(discount - discountBase);
    var base = round2(baseBeforeDiscount - discountBase);
    var tax = round2(taxBeforeDiscount - discountTax);
    return {
      gross: round2(grossBeforeDiscount - discount),
      base: base,
      tax: tax,
      discountBase: discountBase
    };
  }

  function readUrlParam(key) {
    return new URLSearchParams(window.location.search).get(key) || '';
  }

  var PENDING_POS_SALE_STORAGE_KEY = 'pos.factura.pending_sale.v1';
  var CART_STORAGE_KEY = 'pos.ventas.cart.v1';
  var LAST_TICKET_STORAGE_KEY = 'pos.ventas.last_ticket.v1';

  var state = {
    emitter: null,
    signature: null,
    points: [],
    customers: [],
    products: [],
    services: [],
    details: [],
    payments: [],
    facturaPaymentDraftMethod: 'cash',
    additionalFields: [],
    paymentMethodDraft: 'cash',
    originSaleId: '',
    buyerCustomerId: '',
    pendingPosSale: null,
    invoiceSubmitting: false
  };

  function apiGet(action, extra) {
    return $.getJSON('../api/facturacion.php', $.extend({ action: action }, extra || {}));
  }

  function apiPost(payload) {
    return $.ajax({
      url: '../api/facturacion.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(payload)
    });
  }

  function apiDelete(payload) {
    return $.ajax({
      url: '../api/facturacion.php',
      method: 'DELETE',
      contentType: 'application/json',
      data: JSON.stringify(payload)
    });
  }

  function readPendingPosSale() {
    try {
      var raw = (window.sessionStorage.getItem(PENDING_POS_SALE_STORAGE_KEY) || '').toString();
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (err) {
      return null;
    }
  }

  function clearPendingPosSale() {
    try {
      window.sessionStorage.removeItem(PENDING_POS_SALE_STORAGE_KEY);
    } catch (err) {}
  }

  function markVentaCartAsCompleted(ticketId) {
    try {
      window.sessionStorage.removeItem(CART_STORAGE_KEY);
      if (ticketId) {
        window.sessionStorage.setItem(LAST_TICKET_STORAGE_KEY, (ticketId || '').toString());
      }
    } catch (err) {}
  }

  function setInvoiceSubmitting(isSubmitting) {
    var busy = Boolean(isSubmitting);
    state.invoiceSubmitting = busy;
    $('#factura-issue-btn')
      .prop('disabled', busy)
      .text(busy ? 'Firmando y enviando...' : 'Firmar y enviar');
  }

  function facturaAlert(options) {
    var opts = options || {};
    if (window.Swal && typeof window.Swal.fire === 'function') {
      return window.Swal.fire({
        icon: opts.icon || 'info',
        title: opts.title || '',
        text: opts.text || '',
        html: opts.html || undefined,
        confirmButtonText: opts.confirmButtonText || 'Aceptar',
        allowOutsideClick: opts.allowOutsideClick !== false,
        allowEscapeKey: opts.allowEscapeKey !== false
      });
    }
    if (opts.icon === 'success') {
      showNotice((opts.title || 'Correcto') + (opts.text ? ': ' + opts.text : ''), 'success');
    } else if (opts.icon === 'error') {
      showNotice((opts.title || 'Error') + (opts.text ? ': ' + opts.text : ''), 'error');
    } else {
      showNotice((opts.title || '') + (opts.text ? ': ' + opts.text : ''), opts.icon || 'info');
    }
    return $.Deferred().resolve().promise();
  }

  function currentBuyerIdentificationType() {
    return ($('#factura-buyer-id-type').val() || '').toString().trim();
  }

  function currentBuyerIdentification() {
    return ($('#factura-buyer-identification').val() || '').toString().trim();
  }

  function currentBuyerName() {
    return ($('#factura-buyer-name').val() || '').toString().trim();
  }

  function hasBuyerCustomerContext() {
    var id = (state.buyerCustomerId || '').toString().trim();
    return id !== '' && id !== 'c-001';
  }

  function updateSaveBuyerIdButton() {
    var shouldShow = currentBuyerIdentificationType() !== 'Consumidor final'
      && currentBuyerName() !== ''
      && currentBuyerIdentification() !== '';
    $('#factura-save-buyer-id-btn').prop('hidden', !shouldShow).toggle(shouldShow);
  }

  function resetFacturaIssueForm() {
    state.details = [];
    state.payments = [];
    state.additionalFields = [];
    state.pendingPosSale = null;
    state.originSaleId = '';
    state.buyerCustomerId = '';
    state.paymentMethodDraft = 'cash';

    $('#factura-buyer-id-type').val('Consumidor final');
    $('#factura-buyer-identification').val('');
    $('#factura-buyer-name').val('');
    $('#factura-buyer-address').val('');
    $('#factura-buyer-phone').val('');
    $('#factura-buyer-email').val('');
    $('#factura-product-search').val('');
    $('#factura-tip').val('');
    $('#factura-pay-cash-value,#factura-pay-transfer-value,#factura-pay-credit-value,#factura-pay-mixed-cash,#factura-pay-mixed-transfer,#factura-pay-mixed-credit,#factura-pay-credit-interest,#factura-pay-mixed-interest').val('');
    $('#factura-pay-credit-due-date,#factura-pay-mixed-due-date').val('');
    $('#factura-pay-credit-period,#factura-pay-mixed-period').val('monthly');
    $('#factura-payment-modal').removeClass('active');
    $('#factura-payment-actions').prop('hidden', false).show();
    $('#factura-payment-origin-note').prop('hidden', true).hide();
    $('.factura-buyer-suggestions').prop('hidden', true).empty();
    clearPendingPosSale();

    renderFacturaDetails();
    renderFacturaPayments();
    renderFacturaAdditionalFields();
    recalcFacturaTotals();
    updateSaveBuyerIdButton();
  }

  function currentSection() {
    return ($('#facturacion-module').data('section') || '').toString();
  }

  function currentView() {
    return ($('#facturacion-module').data('view') || '').toString();
  }

  function sriStatusLabel(value, fallback) {
    var raw = (value || '').toString().trim().toUpperCase();
    if (raw === 'RECIBIDA') return 'RECIBIDA';
    if (raw === 'DEVUELTA') return 'DEVUELTA';
    if (raw === 'AUT') return 'AUTORIZADO';
    if (raw === 'AUTORIZADO') return 'AUTORIZADO';
    if (raw === 'NAT') return 'NO AUTORIZADO';
    if (raw === 'NO AUTORIZADO') return 'NO AUTORIZADO';
    if (raw === 'PPR') return 'PENDIENTE';
    if (!raw) return fallback || 'N/D';
    return raw;
  }

  function bindFacturacionNav() {
    $('[data-facturacion-nav]').on('change', function () {
      var section = ($(this).data('facturacion-nav') || '').toString();
      var view = ($(this).val() || '').toString();
      if (!section || !view) return;
      window.location.href = 'index.php?mod=facturas&section=' + encodeURIComponent(section) + '&view=' + encodeURIComponent(view);
    });
  }

  function setStatus(id, message, isError) {
    var $node = $(id);
    $node.text(message || '');
    $node.css('color', isError ? '#b91c1c' : '#2563eb');
  }

  function showNotice(message, type) {
    var text = (message || '').toString().trim();
    if (!text) return;

    var host = document.getElementById('app-notices');
    if (!host) {
      host = document.createElement('div');
      host.id = 'app-notices';
      host.className = 'app-notices';
      document.body.appendChild(host);
    }

    var item = document.createElement('div');
    item.className = 'app-notice app-notice--' + ((type || 'info').toString());
    item.textContent = text;
    host.appendChild(item);

    window.setTimeout(function () {
      item.classList.add('is-leaving');
      window.setTimeout(function () {
        if (item.parentNode) {
          item.parentNode.removeChild(item);
        }
      }, 220);
    }, 2600);
  }

  var confirmTokens = {};

  function requireSecondClickConfirmation(key, statusId, promptMessage) {
    var now = Date.now();
    var previous = confirmTokens[key] || 0;
    if (previous > 0 && (now - previous) <= 7000) {
      delete confirmTokens[key];
      setStatus(statusId, '');
      return true;
    }

    confirmTokens[key] = now;
    setStatus(statusId, (promptMessage || 'Confirme la accion.') + ' Haga clic nuevamente en menos de 7 segundos.', true);
    window.setTimeout(function () {
      if (confirmTokens[key] === now) {
        delete confirmTokens[key];
      }
    }, 7100);
    return false;
  }

  function responseErrorMessage(xhr, fallback) {
    var msg = fallback || 'Ocurrio un error.';
    try {
      if (xhr && xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) {
        msg = xhr.responseJSON.error || xhr.responseJSON.message;
      } else if (xhr && xhr.responseText) {
        msg = xhr.responseText;
      }
    } catch (e) {}
    return msg;
  }

  function loadOverview() {
    return apiGet('overview').done(function (res) {
      if (!res.ok) return;
      state.emitter = res.data.emitter || {};
      state.signature = res.data.signature || {};
      state.points = Array.isArray(res.data.points) ? res.data.points : [];
    });
  }

  function fillForm($form, data) {
    Object.keys(data || {}).forEach(function (key) {
      var $field = $form.find('[name="' + key + '"]');
      if (!$field.length || (($field.attr('type') || '').toLowerCase() === 'file')) {
        return;
      }
      $field.val(data[key]);
    });
  }

  function serializeForm($form) {
    var data = {};
    $form.serializeArray().forEach(function (row) {
      data[row.name] = row.value;
    });
    return data;
  }

  function bindEmitterPage() {
    apiGet('emitter').done(function (res) {
      if (!res.ok) return;
      fillForm($('#facturacion-emisor-form'), res.data || {});
    });

    $('#facturacion-emisor-save').on('click', function () {
      var payload = serializeForm($('#facturacion-emisor-form'));
      payload.action = 'save_emitter';
      apiPost(payload).done(function (res) {
        if (!res.ok) {
          setStatus('#facturacion-emisor-status', res.error || 'No se pudo guardar.', true);
          return;
        }
        setStatus('#facturacion-emisor-status', 'Datos del emisor guardados correctamente.');
      });
    });
  }

  function bindSignaturePage() {
    apiGet('signature').done(function (res) {
      if (!res.ok) return;
      fillForm($('#facturacion-firma-form'), res.data || {});
      var current = ((res.data || {}).certificatePath || '').toString();
      $('#facturacion-certificate-current').text(current ? ('Certificado actual: ' + current.split(/[\\/]/).pop()) : 'No hay certificado cargado.');
    });

    $('#facturacion-firma-save').on('click', function () {
      var form = document.getElementById('facturacion-firma-form');
      var payload = new FormData(form);
      payload.append('action', 'save_signature');
      $.ajax({
        url: '../api/facturacion.php',
        method: 'POST',
        data: payload,
        processData: false,
        contentType: false
      }).done(function (res) {
        if (!res.ok) {
          setStatus('#facturacion-firma-status', res.error || 'No se pudo guardar.', true);
          return;
        }
        var current = ((res.data || {}).certificatePath || '').toString();
        $('#facturacion-certificate-current').text(current ? ('Certificado actual: ' + current.split(/[\\/]/).pop()) : 'No hay certificado cargado.');
        setStatus('#facturacion-firma-status', 'Perfil y firma guardados correctamente.');
      });
    });

    $('#facturacion-firma-test').on('click', function () {
      var form = document.getElementById('facturacion-firma-form');
      var payload = new FormData(form);
      payload.append('action', 'test_signature');
      setStatus('#facturacion-firma-status', 'Validando certificado...');
      $.ajax({
        url: '../api/facturacion.php',
        method: 'POST',
        data: payload,
        processData: false,
        contentType: false,
        dataType: 'json'
      }).done(function (res) {
        if (!res.ok || (res.data && res.data.valid === false)) {
          var errorMessage = (res.data && res.data.message) || res.error || 'No se pudo validar el certificado.';
          setStatus('#facturacion-firma-status', errorMessage, true);
          window.alert('Error de certificado:\n' + errorMessage);
          return;
        }
        var meta = (res.data || {}).meta || {};
        var subject = meta.subject && (meta.subject.CN || meta.subject.O || '');
        var issuer = meta.issuer && (meta.issuer.CN || meta.issuer.O || '');
        var validTo = (meta.validTo || '').toString();
        var viaLegacy = meta.usedLegacyProvider ? ' (compatibilidad legacy activada)' : '';
        var successMessage =
          'Certificado valido. Titular: ' + (subject || 'N/D') + ' | Emisor: ' + (issuer || 'N/D') + ' | Vigencia hasta: ' + (validTo || 'N/D') + viaLegacy;
        setStatus(
          '#facturacion-firma-status',
          successMessage
        );
        window.alert('Certificado validado correctamente.' + (viaLegacy ? '\nSe uso modo de compatibilidad legacy de OpenSSL.' : ''));
      }).fail(function (xhr) {
        var msg = 'No se pudo validar el certificado.';
        try {
          var body = xhr && xhr.responseJSON;
          if (body && (body.error || body.message)) {
            msg = body.error || body.message;
          } else if (xhr && xhr.responseText) {
            msg = xhr.responseText;
          }
        } catch (e) {}
        setStatus('#facturacion-firma-status', msg, true);
        window.alert('Error de certificado:\n' + msg);
      });
    });

    $('#facturacion-email-test').on('click', function () {
      var form = document.getElementById('facturacion-firma-form');
      var payload = new FormData(form);
      var toEmail = (payload.get('testEmail') || '').toString().trim();
      if (!toEmail) {
        setStatus('#facturacion-firma-status', 'Ingrese el correo para la prueba.', true);
        window.alert('Ingrese el correo para la prueba.');
        return;
      }
      payload.append('action', 'test_email');
      setStatus('#facturacion-firma-status', 'Enviando correo de prueba...');
      $.ajax({
        url: '../api/facturacion.php',
        method: 'POST',
        data: payload,
        processData: false,
        contentType: false,
        dataType: 'json'
      }).done(function (res) {
        if (!res.ok) {
          var errorMessage = res.error || 'No se pudo enviar el correo de prueba.';
          setStatus('#facturacion-firma-status', errorMessage, true);
          window.alert('Error de correo:\n' + errorMessage);
          return;
        }
        var message = ((res.data || {}).message || 'Correo de prueba enviado correctamente.').toString();
        setStatus('#facturacion-firma-status', message);
        window.alert(message);
      }).fail(function (xhr) {
        var msg = responseErrorMessage(xhr, 'No se pudo enviar el correo de prueba.');
        setStatus('#facturacion-firma-status', msg, true);
        window.alert('Error de correo:\n' + msg);
      });
    });
  }

  function renderServiceRows(rows) {
    var q = ($('#facturacion-servicios-search').val() || '').toString().trim().toLowerCase();
    var $tbody = $('#facturacion-servicios-body').empty();
    rows.filter(function (row) {
      var text = ((row.barcode || '') + ' ' + (row.name || '')).toLowerCase();
      return !q || text.indexOf(q) >= 0;
    }).forEach(function (row) {
      var service = row.service || {};
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(row.barcode || row.id || '') + '</td>' +
          '<td>' + escapeHtml(row.name || '') + '</td>' +
          '<td>' + escapeHtml(service.codigoPrincipal || row.barcode || row.id || '') + '</td>' +
          '<td>' + escapeHtml(service.codigoAuxiliar || row.barcode || '') + '</td>' +
          '<td>' + escapeHtml(row.iva || 'No') + '</td>' +
          '<td>' + escapeHtml(String(service.iceValue || '0.00')) + '</td>' +
          '<td><button class="btn-secondary" type="button">Editar</button></td>' +
        '</tr>'
      );
      $tr.find('button').on('click', function () {
        var codigoPrincipal = window.prompt('Codigo principal', service.codigoPrincipal || row.barcode || row.id || '');
        if (codigoPrincipal === null) return;
        var codigoAuxiliar = window.prompt('Codigo auxiliar', service.codigoAuxiliar || row.barcode || '');
        if (codigoAuxiliar === null) return;
        var iceValue = window.prompt('Valor ICE', String(service.iceValue || 0));
        if (iceValue === null) return;

        apiPost({
          action: 'save_product_service',
          productId: row.id,
          codigoPrincipal: codigoPrincipal,
          codigoAuxiliar: codigoAuxiliar,
          iceValue: parseFloat(iceValue) || 0
        }).done(function (res) {
          if (!res.ok) {
            window.alert(res.error || 'No se pudo guardar.');
            return;
          }
          loadServicesPage();
        });
      });
      $tbody.append($tr);
    });

    if ($tbody.children().length === 0) {
      $tbody.append('<tr><td colspan="7" class="factura-empty">No existen productos para mostrar.</td></tr>');
    }
  }

  function loadServicesPage() {
    apiGet('product_services').done(function (res) {
      if (!res.ok) return;
      state.services = res.data.services || [];
      state.products = res.data.products || [];
      renderServiceRows(state.products);
    });
  }

  function bindServicesPage() {
    loadServicesPage();
    $('#facturacion-servicios-search').on('input', function () {
      renderServiceRows(state.products || []);
    });
    $('#facturacion-servicios-refresh').on('click', loadServicesPage);
  }

  function bindCargaPage() {
    $('#facturacion-carga-run').on('click', function () {
      apiPost({ action: 'sync_product_services' }).done(function (res) {
        if (!res.ok) {
          setStatus('#facturacion-carga-status', res.error || 'No se pudo sincronizar.', true);
          return;
        }
        setStatus('#facturacion-carga-status', 'Productos sincronizados correctamente: ' + (res.data || []).length + ' registros.');
      });
    });
  }

  function renderPointsRows() {
    var $tbody = $('#facturacion-puntos-body').empty();
    if (!state.points.length) {
      $tbody.append('<tr><td colspan="3" class="factura-empty">No existen puntos de emision</td></tr>');
      return;
    }
    state.points.forEach(function (point) {
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(point.nombre || '') + '</td>' +
          '<td>' + escapeHtml((point.estab || '000') + '-' + (point.ptoEmi || '000')) + '</td>' +
          '<td>' + escapeHtml(String(point.secuencialActual || 0)) + '</td>' +
        '</tr>'
      );
      $tr.on('click', function () {
        fillForm($('#facturacion-punto-form'), point);
      });
      $tbody.append($tr);
    });
  }

  function loadPointsPage() {
    apiGet('points').done(function (res) {
      if (!res.ok) return;
      state.points = Array.isArray(res.data) ? res.data : [];
      renderPointsRows();
    });
  }

  function bindPointsPage() {
    loadPointsPage();

    $('#facturacion-punto-new').on('click', function () {
      $('#facturacion-punto-form')[0].reset();
      $('#facturacion-punto-form [name=id]').val('');
    });

    $('#facturacion-punto-save').on('click', function () {
      var payload = serializeForm($('#facturacion-punto-form'));
      payload.action = 'save_point';
      apiPost(payload).done(function (res) {
        if (!res.ok) {
          setStatus('#facturacion-punto-status', res.error || 'No se pudo guardar.', true);
          return;
        }
        setStatus('#facturacion-punto-status', 'Punto de emision guardado.');
        loadPointsPage();
        fillForm($('#facturacion-punto-form'), res.data || {});
      });
    });

    $('#facturacion-punto-delete').on('click', function () {
      var id = ($('#facturacion-punto-form [name=id]').val() || '').toString();
      if (!id) return;
      if (!requireSecondClickConfirmation('delete-point-' + id, '#facturacion-punto-status', 'Confirme eliminar punto de emision.')) return;
      apiDelete({ action: 'delete_point', id: id }).done(function (res) {
        if (!res.ok) {
          setStatus('#facturacion-punto-status', res.error || 'No se pudo eliminar.', true);
          return;
        }
        setStatus('#facturacion-punto-status', 'Punto eliminado.');
        $('#facturacion-punto-form')[0].reset();
        loadPointsPage();
      }).fail(function (xhr) {
        setStatus('#facturacion-punto-status', responseErrorMessage(xhr, 'No se pudo eliminar.'), true);
      });
    });
  }

  function buildFacturaPayload() {
    return {
      pointId: ($('#factura-point-id').val() || '').toString(),
      issueDate: ($('#factura-issue-date').val() || '').toString(),
      guideNumber: ($('#factura-guide-number').val() || '').toString(),
      isNegotiable: $('#factura-is-negotiable').is(':checked'),
      buyer: {
        identification: ($('#factura-buyer-identification').val() || '').toString(),
        identificationType: ($('#factura-buyer-id-type').val() || '').toString(),
        razonSocial: ($('#factura-buyer-name').val() || '').toString(),
        address: ($('#factura-buyer-address').val() || '').toString(),
        phone: ($('#factura-buyer-phone').val() || '').toString(),
        email: ($('#factura-buyer-email').val() || '').toString()
      },
      details: state.details.map(function (item) { return $.extend({}, item); }),
      payments: state.payments.map(function (item) { return $.extend({}, item); }),
      additionalFields: state.additionalFields.map(function (item) { return $.extend({}, item); }),
      tip: ($('#factura-tip').val() || '').toString(),
      saleId: (state.originSaleId || '').toString()
    };
  }

  function appendInvoiceReferenceToNote(baseNote, documentData) {
    var note = (baseNote || '').toString().trim();
    var doc = documentData || {};
    var secuencial = (doc.secuencial || '').toString().trim();
    var accessKey = (doc.accessKey || '').toString().trim();
    var parts = [];
    if (secuencial) parts.push('Factura ' + secuencial);
    if (accessKey) parts.push('Clave ' + accessKey);
    if (!parts.length) return note;
    var suffix = parts.join(' | ');
    return note ? (note + ' | ' + suffix) : suffix;
  }

  function facturaThermalPaperWidthMm() {
    var raw = (window.localStorage.getItem('pos.print.paperWidthMm') || '').toString().trim();
    return raw === '80' ? 80 : 58;
  }

  function facturaEnvLabel(value) {
    return (value || '').toString() === '2' ? 'PRODUCCION' : 'PRUEBAS';
  }

  function facturaTipoEmisionLabel(value) {
    return (value || '').toString() === '1' ? 'NORMAL' : (value || 'NORMAL').toString().toUpperCase();
  }

  function facturaAuthStatusLabel(documentData) {
    var sri = (documentData && documentData.sri && typeof documentData.sri === 'object') ? documentData.sri : {};
    var status = (sri.authorizationStatus || documentData.status || '').toString().trim().toUpperCase();
    if (status === 'AUT' || status === 'AUTHORIZED') return 'AUTORIZADO';
    if (status === 'RECIBIDA') return 'RECIBIDA';
    if (status === 'DEVUELTA') return 'DEVUELTA';
    if (status === 'NAT') return 'NO AUTORIZADO';
    return status || 'PENDIENTE';
  }

  function facturaReceivedAmount(documentData) {
    var payments = Array.isArray(documentData.payments) ? documentData.payments : [];
    return payments.reduce(function (sum, row) {
      return sum + Number(row.total || row.value || 0);
    }, 0);
  }

  function facturaTicketTotalValue(totals, key) {
    var value = totals && totals[key] !== undefined && totals[key] !== null ? totals[key] : 0;
    if (value === '') return 0;
    return Number(value || 0);
  }

  function saleItemInvoicePricing(item, salePayload) {
    var qty = parseFloat(item && item.qty ? item.qty : 0) || 0;
    var currentUnit = parseFloat(item && item.price ? item.price : 0) || 0;
    var baseUnit = parseFloat(item && item.basePrice ? item.basePrice : 0) || 0;
    if (!(baseUnit > 0)) {
      baseUnit = parseFloat(item && item.normalPrice ? item.normalPrice : 0) || 0;
    }
    if (!(baseUnit > 0)) {
      baseUnit = currentUnit;
    }

    var globalPct = parseFloat(
      item && item.saleDiscountPct !== undefined && item.saleDiscountPct !== null
        ? item.saleDiscountPct
        : (salePayload && salePayload.discountPct ? salePayload.discountPct : 0)
    ) || 0;
    globalPct = Math.max(0, Math.min(100, globalPct));

    var invoiceUnit = parseFloat(
      item && item.invoiceUnitPrice !== undefined && item.invoiceUnitPrice !== null
        ? item.invoiceUnitPrice
        : 0
    ) || 0;
    if (!(invoiceUnit > 0)) {
      invoiceUnit = currentUnit;
      if (globalPct > 0) {
        invoiceUnit = round2(invoiceUnit * (1 - (globalPct / 100)));
      }
    }

    var lineBaseGross = round2(qty * baseUnit);
    var lineNetGross = round2(qty * invoiceUnit);
    return {
      unitPrice: baseUnit,
      discount: round2(Math.max(0, lineBaseGross - lineNetGross))
    };
  }

  function printFacturaTicket(documentData) {
    var doc = documentData || {};
    var emitter = doc.emitter || state.emitter || {};
    var point = doc.point || {};
    var buyer = doc.buyer || {};
    var details = Array.isArray(doc.details) ? doc.details : [];
    var totals = doc.totals || {};
    var payments = Array.isArray(doc.payments) ? doc.payments : [];
    var paperMm = facturaThermalPaperWidthMm();
    var facturaNumero = [doc.estab || point.estab || '', doc.ptoEmi || point.ptoEmi || '', doc.secuencial || ''].join('-');
    var authorizationDate = ((doc.sri || {}).authorizationDate || (doc.sri || {}).authorizedAt || '').toString();
    var received = facturaReceivedAmount(doc);
    var total = Number(totals.importeTotal || 0);
    var change = Math.max(0, received - total);
    var qtyTotal = details.reduce(function (sum, item) { return sum + Number(item.cantidad || 0); }, 0);
    var subtotalSinImpuestos = facturaTicketTotalValue(totals, 'subtotalSinImpuestos');
    var totalDescuento = facturaTicketTotalValue(totals, 'displayTotalDescuento');
    if (!(totalDescuento > 0)) {
      totalDescuento = facturaTicketTotalValue(totals, 'totalDescuento');
    }
    var subtotalIva15 = facturaTicketTotalValue(totals, 'subtotal15');
    var iva15 = facturaTicketTotalValue(totals, 'iva15');
    var subtotalIva0 = facturaTicketTotalValue(totals, 'subtotal0');
    var propina = facturaTicketTotalValue(totals, 'propina');

    function line(label, value) {
      var cleanValue = value === undefined || value === null ? '' : value.toString();
      if (!cleanValue) return '';
      return '<div>' + escapeHtml(label + cleanValue) + '</div>';
    }

    var rows = details.map(function (item) {
      var qty = Number(item.cantidad || 0);
      var unit = Number(item.precioVenta !== undefined ? item.precioVenta : item.precioUnitario || 0);
      var discount = Number(item.descuentoVenta !== undefined ? item.descuentoVenta : item.descuento || 0);
      var amount = Number(item.totalVenta !== undefined ? item.totalVenta : 0);
      if (!(amount > 0)) {
        amount = Math.max(0, qty * unit);
      }
      amount = Math.max(0, amount - discount);
      var displayUnit = unit;
      if (discount > 0 && qty > 0) {
        displayUnit = round2(amount / qty);
      }
      var taxableMark = taxRateFromIva(item.iva || '0%') > 0 ? '*' : '';
      return (
        '<tr>' +
          '<td class="qty">' + escapeHtml(money(qty)) + '</td>' +
          '<td class="desc">' + escapeHtml((item.descripcion || '').toString()) + '</td>' +
          '<td class="unit">' + escapeHtml(money(displayUnit)) + '</td>' +
          '<td class="amt">' + escapeHtml(money(amount) + taxableMark) + '</td>' +
        '</tr>'
      );
    }).join('');

    var paymentRows = payments.map(function (payment) {
      return '<div class="line"><span>' + escapeHtml((payment.label || payment.formaPago || 'Pago').toString().toUpperCase()) + '</span><strong>' + money(Number(payment.total || payment.value || 0)) + '</strong></div>';
    }).join('');

    var html = [
      '<!doctype html><html><head><meta charset="utf-8"><title>Ticket factura</title>',
      '<style>',
      '@page{size:' + paperMm + 'mm auto;margin:2mm}',
      'html,body{margin:0;padding:0;background:#fff}',
      'body{font-family:Consolas,"Courier New",monospace;color:#000}',
      '.ticket{width:' + (paperMm - 4) + 'mm;padding:1mm 1mm 4mm;font-size:10px;line-height:1.18}',
      '.center{text-align:center}.brand{font-size:16px;font-weight:700;letter-spacing:1px}.small{font-size:8px}.title{font-size:12px;font-weight:700}',
      '.sep{border-top:1px dashed #000;margin:4px 0}.sep2{border-top:1px solid #000;margin:4px 0}',
      '.key{word-break:break-all;text-align:center;font-size:9px}',
      'table{width:100%;border-collapse:collapse;font-size:9px}th,td{padding:2px 1px;vertical-align:top}thead th{border-bottom:1px dashed #000}',
      '.qty{width:15%;text-align:right}.desc{width:45%;word-break:break-word}.unit{width:20%;text-align:right}.amt{width:20%;text-align:right}',
      '.line{display:flex;justify-content:space-between;gap:6px}.line strong{font-weight:700}.total{font-size:12px;font-weight:700}',
      '.signature{margin-top:14px;text-align:center}.signature-line{border-top:1px solid #000;margin:18px auto 3px;width:80%}',
      '.legal{font-size:8px;text-align:center;margin-top:7px}.thanks{text-align:center;margin-top:10px;font-size:9px}',
      '</style></head><body><div class="ticket">',
      '<div class="center title">FACTURA ELECTRONICA</div>',
      '<div class="center brand">' + escapeHtml((emitter.nombreComercial || emitter.razonSocial || 'FACTURA').toString()) + '</div>',
      '<div class="center small">' + escapeHtml((emitter.razonSocial || '').toString()) + '</div>',
      line('RUC: ', emitter.ruc),
      line('MATRIZ: ', emitter.dirMatriz),
      line('TELEFONO: ', emitter.telefono),
      '<div>AMBIENTE: ' + escapeHtml(facturaEnvLabel(doc.environment || emitter.ambiente)) + '</div>',
      '<div>EMISION: ' + escapeHtml(facturaTipoEmisionLabel(emitter.tipoEmision)) + '</div>',
      '<div class="center">OBLIGADO A LLEVAR CONTABILIDAD: ' + escapeHtml((emitter.obligadoContabilidad || 'NO').toString().toUpperCase()) + '</div>',
      '<div class="center">*** CLAVE DE ACCESO ***</div>',
      '<div class="key">' + escapeHtml((doc.accessKey || '').toString()) + '</div>',
      '<div class="sep2"></div>',
      '<div class="center title">FACTURA NRO. ' + escapeHtml(facturaNumero) + '</div>',
      '<div class="sep2"></div>',
      line('RUC/CI: ', buyer.identification),
      line('Telf.: ', buyer.phone),
      line('Nombre: ', buyer.razonSocial),
      line('Direc.: ', buyer.address),
      line('Correo: ', buyer.email),
      line('Fecha: ', doc.issueDate || doc.issueDateKey),
      line('Aut.: ', authorizationDate),
      line('Estado: ', facturaAuthStatusLabel(doc)),
      '<div class="sep"></div>',
      '<table><thead><tr><th class="qty">Cant.</th><th class="desc">Producto</th><th class="unit">Precio U.</th><th class="amt">Total</th></tr></thead><tbody>',
      rows,
      '</tbody></table>',
      '<div class="sep"></div>',
      '<div class="line"><span>SUBTOTAL SIN IMPUESTO:</span><strong>' + money(subtotalSinImpuestos) + '</strong></div>',
      '<div class="line"><span>DESCUENTO:</span><strong>' + money(totalDescuento) + '</strong></div>',
      '<div class="line"><span>SUBTOTAL IVA 0%:</span><strong>' + money(subtotalIva0) + '</strong></div>',
      subtotalIva15 > 0 ? '<div class="line"><span>SUBTOTAL IVA 15%:</span><strong>' + money(subtotalIva15) + '</strong></div>' : '',
      iva15 > 0 ? '<div class="line"><span>IVA 15%:</span><strong>' + money(iva15) + '</strong></div>' : '',
      '<div class="line"><span>PROPINA:</span><strong>' + money(propina) + '</strong></div>',
      '<div class="line total"><span>VALOR TOTAL:</span><strong>' + money(total) + '</strong></div>',
      '<div class="sep2"></div>',
      '<div class="center title">F O R M A S  D E  P A G O</div>',
      paymentRows || '<div class="line"><span>EFECTIVO</span><strong>' + money(total) + '</strong></div>',
      '<div class="sep2"></div>',
      '<div class="line"><span>MONTO RECIBIDO</span><strong>' + money(received || total) + '</strong></div>',
      '<div class="line"><span>CAMBIO</span><strong>' + money(change) + '</strong></div>',
      '<div class="legal">DESCARGUE SU FACTURA ELECTRONICA EN:<br>https://srienlinea.sri.gob.ec/<br>DOCUMENTO SIN SUSTENTO TRIBUTARIO</div>',
      '<div class="legal">1 REALIZADA LA COMPRA NO HAY DEVOLUCIONES<br>2 LOS CAMBIOS SE REALIZARAN SEGUN POLITICAS DEL LOCAL</div>',
      '<div class="signature"><div class="signature-line"></div><div>FIRMA DEL CLIENTE</div></div>',
      '<div class="center title">*** ORIGINAL ***</div>',
      '<div class="thanks">Muchas gracias por su compra</div>',
      '</div></body></html>'
    ].join('');

    var iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '-10000px';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    var printDocument = iframe.contentWindow && iframe.contentWindow.document;
    if (!printDocument) {
      document.body.removeChild(iframe);
      showNotice('Factura emitida, pero no se pudo abrir la impresion del ticket.', 'warning');
      return false;
    }

    printDocument.open();
    printDocument.write(html);
    printDocument.close();

    window.setTimeout(function () {
      try {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
      } finally {
        window.setTimeout(function () {
          if (iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);
          }
        }, 800);
      }
    }, 150);

    return true;
  }

  function buildFacturaPaymentsFromSalePayload(salePayload, totalOverride) {
    var source = salePayload && typeof salePayload === 'object' ? salePayload : {};
    var total = round2(Number(totalOverride !== undefined ? totalOverride : source.total) || 0);
    var method = (source.paymentMethod || 'cash').toString().trim().toLowerCase();
    var mixed = source.mixedPayments && typeof source.mixedPayments === 'object' ? source.mixedPayments : {};
    var payments = [];

    function pushPayment(paymentMethod, label, value, term) {
      var amount = round2(Number(value || 0));
      if (!(amount > 0)) return;
      var issueDate = parseFacturaIssueDateToDate();
      var safeTerm = Number(term || 0);
      payments.push({
        method: paymentMethod,
        label: label,
        value: amount,
        term: safeTerm,
        timeUnit: 'dias',
        dueDate: safeTerm > 0 ? formatDateToInputValue(addDaysToDate(issueDate, safeTerm)) : '',
        interestPercent: 0,
        period: safeTerm >= 30 ? 'monthly' : (safeTerm >= 15 ? 'biweekly' : (safeTerm >= 7 ? 'weekly' : 'daily'))
      });
    }

    if (method === 'mixed') {
      pushPayment('cash', 'Efectivo', mixed.cash || 0, 0);
      pushPayment('transfer', 'Transferencia', mixed.transfer || 0, 0);
      pushPayment('credit', 'Credito', mixed.credit || 0, 30);
    } else if (method === 'transfer') {
      pushPayment('transfer', 'Transferencia', total, 0);
    } else if (method === 'credit') {
      pushPayment('credit', 'Credito', total, 30);
    } else {
      pushPayment('cash', 'Efectivo', total, 0);
    }

    if (!payments.length && total > 0) {
      pushPayment('cash', 'Efectivo', total, 0);
    }

    var sum = payments.reduce(function (acc, row) { return acc + Number(row.value || 0); }, 0);
    var diff = round2(total - sum);
    if (payments.length && Math.abs(diff) >= 0.01) {
      payments[payments.length - 1].value = round2(Number(payments[payments.length - 1].value || 0) + diff);
    }

    return payments;
  }

  function buildSalePayloadFromPendingInvoice(documentData) {
    var wrapper = state.pendingPosSale && typeof state.pendingPosSale === 'object' ? state.pendingPosSale : null;
    var sourcePayload = wrapper && wrapper.salePayload && typeof wrapper.salePayload === 'object'
      ? wrapper.salePayload
      : null;
    if (!sourcePayload) return null;

    var totals = recalcFacturaTotals();
    var buyerName = ($('#factura-buyer-name').val() || sourcePayload.customerName || 'Consumidor final').toString().trim();
    var buyerIdentification = ($('#factura-buyer-identification').val() || '').toString().trim();
    var buyerType = ($('#factura-buyer-id-type').val() || '').toString().trim();
    var mappedItems = (state.details || []).map(function (item) {
      var qty = parseFloat(item.cantidad || 0) || 0;
      var discount = parseFloat(item.descuento || 0) || 0;
      var unitPrice = parseFloat(item.precioUnitario || 0) || 0;
      var netUnitPrice = qty > 0 ? ((unitPrice * qty) - discount) / qty : unitPrice;
      var normalizedIva = normalizeIva(item.iva);
      return {
        id: (item.productId || item.codigoPrincipal || item.codigoAuxiliar || '').toString(),
        barcode: (item.codigoPrincipal || item.codigoAuxiliar || item.productId || '').toString(),
        name: (item.descripcion || '').toString(),
        iva: normalizedIva,
        price: round2(netUnitPrice),
        qty: qty
      };
    }).filter(function (item) { return item.qty > 0; });

    var salePayload = $.extend(true, {}, sourcePayload);
    salePayload.items = mappedItems;
    salePayload.subtotal = round2(totals.subtotalSinImpuestos || 0);
    salePayload.total = round2(totals.importeTotal || 0);
    var paymentMethod = (sourcePayload.paymentMethod || 'cash').toString().trim().toLowerCase();
    salePayload.paymentMethod = paymentMethod || 'cash';
    salePayload.transferMeta = sourcePayload.transferMeta && typeof sourcePayload.transferMeta === 'object'
      ? $.extend({}, sourcePayload.transferMeta)
      : { reference: '', phone: '' };
    if (paymentMethod === 'credit') {
      salePayload.paidWith = 0;
      salePayload.change = 0;
      salePayload.amountPending = round2(salePayload.total);
      salePayload.mixedPayments = { cash: 0, transfer: 0, credit: round2(salePayload.total) };
    } else if (paymentMethod === 'mixed') {
      salePayload.mixedPayments = buildFacturaPaymentsFromSalePayload(sourcePayload, salePayload.total).reduce(function (acc, row) {
        if (row.method === 'cash') acc.cash = round2(Number(row.value || 0));
        if (row.method === 'transfer') acc.transfer = round2(Number(row.value || 0));
        if (row.method === 'credit') acc.credit = round2(Number(row.value || 0));
        return acc;
      }, { cash: 0, transfer: 0, credit: 0 });
      salePayload.paidWith = round2(Number(salePayload.mixedPayments.cash || 0) + Number(salePayload.mixedPayments.transfer || 0));
      salePayload.amountPending = round2(Number(salePayload.mixedPayments.credit || 0));
      salePayload.change = 0;
    } else {
      salePayload.paidWith = round2(salePayload.total);
      salePayload.change = 0;
      salePayload.amountPending = 0;
      salePayload.mixedPayments = null;
    }
    salePayload.customerName = buyerName || sourcePayload.customerName || 'Consumidor final';
    salePayload.customerId = (buyerType === 'Consumidor final' || buyerIdentification === '9999999999999')
      ? 'c-001'
      : (sourcePayload.customerId || buyerIdentification || 'c-001');
    salePayload.paymentNote = appendInvoiceReferenceToNote(salePayload.paymentNote, documentData);
    return salePayload;
  }

  function registerPendingPosSale(documentData) {
    var salePayload = buildSalePayloadFromPendingInvoice(documentData);
    if (!salePayload) {
      return $.Deferred().resolve({ ok: true, skipped: true }).promise();
    }

    return $.ajax({
      url: '../api/sales.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(salePayload)
    });
  }

  function applyTicketPrefill(ticketId) {
    var safeTicketId = (ticketId || '').toString().trim();
    if (!safeTicketId) return;

    apiGet('sale_context', { ticketId: safeTicketId }).done(function (res) {
      if (!res.ok) {
        setStatus('#factura-issue-status', res.error || 'No se pudo cargar la venta para facturar.', true);
        return;
      }

      var data = res.data || {};
      var buyer = data.buyer || {};
      state.originSaleId = (data.ticketId || safeTicketId).toString();
      state.buyerCustomerId = (((data.sale || {}).customerId) || '').toString();

      $('#factura-buyer-identification').val((buyer.identification || '').toString());
      $('#factura-buyer-id-type').val((buyer.identificationType || 'Consumidor final').toString());
      $('#factura-buyer-name').val((buyer.razonSocial || '').toString());
      $('#factura-buyer-address').val((buyer.address || '').toString());
      $('#factura-buyer-phone').val((buyer.phone || '').toString());
      $('#factura-buyer-email').val((buyer.email || '').toString());

      state.details = (data.details || []).map(function (item) {
        return {
          productId: (item.productId || '').toString(),
          codigoPrincipal: (item.codigoPrincipal || '').toString(),
          codigoAuxiliar: (item.codigoAuxiliar || '').toString(),
          cantidad: parseFloat(item.cantidad || 0) || 0,
          descripcion: (item.descripcion || '').toString(),
          precioUnitario: parseFloat(item.precioUnitario || 0) || 0,
          iva: normalizeIva(item.iva),
          descuento: parseFloat(item.descuento || 0) || 0,
          valorICE: parseFloat(item.valorICE || 0) || 0
        };
      }).filter(function (item) { return item.cantidad > 0; });

      state.payments = (data.payments || []).map(function (item) {
        return {
          method: (item.method || 'cash').toString(),
          label: (item.label || 'Efectivo').toString(),
          value: parseFloat(item.value || 0) || 0,
          term: parseInt(item.term || 0, 10) || 0,
          timeUnit: (item.timeUnit || 'dias').toString()
        };
      }).filter(function (item) { return item.value > 0; });

      if (state.payments.length > 0) {
        state.paymentMethodDraft = (state.payments[0].method || 'cash').toString();
      }

      renderFacturaDetails();
      renderFacturaPayments();
      updateSaveBuyerIdButton();
      setStatus('#factura-issue-status', 'Venta #' + safeTicketId + ' cargada en la factura.');
    }).fail(function (xhr) {
      var message = 'No se pudo cargar la venta para facturar.';
      try {
        message = (xhr && xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) || message;
      } catch (e) {}
      setStatus('#factura-issue-status', message, true);
    });
  }

  function recalcFacturaTotals() {
    var totals = {
      subtotalSinImpuestos: 0,
      subtotal15: 0,
      subtotal12: 0,
      subtotal5: 0,
      subtotalTarifaEspecial: 0,
      subtotal0: 0,
      subtotalNoObjetoIva: 0,
      subtotalExentoIva: 0,
      totalDescuento: 0,
      valorICE: 0,
      iva15: 0,
      iva12: 0,
      iva5: 0,
      ivaTarifaEspecial: 0,
      importeTotal: 0
    };

    var grossByRate = {
      '15%': 0,
      '12%': 0,
      '0%': 0
    };
    var displayTaxByRate = {
      '15%': 0,
      '12%': 0
    };

    state.details.forEach(function (item) {
      var iva = normalizeIva(item.iva);
      var qty = Number(item.cantidad || 0);
      var unit = Number(item.precioUnitario || 0);
      var grossBeforeDiscount = round2(qty * unit);
      var discount = Math.min(Math.max(0, Number(item.descuento || 0)), grossBeforeDiscount);
      var netGross = round2(grossBeforeDiscount - discount);
      totals.subtotalSinDescuento += grossBeforeDiscount;
      totals.totalDescuento += discount;
      totals.valorICE += item.valorICE || 0;
      if (iva === '15%') {
        var displayBase15 = round2(netGross * 0.85);
        totals.subtotal15 += displayBase15;
        displayTaxByRate['15%'] += round2(netGross - displayBase15);
        grossByRate['15%'] += netGross;
      } else if (iva === '12%') {
        var displayBase12 = round2(netGross * 0.88);
        totals.subtotal12 += displayBase12;
        displayTaxByRate['12%'] += round2(netGross - displayBase12);
        grossByRate['12%'] += netGross;
      } else {
        totals.subtotal0 += netGross;
        grossByRate['0%'] += netGross;
      }
    });

    totals.subtotal15 = round2(totals.subtotal15);
    totals.iva15 = round2(grossByRate['15%'] - totals.subtotal15);
    totals.subtotal12 = round2(totals.subtotal12);
    totals.iva12 = round2(grossByRate['12%'] - totals.subtotal12);
    totals.subtotal0 = round2(totals.subtotal0);
    totals.subtotalSinImpuestos = round2(totals.subtotal15 + totals.subtotal12 + totals.subtotal0);
    totals.totalDescuento = round2(totals.totalDescuento);

    var propina = parseFloat(($('#factura-tip').val() || '0').toString().replace(',', '.')) || 0;
    totals.propina = Math.max(0, propina);
    totals.iva0 = 0;
    totals.subtotalSinDescuento = round2(totals.subtotalSinDescuento);
    totals.importeTotal = round2(Object.keys(grossByRate).reduce(function (sum, key) {
      return sum + grossByRate[key];
    }, 0) + totals.propina);
    totals.displaySubtotalSinDescuento = totals.subtotalSinDescuento;
    totals.displayTotalDescuento = totals.totalDescuento;
    totals.displayTotalSinImpuestos = totals.subtotalSinImpuestos;
    totals.displaySubtotal15 = totals.subtotal15;
    totals.displaySubtotal0 = totals.subtotal0;
    totals.displayIva15 = round2(displayTaxByRate['15%']);
    totals.displayIva12 = round2(displayTaxByRate['12%']);

    Object.keys(totals).forEach(function (key) {
      $('[data-total="' + key + '"]').text(money(totals[key]));
    });
    return totals;
  }

  function renderFacturaDetails() {
    var $tbody = $('#factura-detail-body').empty();
    state.details.forEach(function (item, index) {
      var qty = Number(item.cantidad || 0);
      var lineTotal = round2(qty * Number(item.precioUnitario || 0));
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(item.codigoPrincipal || '') + '</td>' +
          '<td>' + escapeHtml(item.codigoAuxiliar || '') + '</td>' +
          '<td class="catalog-center">' + escapeHtml(String(item.cantidad || 0)) + '</td>' +
          '<td>' + escapeHtml(item.descripcion || '') + '</td>' +
          '<td class="catalog-money">' + money(item.precioUnitario || 0) + '</td>' +
          '<td class="catalog-center">' + escapeHtml(item.iva || '0%') + '</td>' +
          '<td class="catalog-money">' + money(item.descuento || 0) + '</td>' +
          '<td class="catalog-money">' + money(lineTotal) + '</td>' +
          '<td class="catalog-money">' + money(item.valorICE || 0) + '</td>' +
          '<td><button class="btn-secondary" type="button">Quitar</button></td>' +
        '</tr>'
      );
      $tr.find('button').on('click', function () {
        state.details.splice(index, 1);
        renderFacturaDetails();
      });
      $tbody.append($tr);
    });

    if (!$tbody.children().length) {
      $tbody.append('<tr><td colspan="10" class="factura-empty">No existen productos</td></tr>');
    }
    recalcFacturaTotals();
  }

  function renderFacturaPayments() {
    var $tbody = $('#factura-payments-body').empty();
    var lockedBySale = Boolean(state.pendingPosSale);
    state.payments.forEach(function (item, index) {
      var dueDateLabel = (item.dueDate || '').toString() || (Number(item.term || 0) > 0 ? (String(item.term || 0) + ' dias') : '--');
      var periodLabel = (item.period ? facturaPeriodLabel(item.period) : (item.timeUnit || 'dias'));
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(item.label || '') + '</td>' +
          '<td class="catalog-money">' + money(item.value || 0) + '</td>' +
          '<td class="catalog-center">' + escapeHtml(dueDateLabel) + '</td>' +
          '<td class="catalog-center">' + escapeHtml(periodLabel) + '</td>' +
          '<td>' + (lockedBySale ? '<span class="muted">Desde venta</span>' : '<button class="btn-secondary" type="button">Quitar</button>') + '</td>' +
        '</tr>'
      );
      if (!lockedBySale) {
        $tr.find('button').on('click', function () {
          state.payments.splice(index, 1);
          renderFacturaPayments();
        });
      }
      $tbody.append($tr);
    });
    if (!$tbody.children().length) {
      $tbody.append('<tr><td colspan="5" class="factura-empty">No existen formas de pago</td></tr>');
    }
  }

  function currentFacturaTotal() {
    return round2(Number(recalcFacturaTotals().importeTotal || 0));
  }

  function setFacturaPaymentDraftMethod(method) {
    state.facturaPaymentDraftMethod = (method || 'cash').toString();
    $('[data-factura-pay-method]').each(function () {
      var active = (($(this).data('factura-pay-method') || '').toString() === state.facturaPaymentDraftMethod);
      $(this).toggleClass('is-active', active);
    });
    $('#factura-pay-pane-cash').prop('hidden', state.facturaPaymentDraftMethod !== 'cash');
    $('#factura-pay-pane-credit').prop('hidden', state.facturaPaymentDraftMethod !== 'credit');
    $('#factura-pay-pane-mixed').prop('hidden', state.facturaPaymentDraftMethod !== 'mixed');
    $('#factura-pay-pane-transfer').prop('hidden', state.facturaPaymentDraftMethod !== 'transfer');
    updateFacturaMixedRemaining();
  }

  function updateFacturaMixedRemaining() {
    var total = currentFacturaTotal();
    var cash = Number($('#factura-pay-mixed-cash').val() || 0);
    var transfer = Number($('#factura-pay-mixed-transfer').val() || 0);
    var credit = Number($('#factura-pay-mixed-credit').val() || 0);
    var remaining = round2(total - cash - transfer - credit);
    $('#factura-pay-mixed-remaining').text('Falta por completar: ' + money(Math.max(0, remaining)));
  }

  function syncFacturaCreditDueDateFromPeriod() {
    var period = ($('#factura-pay-credit-period').val() || 'monthly').toString();
    $('#factura-pay-credit-due-date').val(formatDateToInputValue(addDaysToDate(parseFacturaIssueDateToDate(), facturaPeriodDays(period))));
  }

  function syncFacturaMixedDueDateFromPeriod() {
    var period = ($('#factura-pay-mixed-period').val() || 'monthly').toString();
    $('#factura-pay-mixed-due-date').val(formatDateToInputValue(addDaysToDate(parseFacturaIssueDateToDate(), facturaPeriodDays(period))));
  }

  function hydrateFacturaPaymentModalFromState() {
    var total = currentFacturaTotal();
    var issueDate = parseFacturaIssueDateToDate();
    var defaultDueDate = formatDateToInputValue(addDaysToDate(issueDate, 30));
    $('#factura-payment-total').val(money(total));
    $('#factura-pay-cash-value').val(money(total));
    $('#factura-pay-transfer-value').val(money(total));
    $('#factura-pay-credit-value').val(money(total));
    $('#factura-pay-credit-due-date').val(defaultDueDate);
    $('#factura-pay-credit-interest').val('0.00');
    $('#factura-pay-credit-period').val('monthly');
    $('#factura-pay-mixed-cash').val('0.00');
    $('#factura-pay-mixed-transfer').val('0.00');
    $('#factura-pay-mixed-credit').val('0.00');
    $('#factura-pay-mixed-due-date').val(defaultDueDate);
    $('#factura-pay-mixed-interest').val('0.00');
    $('#factura-pay-mixed-period').val('monthly');

    if (state.payments.length === 1) {
      var single = state.payments[0] || {};
      var singleMethod = (single.method || 'cash').toString();
      if (singleMethod === 'transfer') {
        $('#factura-pay-transfer-value').val(money(single.value || 0));
      } else if (singleMethod === 'credit') {
        $('#factura-pay-credit-value').val(money(single.value || 0));
        $('#factura-pay-credit-due-date').val((single.dueDate || '').toString() || defaultDueDate);
        $('#factura-pay-credit-interest').val(money(single.interestPercent || 0));
        $('#factura-pay-credit-period').val((single.period || 'monthly').toString());
      } else {
        $('#factura-pay-cash-value').val(money(single.value || 0));
      }
      setFacturaPaymentDraftMethod(singleMethod);
      return;
    }

    if (state.payments.length > 1) {
      setFacturaPaymentDraftMethod('mixed');
      state.payments.forEach(function (item) {
        var method = (item.method || '').toString();
        if (method === 'cash') $('#factura-pay-mixed-cash').val(money(item.value || 0));
        if (method === 'transfer') $('#factura-pay-mixed-transfer').val(money(item.value || 0));
        if (method === 'credit') {
          $('#factura-pay-mixed-credit').val(money(item.value || 0));
          $('#factura-pay-mixed-due-date').val((item.dueDate || '').toString() || defaultDueDate);
          $('#factura-pay-mixed-interest').val(money(item.interestPercent || 0));
          $('#factura-pay-mixed-period').val((item.period || 'monthly').toString());
        }
      });
      updateFacturaMixedRemaining();
      return;
    }

    setFacturaPaymentDraftMethod(state.facturaPaymentDraftMethod || 'cash');
  }

  function openFacturaPaymentModal() {
    if (state.pendingPosSale) {
      setStatus('#factura-issue-status', 'La forma de pago se toma desde la venta realizada en caja.');
      return;
    }
    hydrateFacturaPaymentModalFromState();
    $('#factura-payment-modal').addClass('active');
  }

  function closeFacturaPaymentModal() {
    $('#factura-payment-modal').removeClass('active');
  }

  function applyFacturaPaymentModal() {
    var total = currentFacturaTotal();
    var payments = [];
    var method = (state.facturaPaymentDraftMethod || 'cash').toString();

    if (method === 'cash') {
      var cash = round2(Number($('#factura-pay-cash-value').val() || 0));
      if (cash <= 0) cash = total;
      payments.push({ method: 'cash', label: 'Efectivo', value: cash, term: 0, timeUnit: 'dias' });
    } else if (method === 'transfer') {
      var transfer = round2(Number($('#factura-pay-transfer-value').val() || 0));
      if (transfer <= 0) transfer = total;
      payments.push({ method: 'transfer', label: 'Transferencia', value: transfer, term: 0, timeUnit: 'dias' });
    } else if (method === 'credit') {
      var credit = round2(Number($('#factura-pay-credit-value').val() || 0));
      var creditDueDate = ($('#factura-pay-credit-due-date').val() || '').toString() || formatDateToInputValue(addDaysToDate(parseFacturaIssueDateToDate(), 30));
      var creditInterest = round2(Number($('#factura-pay-credit-interest').val() || 0));
      var creditPeriod = ($('#factura-pay-credit-period').val() || 'monthly').toString();
      if (credit <= 0) credit = total;
      payments.push({
        method: 'credit',
        label: 'Credito',
        value: credit,
        term: diffDaysBetweenDates(parseFacturaIssueDateToDate(), new Date(creditDueDate + 'T00:00:00')),
        timeUnit: 'dias',
        dueDate: creditDueDate,
        interestPercent: creditInterest,
        period: creditPeriod
      });
    } else if (method === 'mixed') {
      var mixedCash = round2(Number($('#factura-pay-mixed-cash').val() || 0));
      var mixedTransfer = round2(Number($('#factura-pay-mixed-transfer').val() || 0));
      var mixedCredit = round2(Number($('#factura-pay-mixed-credit').val() || 0));
      var mixedDueDate = ($('#factura-pay-mixed-due-date').val() || '').toString() || formatDateToInputValue(addDaysToDate(parseFacturaIssueDateToDate(), 30));
      var mixedInterest = round2(Number($('#factura-pay-mixed-interest').val() || 0));
      var mixedPeriod = ($('#factura-pay-mixed-period').val() || 'monthly').toString();
      if (mixedCash > 0) {
        payments.push({ method: 'cash', label: 'Efectivo', value: mixedCash, term: 0, timeUnit: 'dias' });
      }
      if (mixedTransfer > 0) {
        payments.push({ method: 'transfer', label: 'Transferencia', value: mixedTransfer, term: 0, timeUnit: 'dias' });
      }
      if (mixedCredit > 0) {
        payments.push({
          method: 'credit',
          label: 'Credito',
          value: mixedCredit,
          term: diffDaysBetweenDates(parseFacturaIssueDateToDate(), new Date(mixedDueDate + 'T00:00:00')),
          timeUnit: 'dias',
          dueDate: mixedDueDate,
          interestPercent: mixedInterest,
          period: mixedPeriod
        });
      }
      if (!payments.length) {
        setStatus('#factura-issue-status', 'Ingrese al menos un valor en el pago mixto.', true);
        return;
      }
    }

    if (!payments.length) {
      setStatus('#factura-issue-status', 'No existen formas de pago para guardar.', true);
      return;
    }

    var sum = round2(payments.reduce(function (acc, item) {
      return acc + Number(item.value || 0);
    }, 0));
    var diff = round2(total - sum);
    if (Math.abs(diff) >= 0.01) {
      payments[payments.length - 1].value = round2(Number(payments[payments.length - 1].value || 0) + diff);
    }

    state.payments = payments;
    renderFacturaPayments();
    closeFacturaPaymentModal();
    setStatus('#factura-issue-status', 'Formas de pago actualizadas.');
  }

  function renderFacturaAdditionalFields() {
    var $tbody = $('#factura-additional-body').empty();
    state.additionalFields.forEach(function (item, index) {
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(item.name || '') + '</td>' +
          '<td>' + escapeHtml(item.value || '') + '</td>' +
          '<td><button class="btn-secondary" type="button">Quitar</button></td>' +
        '</tr>'
      );
      $tr.find('button').on('click', function () {
        state.additionalFields.splice(index, 1);
        renderFacturaAdditionalFields();
      });
      $tbody.append($tr);
    });
    if (!$tbody.children().length) {
      $tbody.append('<tr><td colspan="3" class="factura-empty">No existen campos adicionales</td></tr>');
    }
  }

  function addProductToFacturaDetail(product) {
    var qty = parseFloat(window.prompt('Cantidad', '1') || '0');
    if (!(qty > 0)) return;
    state.details.push({
      productId: product.id,
      codigoPrincipal: (product.service && product.service.codigoPrincipal) || product.barcode || product.id || '',
      codigoAuxiliar: (product.service && product.service.codigoAuxiliar) || product.barcode || '',
      cantidad: qty,
      descripcion: product.name || '',
      precioUnitario: parseFloat(product.price || 0),
      iva: normalizeIva(product.iva),
      descuento: 0,
      valorICE: parseFloat((product.service && product.service.iceValue) || 0)
    });
    $('#factura-product-search').val('');
    $('#factura-product-search-modal-input').val('');
    renderFacturaDetails();
  }

  function closeFacturaProductSearchModal() {
    $('#factura-product-search-modal').removeClass('active');
  }

  function openFacturaProductSearchModal() {
    $('#factura-product-search-modal').addClass('active');
    $('#factura-product-search-modal-input').val(($('#factura-product-search').val() || '').toString());
    searchInvoiceProducts();
    window.setTimeout(function () {
      $('#factura-product-search-modal-input').trigger('focus').trigger('select');
    }, 0);
  }

  function renderProductSearchResults(rows) {
    var $tbody = $('#factura-product-search-modal-results').empty();
    rows.slice(0, 30).forEach(function (product) {
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(product.barcode || product.id || '') + '</td>' +
          '<td>' + escapeHtml(product.name || '') + '</td>' +
          '<td class="catalog-center">' + escapeHtml(product.iva || 'No') + '</td>' +
          '<td class="catalog-money">' + money(product.price || 0) + '</td>' +
        '</tr>'
      );
      $tr.on('click', function () {
        addProductToFacturaDetail(product);
        closeFacturaProductSearchModal();
      });
      $tbody.append($tr);
    });
    if (!$tbody.children().length) {
      $tbody.append('<tr><td colspan="4" class="factura-empty">No se encontraron productos.</td></tr>');
    }
  }

  function searchInvoiceProducts() {
    var source = $('#factura-product-search-modal').hasClass('active')
      ? $('#factura-product-search-modal-input').val()
      : $('#factura-product-search').val();
    var q = (source || '').toString().trim().toLowerCase();
    var wide = q.indexOf('*') === 0;
    var needle = wide ? q.slice(1).trim() : q;
    var rows = (state.products || []).filter(function (product) {
      var barcode = (product.barcode || '').toString().toLowerCase();
      var id = (product.id || '').toString().toLowerCase();
      var name = (product.name || '').toString().toLowerCase();
      if (!needle) {
        return true;
      }
      if (wide) {
        return barcode.indexOf(needle) >= 0 || id.indexOf(needle) >= 0 || name.indexOf(needle) >= 0;
      }
      return barcode.indexOf(needle) === 0 || id.indexOf(needle) === 0 || name.indexOf(needle) === 0;
    });
    renderProductSearchResults(rows);
  }

  function findFacturaProductByCode(query) {
    var q = (query || '').toString().trim().toLowerCase();
    if (!q) return null;
    return (state.products || []).find(function (product) {
      var barcode = (product.barcode || '').toString().trim().toLowerCase();
      var id = (product.id || '').toString().trim().toLowerCase();
      return barcode === q || id === q;
    }) || null;
  }

  function findFacturaProductByCodeRemote(query) {
    var code = (query || '').toString().trim();
    if (!code) {
      return $.Deferred().resolve(null).promise();
    }
    return $.getJSON('../api/products.php', { q: code }).then(function (res) {
      if (!res || !res.ok || !Array.isArray(res.data)) {
        return null;
      }
      var q = code.toLowerCase();
      var exact = res.data.find(function (product) {
        var barcode = (product.barcode || '').toString().trim().toLowerCase();
        var id = (product.id || '').toString().trim().toLowerCase();
        return barcode === q || id === q;
      }) || null;
      if (!exact) {
        return null;
      }
      var service = ((state.services || []).find(function (row) { return row.productId === exact.id; }) || null);
      return $.extend({}, exact, { service: service });
    }, function () {
      return null;
    });
  }

  function bindInvoicePage() {
    var ticketIdFromUrl = readUrlParam('ticketId');
    var originFromUrl = readUrlParam('origin');
    $('#factura-payment-actions').prop('hidden', false).show();
    $('#factura-payment-origin-note').prop('hidden', true).hide();

    apiGet('invoice_defaults').done(function (res) {
      if (!res.ok) return;
      state.emitter = res.data.emitter || {};
      state.points = res.data.points || [];
      state.customers = res.data.customers || [];
      state.products = (res.data.products || []).map(function (product) {
        return $.extend({}, product, {
          service: ((state.services || []).find(function (row) { return row.productId === product.id; }) || null)
        });
      });
      $('#factura-commercial-name').val(state.emitter.nombreComercial || '');

      var $estab = $('#factura-point-establishment').empty();
      var $point = $('#factura-point-id').empty();
      state.points.forEach(function (row) {
        $estab.append('<option value="' + escapeHtml(row.id) + '">' + escapeHtml(row.estab + ' - ' + row.nombre) + '</option>');
        $point.append('<option value="' + escapeHtml(row.id) + '">' + escapeHtml(row.ptoEmi + ' - ' + row.nombre) + '</option>');
      });

      if (ticketIdFromUrl) {
        applyTicketPrefill(ticketIdFromUrl);
      } else if (originFromUrl === 'ventas') {
        applyPendingPosSalePrefill();
      }
    });

    apiGet('product_services').done(function (res) {
      if (res.ok) {
        state.services = res.data.services || [];
      }
    });

    function customerIdentityCandidates(customer) {
      return [
        customer.taxId,
        customer.identification,
        customer.document,
        customer.documentNumber,
        customer.ruc,
        customer.cedula,
        customer.idNumber
      ].filter(Boolean).map(function (v) { return v.toString(); });
    }

    function customerDisplayName(customer) {
      return (customer && (customer.name || customer.razonSocial || ((customer.firstName || '') + ' ' + (customer.lastName || '')).trim()) || '').toString().trim();
    }

    function customerPrimaryIdentification(customer) {
      var candidates = customerIdentityCandidates(customer);
      return (candidates[0] || '').toString().trim();
    }

    function customerSearchText(value) {
      return (value || '').toString().trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function findCustomerByIdentification(rawIdentification) {
      var q = (rawIdentification || '').toString().trim();
      if (!q) return null;
      var qDigits = normalizeDigits(q);
      var customers = Array.isArray(state.customers) ? state.customers : [];

      var exact = customers.find(function (customer) {
        return customerIdentityCandidates(customer).some(function (idv) {
          var idDigits = normalizeDigits(idv);
          return (qDigits && idDigits && qDigits === idDigits) || idv.toLowerCase() === q.toLowerCase();
        });
      });
      if (exact) return exact;

      return null;
    }

    function findCustomerByName(rawName) {
      var q = customerSearchText(rawName);
      if (!q) return null;
      var customers = Array.isArray(state.customers) ? state.customers : [];
      return customers.find(function (customer) {
        return customerSearchText(customerDisplayName(customer)) === q;
      }) || null;
    }

    function customerMatchesIdentification(customer, query) {
      var q = normalizeDigits(query);
      if (!q) return false;
      return customerIdentityCandidates(customer).some(function (idv) {
        var digits = normalizeDigits(idv);
        return digits && (digits.indexOf(q) === 0 || digits.indexOf(q) >= 0);
      });
    }

    function customerMatchesName(customer, query) {
      var q = customerSearchText(query);
      if (!q) return false;
      return customerSearchText(customerDisplayName(customer)).indexOf(q) >= 0;
    }

    function buyerSuggestionRank(type, customer, query) {
      if (type === 'identification') {
        var qDigits = normalizeDigits(query);
        var best = 10;
        customerIdentityCandidates(customer).forEach(function (idv) {
          var digits = normalizeDigits(idv);
          if (!digits || !qDigits) return;
          if (digits === qDigits) best = Math.min(best, 0);
          else if (digits.indexOf(qDigits) === 0) best = Math.min(best, 1);
          else if (digits.indexOf(qDigits) >= 0) best = Math.min(best, 2);
        });
        return best;
      }

      var q = customerSearchText(query);
      var name = customerSearchText(customerDisplayName(customer));
      if (name === q) return 0;
      if (name.indexOf(q) === 0) return 1;
      if (name.indexOf(' ' + q) >= 0) return 2;
      if (name.indexOf(q) >= 0) return 3;
      return 10;
    }

    function buyerSuggestionRows(type, query) {
      var customers = Array.isArray(state.customers) ? state.customers : [];
      var matcher = type === 'identification' ? customerMatchesIdentification : customerMatchesName;
      return customers.filter(function (customer) {
        if (!customer || (customer.id || '').toString() === 'c-001') return false;
        return matcher(customer, query);
      }).sort(function (a, b) {
        var rankA = buyerSuggestionRank(type, a, query);
        var rankB = buyerSuggestionRank(type, b, query);
        if (rankA !== rankB) return rankA - rankB;
        return customerDisplayName(a).localeCompare(customerDisplayName(b), 'es');
      }).slice(0, 50);
    }

    function hideBuyerSuggestions(type) {
      var selector = type === 'identification'
        ? '#factura-buyer-identification-suggestions'
        : '#factura-buyer-name-suggestions';
      $(selector).prop('hidden', true).empty();
    }

    function hideAllBuyerSuggestions() {
      hideBuyerSuggestions('identification');
      hideBuyerSuggestions('name');
    }

    function renderBuyerSuggestions(type, query) {
      var $box = type === 'identification'
        ? $('#factura-buyer-identification-suggestions')
        : $('#factura-buyer-name-suggestions');
      var q = (query || '').toString().trim();
      if (type === 'identification') {
        q = normalizeDigits(q);
      }
      if (q.length < 2) {
        $box.prop('hidden', true).empty();
        return;
      }
      var rows = buyerSuggestionRows(type, query);
      if (!rows.length) {
        $box.prop('hidden', false).html('<div class="factura-buyer-suggestion factura-buyer-suggestion--empty">Sin coincidencias</div>');
        return;
      }
      $box.prop('hidden', false).empty();
      rows.forEach(function (customer) {
        var idText = customerPrimaryIdentification(customer);
        var name = customerDisplayName(customer);
        var $row = $(
          '<button type="button" class="factura-buyer-suggestion">' +
            '<strong>' + escapeHtml(name || 'Cliente') + '</strong>' +
            '<span>' + escapeHtml(idText || 'Sin identificacion') + (customer.phone ? ' | ' + escapeHtml(customer.phone) : '') + '</span>' +
          '</button>'
        );
        $row.on('mousedown', function (event) {
          event.preventDefault();
          fillBuyerFromCustomer(customer);
          hideAllBuyerSuggestions();
          setStatus('#factura-issue-status', 'Cliente cargado desde coincidencias.');
        });
        $box.append($row);
      });
    }

    function fillBuyerFromCustomer(customer) {
      var identification = customerPrimaryIdentification(customer);
      $('#factura-buyer-identification').val(identification || '');
      $('#factura-buyer-id-type').val(identification ? (inferBuyerIdentificationType(identification) || '') : '');
      $('#factura-buyer-name').val(customerDisplayName(customer));
      $('#factura-buyer-address').val(customer.address1 || customer.address || '');
      $('#factura-buyer-phone').val(customer.phone || '');
      $('#factura-buyer-email').val(customer.email || '');
      state.buyerCustomerId = (customer && customer.id !== undefined ? customer.id : '').toString();
      updateSaveBuyerIdButton();
    }

    function findCustomerById(rawCustomerId) {
      var customerId = (rawCustomerId || '').toString().trim();
      if (!customerId) return null;
      var customers = Array.isArray(state.customers) ? state.customers : [];
      return customers.find(function (customer) {
        return (customer && customer.id !== undefined ? customer.id : '').toString() === customerId;
      }) || null;
    }

    function currentPointAddress() {
      var pointId = ($('#factura-point-id').val() || $('#factura-point-establishment').val() || '').toString();
      var points = Array.isArray(state.points) ? state.points : [];
      var point = points.find(function (row) {
        return (row && row.id !== undefined ? row.id : '').toString() === pointId;
      }) || null;
      return (point && point.dirEstablecimiento ? point.dirEstablecimiento : '').toString();
    }

    function applyConsumidorFinalDefaults() {
      $('#factura-buyer-id-type').val('Consumidor final');
      $('#factura-buyer-identification').val('9999999999999');
      $('#factura-buyer-name').val('Consumidor final');
      $('#factura-buyer-address').val(currentPointAddress());
      $('#factura-buyer-phone').val('');
      $('#factura-buyer-email').val('');
      updateSaveBuyerIdButton();
    }

    function applyPendingPosSalePrefill() {
      var wrapper = readPendingPosSale();
      var salePayload = wrapper && wrapper.salePayload && typeof wrapper.salePayload === 'object'
        ? wrapper.salePayload
        : null;
      if (!salePayload) {
        setStatus('#factura-issue-status', 'No se encontro una venta pendiente para facturar desde caja.', true);
        return;
      }

      state.pendingPosSale = wrapper;
      state.originSaleId = (salePayload.ticketId || '').toString();
      state.buyerCustomerId = (salePayload.customerId || '').toString();

      var customerId = (salePayload.customerId || '').toString().trim();
      var customerName = (salePayload.customerName || '').toString().trim();
      var customer = findCustomerById(customerId);
      var identification = customer
        ? ((customer.taxId || customer.identification || customer.document || '').toString())
        : (/^c-\d+$/i.test(customerId) ? '' : customerId);

      if (!customerId || customerId === 'c-001' || !customerName || customerName.toLowerCase() === 'publico en general') {
        applyConsumidorFinalDefaults();
      } else {
        $('#factura-buyer-identification').val(identification);
        $('#factura-buyer-id-type').val(inferBuyerIdentificationType(identification) || '');
        $('#factura-buyer-name').val(customer ? (customer.name || customer.razonSocial || customerName) : customerName);
        $('#factura-buyer-address').val(customer ? (customer.address1 || customer.address || '') : '');
        $('#factura-buyer-phone').val(customer ? (customer.phone || '') : '');
        $('#factura-buyer-email').val(customer ? (customer.email || '') : '');
      }

      state.details = (salePayload.items || []).map(function (item) {
        var code = (item.barcode || item.id || '').toString();
        var pricing = saleItemInvoicePricing(item, salePayload);
        return {
          productId: (item.id || '').toString(),
          codigoPrincipal: code,
          codigoAuxiliar: code,
          cantidad: parseFloat(item.qty || 0) || 0,
          descripcion: (item.name || '').toString(),
          precioUnitario: pricing.unitPrice,
          iva: normalizeIva(item.iva),
          descuento: pricing.discount,
          valorICE: 0
        };
      }).filter(function (item) { return item.cantidad > 0; });

      state.payments = buildFacturaPaymentsFromSalePayload(salePayload, parseFloat(salePayload.total || 0) || 0);
      state.paymentMethodDraft = (state.payments[0] && state.payments[0].method ? state.payments[0].method : 'cash').toString();
      $('#factura-payment-actions').prop('hidden', true).hide();
      $('#factura-payment-origin-note').prop('hidden', false).show();

      renderFacturaDetails();
      renderFacturaPayments();
      updateSaveBuyerIdButton();
      setStatus('#factura-issue-status', 'Venta de caja cargada en la factura. Revise los datos y emita el comprobante.');
    }

    function syncBuyerIdentificationType() {
      var rawIdentification = ($('#factura-buyer-identification').val() || '').toString();
      var inferredType = inferBuyerIdentificationType(rawIdentification);
      if (inferredType) {
        $('#factura-buyer-id-type').val(inferredType);
      }
      if (inferredType === 'Consumidor final' || ($('#factura-buyer-id-type').val() || '').toString() === 'Consumidor final') {
        applyConsumidorFinalDefaults();
      }
      updateSaveBuyerIdButton();
    }

    function saveBuyerAsCustomer() {
      var customerId = (state.buyerCustomerId || '').toString().trim();
      var identification = currentBuyerIdentification();
      var identificationType = currentBuyerIdentificationType();
      if (!identification) {
        showNotice('Ingrese la identificacion antes de guardar el adquirente.', 'warning');
        return;
      }
      if (identificationType === 'Consumidor final') {
        showNotice('Consumidor final no se guarda como cliente.', 'warning');
        return;
      }

      var name = currentBuyerName();
      if (!name) {
        showNotice('Ingrese la razon social antes de guardar el adquirente.', 'warning');
        return;
      }

      var customer = findCustomerById(customerId) || findCustomerByIdentification(identification) || {};
      var isUpdate = customer && customer.id && customer.id !== 'c-001';
      var payload = $.extend({}, customer, {
        action: isUpdate ? 'update' : 'create',
        name: name,
        taxId: identification,
        identification: identification,
        address1: ($('#factura-buyer-address').val() || '').toString().trim(),
        phone: ($('#factura-buyer-phone').val() || '').toString().trim(),
        email: ($('#factura-buyer-email').val() || '').toString().trim()
      });
      if (isUpdate) {
        payload.id = customer.id;
      }

      $('#factura-save-buyer-id-btn').prop('disabled', true);
      showNotice(isUpdate ? 'Actualizando datos del adquirente...' : 'Guardando adquirente como cliente...', 'info');
      $.ajax({
        url: '../api/customers.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload)
      }).done(function (res) {
        if (!res.ok) {
          showNotice(res.error || 'No se pudo guardar el adquirente como cliente.', 'error');
          return;
        }
        var updated = res.data || payload;
        var updatedId = (updated && updated.id !== undefined ? updated.id : '').toString();
        var found = false;
        state.customers = (state.customers || []).map(function (row) {
          if ((row && row.id !== undefined ? row.id : '').toString() !== updatedId) {
            return row;
          }
          found = true;
          return $.extend({}, row, updated, {
            taxId: updated.taxId || updated.identification || identification,
            identification: updated.identification || updated.taxId || identification,
            address1: updated.address1 || payload.address1,
            phone: updated.phone || payload.phone,
            email: updated.email || payload.email
          });
        });
        if (!found) {
          state.customers.push($.extend({}, updated, {
            taxId: updated.taxId || updated.identification || identification,
            identification: updated.identification || updated.taxId || identification,
            address1: updated.address1 || payload.address1,
            phone: updated.phone || payload.phone,
            email: updated.email || payload.email
          }));
        }
        state.buyerCustomerId = updatedId;
        showNotice((isUpdate ? 'Cliente actualizado: ' : 'Cliente creado: ') + name + ' / ' + identification + '.', 'success');
        updateSaveBuyerIdButton();
      }).fail(function (xhr) {
        showNotice('Error al guardar adquirente: ' + responseErrorMessage(xhr, 'No se pudo guardar el adquirente como cliente.'), 'error');
      }).always(function () {
        $('#factura-save-buyer-id-btn').prop('disabled', false);
      });
    }

    function searchAndFillBuyer() {
      var q = ($('#factura-buyer-identification').val() || '').toString().trim();
      syncBuyerIdentificationType();
      if (($('#factura-buyer-id-type').val() || '').toString() === 'Consumidor final') {
        setStatus('#factura-issue-status', 'Datos de consumidor final cargados automaticamente.');
        return;
      }
      var match = findCustomerByIdentification(q);
      if (!match) {
        setStatus('#factura-issue-status', 'No se encontro cliente en el catalogo local. Puede seguir llenando los datos manualmente.', true);
        return;
      }
      fillBuyerFromCustomer(match);
      setStatus('#factura-issue-status', 'Cliente cargado desde el catalogo.');
    }

    function searchAndFillBuyerByName() {
      var q = ($('#factura-buyer-name').val() || '').toString().trim();
      var match = findCustomerByName(q);
      if (!match) {
        setStatus('#factura-issue-status', 'Seleccione una coincidencia de razon social para cargar los datos del adquirente.', true);
        return;
      }
      fillBuyerFromCustomer(match);
      hideAllBuyerSuggestions();
      setStatus('#factura-issue-status', 'Cliente cargado desde el catalogo.');
    }

    $('#factura-buyer-search-btn').on('click', searchAndFillBuyer);
    $('#factura-save-buyer-id-btn').on('click', saveBuyerAsCustomer);
    $('#factura-point-id, #factura-point-establishment').on('change', function () {
      if (($('#factura-buyer-id-type').val() || '').toString() === 'Consumidor final') {
        applyConsumidorFinalDefaults();
      }
    });
    $('#factura-buyer-id-type').on('change', function () {
      if (($(this).val() || '').toString() === 'Consumidor final') {
        applyConsumidorFinalDefaults();
      } else {
        updateSaveBuyerIdButton();
      }
    });
    $('#factura-buyer-identification').on('input', function () {
      syncBuyerIdentificationType();
      renderBuyerSuggestions('identification', $(this).val());
    });
    $('#factura-buyer-identification').on('change blur', function () {
      window.setTimeout(function () { hideBuyerSuggestions('identification'); }, 120);
      if (!($(this).val() || '').toString().trim()) return;
      searchAndFillBuyer();
    });
    $('#factura-buyer-identification').on('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      searchAndFillBuyer();
    });
    $('#factura-buyer-name').on('input', function () {
      updateSaveBuyerIdButton();
      renderBuyerSuggestions('name', $(this).val());
    });
    $('#factura-buyer-name').on('blur', function () {
      window.setTimeout(function () { hideBuyerSuggestions('name'); }, 120);
    });
    $('#factura-buyer-name').on('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      searchAndFillBuyerByName();
    });

    $('#factura-product-search-btn').on('click', openFacturaProductSearchModal);
    $('#factura-product-search').on('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var query = ($(this).val() || '').toString().trim();
        if (!query) {
          return;
        }
        var localProduct = findFacturaProductByCode(query);
        if (localProduct) {
          addProductToFacturaDetail(localProduct);
          setStatus('#factura-issue-status', 'Producto agregado al detalle.');
          return;
        }
        findFacturaProductByCodeRemote(query).done(function (exactProduct) {
          if (!exactProduct) {
            setStatus('#factura-issue-status', 'Producto no encontrado por codigo. Use Buscar (F10) si no conoce el codigo.', true);
            return;
          }
          addProductToFacturaDetail(exactProduct);
          setStatus('#factura-issue-status', 'Producto agregado al detalle.');
        });
        return;
      }
      if (e.key === 'F10') {
        e.preventDefault();
        openFacturaProductSearchModal();
      }
    });
    $('#factura-product-search-modal-input').on('input', searchInvoiceProducts);
    $('#factura-product-search-close-btn').on('click', closeFacturaProductSearchModal);
    $('#factura-product-search-modal').on('click', function (e) {
      if (e.target === this) {
        closeFacturaProductSearchModal();
      }
    });

    $('#factura-open-payment-btn').on('click', openFacturaPaymentModal);
    $('[data-factura-pay-method]').on('click', function () {
      setFacturaPaymentDraftMethod(($(this).data('factura-pay-method') || 'cash').toString());
    });
    $('#factura-pay-mixed-cash, #factura-pay-mixed-transfer, #factura-pay-mixed-credit').on('input', updateFacturaMixedRemaining);
    $('#factura-pay-credit-period').on('change', syncFacturaCreditDueDateFromPeriod);
    $('#factura-pay-mixed-period').on('change', syncFacturaMixedDueDateFromPeriod);
    $('#factura-payment-cancel-btn').on('click', closeFacturaPaymentModal);
    $('#factura-payment-apply-btn').on('click', applyFacturaPaymentModal);
    $('#factura-payment-modal').on('click', function (e) {
      if (e.target === this) {
        closeFacturaPaymentModal();
      }
    });

    $('#factura-add-field-btn').on('click', function () {
      var name = window.prompt('Nombre del campo adicional', '');
      if (!name) return;
      var value = window.prompt('Descripcion', '');
      if (value === null) return;
      state.additionalFields.push({ name: name, value: value });
      renderFacturaAdditionalFields();
    });

    $('#factura-save-draft-btn').on('click', function () {
      apiPost($.extend({ action: 'save_invoice_draft' }, buildFacturaPayload())).done(function (res) {
        if (!res.ok) {
          setStatus('#factura-issue-status', res.error || 'No se pudo guardar el borrador.', true);
          return;
        }
        setStatus('#factura-issue-status', 'Borrador guardado. Documento ' + (res.data.id || '') + ' / clave ' + (res.data.accessKey || ''));
      }).fail(function (xhr) {
        setStatus('#factura-issue-status', responseErrorMessage(xhr, 'No se pudo guardar el borrador.'), true);
      });
    });

    $('#factura-issue-btn').on('click', function () {
      if (state.invoiceSubmitting) return;
      setInvoiceSubmitting(true);
      apiPost($.extend({ action: 'issue_invoice' }, buildFacturaPayload())).done(function (res) {
        if (!res.ok) {
          setStatus('#factura-issue-status', res.error || 'No se pudo emitir la factura.', true);
          facturaAlert({
            icon: 'error',
            title: 'No se pudo emitir la factura',
            text: res.error || 'Revise los datos e intente nuevamente.'
          });
          setInvoiceSubmitting(false);
          return;
        }

        registerPendingPosSale(res.data || {}).done(function (saleRes) {
          if (saleRes && saleRes.ok === false) {
            setStatus('#factura-issue-status', 'Factura emitida, pero no se pudo registrar la venta en caja: ' + (saleRes.error || 'error desconocido'), true);
            setInvoiceSubmitting(false);
            return;
          }

          var files = (res.data || {}).files || {};
          var saleTicketId = (saleRes && saleRes.data ? saleRes.data.ticketId : '').toString();
          if (saleTicketId) {
            clearPendingPosSale();
            markVentaCartAsCompleted(saleTicketId);
            state.pendingPosSale = null;
          }
          setStatus(
            '#factura-issue-status',
            'Factura procesada. Estado: ' + ((res.data || {}).status || '') +
            (saleTicketId ? (' | Venta registrada: #' + saleTicketId) : '') +
            ' | XML: ' + (files.authorizedXml || files.generatedXml || '')
          );
          if (printFacturaTicket(res.data || {})) {
            showNotice('Ticket de factura enviado a impresion.', 'success');
          }
          facturaAlert({
            icon: 'success',
            title: 'Factura emitida correctamente',
            html: 'Estado: <strong>' + escapeHtml(((res.data || {}).status || 'Procesada').toString()) + '</strong>' +
              (saleTicketId ? '<br>Venta registrada: <strong>#' + escapeHtml(saleTicketId) + '</strong>' : '') +
              '<br>El formulario quedo listo para una nueva factura.',
            confirmButtonText: 'Aceptar'
          });
          resetFacturaIssueForm();
          setInvoiceSubmitting(false);
        }).fail(function (xhr) {
          setStatus('#factura-issue-status', 'Factura emitida, pero no se pudo registrar la venta en caja: ' + responseErrorMessage(xhr, 'Error desconocido.'), true);
          facturaAlert({
            icon: 'warning',
            title: 'Factura emitida con advertencia',
            text: 'No se pudo registrar la venta en caja: ' + responseErrorMessage(xhr, 'Error desconocido.')
          });
          setInvoiceSubmitting(false);
        });
      }).fail(function (xhr) {
        setStatus('#factura-issue-status', responseErrorMessage(xhr, 'No se pudo emitir la factura.'), true);
        facturaAlert({
          icon: 'error',
          title: 'No se pudo emitir la factura',
          text: responseErrorMessage(xhr, 'Revise los datos e intente nuevamente.')
        });
        setInvoiceSubmitting(false);
      });
    });

    renderFacturaDetails();
    renderFacturaPayments();
    renderFacturaAdditionalFields();

    $(document).on('keydown.facturaPayment', function (e) {
      if (e.key === 'F10' && currentSection() === 'emision' && currentView() === 'factura') {
        e.preventDefault();
        if ($('#factura-product-search-modal').hasClass('active')) {
          return;
        }
        if (!$('#factura-payment-modal').hasClass('active')) {
          openFacturaProductSearchModal();
          return;
        }
      }
      if (e.key === 'F12' && currentSection() === 'emision' && currentView() === 'factura') {
        e.preventDefault();
        if (state.pendingPosSale) {
          return;
        }
        if ($('#factura-payment-modal').hasClass('active')) {
          applyFacturaPaymentModal();
          return;
        }
        openFacturaPaymentModal();
      }
      if (e.key === 'Escape' && $('#factura-payment-modal').hasClass('active')) {
        e.preventDefault();
        closeFacturaPaymentModal();
      }
      if (e.key === 'Escape' && $('#factura-product-search-modal').hasClass('active')) {
        e.preventDefault();
        closeFacturaProductSearchModal();
      }
    });
  }

  function bindDocumentsPage() {
    var documentRowsCache = [];

    function setDocumentStatus(message, isError) {
      setStatus('#facturacion-document-status', message, isError);
    }

    function mapStatusByView(view) {
      if (view === 'no-autorizados') return 'error';
      if (view === 'pendientes-anular') return 'annul_pending';
      if (view === 'anulados') return 'annulled';
      return '';
    }

    function parseDocumentDate(row) {
      var raw = (row && (row.issueDate || row.createdAt) || '').toString().trim();
      if (!raw) return null;
      var match = raw.match(/^(\d{2})-(\d{2})-(\d{4})/);
      if (match) {
        return new Date(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
      }
      match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (match) {
        return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
      }
      var parsed = new Date(raw);
      return Number.isNaN(parsed.getTime()) ? null : new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate());
    }

    function parseDateInputValue(selector) {
      var value = ($(selector).val() || '').toString().trim();
      if (!value) return null;
      var match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!match) return null;
      return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    }

    function documentIsInvoice(row) {
      var type = (row && row.docType || '').toString();
      var name = (row && row.docName || '').toString().toLowerCase();
      return type === '01' || name.indexOf('factura') >= 0;
    }

    function documentTotalValue(row, key) {
      var totals = row && typeof row.totals === 'object' ? row.totals : {};
      return Number(totals[key] || 0);
    }

    function documentMatchesReportFilters(row) {
      var from = parseDateInputValue('#facturacion-report-date-from');
      var to = parseDateInputValue('#facturacion-report-date-to');
      var status = ($('#facturacion-report-status').val() || '').toString().trim().toLowerCase();
      if (status && (row.status || '').toString().trim().toLowerCase() !== status) return false;
      if (!from && !to) return true;
      var date = parseDocumentDate(row);
      if (!date) return false;
      if (from && date < from) return false;
      if (to && date > to) return false;
      return true;
    }

    function invoiceRowsForReport(rows) {
      return (Array.isArray(rows) ? rows : []).filter(function (row) {
        if (!documentIsInvoice(row)) return false;
        return documentMatchesReportFilters(row);
      });
    }

    function renderInvoiceReport(rows) {
      if (!$('#facturacion-report-panel').length) return;
      var reportRows = invoiceRowsForReport(rows);
      var summary = reportRows.reduce(function (acc, row) {
        var subtotal0 = documentTotalValue(row, 'subtotal0');
        var subtotal15 = documentTotalValue(row, 'subtotal15');
        var iva15 = documentTotalValue(row, 'iva15');
        acc.count += 1;
        acc.total += documentTotalValue(row, 'importeTotal');
        acc.subtotal0 += subtotal0;
        acc.total0 += subtotal0 + documentTotalValue(row, 'iva0');
        acc.subtotal15 += subtotal15;
        acc.total15 += subtotal15 + iva15;
        return acc;
      }, {
        count: 0,
        total: 0,
        total0: 0,
        subtotal0: 0,
        total15: 0,
        subtotal15: 0
      });

      $('[data-invoice-report="count"]').text(String(summary.count));
      $('[data-invoice-report="total"]').text(money(summary.total));
      $('[data-invoice-report="total0"]').text(money(summary.total0));
      $('[data-invoice-report="subtotal0"]').text(money(summary.subtotal0));
      $('[data-invoice-report="total15"]').text(money(summary.total15));
      $('[data-invoice-report="subtotal15"]').text(money(summary.subtotal15));
    }

    function renderRows(rows) {
      var q = ($('#facturacion-document-search').val() || '').toString().trim().toLowerCase();
      var $tbody = $('#facturacion-document-body').empty();
      renderInvoiceReport(rows);
      function envLabel(value) {
        return (value || '').toString() === '2' ? 'Produccion' : 'Pruebas';
      }
      rows.filter(function (row) {
        return documentMatchesReportFilters(row);
      }).filter(function (row) {
        var haystack = ((row.accessKey || '') + ' ' + (row.secuencial || '') + ' ' + ((row.buyer || {}).razonSocial || '') + ' ' + (row.adminTag || '')).toLowerCase();
        return !q || haystack.indexOf(q) >= 0;
      }).forEach(function (row) {
        var files = row.files || {};
        var sri = row.sri || {};
        var adminTag = (row.adminTag || '').toString().trim();
        var fileList = [files.generatedXml, files.signedXml, files.authorizedXml, files.pdf].filter(Boolean).map(function (item) {
          return escapeHtml((item || '').toString().split(/[\\/]/).pop());
        }).join('<br>');
        var $tr = $(
          '<tr>' +
            '<td>' + escapeHtml((row.issueDate || '').toString()) + '</td>' +
            '<td>' + escapeHtml(row.docName || '') + '</td>' +
            '<td>' + escapeHtml(row.secuencial || '') + '</td>' +
            '<td>' + escapeHtml(envLabel(row.environment || '')) + '</td>' +
            '<td>' + escapeHtml(adminTag || '--') + '</td>' +
            '<td>' + escapeHtml(((row.buyer || {}).razonSocial || '')) + '</td>' +
            '<td>' + escapeHtml(row.status || '') + '</td>' +
            '<td>' + escapeHtml(sriStatusLabel(sri.receptionStatus, 'N/D')) + '</td>' +
            '<td>' + escapeHtml(sriStatusLabel(sri.authorizationStatus, 'N/D')) + '</td>' +
            '<td>' + escapeHtml(row.accessKey || '') + '</td>' +
            '<td>' + fileList + '</td>' +
            '<td>' +
              (files.pdf ? '<button class="btn-secondary facturacion-print-pdf" type="button">Imprimir PDF</button> ' : '') +
              '<button class="btn-secondary facturacion-reprint-ticket" type="button">Reimprimir ticket</button> ' +
              '<button class="btn-secondary facturacion-reprocess" type="button">Reprocesar</button> ' +
              '<button class="btn-secondary facturacion-refresh-auth" type="button">Consultar autorizacion ahora</button>' +
            '</td>' +
          '</tr>'
        );
        $tr.find('.facturacion-print-pdf').on('click', function () {
          window.open('../api/facturacion.php?action=document_file&id=' + encodeURIComponent(row.id || '') + '&kind=pdf', '_blank', 'noopener');
        });
        $tr.find('.facturacion-reprint-ticket').on('click', function () {
          var id = (row.id || '').toString();
          if (!id) {
            setDocumentStatus('Documento no disponible para reimprimir ticket.', true);
            return;
          }
          setDocumentStatus('Preparando ticket de factura...');
          apiGet('document', { id: id }).done(function (res) {
            if (!res.ok) {
              setDocumentStatus(res.error || 'No se pudo cargar el comprobante para imprimir ticket.', true);
              return;
            }
            if (printFacturaTicket(res.data || row)) {
              setDocumentStatus('Ticket de factura enviado a impresion.');
              showNotice('Ticket de factura enviado a impresion.', 'success');
            }
          }).fail(function (xhr) {
            setDocumentStatus(responseErrorMessage(xhr, 'No se pudo cargar el comprobante para imprimir ticket.'), true);
          });
        });
        $tr.find('.facturacion-reprocess').on('click', function () {
          if (!requireSecondClickConfirmation('reprocess-' + row.id, '#facturacion-document-status', 'Confirme reprocesar este comprobante.')) return;
          apiPost({ action: 'reprocess_document', id: row.id }).done(function (res) {
            if (!res.ok) {
              setDocumentStatus(res.error || 'No se pudo reprocesar.', true);
              return;
            }
            setDocumentStatus('Comprobante reprocesado correctamente.');
            loadDocuments();
          }).fail(function (xhr) {
            setDocumentStatus(responseErrorMessage(xhr, 'No se pudo reprocesar.'), true);
          });
        });
        $tr.find('.facturacion-refresh-auth').on('click', function () {
          apiPost({ action: 'refresh_authorization', id: row.id }).done(function (res) {
            if (!res.ok) {
              setDocumentStatus(res.error || 'No se pudo consultar autorizacion.', true);
              return;
            }
            var auth = (((res.data || {}).sri || {}).authorizationStatus || '').toString();
            if (auth === 'AUT') {
              setDocumentStatus('Comprobante autorizado por SRI.');
            } else if (auth === 'NAT') {
              setDocumentStatus('Comprobante no autorizado por SRI.', true);
            } else {
              setDocumentStatus('SRI aun no responde autorizacion final (PPR).');
            }
            loadDocuments();
          }).fail(function (xhr) {
            setDocumentStatus(responseErrorMessage(xhr, 'No se pudo consultar autorizacion.'), true);
          });
        });
        $tbody.append($tr);
      });
      if (!$tbody.children().length) {
        $tbody.append('<tr><td colspan="12" class="factura-empty">No existen comprobantes</td></tr>');
      }
    }

    function loadDocuments() {
      apiGet('documents', { status: mapStatusByView(currentView()) }).done(function (res) {
        if (!res.ok) {
          setDocumentStatus(res.error || 'No se pudieron cargar los comprobantes.', true);
          return;
        }
        documentRowsCache = Array.isArray(res.data) ? res.data : [];
        renderRows(documentRowsCache);
      }).fail(function (xhr) {
        setDocumentStatus(responseErrorMessage(xhr, 'No se pudieron cargar los comprobantes.'), true);
      });
    }

    $('#facturacion-document-search').on('input', loadDocuments);
    $('#facturacion-document-refresh').on('click', loadDocuments);
    $('#facturacion-report-date-from,#facturacion-report-date-to,#facturacion-report-status').on('change', function () {
      renderRows(documentRowsCache);
    });
    $('#facturacion-report-clear').on('click', function () {
      $('#facturacion-report-date-from,#facturacion-report-date-to').val('');
      $('#facturacion-report-status').val('');
      renderRows(documentRowsCache);
    });
    loadDocuments();
  }

  $(function () {
    if (!isFacturacionPage()) return;

    bindFacturacionNav();
    loadOverview();

    if (currentSection() === 'configuracion' && currentView() === 'emisor') {
      bindEmitterPage();
      return;
    }
    if (currentSection() === 'configuracion' && currentView() === 'firma') {
      bindSignaturePage();
      return;
    }
    if (currentSection() === 'configuracion' && currentView() === 'servicios') {
      bindServicesPage();
      return;
    }
    if (currentSection() === 'configuracion' && currentView() === 'carga') {
      bindCargaPage();
      return;
    }
    if (currentSection() === 'configuracion' && currentView() === 'puntos') {
      bindPointsPage();
      return;
    }
    if (currentSection() === 'emision' && currentView() === 'factura') {
      bindInvoicePage();
      return;
    }
    if (currentSection() === 'comprobantes') {
      bindDocumentsPage();
    }
  });
})();
