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
    customer: { id: 'c-001', name: 'Publico en general' }
  };

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
    if (method === 'credit') return 'Credito';
    if (method === 'voucher') return 'Vales';
    if (method === 'transfer') return 'Transferencia';
    if (method === 'check') return 'Cheque';
    return 'Efectivo';
  }

  function normalizeIvaLabel(iva) {
    var raw = (iva || '').toString().trim().toLowerCase();
    if (raw === '12' || raw === '12%' || raw === 'iva 12' || raw === 'iva 12%' || raw === 'si' || raw === 'sí') return '12%';
    if (raw === '15' || raw === '15%' || raw === 'iva 15' || raw === 'iva 15%') return '15%';
    return 'No';
  }

  function isCreditPayment() {
    return state.paymentMethod === 'credit';
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
  }

  function renderGrid() {
    const $tbody = $('#venta-body').empty();
    state.items.forEach(function (item, index) {
      const $tr = $(
        '<tr data-idx="' + index + '" class="' + (index === state.selectedIndex ? 'row-selected' : '') + '">' +
          '<td>' + (item.barcode || '') + '</td>' +
          '<td>' + escapeHtml(item.name || '') + '</td>' +
          '<td>' + escapeHtml(normalizeIvaLabel(item.iva)) + '</td>' +
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
      $tbody.append($tr);
    });
    recalc();
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
    $('#modal-pago [name=pagoCon]').prop('disabled', disableAmount);
    if (disableAmount) {
      $('#modal-pago [name=pagoCon]').val('0.00');
    }
    updateCambio();
  }

  function openPayModal() {
    $('#modal-pago [name=total]').val(state.total.toFixed(2));
    $('#modal-pago [name=metodoPago]').val(state.paymentMethod);
    $('#modal-pago [name=pagoCon]').val(isCreditPayment() ? '0.00' : '');
    $('#modal-pago [data-cambio]').text(formatMoney(0));
    $('#modal-pago').addClass('active');
    updatePaymentMethod();
    if (isCreditPayment()) {
      $('#modal-pago [name=metodoPago]').focus();
    } else {
      $('#modal-pago [name=pagoCon]').focus();
    }
  }

  function updateCambio() {
    if (isCreditPayment()) {
      state.paidWith = 0;
      state.change = 0;
      $('#modal-pago [data-cambio]').text(formatMoney(0));
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

  function confirmSale() {
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

    const payload = {
      items: state.items,
      subtotal: state.subtotal,
      total: state.total,
      paidWith: state.paidWith,
      change: state.change,
      paymentMethod: state.paymentMethod,
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

      closeModal('#modal-pago');
      state.items = [];
      state.selectedIndex = -1;
      state.paidWith = 0;
      state.change = 0;
      state.paymentMethod = 'cash';
      renderGrid();
      window.alert('Venta realizada. Ticket #' + res.data.ticketId);
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
    $('#modal-common .btn-primary').on('click', confirmCommonItem);
    $('#modal-common .btn-secondary').on('click', function () { closeModal('#modal-common'); });
    $('#modal-pago .btn-secondary').on('click', function () { closeModal('#modal-pago'); });
    $('#modal-pago [name=metodoPago]').on('change', updatePaymentMethod);
    $('#modal-pago [name=pagoCon]').on('input', updateCambio);
    $('#modal-pago .btn-primary').on('click', confirmSale);
    $('#modal-buscar .btn-secondary').on('click', function () { closeModal('#modal-buscar'); });
    $('#modal-buscar [name=q]').on('input', function () { loadBuscarResults($(this).val().toString()); });
    $('#modal-cliente .btn-secondary').on('click', function () { closeModal('#modal-cliente'); });
    $('#modal-cliente [name=q]').on('input', function () { loadCustomerResults($(this).val().toString()); });
    $('#modal-stock .btn-secondary').on('click', function () { closeModal('#modal-stock'); });
    $('#modal-stock .btn-primary').on('click', confirmStockMovement);

    $(window).on('keydown', function (e) {
      const key = e.key.toLowerCase();

      if (e.key === 'Escape') {
        $('.modal.active').removeClass('active');
        return;
      }

      if (e.key === 'F1' || e.key === 'f1') { e.preventDefault(); window.location.href = 'index.php?mod=ventas'; return; }
      if (e.key === 'F2' || e.key === 'f2') { e.preventDefault(); window.location.href = 'index.php?mod=creditos'; return; }
      if (e.key === 'F3' || e.key === 'f3') { e.preventDefault(); window.location.href = 'index.php?mod=productos'; return; }
      if (e.key === 'F4' || e.key === 'f4') { e.preventDefault(); window.location.href = 'index.php?mod=inventario'; return; }

      if (e.ctrlKey && key === 'p') { e.preventDefault(); openCommonItemModal(); return; }
      if (e.key === 'Enter') { e.preventDefault(); addItemByCode($('#codigo').val().toString().trim()); return; }
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
    $('#clienteNombre').text(state.customer.name);
    renderGrid();
  });
})();
