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
    mixedPayments: { cash: 0, transfer: 0, credit: 0 },
    paymentNote: '',
    transferMeta: { reference: '', phone: '' },
    saleDiscountPct: 0,
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

  function hasActiveModal() {
    return $('.modal.active').length > 0;
  }

  function focusCodigoInput(force) {
    if (!isVentasPage()) return;
    if (!force && hasActiveModal()) return;
    const $codigo = $('#codigo');
    if ($codigo.length === 0 || $codigo.is(':disabled')) return;
    if (document.activeElement === $codigo[0]) return;
    $codigo.trigger('focus');
  }

  function parseCodeQtyInput(rawValue) {
    const raw = (rawValue || '').toString().trim();
    if (!raw) return null;

    // Supported formats: CODIGO, CODIGO*3, CODIGO x 3, CODIGO 3
    let match = raw.match(/^(.+?)\s*[*xX]\s*(\d+)$/);
    if (!match) {
      match = raw.match(/^(\S+)\s+(\d+)$/);
    }

    if (!match) {
      return { code: raw, qty: 1 };
    }

    const code = (match[1] || '').toString().trim();
    const qty = parseInt((match[2] || '1').toString(), 10);
    if (!code || !Number.isFinite(qty) || qty <= 0) {
      return null;
    }
    return { code: code, qty: qty };
  }

  function processCodigoInput() {
    const parsed = parseCodeQtyInput($('#codigo').val());
    if (!parsed) {
      window.alert('Formato inválido. Use CODIGO o CODIGO*Cantidad.');
      focusCodigoInput(true);
      return;
    }
    addItemByCode(parsed.code, parsed.qty);
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
        transferMeta: state.transferMeta,
        saleDiscountPct: state.saleDiscountPct,
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
        transfer: Number(mixed.transfer || 0),
        credit: Number(mixed.credit ?? mixed.card ?? 0)
      };
      state.paymentNote = (parsed.paymentNote || '').toString();
      const transferMeta = parsed.transferMeta || {};
      state.transferMeta = {
        reference: (transferMeta.reference || '').toString(),
        phone: (transferMeta.phone || '').toString()
      };
      state.saleDiscountPct = Math.max(0, Math.min(100, Number(parsed.saleDiscountPct || 0)));

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
      state.mixedPayments = { cash: 0, transfer: 0, credit: 0 };
      state.paymentNote = '';
      state.transferMeta = { reference: '', phone: '' };
      state.saleDiscountPct = 0;
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
    if (method === 'mixed') return 'Mixto';
    if (method === 'credit') return 'Credito';
    if (method === 'transfer') return 'Transferencia';
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
    const options = ['No', '12%', '15%'];
    const normalized = normalizeIvaLabel(current);
    const currentIndex = options.indexOf(normalized);
    if (currentIndex < 0) return 'No';
    return options[(currentIndex + 1) % options.length];
  }

  function isCreditPayment() {
    return state.paymentMethod === 'credit';
  }

  function isMixedPayment() {
    return state.paymentMethod === 'mixed';
  }

  function isAutoPaidMethod() {
    return state.paymentMethod === 'transfer';
  }

  function buildPaymentNote() {
    const baseNote = (state.paymentNote || '').toString().trim();
    if (state.paymentMethod !== 'transfer' && state.paymentMethod !== 'mixed') {
      return baseNote;
    }

    const isMixed = state.paymentMethod === 'mixed';
    const ref = isMixed
      ? ($('#modal-pago [name=mixedTransferRef]').val() || '').toString().trim()
      : ($('#modal-pago [name=transferRef]').val() || '').toString().trim();
    const phone = isMixed
      ? ''
      : ($('#modal-pago [name=transferPhone]').val() || '').toString().trim();
    state.transferMeta.reference = ref;
    state.transferMeta.phone = phone;

    if (!ref && !phone) {
      return baseNote;
    }

    const parts = [];
    if (ref) parts.push(isMixed ? ('Ref transfer (mixto): ' + ref) : ('Ref: ' + ref));
    if (phone) parts.push('Tel: ' + phone);
    const transferNote = parts.join(' | ');
    return baseNote ? (baseNote + ' | ' + transferNote) : transferNote;
  }

  function getItemGrossAmount(item) {
    return Number(item.price || 0) * Number(item.qty || 0);
  }

  function getItemNetAmount(item) {
    const gross = getItemGrossAmount(item);
    const pct = Math.max(0, Math.min(100, Number(state.saleDiscountPct || 0)));
    return gross - (gross * (pct / 100));
  }

  function getSaleDiscountAmount() {
    return Math.max(0, Number(state.subtotal || 0) - Number(state.total || 0));
  }

  function recalc() {
    state.subtotal = state.items.reduce(function (sum, item) {
      return sum + getItemGrossAmount(item);
    }, 0);
    state.total = state.items.reduce(function (sum, item) {
      return sum + getItemNetAmount(item);
    }, 0);

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
    return ['cash', 'credit', 'mixed', 'transfer'];
  }

  function setPaymentMethod(method) {
    const normalized = paymentMethodOptions().includes(method) ? method : 'cash';
    state.paymentMethod = normalized;
    $('#modal-pago [name=metodoPago]').val(normalized);
    $('#pay-method-picker .pay-method-option').each(function () {
      const $btn = $(this);
      const active = ($btn.data('payment-method') || '').toString() === normalized;
      $btn.toggleClass('active', active);
      $btn.attr('aria-checked', active ? 'true' : 'false');
    });
  }

  function cyclePaymentMethod(direction) {
    const options = paymentMethodOptions();
    const current = (state.paymentMethod || 'cash').toString();
    const idx = options.indexOf(current);
    const start = idx >= 0 ? idx : 0;
    const nextIdx = (start + direction + options.length) % options.length;
    setPaymentMethod(options[nextIdx]);
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
          '<td class="venta-price-cell" data-price-idx="' + index + '" title="Doble click para editar precio">' + formatMoney(item.price) + '</td>' +
          '<td class="qty">' + item.qty + '</td>' +
          '<td>' + formatMoney(getItemNetAmount(item)) + '</td>' +
          '<td>' + (item.stock ?? '') + '</td>' +
        '</tr>'
      );
      $tr.on('click', function () {
        if (state.selectedIndex !== index) {
          state.selectedIndex = index;
          renderGrid();
        }
      });
      $tr.find('.qty').on('dblclick', function (e) {
        e.stopPropagation();
        promptChangeQty(index);
      });

      $tr.find('[data-iva-idx]').on('click', function (e) {
        e.stopPropagation();
        toggleItemIva(index);
      });

      $tr.find('[data-price-idx]').on('dblclick', function (e) {
        e.preventDefault();
        e.stopPropagation();
        promptChangePrice(index);
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
    if (item && item.customPrice) {
      return;
    }
    if (!item || !item.wholesale) {
      item.price = Number(item.basePrice);
      return;
    }

    const minQty = Number(item.wholesale.minQty || 0);
    const wholesalePrice = Number(item.wholesale.price || item.basePrice);
    item.price = item.qty >= minQty ? wholesalePrice : Number(item.basePrice);
  }

  function addItemByCode(code, qty) {
    if (!code) return;
    const qtyToAdd = Math.max(1, parseInt(String(qty || 1), 10) || 1);

    $.getJSON('../api/products.php', { q: code }).done(function (res) {
      if (!res.ok || !res.data || res.data.length === 0) {
        window.alert('Producto no encontrado');
        focusCodigoInput(true);
        return;
      }

      const product = res.data[0];
      const index = state.items.findIndex(function (item) {
        return item.id === product.id;
      });
      const availableStock = Number(product.stock || 0);

      if (index >= 0) {
        const nextQty = Number(state.items[index].qty || 0) + qtyToAdd;
        if (!String(product.id || '').startsWith('tmp-') && nextQty > availableStock) {
          window.alert('Stock insuficiente para ' + (product.name || 'el producto') + '. Existencia: ' + availableStock);
          focusCodigoInput(true);
          return;
        }
        state.items[index].qty = nextQty;
        applyWholesaleForItem(state.items[index]);
        state.selectedIndex = index;
      } else {
        if (!String(product.id || '').startsWith('tmp-') && availableStock <= 0) {
          window.alert('Stock insuficiente para ' + (product.name || 'el producto') + '.');
          focusCodigoInput(true);
          return;
        }
        if (!String(product.id || '').startsWith('tmp-') && qtyToAdd > availableStock) {
          window.alert('Stock insuficiente para ' + (product.name || 'el producto') + '. Existencia: ' + availableStock);
          focusCodigoInput(true);
          return;
        }
        const newItem = {
          id: product.id,
          barcode: product.barcode,
          name: product.name,
          iva: normalizeIvaLabel(product.iva),
          basePrice: Number(product.price),
          price: Number(product.price),
          wholesale: product.wholesale || null,
          customPrice: false,
          qty: qtyToAdd,
          stock: product.stock
        };
        applyWholesaleForItem(newItem);
        state.items.push(newItem);
        state.selectedIndex = state.items.length - 1;
      }
      renderGrid();
      $('#codigo').val('');
      focusCodigoInput(true);
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

  function hasAssignedCreditCustomer() {
    return Boolean(state.customer && state.customer.id && state.customer.id !== 'c-001');
  }

  function applySelectedCustomer(customer) {
    state.customer = {
      id: (customer?.id || 'c-001').toString(),
      name: (customer?.name || 'Publico en general').toString()
    };
    $('#clienteNombre').text(state.customer.name || 'Cliente');
    renderPayCustomerSummary();
    persistCartState();
  }

  function renderPayCustomerSummary() {
    const method = (state.paymentMethod || 'cash').toString();
    const requiresCreditCustomer = (method === 'credit' || method === 'mixed');
    if (!requiresCreditCustomer) {
      $('#pay-customer-summary').prop('hidden', true).hide().text('');
      return;
    }

    const text = hasAssignedCreditCustomer()
      ? ('Cliente seleccionado: ' + (state.customer.name || '') + ' (' + (state.customer.id || '') + ')')
      : 'Debe seleccionar un cliente para registrar saldo pendiente.';

    $('#pay-customer-summary').prop('hidden', false).show().text(text);
  }

  function loadPayCreditCustomers(query) {
    const q = (query || '').toString();
    $.getJSON('../api/customers.php', { q: q }).done(function (res) {
      const $tbody = $('#pay-credit-customers-body').empty();
      const customers = (res.ok && Array.isArray(res.data)) ? res.data : [];

      customers
        .filter(function (customer) {
          return (customer?.id || '') !== 'c-001';
        })
        .slice(0, 50)
        .forEach(function (customer) {
          const isSelected = (state.customer?.id || '') === (customer.id || '');
          const $tr = $(
            '<tr class="pay-credit-row ' + (isSelected ? 'selected' : '') + '">' +
              '<td>' + escapeHtml(customer.id || '') + '</td>' +
              '<td>' + escapeHtml(customer.name || '') + '</td>' +
              '<td>' + escapeHtml(customer.phone || '') + '</td>' +
            '</tr>'
          );
          $tr.on('click', function () {
            applySelectedCustomer(customer);
            loadPayCreditCustomers($('#modal-pago [name=creditCustomerQ]').val());
            updateCambio();
          });
          $tbody.append($tr);
        });
    });
  }

  function updatePaymentMethod() {
    state.paymentMethod = ($('#modal-pago [name=metodoPago]').val() || state.paymentMethod || 'cash').toString();
    setPaymentMethod(state.paymentMethod);
    const showCash = state.paymentMethod === 'cash';
    const showCredit = state.paymentMethod === 'credit';
    const showMixed = state.paymentMethod === 'mixed';
    const showTransfer = state.paymentMethod === 'transfer';
    const disableAmount = !showCash;

    // Reset all method-specific sections first so only the current method fields remain visible.
    $('#pay-single-field, #pay-mixed-grid, #pay-mixed-transfer-ref, #pay-credit-picker, #pay-transfer-fields, #pay-credit-info, #pay-customer-summary')
      .prop('hidden', true)
      .hide();

    if (showCash) {
      $('#pay-single-field').prop('hidden', false).show();
    }
    if (showMixed) {
      $('#pay-mixed-grid').prop('hidden', false).show();
      $('#pay-mixed-transfer-ref').prop('hidden', false).show();
    }
    if (showCredit) {
      $('#pay-credit-picker').prop('hidden', false).show();
    }
    if (showTransfer) {
      $('#pay-transfer-fields').prop('hidden', false).show();
    }
    if (showCredit || showMixed) {
      $('#pay-credit-info').prop('hidden', false).show();
    }
    if (showCredit) {
      $('#pay-credit-info').text('Venta a crédito: seleccione cliente y el total se registra como saldo pendiente.');
    } else if (showMixed) {
      $('#pay-credit-info').text('Pago mixto: efectivo + transferencia + crédito (si usa crédito, cliente seleccionado).');
    }
    $('#modal-pago [name=pagoCon]').prop('disabled', disableAmount);

    if (showCredit) {
      $('#modal-pago [name=pagoCon]').val('0.00');
      $('#modal-pago [name=pagoConEfectivo]').val('0.00');
      $('#modal-pago [name=pagoConTransferencia]').val('0.00');
      $('#modal-pago [name=pagoConCredito]').val('0.00');
      $('#modal-pago [name=mixedTransferRef]').val('');
      state.mixedPayments = { cash: 0, transfer: 0, credit: 0 };
      loadPayCreditCustomers($('#modal-pago [name=creditCustomerQ]').val());
    } else if (showTransfer) {
      $('#modal-pago [name=pagoCon]').val(isAutoPaidMethod() ? state.total.toFixed(2) : '0.00');
      $('#modal-pago [name=pagoConEfectivo]').val('0.00');
      $('#modal-pago [name=pagoConTransferencia]').val('0.00');
      $('#modal-pago [name=pagoConCredito]').val('0.00');
      $('#modal-pago [name=mixedTransferRef]').val('');
      state.mixedPayments = { cash: 0, transfer: 0, credit: 0 };
    } else if (showCash) {
      $('#modal-pago [name=pagoConEfectivo]').val('0.00');
      $('#modal-pago [name=pagoConTransferencia]').val('0.00');
      $('#modal-pago [name=pagoConCredito]').val('0.00');
      $('#modal-pago [name=mixedTransferRef]').val('');
      state.mixedPayments = { cash: 0, transfer: 0, credit: 0 };
    }

    if (showMixed) {
      $('#modal-pago [name=pagoConEfectivo]').val(state.mixedPayments.cash ? state.mixedPayments.cash.toFixed(2) : '');
      $('#modal-pago [name=pagoConTransferencia]').val(state.mixedPayments.transfer ? state.mixedPayments.transfer.toFixed(2) : '');
      $('#modal-pago [name=pagoConCredito]').val(state.mixedPayments.credit ? state.mixedPayments.credit.toFixed(2) : '');
      $('#modal-pago [name=mixedTransferRef]').val(state.transferMeta.reference || '');
    }

    renderPayCustomerSummary();
    updateCambio();
  }

  function openPayModal() {
    const discountAmount = getSaleDiscountAmount();
    $('#modal-pago [name=subtotal]').val(state.subtotal.toFixed(2));
    $('#modal-pago [name=discount]').val(discountAmount.toFixed(2));
    $('#modal-pago [name=total]').val(state.total.toFixed(2));
    setPaymentMethod(state.paymentMethod);
    $('#modal-pago [name=pagoCon]').val(isCreditPayment() ? '0.00' : '');
    $('#modal-pago [name=pagoConEfectivo]').val(state.mixedPayments.cash ? state.mixedPayments.cash.toFixed(2) : '');
    $('#modal-pago [name=pagoConTransferencia]').val(state.mixedPayments.transfer ? state.mixedPayments.transfer.toFixed(2) : '');
    $('#modal-pago [name=pagoConCredito]').val(state.mixedPayments.credit ? state.mixedPayments.credit.toFixed(2) : '');
    $('#modal-pago [name=transferRef]').val(state.transferMeta.reference || '');
    $('#modal-pago [name=transferPhone]').val(state.transferMeta.phone || '');
    $('#modal-pago [name=mixedTransferRef]').val(state.transferMeta.reference || '');
    $('#modal-pago [name=creditCustomerQ]').val('');
    $('#pay-note-preview').text('Nota: ' + (state.paymentNote ? state.paymentNote : '-'));
    $('#modal-pago [data-cambio]').text(formatMoney(0));
    $('#modal-pago').addClass('active');
    updatePaymentMethod();
    if (isCreditPayment()) {
      $('#modal-pago [name=creditCustomerQ]').focus();
    } else if (isMixedPayment()) {
      $('#modal-pago [name=pagoConEfectivo]').focus();
    } else if (isAutoPaidMethod()) {
      $('#modal-pago [name=transferRef]').focus();
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

    if (isAutoPaidMethod()) {
      state.paidWith = state.total;
      state.change = 0;
      state.transferMeta.reference = ($('#modal-pago [name=transferRef]').val() || '').toString().trim();
      state.transferMeta.phone = ($('#modal-pago [name=transferPhone]').val() || '').toString().trim();
      $('#modal-pago [data-cambio]').text(formatMoney(0));
      recalc();
      return;
    }

    if (isMixedPayment()) {
      const cashPart = parseFloat($('#modal-pago [name=pagoConEfectivo]').val().toString()) || 0;
      const transferPart = parseFloat($('#modal-pago [name=pagoConTransferencia]').val().toString()) || 0;
      const creditPart = parseFloat($('#modal-pago [name=pagoConCredito]').val().toString()) || 0;
      state.transferMeta.reference = ($('#modal-pago [name=mixedTransferRef]').val() || '').toString().trim();
      const totalCovered = Math.max(0, cashPart) + Math.max(0, transferPart) + Math.max(0, creditPart);
      const changeMixed = Math.max(0, totalCovered - state.total);
      state.mixedPayments = {
        cash: Math.max(0, cashPart),
        transfer: Math.max(0, transferPart),
        credit: Math.max(0, creditPart)
      };
      state.paidWith = Math.max(0, cashPart) + Math.max(0, transferPart);
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
    const mixed = safeSale.mixedPayments || { cash: 0, transfer: 0, credit: 0 };
    const mixedInfo = String(safeSale.paymentMethod || '') === 'mixed'
      ? '<div>Efectivo: ' + formatMoney(Number(mixed.cash || 0)) + ' | Transferencia: ' + formatMoney(Number(mixed.transfer || 0)) + ' | Crédito: ' + formatMoney(Number(mixed.credit || mixed.card || 0)) + '</div>'
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

    if (isCreditPayment() && !hasAssignedCreditCustomer()) {
      window.alert('Asigne un cliente antes de registrar saldo pendiente.');
      return;
    }

    if (isMixedPayment() && state.mixedPayments.cash <= 0 && state.mixedPayments.transfer <= 0 && state.mixedPayments.credit <= 0) {
      window.alert('Ingrese montos para pago mixto.');
      return;
    }

    if (isMixedPayment()) {
      const covered = Number(state.mixedPayments.cash || 0) + Number(state.mixedPayments.transfer || 0) + Number(state.mixedPayments.credit || 0);
      if (covered < state.total) {
        window.alert('En pago mixto, Efectivo + Transferencia + Crédito debe cubrir el total.');
        return;
      }
      if (state.mixedPayments.cash < 0) {
        window.alert('Monto de efectivo inválido.');
        return;
      }
      if (state.mixedPayments.transfer < 0) {
        window.alert('Monto de transferencia inválido.');
        return;
      }
      if (state.mixedPayments.transfer > 0 && (state.transferMeta.reference || '').toString().trim() === '') {
        window.alert('Ingrese referencia de transferencia en pago mixto.');
        return;
      }
      if (state.mixedPayments.credit > 0 && !hasAssignedCreditCustomer()) {
        window.alert('Seleccione cliente cuando haya parte a crédito en pago mixto.');
        return;
      }
    }

    if (isAutoPaidMethod()) {
      const ref = (state.transferMeta.reference || '').toString().trim();
      if (ref === '') {
        window.alert('Ingrese referencia para transferencia.');
        return;
      }
    }

    if (!isCreditPayment() && !isMixedPayment() && !isAutoPaidMethod() && !(state.paidWith >= state.total)) {
      window.alert('Pago insuficiente');
      return;
    }

    const stockConflict = state.items.find(function (item) {
      if (String(item.id || '').startsWith('tmp-')) return false;
      return Number(item.qty || 0) > Number(item.stock || 0);
    });
    if (stockConflict) {
      window.alert(
        'Stock insuficiente para ' + (stockConflict.name || 'el producto') +
        '. Cantidad solicitada: ' + Number(stockConflict.qty || 0) +
        ', existencia: ' + Number(stockConflict.stock || 0)
      );
      return;
    }

    const paymentNotePayload = buildPaymentNote();

    const payload = {
      items: state.items,
      subtotal: state.subtotal,
      discountPct: state.saleDiscountPct,
      discountAmount: getSaleDiscountAmount(),
      total: state.total,
      paidWith: state.paidWith,
      change: state.change,
      paymentMethod: state.paymentMethod,
      mixedPayments: isMixedPayment() ? state.mixedPayments : null,
      paymentNote: paymentNotePayload,
      amountPending: isCreditPayment() ? state.total : (isMixedPayment() ? Number(state.mixedPayments.credit || 0) : 0),
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
        mixedPayments: state.paymentMethod === 'mixed' ? { cash: state.mixedPayments.cash, transfer: state.mixedPayments.transfer, credit: state.mixedPayments.credit } : null,
        customerName: state.customer?.name || 'Publico en general',
        paymentNote: paymentNotePayload
      };

      closeModal('#modal-pago');
      state.items = [];
      state.selectedIndex = -1;
      state.paidWith = 0;
      state.change = 0;
      state.paymentMethod = 'cash';
      state.mixedPayments = { cash: 0, transfer: 0, credit: 0 };
      state.paymentNote = '';
      state.transferMeta = { reference: '', phone: '' };
      state.saleDiscountPct = 0;
      renderGrid();

      if (shouldPrint) {
        printTicket(saleSnapshot);
        window.alert('Venta realizada. Ticket #' + ticketId + '\nTicket enviado a impresión.');
      } else {
        window.alert('Venta realizada. Ticket #' + ticketId);
      }
    }).fail(function (xhr) {
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'No se pudo registrar la venta.';
      window.alert('No se pudo registrar la venta: ' + backendError);
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
          applySelectedCustomer(customer);
          closeModal('#modal-cliente');
        });
        $tbody.append($tr);
      });
    });
  }

  function openStockModal(type) {
    $('#modal-stock [name=tipo]').val(type);
    const isEntry = type === 'entrada';
    $('#modal-stock [name=amount]').val('0.00');
    $('#modal-stock [name=note]').val('');
    $('#modal-stock header').text(isEntry ? 'Entrada de efectivo (F7)' : 'Salida de efectivo (F8)');
    $('#cash-movement-help').text(
      isEntry
        ? 'Registre la cantidad y un comentario para la entrada de efectivo.'
        : 'Registre la cantidad y la razón o proveedor para la salida de efectivo.'
    );
    $('#cash-movement-note-title').text(isEntry ? 'Comentario' : 'Razón o proveedor');
    $('#modal-stock [name=note]').attr('placeholder', isEntry ? 'Entrada de dinero' : 'Razón o proveedor');
    $('#modal-stock').addClass('active');
    $('#modal-stock [name=amount]').focus();
  }

  function confirmStockMovement() {
    const type = $('#modal-stock [name=tipo]').val().toString();
    const amount = parseFloat($('#modal-stock [name=amount]').val().toString()) || 0;
    const note = ($('#modal-stock [name=note]').val() || '').toString().trim();
    if (!(amount > 0)) {
      window.alert('Ingrese una cantidad válida.');
      return;
    }
    if (type !== 'entrada' && !note) {
      window.alert('Ingrese razón o proveedor para la salida.');
      return;
    }

    $.ajax({
      url: '../api/shift.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        action: 'cash_movement',
        movementType: type === 'entrada' ? 'entry' : 'exit',
        amount: amount,
        note: note
      })
    }).done(function (result) {
      if (!result.ok) {
        window.alert(result.error || 'No se pudo registrar movimiento de efectivo.');
        return;
      }
      closeModal('#modal-stock');
      window.alert((result?.data?.message || 'Movimiento registrado correctamente.') + '\nCantidad: ' + formatMoney(amount));
      focusCodigoInput(true);
    }).fail(function (xhr) {
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'No se pudo registrar movimiento de efectivo.';
      window.alert(backendError);
    });
  }

  function applyWholesaleIfNeeded() {
    state.items.forEach(applyWholesaleForItem);
    renderGrid();
  }

  function promptChangePrice(index) {
    const item = state.items[index];
    if (!item) return;
    const nextPriceStr = window.prompt('Precio de venta:', Number(item.price || 0).toFixed(2));
    if (nextPriceStr === null) return;
    const nextPrice = parseFloat(nextPriceStr);
    if (!Number.isFinite(nextPrice) || nextPrice < 0) {
      window.alert('Precio inválido');
      return;
    }
    item.price = Number(nextPrice.toFixed(2));
    item.customPrice = true;
    renderGrid();
  }

  function openDiscountModal() {
    $('#sale-discount-pct').val(Number(state.saleDiscountPct || 0).toFixed(2));
    $('#modal-discount').addClass('active');
    $('#sale-discount-pct').focus();
    $('#sale-discount-pct').select();
  }

  function applySaleDiscount() {
    const raw = ($('#sale-discount-pct').val() || '0').toString();
    const pct = Number(raw);
    if (!Number.isFinite(pct) || pct < 0 || pct > 100) {
      window.alert('Ingrese un descuento válido entre 0 y 100.');
      return;
    }
    state.saleDiscountPct = Number(pct.toFixed(2));
    closeModal('#modal-discount');
    renderGrid();
  }

  function clearSaleDiscount() {
    state.saleDiscountPct = 0;
    closeModal('#modal-discount');
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
    if (!String(item.id || '').startsWith('tmp-') && nextQty > Number(item.stock || 0)) {
      window.alert('Stock insuficiente para ' + (item.name || 'el producto') + '. Existencia: ' + Number(item.stock || 0));
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
      processCodigoInput();
    });
    $('#btn-varios').on('click', function () { focusCodigoInput(true); });
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
    $('#btn-descuento').on('click', openDiscountModal);
    $('#btn-ventas-dia').on('click', function () { openSalesHistoryModal('view'); });
    $('#btn-devoluciones').on('click', function () { openSalesHistoryModal('return'); });
    $('#modal-common .btn-primary').on('click', confirmCommonItem);
    $('#modal-common .btn-secondary').on('click', function () { closeModal('#modal-common'); });
    $('#btn-pay-cancel').on('click', function () { closeModal('#modal-pago'); });
    $('#btn-pay-note').on('click', capturePaymentNote);
    $('#pay-method-picker .pay-method-option').on('click', function () {
      const method = ($(this).data('payment-method') || '').toString();
      setPaymentMethod(method);
      updatePaymentMethod();

      if (isMixedPayment()) {
        $('#modal-pago [name=pagoConEfectivo]').focus();
      } else if (isAutoPaidMethod()) {
        $('#modal-pago [name=transferRef]').focus();
      } else if (isCreditPayment()) {
        $('#modal-pago [name=creditCustomerQ]').focus();
      } else {
        $('#modal-pago [name=pagoCon]').focus();
      }
    });
    $('#modal-pago [name=pagoCon]').on('input', updateCambio);
    $('#modal-pago [name=pagoConEfectivo], #modal-pago [name=pagoConTransferencia], #modal-pago [name=pagoConCredito]').on('input', updateCambio);
    $('#modal-pago [name=mixedTransferRef]').on('input', updateCambio);
    $('#modal-pago [name=transferRef], #modal-pago [name=transferPhone]').on('input', updateCambio);
    $('#modal-pago [name=creditCustomerQ]').on('input', function () {
      loadPayCreditCustomers($(this).val().toString());
    });
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
    $('#sale-discount-cancel').on('click', function () { closeModal('#modal-discount'); });
    $('#sale-discount-apply').on('click', applySaleDiscount);
    $('#sale-discount-clear').on('click', clearSaleDiscount);
    $('#sale-discount-pct').on('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        applySaleDiscount();
      }
    });

    $('#codigo').on('blur', function () {
      window.setTimeout(function () { focusCodigoInput(false); }, 0);
    });

    $(document).on('click', function (e) {
      if ($(e.target).closest('.modal.active, #codigo').length > 0) return;
      window.setTimeout(function () { focusCodigoInput(false); }, 0);
    });

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
        if (e.key === 'ArrowUp') { e.preventDefault(); cyclePaymentMethod(-1); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); cyclePaymentMethod(1); return; }
        if (e.key === 'Enter' && !isTextControl) { e.preventDefault(); confirmSale({ printTicket: false }); return; }

        // Evita choques con atajos de módulos mientras se está cobrando.
        if (/^f\d+$/i.test(e.key)) { e.preventDefault(); return; }
      }

      if (e.key === 'F1' || e.key === 'f1') { e.preventDefault(); window.location.href = 'index.php?mod=ventas'; return; }
      if (e.key === 'F2' || e.key === 'f2') { e.preventDefault(); window.location.href = 'index.php?mod=creditos'; return; }
      if (e.key === 'F3' || e.key === 'f3') { e.preventDefault(); window.location.href = 'index.php?mod=productos'; return; }
      if (e.key === 'F4' || e.key === 'f4') { e.preventDefault(); window.location.href = 'index.php?mod=inventario'; return; }

      if (e.ctrlKey && key === 'p') { e.preventDefault(); openCommonItemModal(); return; }
      if (e.key === 'Enter' && !isTextControl) { e.preventDefault(); processCodigoInput(); return; }
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
    focusCodigoInput(true);
  });
})();
