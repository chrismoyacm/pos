/* global $, window, document */
(function () {
  'use strict';

  function isCutPage() {
    return $('#cut-module').length > 0;
  }

  function formatMoney(n) {
    return '$' + Number(n || 0).toFixed(2);
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, function (s) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[s];
    });
  }

  function paymentMethodKey(method) {
    const normalized = (method || 'cash').toString().toLowerCase();
    return ['cash', 'card', 'credit', 'voucher', 'transfer', 'check'].includes(normalized) ? normalized : 'cash';
  }

  function ivaRateFromValue(value) {
    const raw = (value || '').toString().trim().toLowerCase();
    if (!raw || raw === 'no' || raw === '0' || raw === 'false') return 0;
    if (raw === 'si' || raw === 'sí' || raw === '12' || raw === '12%' || raw === 'iva 12' || raw === 'iva 12%') return 12;
    if (raw === '15' || raw === '15%' || raw === 'iva 15' || raw === 'iva 15%') return 15;
    const match = raw.match(/(\d{1,2})(?:\.\d+)?%?/);
    if (!match) return 0;
    const parsed = Number(match[1] || 0);
    if (!Number.isFinite(parsed) || parsed <= 0) return 0;
    return parsed;
  }

  const state = {
    mode: 'cashier',
    sales: [],
    products: [],
    customers: [],
    closingShift: false,
    shift: {
      hasOpenShift: false,
      shiftId: '',
      openedAt: '',
      expectedCash: 0
    },
    cashMovements: [],
    cashMovementSummary: {
      entriesTotal: 0,
      exitsTotal: 0,
      netTotal: 0
    },
    creditPayments: [],
    creditPaymentsSummary: {
      total: 0,
      cashTotal: 0
    }
  };

  function currentCashierName() {
    return ($('.user-name').first().text() || '').toString().trim();
  }

  function findProduct(productId) {
    return state.products.find(function (product) {
      return product.id === productId;
    }) || null;
  }

  function customerName(customerId, fallbackName) {
    if (fallbackName) return fallbackName;
    const customer = state.customers.find(function (row) {
      return row.id === customerId;
    });
    return customer?.name || 'Publico en general';
  }

  function modeTitle() {
    return state.mode === 'day' ? 'Corte del dia' : 'Corte de cajero';
  }

  function modeRange() {
    const sales = filteredSales();
    const hasShiftRange = state.mode === 'cashier' && state.shift.hasOpenShift && state.shift.openedAt;
    if (hasShiftRange) {
      const first = new Date(state.shift.openedAt || Date.now());
      const last = new Date();
      const formatter = new Intl.DateTimeFormat('es-EC', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
      return 'De las ' + formatter.format(first) + ' a las ' + formatter.format(last);
    }

    if (sales.length === 0) {
      return 'De las - a las -';
    }

    const sorted = [...sales].sort(function (a, b) {
      return String(a.createdAt || '').localeCompare(String(b.createdAt || ''));
    });
    const first = new Date(sorted[0].createdAt || Date.now());
    const last = new Date(sorted[sorted.length - 1].createdAt || Date.now());
    const formatter = new Intl.DateTimeFormat('es-EC', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });

    return 'De las ' + formatter.format(first) + ' a las ' + formatter.format(last);
  }

  function renderLines($container, rows) {
    $container.empty();
    rows.forEach(function (row) {
      $container.append(
        '<div class="corte-line">' +
          '<span>' + escapeHtml(row.label) + '</span>' +
          '<strong class="' + (row.value < 0 ? 'neg' : row.value > 0 ? 'pos' : '') + '">' +
            (row.value > 0 ? '+ ' : row.value < 0 ? '- ' : '') + formatMoney(Math.abs(row.value)) +
          '</strong>' +
        '</div>'
      );
    });
  }

  function renderListOrEmpty(selector, rows, emptyText) {
    const $node = $(selector).empty();
    if (!rows.length) {
      $node.addClass('corte-empty').text(emptyText);
      return;
    }

    $node.removeClass('corte-empty');
    rows.forEach(function (row) {
      $node.append(
        '<div class="corte-line corte-line--soft">' +
          '<span>' + escapeHtml(row.label) + '</span>' +
          '<strong>' + (row.amount !== undefined ? formatMoney(row.amount) : escapeHtml(row.value || '')) + '</strong>' +
        '</div>'
      );
    });
  }

  function computeSummary() {
    const sales = filteredSales();
    const summary = {
      totalSales: 0,
      totalProfit: 0,
      methodTotals: {
        cash: 0,
        card: 0,
        credit: 0,
        voucher: 0,
        transfer: 0,
        check: 0
      },
      taxTotals: {},
      departmentSales: {},
      customerSales: {},
      customerProfits: {}
    };

    sales.forEach(function (sale) {
      const total = Number(sale.total || 0);
      const method = paymentMethodKey(sale.paymentMethod);
      const customerId = (sale.customerId || 'c-001').toString();

      summary.totalSales += total;
      summary.methodTotals[method] += total;
      summary.customerSales[customerId] = (summary.customerSales[customerId] || 0) + total;

      (sale.items || []).forEach(function (item) {
        const product = findProduct(item.id);
        const cost = Number(product?.cost || 0);
        const qty = Number(item.qty || 0);
        const price = Number(item.price || 0);
        const profit = (price - cost) * qty;
        const lineTotal = price * qty;
        const ivaRate = ivaRateFromValue(item?.iva ?? product?.iva ?? 'No');

        summary.totalProfit += profit;
        summary.customerProfits[customerId] = (summary.customerProfits[customerId] || 0) + profit;

        const department = product?.department || 'Sin Departamento';
        summary.departmentSales[department] = (summary.departmentSales[department] || 0) + (price * qty);

        if (ivaRate > 0 && lineTotal > 0) {
          const tax = lineTotal * (ivaRate / (100 + ivaRate));
          const taxable = lineTotal - tax;
          const key = String(ivaRate);
          if (!summary.taxTotals[key]) {
            summary.taxTotals[key] = {
              rate: ivaRate,
              taxable: 0,
              tax: 0,
              total: 0
            };
          }
          summary.taxTotals[key].taxable += taxable;
          summary.taxTotals[key].tax += tax;
          summary.taxTotals[key].total += lineTotal;
        }
      });
    });

    return summary;
  }

  function filteredSales() {
    const today = new Date().toISOString().slice(0, 10);
    if (state.mode === 'day') {
      return state.sales.filter(function (sale) {
        const createdAt = (sale?.createdAt || '').toString();
        return createdAt.slice(0, 10) === today;
      });
    }

    if (state.mode !== 'cashier') {
      return state.sales;
    }

    const cashier = currentCashierName();
    if (!cashier) {
      return state.sales;
    }

    const matches = state.sales.filter(function (sale) {
      return (sale.cashier || '').toString() === cashier;
    });

    return matches.length ? matches : state.sales;
  }

  function render() {
    const summary = computeSummary();
    const entriesTotal = Number(state.cashMovementSummary?.entriesTotal || 0);
    const exitsTotal = Number(state.cashMovementSummary?.exitsTotal || 0);
    const creditCashTotal = Number(state.creditPaymentsSummary?.cashTotal || 0);
    const cashEntryRows = state.cashMovements
      .filter(function (mv) { return (mv?.type || '') === 'entry'; })
      .slice(0, 12)
      .map(function (mv) {
        const when = (mv?.createdAt || '').toString();
        const whenLabel = when ? new Date(when).toLocaleString('es-EC', { hour12: false }) : '';
        const note = (mv?.note || '').toString().trim();
        return {
          label: (note || 'Entrada de efectivo') + (whenLabel ? ' (' + whenLabel + ')' : ''),
          amount: Number(mv?.amount || 0)
        };
      });
    const creditPaymentRows = state.creditPayments
      .slice(0, 12)
      .map(function (p) {
        const when = (p?.createdAt || '').toString();
        const whenLabel = when ? new Date(when).toLocaleString('es-EC', { hour12: false }) : '';
        const method = (p?.paymentMethod || 'cash').toString();
        const note = (p?.note || '').toString().trim();
        const folio = (p?.folio || '').toString().trim();
        const labelParts = [];
        if (folio) labelParts.push(folio);
        labelParts.push('Abono ' + method.toUpperCase());
        if (note) labelParts.push(note);
        if (whenLabel) labelParts.push(whenLabel);
        return {
          label: labelParts.join(' - '),
          amount: Number(p?.amount || 0)
        };
      });

    const cashBoxRows = [
      { label: 'Fondo de caja', value: 0 },
      { label: 'Ventas en Efectivo', value: summary.methodTotals.cash },
      { label: 'Abonos en efectivo', value: creditCashTotal },
      { label: 'Entradas', value: entriesTotal },
      { label: 'Salidas', value: 0 - exitsTotal },
      { label: 'Devoluciones en efectivo', value: 0 }
    ];
    const salesRows = [
      { label: 'En Efectivo', value: summary.methodTotals.cash },
      { label: 'Con Tarjeta de Credito', value: summary.methodTotals.card },
      { label: 'A Credito', value: summary.methodTotals.credit },
      { label: 'Con Vales de Despensa', value: summary.methodTotals.voucher },
      { label: 'Con Transferencia', value: summary.methodTotals.transfer },
      { label: 'Con Cheque', value: summary.methodTotals.check },
      { label: 'Devoluciones de Ventas', value: 0 }
    ];

    $('#cut-title').text(modeTitle());
    $('#cut-range').text(modeRange());
    $('#cut-total-sales').text(formatMoney(summary.totalSales));
    $('#cut-total-profit').text(formatMoney(summary.totalProfit));

    renderLines($('#cut-cash-box-lines'), cashBoxRows);
    renderLines($('#cut-sales-lines'), salesRows);
    $('#cut-cash-box-total').text(formatMoney(cashBoxRows.reduce(function (sum, row) { return sum + row.value; }, 0)));
    $('#cut-sales-total').text(formatMoney(salesRows.reduce(function (sum, row) { return sum + row.value; }, 0)));

    renderListOrEmpty('#cut-cash-in-list', cashEntryRows, '- No hubo Entradas en Efectivo -');

    const cashIncomeRows = summary.methodTotals.cash > 0
      ? [{ label: 'Ventas de contado', amount: summary.methodTotals.cash }]
      : [];
    renderListOrEmpty('#cut-cash-income-list', cashIncomeRows, '- No hubo ingresos de contado -');
    $('#cut-cash-income-total').text(formatMoney(summary.methodTotals.cash));

    const departmentRows = Object.entries(summary.departmentSales)
      .sort(function (a, b) { return b[1] - a[1]; })
      .map(function (entry) {
        return { label: entry[0], amount: entry[1] };
      });
    renderListOrEmpty('#cut-sales-by-department', departmentRows, '- No se registro ninguna venta -');

    const taxRows = Object.values(summary.taxTotals || {})
      .sort(function (a, b) { return Number(b?.rate || 0) - Number(a?.rate || 0); })
      .map(function (row) {
        const base = Number(row?.taxable || 0);
        const tax = Number(row?.tax || 0);
        const rate = Number(row?.rate || 0);
        return {
          label: 'IVA ' + rate + '% (Base ' + formatMoney(base) + ')',
          amount: tax
        };
      });
    renderListOrEmpty('#cut-taxes', taxRows, '- No hubo ventas -');
    renderListOrEmpty('#cut-credit-payments', creditPaymentRows, '- No se recibieron pagos de creditos -');

    const topCustomers = Object.entries(summary.customerSales)
      .sort(function (a, b) { return b[1] - a[1]; })
      .slice(0, 5)
      .map(function (entry) {
        const customerId = entry[0];
        const amount = entry[1];
        const sale = state.sales.find(function (row) { return String(row.customerId || 'c-001') === customerId; });
        return { label: customerName(customerId, sale?.customerName || ''), amount: amount };
      });
    renderListOrEmpty('#cut-top-customers', topCustomers, '- Sin datos de clientes -');

    const topProfitCustomers = Object.entries(summary.customerProfits)
      .sort(function (a, b) { return b[1] - a[1]; })
      .slice(0, 5)
      .map(function (entry) {
        const customerId = entry[0];
        const amount = entry[1];
        const sale = state.sales.find(function (row) { return String(row.customerId || 'c-001') === customerId; });
        return { label: customerName(customerId, sale?.customerName || ''), amount: amount };
      });
    renderListOrEmpty('#cut-top-profit-customers', topProfitCustomers, '- Sin datos de ganancias -');
    updateCloseButtonState();
  }

  function updateCloseButtonState() {
    const $btn = $('#cut-close-btn');
    if (!$btn.length) return;

    if (state.mode !== 'cashier') {
      $btn.prop('disabled', true).text('Cerrar turno (solo cajero)');
      return;
    }
    if (!state.shift.hasOpenShift) {
      $btn.prop('disabled', true).text('No hay turno abierto');
      return;
    }
    if (state.closingShift) {
      $btn.prop('disabled', true).text('Cerrando turno...');
      return;
    }

    $btn.prop('disabled', false).text('Cerrar turno ...');
  }

  function parseCashValue(value) {
    const normalized = (value || '').toString().trim().replace(',', '.');
    const parsed = parseFloat(normalized);
    if (!Number.isFinite(parsed)) return null;
    return Number(parsed.toFixed(2));
  }

  function hideCloseShiftModal() {
    const $modal = $('#cut-close-modal');
    $modal.removeClass('active').attr('aria-hidden', 'true');
    $('#cut-close-modal-error').text('');
  }

  function updateCloseShiftDifference() {
    const expected = Number(state.shift.expectedCash || 0);
    const actual = parseCashValue($('#cut-close-actual').val());
    if (actual === null) {
      $('#cut-close-difference').val('Ingrese un valor valido');
      return;
    }
    const diff = Number((actual - expected).toFixed(2));
    $('#cut-close-difference').val(formatMoney(diff));
  }

  function openCloseShiftModal() {
    if (state.mode !== 'cashier') {
      window.alert('Para cerrar turno debe estar en "Corte de cajero".');
      return;
    }
    if (!state.shift.hasOpenShift) {
      window.alert('No hay un turno abierto para cerrar.');
      return;
    }

    const expectedCash = Number(state.shift.expectedCash || 0);
    $('#cut-close-expected').val(formatMoney(expectedCash));
    $('#cut-close-actual').val(expectedCash.toFixed(2));
    $('#cut-close-modal-error').text('');
    $('#cut-close-confirm').prop('disabled', !!state.closingShift).text(state.closingShift ? 'Cerrando...' : 'Cerrar turno');
    updateCloseShiftDifference();
    $('#cut-close-modal').addClass('active').attr('aria-hidden', 'false');
    $('#cut-close-actual').focus().select();
  }

  function closeShiftFromModal() {
    if (state.closingShift) return;

    const actualCash = parseCashValue($('#cut-close-actual').val());
    if (!Number.isFinite(actualCash) || actualCash < 0) {
      $('#cut-close-modal-error').text('Ingrese un monto valido mayor o igual a 0.');
      return;
    }
    $('#cut-close-modal-error').text('');

    state.closingShift = true;
    updateCloseButtonState();
    $('#cut-close-confirm').prop('disabled', true).text('Cerrando...');

    $.ajax({
      url: '../api/shift.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        action: 'close_shift',
        actualCash: Number(actualCash.toFixed(2))
      })
    }).done(function (res) {
      if (!res || !res.ok) {
        $('#cut-close-modal-error').text((res && res.error) ? res.error : 'No se pudo cerrar el turno.');
        return;
      }

      const data = res.data || {};
      const expected = Number(data.expectedCash || 0);
      const actual = Number(data.actualCash || 0);
      const diff = Number(data.difference || 0);
      hideCloseShiftModal();
      window.alert(
        (data.message || 'Turno cerrado correctamente') +
        '\nEsperado: ' + formatMoney(expected) +
        '\nContado: ' + formatMoney(actual) +
        '\nDiferencia: ' + formatMoney(diff)
      );
      window.location.href = (data.redirect || '../api/auth.php?action=logout').toString();
    }).fail(function (xhr) {
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'No se pudo cerrar el turno.';
      $('#cut-close-modal-error').text(backendError);
    }).always(function () {
      state.closingShift = false;
      updateCloseButtonState();
      $('#cut-close-confirm').prop('disabled', false).text('Cerrar turno');
    });
  }

  function bind() {
    $('[data-cut-mode]').on('click', function () {
      state.mode = ($(this).data('cut-mode') || 'cashier').toString();
      $('[data-cut-mode]').removeClass('active');
      $(this).addClass('active');
      $.when(refreshCashMovements(), refreshCreditPayments()).always(function () {
        render();
      });
    });

    $('#cut-print-btn').on('click', function () {
      window.print();
    });

    $('#cut-close-btn').on('click', function () {
      openCloseShiftModal();
    });

    $('#cut-close-cancel').on('click', function () {
      if (state.closingShift) return;
      hideCloseShiftModal();
    });

    $('#cut-close-confirm').on('click', function () {
      closeShiftFromModal();
    });

    $('#cut-close-actual').on('input', function () {
      updateCloseShiftDifference();
      $('#cut-close-modal-error').text('');
    });

    $('#cut-close-modal').on('click', function (event) {
      if (event.target === this && !state.closingShift) {
        hideCloseShiftModal();
      }
    });

    $(document).on('keydown', function (event) {
      if (event.key === 'Escape' && $('#cut-close-modal').hasClass('active') && !state.closingShift) {
        hideCloseShiftModal();
      }
      if (event.key === 'Enter' && $('#cut-close-modal').hasClass('active')) {
        event.preventDefault();
        closeShiftFromModal();
      }
    });
  }

  function loadSales() {
    return $.getJSON('../api/sales.php').done(function (res) {
      state.sales = (res.ok && Array.isArray(res.data)) ? res.data : [];
    });
  }

  function loadProducts() {
    return $.getJSON('../api/products.php').done(function (res) {
      state.products = (res.ok && Array.isArray(res.data)) ? res.data : [];
    });
  }

  function loadCustomers() {
    return $.getJSON('../api/customers.php').done(function (res) {
      state.customers = (res.ok && Array.isArray(res.data)) ? res.data : [];
    });
  }

  function currentDateYmd() {
    return new Date().toISOString().slice(0, 10);
  }

  function loadShiftStatus() {
    return $.getJSON('../api/shift.php').done(function (res) {
      const data = (res && res.ok && res.data) ? res.data : {};
      state.shift.hasOpenShift = !!data.hasOpenShift;
      state.shift.shiftId = (data.shiftId || '').toString();
      state.shift.openedAt = (data.openedAt || '').toString();
      state.shift.expectedCash = Number(data.expectedCash || 0);
    }).fail(function () {
      state.shift.hasOpenShift = false;
      state.shift.shiftId = '';
      state.shift.openedAt = '';
      state.shift.expectedCash = 0;
    });
  }

  function refreshCashMovements() {
    const params = {
      action: 'movements',
      page: 1,
      pageSize: 100
    };

    if (state.mode === 'cashier' && state.shift.hasOpenShift && state.shift.shiftId) {
      params.shiftId = state.shift.shiftId;
    }

    if (state.mode === 'day') {
      const d = currentDateYmd();
      params.dateFrom = d;
      params.dateTo = d;
    }

    return $.getJSON('../api/shift.php', params).done(function (res) {
      const data = (res && res.ok) ? (res.data || {}) : {};
      state.cashMovements = Array.isArray(data.items) ? data.items : [];
      const s = data.summary || {};
      state.cashMovementSummary = {
        entriesTotal: Number(s.entriesTotal || 0),
        exitsTotal: Number(s.exitsTotal || 0),
        netTotal: Number(s.netTotal || 0)
      };
    }).fail(function () {
      state.cashMovements = [];
      state.cashMovementSummary = { entriesTotal: 0, exitsTotal: 0, netTotal: 0 };
    });
  }

  function refreshCreditPayments() {
    const params = {
      action: 'credit_payments',
      page: 1,
      pageSize: 100
    };

    if (state.mode === 'cashier') {
      const cashier = currentCashierName();
      if (cashier) {
        params.cashier = cashier;
      }
      if (state.shift.hasOpenShift && state.shift.openedAt) {
        params.datetimeFrom = state.shift.openedAt;
      }
    }

    if (state.mode === 'day') {
      const d = currentDateYmd();
      params.dateFrom = d;
      params.dateTo = d;
    }

    return $.getJSON('../api/shift.php', params).done(function (res) {
      const data = (res && res.ok) ? (res.data || {}) : {};
      state.creditPayments = Array.isArray(data.items) ? data.items : [];
      const s = data.summary || {};
      state.creditPaymentsSummary = {
        total: Number(s.total || 0),
        cashTotal: Number(s.cashTotal || 0)
      };
    }).fail(function () {
      state.creditPayments = [];
      state.creditPaymentsSummary = { total: 0, cashTotal: 0 };
    });
  }

  $(function () {
    if (!isCutPage()) return;
    bind();
    $.when(loadSales(), loadProducts(), loadCustomers(), loadShiftStatus()).done(function () {
      $.when(refreshCashMovements(), refreshCreditPayments()).always(function () {
        render();
      });
    }).fail(function () {
      render();
    });
  });
})();
