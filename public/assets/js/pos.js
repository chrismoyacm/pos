/* global $, window, document */
(function () {
  'use strict';

  const state = {
    items: [],
    selectedIndex: -1,
    subtotal: 0,
    total: 0,
    paidWith: 0,
    change: 0,
    paymentMethod: 'cash',
    mixedPayments: { cash: 0, card: 0 },
    paymentNote: '',
    customer: { id: 'c-001', name: 'Publico en general' }
  };

  const salesHistoryState = {
    mode: 'view',
    sales: [],
    selectedTicketId: '',
    selectedItemIndex: -1
  };

  const CART_STORAGE_KEY = 'pos.ventas.cart.v1';

  function isVentasPage() {
    return $('#venta-body').length > 0;
  }

  function persistCartState() {
    if (!isVentasPage()) return;
    try {
      const snapshot = {
        items: state.items,
        selectedIndex: state.selectedIndex,
        paidWith: state.paidWith,
        change: state.change,
        paymentMethod: state.paymentMethod,
        mixedPayments: state.mixedPayments,
        paymentNote: state.paymentNote,
        customer: state.customer
      };
      window.sessionStorage.setItem(CART_STORAGE_KEY, JSON.stringify(snapshot));
    } catch (err) {
      // Ignore storage errors to avoid interrupting the sales flow.
    }
  }

  function restoreCartState() {
    if (!isVentasPage()) return;
    try {
      const raw = window.sessionStorage.getItem(CART_STORAGE_KEY);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (!parsed || !Array.isArray(parsed.items)) return;

      state.items = parsed.items;
      state.selectedIndex = Number.isInteger(parsed.selectedIndex) ? parsed.selectedIndex : -1;
      state.paidWith = Number(parsed.paidWith || 0);
      state.change = Number(parsed.change || 0);
      state.paymentMethod = (parsed.paymentMethod || 'cash').toString();
      const mixed = parsed.mixedPayments || {};
      state.mixedPayments = {
        cash: Number(mixed.cash || 0),
        card: Number(mixed.card || 0)
      };
      state.paymentNote = (parsed.paymentNote || '').toString();

      const customer = parsed.customer || {};
      state.customer = {
        id: (customer.id || 'c-001').toString(),
        name: (customer.name || 'Publico en general').toString()
      };
    } catch (err) {
      // If snapshot is invalid, start a fresh sale.
      state.items = [];
      state.selectedIndex = -1;
      state.paidWith = 0;
      state.change = 0;
      state.paymentMethod = 'cash';
      state.mixedPayments = { cash: 0, card: 0 };
      state.paymentNote = '';
      state.customer = { id: 'c-001', name: 'Publico en general' };
    }
  }

  function formatMoney(n) {
    return '$' + Number(n || 0).toFixed(2);
  }

  function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, function (s) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[s];
    });
  }

  function paymentMethodLabel(method) {
    if (method === 'card') return 'Tarjeta';
    if (method === 'mixed') return 'Mixto';
    if (method === 'credit') return 'Credito';
    if (method === 'voucher') return 'Vales';
    if (method === 'transfer') return 'Transferencia';
    if (method === 'check') return 'Cheque';
    return 'Efectivo';
  }

  function toDateInputValue(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function formatTimeFromIso(iso) {
    const date = new Date((iso || '').toString());
    if (Number.isNaN(date.getTime())) return '--:--';
    return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
  }

  function normalizeIvaLabel(iva) {
    var raw = (iva || '').toString().trim().toLowerCase();
    if (raw === '12' || raw === '12%' || raw === 'iva 12' || raw === 'iva 12%' || raw === 'si' || raw === 'sí') return '12%';
    if (raw === '15' || raw === '15%' || raw === 'iva 15' || raw === 'iva 15%') return '15%';
    return 'No';
  }

  function nextIvaLabel(current) {
    return normalizeIvaLabel(current) === 'No' ? '12%' : 'No';
  }

  function isCreditPayment() {
    return state.paymentMethod === 'credit';
  }

  function isMixedPayment() {
    return state.paymentMethod === 'mixed';
  }

  function recalc() {
    state.subtotal = state.items.reduce(function (sum, item) {
      return sum + (item.price * item.qty);
    }, 0);
    state.total = state.subtotal;

    $('#subtotal').text(formatMoney(state.subtotal));
    $('#total').text(formatMoney(state.total));
    $('#countItems').text(state.items.length.toString());

    const inlinePayment = isCreditPayment()
      ? paymentMethodLabel(state.paymentMethod)
      : (state.paidWith ? (paymentMethodLabel(state.paymentMethod) + ' ' + formatMoney(state.paidWith)) : '');

    $('#pagoConInline').val(inlinePayment);
    $('#cambioInline').val(state.change ? formatMoney(state.change) : '');

    persistCartState();
  }

  function paymentMethodOptions() {
    return ['cash', 'card', 'mixed', 'credit', 'voucher', 'transfer', 'check'];
  }

  function cyclePaymentMethod(direction) {
    const options = paymentMethodOptions();
    const current = ($('#modal-pago [name=metodoPago]').val() || state.paymentMethod || 'cash').toString();
    const idx = options.indexOf(current);
    const start = idx >= 0 ? idx : 0;
    const nextIdx = (start + direction + options.length) % options.length;
    $('#modal-pago [name=metodoPago]').val(options[nextIdx]);
    updatePaymentMethod();
  }

  function renderGrid() {
    const $tbody = $('#venta-body').empty();
    state.items.forEach(function (item, index) {
      const ivaLabel = normalizeIvaLabel(item.iva);
      const ivaButtonClass = ivaLabel === 'No' ? 'venta-iva-btn venta-iva-btn--off' : 'venta-iva-btn venta-iva-btn--on';
      const $tr = $(
        '<tr data-idx="' + index + '" class="' + (index === state.selectedIndex ? 'row-selected' : '') + '">' +
          '<td>' + (item.barcode || '') + '</td>' +
          '<td>' + escapeHtml(item.name || '') + '</td>' +
          '<td class="catalog-center"><button type="button" class="' + ivaButtonClass + '" data-iva-idx="' + index + '">' + escapeHtml(ivaLabel) + '</button></td>' +
          '<td>' + formatMoney(item.price) + '</td>' +
          '<td class="qty">' + item.qty + '</td>' +
          '<td>' + formatMoney(item.price * item.qty) + '</td>' +
          '<td>' + (item.stock ?? '') + '</td>' +
        '</tr>'
      );
      $tr.on('click', function () {
        state.selectedIndex = index;
        renderGrid();
      });
      $tr.on('dblclick', function () {
        promptChangeQty(index);
      });

      $tr.find('[data-iva-idx]').on('click', function (e) {
        e.stopPropagation();
        toggleItemIva(index);
      });

      $tbody.append($tr);
    });
    recalc();
  }

  function updateProductIva(itemId, ivaValue) {
    return $.ajax({
      url: '../api/products.php',
      method: 'PATCH',
      contentType: 'application/json',
      data: JSON.stringify({
        action: 'update_product',
        id: itemId,
        iva: ivaValue
      })
    });
  }

  function toggleItemIva(index) {
    const item = state.items[index];
    if (!item) return;

    const previous = normalizeIvaLabel(item.iva);
    const next = nextIvaLabel(previous);
    item.iva = next;
    renderGrid();

    // Artículos temporales no se guardan en catálogo.
    if (String(item.id || '').startsWith('tmp-')) {
      return;
    }

    updateProductIva(item.id, next).done(function (res) {
      if (res && res.ok) {
        return;
      }
      item.iva = previous;
      renderGrid();
      window.alert((res && res.error) ? res.error : 'No se pudo guardar el cambio de IVA.');
    }).fail(function () {
      item.iva = previous;
      renderGrid();
      window.alert('No se pudo guardar el cambio de IVA.');
    });
  }

  function applyWholesaleForItem(item) {
    if (!item || !item.wholesale) {
      item.price = Number(item.basePrice);
      return;
    }

    const minQty = Number(item.wholesale.minQty || 0);
    const wholesalePrice = Number(item.wholesale.price || item.basePrice);
    item.price = item.qty >= minQty ? wholesalePrice : Number(item.basePrice);
  }

  function addItemByCode(code) {
    if (!code) return;

    $.getJSON('../api/products.php', { q: code }).done(function (res) {
      if (!res.ok || !res.data || res.data.length === 0) {
        window.alert('Producto no encontrado');
        return;
      }

      const product = res.data[0];
      const index = state.items.findIndex(function (item) {
        return item.id === product.id;
      });

      if (index >= 0) {
        state.items[index].qty += 1;
        applyWholesaleForItem(state.items[index]);
      } else {
        const newItem = {
          id: product.id,
          barcode: product.barcode,
          name: product.name,
          iva: normalizeIvaLabel(product.iva),
          basePrice: Number(product.price),
          price: Number(product.price),
          wholesale: product.wholesale || null,
          qty: 1,
          stock: product.stock
        };
        applyWholesaleForItem(newItem);
        state.items.push(newItem);
      }

      state.selectedIndex = state.items.length - 1;
      renderGrid();
      $('#codigo').val('').focus();
    });
  }

  function deleteSelected() {
    if (state.selectedIndex < 0) return;
    state.items.splice(state.selectedIndex, 1);
    state.selectedIndex = Math.min(state.selectedIndex, state.items.length - 1);
    renderGrid();
  }

  function openCommonItemModal() {
    $('#modal-common [name=descripcion]').val('');
    $('#modal-common [name=precio]').val('');
    $('#modal-common [name=cantidad]').val('1');
    $('#modal-common').addClass('active');
    $('#modal-common [name=descripcion]').focus();
  }

  function confirmCommonItem() {
    const description = $('#modal-common [name=descripcion]').val().toString().trim();
    const price = parseFloat($('#modal-common [name=precio]').val().toString());
    const qty = parseInt($('#modal-common [name=cantidad]').val().toString(), 10) || 1;

    if (!description || !(price >= 0)) {
      window.alert('Complete descripcion y precio');
      return;
    }

    state.items.push({
      id: 'tmp-' + Date.now(),
      barcode: '',
      name: description,
      iva: 'No',
      basePrice: price,
      price: price,
      wholesale: null,
      qty: qty,
      stock: ''
    });

    closeModal('#modal-common');
    renderGrid();
  }

  function updatePaymentMethod() {
    state.paymentMethod = ($('#modal-pago [name=metodoPago]').val() || 'cash').toString();
    const disableAmount = isCreditPayment();
    const showMixed = isMixedPayment();

    $('#pay-mixed-grid').prop('hidden', !showMixed);
    $('#modal-pago [name=pagoCon]').prop('disabled', disableAmount);
    $('#modal-pago [name=pagoCon]').closest('label.field').prop('hidden', showMixed);

    if (disableAmount) {
      $('#modal-pago [name=pagoCon]').val('0.00');
      $('#modal-pago [name=pagoConEfectivo]').val('0.00');
      $('#modal-pago [name=pagoConTarjeta]').val('0.00');
      state.mixedPayments = { cash: 0, card: 0 };
    }

    if (showMixed) {
      $('#modal-pago [name=pagoConEfectivo]').val(state.mixedPayments.cash ? state.mixedPayments.cash.toFixed(2) : '');
      $('#modal-pago [name=pagoConTarjeta]').val(state.mixedPayments.card ? state.mixedPayments.card.toFixed(2) : '');
    }

    updateCambio();
  }

  function openPayModal() {
    $('#modal-pago [name=total]').val(state.total.toFixed(2));
    $('#modal-pago [name=metodoPago]').val(state.paymentMethod);
    $('#modal-pago [name=pagoCon]').val(isCreditPayment() ? '0.00' : '');
    $('#modal-pago [name=pagoConEfectivo]').val(state.mixedPayments.cash ? state.mixedPayments.cash.toFixed(2) : '');
    $('#modal-pago [name=pagoConTarjeta]').val(state.mixedPayments.card ? state.mixedPayments.card.toFixed(2) : '');
    $('#pay-note-preview').text('Nota: ' + (state.paymentNote ? state.paymentNote : '-'));
    $('#modal-pago [data-cambio]').text(formatMoney(0));
    $('#modal-pago').addClass('active');
    updatePaymentMethod();
    if (isCreditPayment()) {
      $('#modal-pago [name=metodoPago]').focus();
    } else if (isMixedPayment()) {
      $('#modal-pago [name=pagoConEfectivo]').focus();
    } else {
      $('#modal-pago [name=pagoCon]').focus();
    }
  }

  function capturePaymentNote() {
    const current = (state.paymentNote || '').toString();
    const next = window.prompt('Nota de pago:', current);
    if (next === null) return;
    state.paymentNote = next.toString().trim();
    $('#pay-note-preview').text('Nota: ' + (state.paymentNote ? state.paymentNote : '-'));
    persistCartState();
  }

  function updateCambio() {
    if (isCreditPayment()) {
      state.paidWith = 0;
      state.change = 0;
      $('#modal-pago [data-cambio]').text(formatMoney(0));
      recalc();
      return;
    }

    if (isMixedPayment()) {
      const cashPart = parseFloat($('#modal-pago [name=pagoConEfectivo]').val().toString()) || 0;
      const cardPart = parseFloat($('#modal-pago [name=pagoConTarjeta]').val().toString()) || 0;
      const paidWithMixed = Math.max(0, cashPart) + Math.max(0, cardPart);
      const changeMixed = Math.max(0, paidWithMixed - state.total);
      state.mixedPayments = {
        cash: Math.max(0, cashPart),
        card: Math.max(0, cardPart)
      };
      state.paidWith = paidWithMixed;
      state.change = changeMixed;
      $('#modal-pago [data-cambio]').text(formatMoney(changeMixed));
      recalc();
      return;
    }

    const paidWith = parseFloat($('#modal-pago [name=pagoCon]').val().toString()) || 0;
    const change = Math.max(0, paidWith - state.total);
    $('#modal-pago [data-cambio]').text(formatMoney(change));
    state.paidWith = paidWith;
    state.change = change;
    recalc();
  }

  function thermalPaperWidthMm() {
    const raw = (window.localStorage.getItem('pos.print.paperWidthMm') || '').toString().trim();
    if (raw === '80') return 80;
    return 58;
  }

  function printTicket(sale) {
    const safeSale = sale || {};
    const items = Array.isArray(safeSale.items) ? safeSale.items : [];
    const paperMm = thermalPaperWidthMm();

    const rows = items.map(function (item) {
      const qty = Number(item.qty || 0);
      const price = Number(item.price || 0);
      const amount = qty * price;
      return (
        '<tr>' +
          '<td class="c-qty">' + escapeHtml(String(qty)) + '</td>' +
          '<td class="c-desc">' + escapeHtml(String(item.name || '')) + '<div class="c-sub">' + formatMoney(price) + ' c/u</div></td>' +
          '<td class="c-amt">' + formatMoney(amount) + '</td>' +
        '</tr>'
      );
    }).join('');

    const paymentMethod = paymentMethodLabel(String(safeSale.paymentMethod || 'cash'));
    const mixed = safeSale.mixedPayments || { cash: 0, card: 0 };
    const mixedInfo = String(safeSale.paymentMethod || '') === 'mixed'
      ? '<div>Efectivo: ' + formatMoney(Number(mixed.cash || 0)) + ' | Tarjeta: ' + formatMoney(Number(mixed.card || 0)) + '</div>'
      : '';

    const html = [
      '<!doctype html><html><head><meta charset="utf-8"><title>Ticket</title>',
      '<style>',
      '@page{size:' + paperMm + 'mm auto;margin:2mm}',
      'html,body{margin:0;padding:0;background:#fff}',
      'body{font-family:Consolas,"Courier New",monospace;color:#000}',
      '.ticket{width:' + (paperMm - 4) + 'mm;padding:1mm 1mm 3mm}',
      '.center{text-align:center}',
      '.title{font-size:12px;font-weight:700;margin:0 0 2px}',
      '.head{font-size:10px;line-height:1.35;margin:0 0 3px}',
      '.sep{border-top:1px dashed #000;margin:2px 0 3px}',
      'table{width:100%;border-collapse:collapse;font-size:10px}',
      'th,td{padding:2px 1px;vertical-align:top}',
      'thead th{border-bottom:1px dashed #000}',
      '.c-qty{width:12%;text-align:center}',
      '.c-desc{width:58%;word-break:break-word}',
      '.c-sub{font-size:9px;opacity:.85}',
      '.c-amt{width:30%;text-align:right;white-space:nowrap}',
      '.tot{font-size:10px;line-height:1.45;margin-top:3px}',
      '.tot .line{display:flex;justify-content:space-between;gap:8px}',
      '.tot .line.total{font-weight:700;font-size:11px}',
      '.note{margin-top:4px;padding-top:3px;border-top:1px dashed #000;font-size:10px;word-break:break-word}',
      '.footer{margin-top:4px;text-align:center;font-size:9px}',
      '</style></head><body>',
      '<div class="ticket">',
      '<div class="center title">POS - TICKET #' + escapeHtml(String(safeSale.ticketId || '')) + '</div>',
      '<div class="head">',
      '<div>Fecha: ' + escapeHtml(String(safeSale.createdAt || '').replace('T', ' ').substring(0, 19)) + '</div>',
      '<div>Cliente: ' + escapeHtml(String(safeSale.customerName || 'Publico en general')) + '</div>',
      '<div>Metodo: ' + escapeHtml(paymentMethod) + '</div>',
      mixedInfo,
      '</div>',
      '<div class="sep"></div>',
      '<table><thead><tr><th class="c-qty">Cant</th><th class="c-desc">Descripcion</th><th class="c-amt">Importe</th></tr></thead><tbody>',
      rows,
      '</tbody></table>',
      '<div class="sep"></div>',
      '<div class="tot">',
      '<div class="line"><span>Subtotal:</span><span>' + formatMoney(Number(safeSale.subtotal || 0)) + '</span></div>',
      '<div class="line total"><span>Total:</span><span>' + formatMoney(Number(safeSale.total || 0)) + '</span></div>',
      '<div class="line"><span>Pago:</span><span>' + formatMoney(Number(safeSale.paidWith || 0)) + '</span></div>',
      '<div class="line"><span>Cambio:</span><span>' + formatMoney(Number(safeSale.change || 0)) + '</span></div>',
      '</div>',
      safeSale.paymentNote ? '<div class="note">Nota: ' + escapeHtml(String(safeSale.paymentNote)) + '</div>' : '',
      '<div class="footer">Gracias por su compra</div>',
      '</div></body></html>'
    ].join('');

    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '-10000px';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow?.document;
    if (!doc) {
      document.body.removeChild(iframe);
      window.alert('No se pudo abrir la vista de impresión.');
      return;
    }

    doc.open();
    doc.write(html);
    doc.close();

    window.setTimeout(function () {
      try {
        iframe.contentWindow?.focus();
        iframe.contentWindow?.print();
      } finally {
        window.setTimeout(function () {
          if (iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);
          }
        }, 800);
      }
    }, 150);
  }

  function confirmSale(options) {
    const opts = options || {};
    const shouldPrint = Boolean(opts.printTicket);

    if (state.items.length === 0) {
      window.alert('No hay productos.');
      return;
    }

    if (isCreditPayment() && (!state.customer || !state.customer.id || state.customer.id === 'c-001')) {
      window.alert('Asigne un cliente antes de registrar una venta a credito');
      return;
    }

    if (!isCreditPayment() && !(state.paidWith >= state.total)) {
      window.alert('Pago insuficiente');
      return;
    }

    if (isMixedPayment() && state.mixedPayments.cash <= 0 && state.mixedPayments.card <= 0) {
      window.alert('Ingrese montos para pago mixto.');
      return;
    }

    const payload = {
      items: state.items,
      subtotal: state.subtotal,
      total: state.total,
      paidWith: state.paidWith,
      change: state.change,
      paymentMethod: state.paymentMethod,
      mixedPayments: isMixedPayment() ? state.mixedPayments : null,
      paymentNote: state.paymentNote,
      amountPending: isCreditPayment() ? state.total : 0,
      customerId: state.customer?.id || null,
      customerName: state.customer?.name || ''
    };

    $.ajax({
      url: '../api/sales.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(payload)
    }).done(function (res) {
      if (!res.ok) {
        window.alert(res.error || 'Error en venta');
        return;
      }

      const ticketId = String(res?.data?.ticketId || '');
      const soldItemsSnapshot = state.items.map(function (item) {
        return {
          id: item.id,
          barcode: item.barcode,
          name: item.name,
          iva: item.iva,
          price: Number(item.price || 0),
          qty: Number(item.qty || 0)
        };
      });
      const saleSnapshot = {
        ticketId: ticketId,
        createdAt: new Date().toISOString(),
        items: soldItemsSnapshot,
        subtotal: state.subtotal,
        total: state.total,
        paidWith: state.paidWith,
        change: state.change,
        paymentMethod: state.paymentMethod,
        mixedPayments: state.paymentMethod === 'mixed' ? { cash: state.mixedPayments.cash, card: state.mixedPayments.card } : null,
        customerName: state.customer?.name || 'Publico en general',
        paymentNote: state.paymentNote
      };

      closeModal('#modal-pago');
      state.items = [];
      state.selectedIndex = -1;
      state.paidWith = 0;
      state.change = 0;
      state.paymentMethod = 'cash';
      state.mixedPayments = { cash: 0, card: 0 };
      state.paymentNote = '';
      renderGrid();

      if (shouldPrint) {
        printTicket(saleSnapshot);
        window.alert('Venta realizada. Ticket #' + ticketId + '\nTicket enviado a impresión.');
      } else {
        window.alert('Venta realizada. Ticket #' + ticketId);
      }
    });
  }

  function closeModal(selector) {
    $(selector).removeClass('active');
  }

  function openBuscarModal() {
    $('#modal-buscar [name=q]').val('');
    $('#modal-buscar [data-results]').empty();
    $('#modal-buscar').addClass('active');
    $('#modal-buscar [name=q]').focus();
    loadBuscarResults('');
  }

  function loadBuscarResults(q) {
    $.getJSON('../api/products.php', { q: q }).done(function (res) {
      const $tbody = $('#modal-buscar [data-results]').empty();
      const products = (res.ok && Array.isArray(res.data)) ? res.data : [];
      products.slice(0, 50).forEach(function (product) {
        const $tr = $(
          '<tr>' +
            '<td>' + (product.barcode || product.id) + '</td>' +
            '<td>' + escapeHtml(product.name || '') + '</td>' +
            '<td>' + formatMoney(product.price) + '</td>' +
            '<td>' + (product.stock ?? '') + '</td>' +
          '</tr>'
        );
        $tr.on('click', function () {
          closeModal('#modal-buscar');
          addItemByCode(product.id);
        });
        $tbody.append($tr);
      });
    });
  }

  function openCustomerModal() {
    $('#modal-cliente [name=q]').val('');
    $('#modal-cliente [data-results]').empty();
    $('#modal-cliente').addClass('active');
    $('#modal-cliente [name=q]').focus();
    loadCustomerResults('');
  }

  function loadCustomerResults(q) {
    $.getJSON('../api/customers.php', { q: q }).done(function (res) {
      const $tbody = $('#modal-cliente [data-results]').empty();
      const customers = (res.ok && Array.isArray(res.data)) ? res.data : [];
      customers.slice(0, 50).forEach(function (customer) {
        const $tr = $(
          '<tr>' +
            '<td>' + escapeHtml(customer.id || '') + '</td>' +
            '<td>' + escapeHtml(customer.name || '') + '</td>' +
          '</tr>'
        );
        $tr.on('click', function () {
          state.customer = { id: customer.id, name: customer.name };
          $('#clienteNombre').text(customer.name || 'Cliente');
          persistCartState();
          closeModal('#modal-cliente');
        });
        $tbody.append($tr);
      });
    });
  }

  function openStockModal(type) {
    $('#modal-stock [name=tipo]').val(type);
    $('#modal-stock [name=codigo]').val('');
    $('#modal-stock [name=cantidad]').val('1');
    $('#modal-stock header').text(type === 'entrada' ? 'Entradas (F7)' : 'Salidas (F8)');
    $('#modal-stock').addClass('active');
    $('#modal-stock [name=codigo]').focus();
  }

  function confirmStockMovement() {
    const type = $('#modal-stock [name=tipo]').val().toString();
    const code = $('#modal-stock [name=codigo]').val().toString().trim();
    const qty = parseInt($('#modal-stock [name=cantidad]').val().toString(), 10) || 0;
    if (!code || qty <= 0) {
      window.alert('Complete codigo y cantidad');
      return;
    }

    $.getJSON('../api/products.php', { q: code }).done(function (res) {
      if (!res.ok || !Array.isArray(res.data) || res.data.length === 0) {
        window.alert('Producto no encontrado');
        return;
      }

      const product = res.data[0];
      const delta = type === 'entrada' ? qty : -qty;
      $.ajax({
        url: '../api/inventario.php',
        method: 'PATCH',
        contentType: 'application/json',
        data: JSON.stringify({ action: 'adjust_stock', productId: product.id, delta: delta, movementType: type === 'entrada' ? 'entry' : 'exit' })
      }).done(function (result) {
        if (!result.ok) {
          window.alert(result.error || 'Error de stock');
          return;
        }
        closeModal('#modal-stock');
        window.alert('Stock actualizado: ' + product.name);
      });
    });
  }

  function applyWholesaleIfNeeded() {
    state.items.forEach(applyWholesaleForItem);
    renderGrid();
  }

  function promptChangeQty(index) {
    const item = state.items[index];
    if (!item) return;
    const nextQtyStr = window.prompt('Cantidad:', String(item.qty));
    if (nextQtyStr === null) return;
    const nextQty = parseInt(nextQtyStr, 10);
    if (!Number.isFinite(nextQty) || nextQty <= 0) {
      window.alert('Cantidad invalida');
      return;
    }
    item.qty = nextQty;
    applyWholesaleForItem(item);
    renderGrid();
  }

  function openReprintLastTicket() {
    $.getJSON('../api/sales.php', { last: 1 }).done(function (res) {
      if (!res.ok || !res.data) {
        window.alert('No hay ventas');
        return;
      }
      const sale = res.data;
      window.alert('Ultimo ticket #' + sale.ticketId + '\nTotal: ' + formatMoney(sale.total) + '\nItems: ' + (sale.items?.length || 0));
    });
  }

  function findSaleByTicketId(ticketId) {
    return salesHistoryState.sales.find(function (sale) {
      return String(sale.ticketId || '') === String(ticketId || '');
    }) || null;
  }

  function returnedQtyForItem(sale, itemId) {
    const returns = Array.isArray(sale?.returns) ? sale.returns : [];
    return returns
      .filter(function (entry) { return String(entry?.itemId || '') === String(itemId || ''); })
      .reduce(function (sum, entry) { return sum + Number(entry?.qty || 0); }, 0);
  }

  function renderSalesList() {
    const q = ($('#sales-day-q').val() || '').toString().trim().toLowerCase();
    const $tbody = $('#sales-day-body').empty();
    const rows = salesHistoryState.sales.filter(function (sale) {
      if (!q) return true;
      const folio = String(sale.ticketId || '').toLowerCase();
      const customer = String(sale.customerName || '').toLowerCase();
      return folio.includes(q) || customer.includes(q);
    });

    rows.forEach(function (sale) {
      const selected = String(sale.ticketId || '') === String(salesHistoryState.selectedTicketId || '');
      const $tr = $(
        '<tr class="' + (selected ? 'row-selected' : '') + '">' +
          '<td>#' + escapeHtml(String(sale.ticketId || '')) + '</td>' +
          '<td>' + escapeHtml(formatTimeFromIso(sale.createdAt)) + '</td>' +
          '<td>' + escapeHtml(String(sale.customerName || 'Publico en general')) + '</td>' +
          '<td class="catalog-money">' + formatMoney(Number(sale.total || 0)) + '</td>' +
        '</tr>'
      );
      $tr.on('click', function () {
        salesHistoryState.selectedTicketId = String(sale.ticketId || '');
        salesHistoryState.selectedItemIndex = -1;
        renderSalesList();
        renderSaleDetail();
      });
      $tbody.append($tr);
    });

    if (rows.length === 0) {
      $tbody.append('<tr><td colspan="4" class="muted">No hay ventas para el criterio indicado.</td></tr>');
    }
  }

  function renderSaleDetail() {
    const sale = findSaleByTicketId(salesHistoryState.selectedTicketId);
    const $tbody = $('#sales-day-items').empty();
    if (!sale) {
      $('#sales-day-meta').text('Seleccione un ticket.');
      $('#sales-day-return-btn').prop('disabled', true);
      return;
    }

    const cashier = String(sale.cashier || 'Cajero');
    const customer = String(sale.customerName || 'Publico en general');
    $('#sales-day-meta').text('Folio #' + String(sale.ticketId || '') + ' | Cajero: ' + cashier + ' | Cliente: ' + customer);

    const items = Array.isArray(sale.items) ? sale.items : [];
    items.forEach(function (item, index) {
      const itemId = String(item.id || '');
      const soldQty = Number(item.qty || 0);
      const returnedQty = returnedQtyForItem(sale, itemId);
      const selected = index === salesHistoryState.selectedItemIndex;
      const $tr = $(
        '<tr class="' + (selected ? 'row-selected' : '') + '">' +
          '<td class="catalog-center">' + escapeHtml(String(soldQty)) + '</td>' +
          '<td>' + escapeHtml(String(item.name || '')) + '</td>' +
          '<td class="catalog-center">' + escapeHtml(String(returnedQty)) + '</td>' +
          '<td class="catalog-money">' + formatMoney(Number(item.price || 0) * soldQty) + '</td>' +
        '</tr>'
      );
      $tr.on('click', function () {
        salesHistoryState.selectedItemIndex = index;
        renderSaleDetail();
      });
      $tbody.append($tr);
    });

    if (items.length === 0) {
      $tbody.append('<tr><td colspan="4" class="muted">Este ticket no tiene artículos.</td></tr>');
    }

    const canReturn = salesHistoryState.mode === 'return' && salesHistoryState.selectedItemIndex >= 0;
    $('#sales-day-return-btn').prop('disabled', !canReturn);
  }

  function loadSalesByDay() {
    const date = ($('#sales-day-date').val() || toDateInputValue(new Date())).toString();
    return $.getJSON('../api/sales.php', { action: 'day', date: date }).done(function (res) {
      salesHistoryState.sales = (res.ok && Array.isArray(res.data)) ? res.data : [];
      if (salesHistoryState.sales.length > 0 && !findSaleByTicketId(salesHistoryState.selectedTicketId)) {
        salesHistoryState.selectedTicketId = String(salesHistoryState.sales[0].ticketId || '');
      }
      renderSalesList();
      renderSaleDetail();
    });
  }

  function openSalesHistoryModal(mode) {
    salesHistoryState.mode = mode === 'return' ? 'return' : 'view';
    salesHistoryState.selectedItemIndex = -1;

    const today = toDateInputValue(new Date());
    if (!$('#sales-day-date').val()) {
      $('#sales-day-date').val(today);
    }

    $('#sales-day-help').text(
      salesHistoryState.mode === 'return'
        ? 'Seleccione un ticket y un artículo para registrar devolución.'
        : 'Puede revisar ventas del día.'
    );
    $('#sales-day-return-btn').prop('disabled', salesHistoryState.mode !== 'return');

    $('#modal-ventas-dia').addClass('active');
    loadSalesByDay();
  }

  function returnSelectedItem() {
    const sale = findSaleByTicketId(salesHistoryState.selectedTicketId);
    if (!sale) return;
    if (salesHistoryState.selectedItemIndex < 0) {
      window.alert('Seleccione un artículo para devolver.');
      return;
    }

    const item = (sale.items || [])[salesHistoryState.selectedItemIndex];
    if (!item) return;

    const soldQty = Number(item.qty || 0);
    const alreadyReturned = returnedQtyForItem(sale, item.id);
    const available = Math.max(0, soldQty - alreadyReturned);
    if (available <= 0) {
      window.alert('Este artículo ya fue devuelto por completo.');
      return;
    }

    const qtyStr = window.prompt('Cantidad a devolver (máximo ' + available + '):', '1');
    if (qtyStr === null) return;
    const qty = parseInt(qtyStr, 10);
    if (!Number.isInteger(qty) || qty <= 0 || qty > available) {
      window.alert('Cantidad inválida.');
      return;
    }

    const reason = (window.prompt('Motivo de devolución:', 'Devolución en caja') || '').toString().trim();
    $.ajax({
      url: '../api/sales.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        action: 'return_item',
        ticketId: sale.ticketId,
        itemId: item.id,
        qty: qty,
        reason: reason
      })
    }).done(function (res) {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo registrar la devolución.');
        return;
      }
      window.alert('Devolución registrada correctamente.');
      loadSalesByDay();
    });
  }

  function bindUI() {
    $('#codigo-form').on('submit', function (e) {
      e.preventDefault();
      addItemByCode($('#codigo').val().toString().trim());
    });
    $('#btn-del').on('click', deleteSelected);
    $('#btn-common').on('click', openCommonItemModal);
    $('#btn-buscar').on('click', openBuscarModal);
    $('#btn-mayoreo').on('click', applyWholesaleIfNeeded);
    $('#btn-entradas').on('click', function () { openStockModal('entrada'); });
    $('#btn-salidas').on('click', function () { openStockModal('salida'); });
    $('#btn-verificador').on('click', openBuscarModal);
    $('#btn-cobrar').on('click', openPayModal);
    $('#btn-eliminar').on('click', deleteSelected);
    $('#btn-cambiar').on('click', function () {
      if (state.selectedIndex < 0) return;
      promptChangeQty(state.selectedIndex);
    });
    $('#btn-cliente').on('click', openCustomerModal);
    $('#btn-reimprimir').on('click', openReprintLastTicket);
    $('#btn-ventas-dia').on('click', function () { openSalesHistoryModal('view'); });
    $('#btn-devoluciones').on('click', function () { openSalesHistoryModal('return'); });
    $('#modal-common .btn-primary').on('click', confirmCommonItem);
    $('#modal-common .btn-secondary').on('click', function () { closeModal('#modal-common'); });
    $('#btn-pay-cancel').on('click', function () { closeModal('#modal-pago'); });
    $('#btn-pay-note').on('click', capturePaymentNote);
    $('#modal-pago [name=metodoPago]').on('change', updatePaymentMethod);
    $('#modal-pago [name=pagoCon]').on('input', updateCambio);
    $('#modal-pago [name=pagoConEfectivo], #modal-pago [name=pagoConTarjeta]').on('input', updateCambio);
    $('#btn-pay-confirm').on('click', function () { confirmSale({ printTicket: false }); });
    $('#btn-pay-confirm-print').on('click', function () { confirmSale({ printTicket: true }); });
    $('#modal-buscar .btn-secondary').on('click', function () { closeModal('#modal-buscar'); });
    $('#modal-buscar [name=q]').on('input', function () { loadBuscarResults($(this).val().toString()); });
    $('#modal-cliente .btn-secondary').on('click', function () { closeModal('#modal-cliente'); });
    $('#modal-cliente [name=q]').on('input', function () { loadCustomerResults($(this).val().toString()); });
    $('#modal-stock .btn-secondary').on('click', function () { closeModal('#modal-stock'); });
    $('#modal-stock .btn-primary').on('click', confirmStockMovement);
    $('#sales-day-close').on('click', function () { closeModal('#modal-ventas-dia'); });
    $('#sales-day-refresh').on('click', loadSalesByDay);
    $('#sales-day-date').on('change', loadSalesByDay);
    $('#sales-day-q').on('input', renderSalesList);
    $('#sales-day-return-btn').on('click', returnSelectedItem);

    $(window).on('keydown', function (e) {
      const key = e.key.toLowerCase();
      const isPayModalActive = $('#modal-pago').hasClass('active');
      const target = e.target;
      const isTextControl = target && (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT' ||
        target.isContentEditable
      );

      if (e.key === 'Escape') {
        $('.modal.active').removeClass('active');
        return;
      }

      if (isPayModalActive) {
        if (e.key === 'F1' || e.key === 'f1') { e.preventDefault(); confirmSale({ printTicket: true }); return; }
        if (e.key === 'F2' || e.key === 'f2') { e.preventDefault(); confirmSale({ printTicket: false }); return; }
        if (e.key === 'F4' || e.key === 'f4') { e.preventDefault(); capturePaymentNote(); return; }
        if (e.key === 'ArrowLeft') { e.preventDefault(); cyclePaymentMethod(-1); return; }
        if (e.key === 'ArrowRight') { e.preventDefault(); cyclePaymentMethod(1); return; }
        if (e.key === 'Enter' && !isTextControl) { e.preventDefault(); confirmSale({ printTicket: false }); return; }

        // Evita choques con atajos de módulos mientras se está cobrando.
        if (/^f\d+$/i.test(e.key)) { e.preventDefault(); return; }
      }

      if (e.key === 'F1' || e.key === 'f1') { e.preventDefault(); window.location.href = 'index.php?mod=ventas'; return; }
      if (e.key === 'F2' || e.key === 'f2') { e.preventDefault(); window.location.href = 'index.php?mod=creditos'; return; }
      if (e.key === 'F3' || e.key === 'f3') { e.preventDefault(); window.location.href = 'index.php?mod=productos'; return; }
      if (e.key === 'F4' || e.key === 'f4') { e.preventDefault(); window.location.href = 'index.php?mod=inventario'; return; }

      if (e.ctrlKey && key === 'p') { e.preventDefault(); openCommonItemModal(); return; }
      if (e.key === 'Enter' && !isTextControl) { e.preventDefault(); addItemByCode($('#codigo').val().toString().trim()); return; }
      if (e.key === 'Delete') { e.preventDefault(); deleteSelected(); return; }
      if (e.key === 'F12' || e.key === 'f12') { e.preventDefault(); openPayModal(); return; }
      if (e.key === 'F10' || e.key === 'f10') { e.preventDefault(); openBuscarModal(); return; }
      if (e.key === 'F11' || e.key === 'f11') { e.preventDefault(); applyWholesaleIfNeeded(); return; }
      if (e.key === 'F7' || e.key === 'f7') { e.preventDefault(); openStockModal('entrada'); return; }
      if (e.key === 'F8' || e.key === 'f8') { e.preventDefault(); openStockModal('salida'); return; }
      if (e.key === 'F9' || e.key === 'f9') { e.preventDefault(); openBuscarModal(); return; }
      if (e.key === 'F5' || e.key === 'f5') { e.preventDefault(); if (state.selectedIndex >= 0) promptChangeQty(state.selectedIndex); return; }
      if (e.key === 'F6' || e.key === 'f6') { e.preventDefault(); window.alert('Pendiente (F6) no implementado aun'); return; }
    });
  }

  $(function () {
    bindUI();
    if (!isVentasPage()) return;

    restoreCartState();
    $('#clienteNombre').text(state.customer.name);
    renderGrid();
  });
})();
