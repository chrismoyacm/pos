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

  function normalizeDigits(value) {
    return (value || '').toString().replace(/\D+/g, '');
  }

  function calcLineTotals(item) {
    var qty = Number(item && item.cantidad ? item.cantidad : 0);
    var unitInput = Number(item && item.precioUnitario ? item.precioUnitario : 0);
    var discount = Number(item && item.descuento ? item.descuento : 0);
    var gross = round2((qty * unitInput) - discount);
    var rate = taxRateFromIva(item && item.iva ? item.iva : '0%');

    if (gross <= 0 || rate <= 0) {
      return {
        gross: Math.max(0, gross),
        base: Math.max(0, gross),
        tax: 0
      };
    }

    var base = round2(gross / (1 + rate));
    var tax = round2(gross - base);
    return {
      gross: gross,
      base: base,
      tax: tax
    };
  }

  function readUrlParam(key) {
    return new URLSearchParams(window.location.search).get(key) || '';
  }

  var state = {
    emitter: null,
    signature: null,
    points: [],
    customers: [],
    products: [],
    services: [],
    details: [],
    payments: [],
    additionalFields: [],
    paymentMethodDraft: 'cash',
    originSaleId: ''
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

    state.details.forEach(function (item) {
      var line = calcLineTotals(item);
      var base = line.base;
      var tax = line.tax;
      var iva = normalizeIva(item.iva);
      totals.subtotalSinImpuestos += base;
      totals.totalDescuento += item.descuento || 0;
      totals.valorICE += item.valorICE || 0;
      if (iva === '15%') {
        totals.subtotal15 += base;
        totals.iva15 += tax;
      } else if (iva === '12%') {
        totals.subtotal12 += base;
        totals.iva12 += tax;
      } else {
        totals.subtotal0 += base;
      }
    });

    totals.importeTotal = totals.subtotalSinImpuestos + totals.iva15 + totals.iva12 + totals.iva5 + totals.ivaTarifaEspecial;

    Object.keys(totals).forEach(function (key) {
      $('[data-total="' + key + '"]').text(money(totals[key]));
    });
    return totals;
  }

  function renderFacturaDetails() {
    var $tbody = $('#factura-detail-body').empty();
    state.details.forEach(function (item, index) {
      var line = calcLineTotals(item);
      var base = line.base;
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(item.codigoPrincipal || '') + '</td>' +
          '<td>' + escapeHtml(item.codigoAuxiliar || '') + '</td>' +
          '<td class="catalog-center">' + escapeHtml(String(item.cantidad || 0)) + '</td>' +
          '<td>' + escapeHtml(item.descripcion || '') + '</td>' +
          '<td class="catalog-money">' + money(item.precioUnitario || 0) + '</td>' +
          '<td class="catalog-center">' + escapeHtml(item.iva || '0%') + '</td>' +
          '<td class="catalog-money">' + money(item.descuento || 0) + '</td>' +
          '<td class="catalog-money">' + money(base) + '</td>' +
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
    state.payments.forEach(function (item, index) {
      var $tr = $(
        '<tr>' +
          '<td>' + escapeHtml(item.label || '') + '</td>' +
          '<td class="catalog-money">' + money(item.value || 0) + '</td>' +
          '<td class="catalog-center">' + escapeHtml(String(item.term || 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtml(item.timeUnit || 'dias') + '</td>' +
          '<td><button class="btn-secondary" type="button">Quitar</button></td>' +
        '</tr>'
      );
      $tr.find('button').on('click', function () {
        state.payments.splice(index, 1);
        renderFacturaPayments();
      });
      $tbody.append($tr);
    });
    if (!$tbody.children().length) {
      $tbody.append('<tr><td colspan="5" class="factura-empty">No existen formas de pago</td></tr>');
    }
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

  function renderProductSearchResults(rows) {
    var $wrap = $('#factura-product-results').empty();
    rows.slice(0, 12).forEach(function (product) {
      var $item = $(
        '<div class="facturacion-search-item">' +
          '<div><strong>' + escapeHtml(product.name || '') + '</strong><div class="facturacion-search-meta">' + escapeHtml(product.barcode || product.id || '') + ' | IVA ' + escapeHtml(product.iva || 'No') + '</div></div>' +
          '<div><strong>$' + money(product.price || 0) + '</strong></div>' +
        '</div>'
      );
      $item.on('click', function () {
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
        $wrap.empty();
        renderFacturaDetails();
      });
      $wrap.append($item);
    });
    if (!$wrap.children().length) {
      $wrap.append('<div class="factura-empty">No se encontraron productos.</div>');
    }
  }

  function searchInvoiceProducts() {
    var q = ($('#factura-product-search').val() || '').toString().trim().toLowerCase();
    if (!q) {
      $('#factura-product-results').empty();
      return;
    }
    var rows = (state.products || []).filter(function (product) {
      var text = ((product.barcode || '') + ' ' + (product.name || '')).toLowerCase();
      return text.indexOf(q) >= 0;
    });
    renderProductSearchResults(rows);
  }

  function bindInvoicePage() {
    var ticketIdFromUrl = readUrlParam('ticketId');

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
        customer.idNumber,
        customer.id
      ].filter(Boolean).map(function (v) { return v.toString(); });
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

      return customers.find(function (customer) {
        var text = (
          (customer.id || '') + ' ' +
          (customer.name || '') + ' ' +
          (customer.email || '') + ' ' +
          (customer.taxId || '') + ' ' +
          (customer.identification || '')
        ).toLowerCase();
        return text.indexOf(q.toLowerCase()) >= 0;
      }) || null;
    }

    function fillBuyerFromCustomer(customer) {
      $('#factura-buyer-name').val(customer.name || customer.razonSocial || '');
      $('#factura-buyer-address').val(customer.address1 || customer.address || '');
      $('#factura-buyer-phone').val(customer.phone || '');
      $('#factura-buyer-email').val(customer.email || '');
    }

    function searchAndFillBuyer() {
      var q = ($('#factura-buyer-identification').val() || '').toString().trim();
      var match = findCustomerByIdentification(q);
      if (!match) {
        setStatus('#factura-issue-status', 'No se encontro cliente en el catalogo local. Puede seguir llenando los datos manualmente.', true);
        return;
      }
      fillBuyerFromCustomer(match);
      setStatus('#factura-issue-status', 'Cliente cargado desde el catalogo.');
    }

    $('#factura-buyer-search-btn').on('click', searchAndFillBuyer);
    $('#factura-buyer-identification').on('change blur', function () {
      if (!($(this).val() || '').toString().trim()) return;
      searchAndFillBuyer();
    });

    var searchTimer = null;
    $('#factura-product-search').on('input', function () {
      if (searchTimer) window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(searchInvoiceProducts, 160);
    });
    $('#factura-product-search-btn').on('click', searchInvoiceProducts);

    $('[data-payment-method]').on('click', function () {
      state.paymentMethodDraft = ($(this).data('payment-method') || 'cash').toString();
      setStatus('#factura-issue-status', 'Forma de pago seleccionada: ' + state.paymentMethodDraft.replace('_', ' '));
    });

    $('#factura-add-payment-btn').on('click', function () {
      var amount = parseFloat(window.prompt('Valor', money(recalcFacturaTotals().importeTotal)) || '0');
      if (!(amount > 0)) return;
      var term = parseInt(window.prompt('Plazo', '0') || '0', 10) || 0;
      var timeUnit = window.prompt('Tiempo', 'dias') || 'dias';
      state.payments.push({
        method: state.paymentMethodDraft,
        label: state.paymentMethodDraft.replace('_', ' '),
        value: amount,
        term: term,
        timeUnit: timeUnit
      });
      renderFacturaPayments();
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
      apiPost($.extend({ action: 'issue_invoice' }, buildFacturaPayload())).done(function (res) {
        if (!res.ok) {
          setStatus('#factura-issue-status', res.error || 'No se pudo emitir la factura.', true);
          return;
        }
        var files = res.data.files || {};
        setStatus('#factura-issue-status', 'Factura procesada. Estado: ' + (res.data.status || '') + ' | XML: ' + (files.authorizedXml || files.generatedXml || ''));
      }).fail(function (xhr) {
        setStatus('#factura-issue-status', responseErrorMessage(xhr, 'No se pudo emitir la factura.'), true);
      });
    });

    renderFacturaDetails();
    renderFacturaPayments();
    renderFacturaAdditionalFields();
  }

  function bindDocumentsPage() {
    function setDocumentStatus(message, isError) {
      setStatus('#facturacion-document-status', message, isError);
    }

    function mapStatusByView(view) {
      if (view === 'no-autorizados') return 'error';
      if (view === 'pendientes-anular') return 'annul_pending';
      if (view === 'anulados') return 'annulled';
      return '';
    }

    function renderRows(rows) {
      var q = ($('#facturacion-document-search').val() || '').toString().trim().toLowerCase();
      var $tbody = $('#facturacion-document-body').empty();
      rows.filter(function (row) {
        var haystack = ((row.accessKey || '') + ' ' + (row.secuencial || '') + ' ' + ((row.buyer || {}).razonSocial || '')).toLowerCase();
        return !q || haystack.indexOf(q) >= 0;
      }).forEach(function (row) {
        var files = row.files || {};
        var sri = row.sri || {};
        var fileList = [files.generatedXml, files.signedXml, files.authorizedXml, files.pdf].filter(Boolean).map(function (item) {
          return escapeHtml((item || '').toString().split(/[\\/]/).pop());
        }).join('<br>');
        var $tr = $(
          '<tr>' +
            '<td>' + escapeHtml((row.issueDate || '').toString()) + '</td>' +
            '<td>' + escapeHtml(row.docName || '') + '</td>' +
            '<td>' + escapeHtml(row.secuencial || '') + '</td>' +
            '<td>' + escapeHtml(((row.buyer || {}).razonSocial || '')) + '</td>' +
            '<td>' + escapeHtml(row.status || '') + '</td>' +
            '<td>' + escapeHtml(sriStatusLabel(sri.receptionStatus, 'N/D')) + '</td>' +
            '<td>' + escapeHtml(sriStatusLabel(sri.authorizationStatus, 'N/D')) + '</td>' +
            '<td>' + escapeHtml(row.accessKey || '') + '</td>' +
            '<td>' + fileList + '</td>' +
            '<td>' +
              '<button class="btn-secondary facturacion-reprocess" type="button">Reprocesar</button> ' +
              '<button class="btn-secondary facturacion-refresh-auth" type="button">Consultar autorizacion ahora</button>' +
            '</td>' +
          '</tr>'
        );
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
        $tbody.append('<tr><td colspan="10" class="factura-empty">No existen comprobantes</td></tr>');
      }
    }

    function loadDocuments() {
      apiGet('documents', { status: mapStatusByView(currentView()) }).done(function (res) {
        if (!res.ok) {
          setDocumentStatus(res.error || 'No se pudieron cargar los comprobantes.', true);
          return;
        }
        renderRows(Array.isArray(res.data) ? res.data : []);
      }).fail(function (xhr) {
        setDocumentStatus(responseErrorMessage(xhr, 'No se pudieron cargar los comprobantes.'), true);
      });
    }

    $('#facturacion-document-search').on('input', loadDocuments);
    $('#facturacion-document-refresh').on('click', loadDocuments);
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
