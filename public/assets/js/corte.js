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

  const state = {
    mode: 'cashier',
    sales: [],
    products: [],
    customers: []
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

        summary.totalProfit += profit;
        summary.customerProfits[customerId] = (summary.customerProfits[customerId] || 0) + profit;

        const department = product?.department || 'Sin Departamento';
        summary.departmentSales[department] = (summary.departmentSales[department] || 0) + (price * qty);
      });
    });

    return summary;
  }

  function filteredSales() {
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
    const cashBoxRows = [
      { label: 'Fondo de caja', value: 0 },
      { label: 'Ventas en Efectivo', value: summary.methodTotals.cash },
      { label: 'Abonos en efectivo', value: 0 },
      { label: 'Entradas', value: 0 },
      { label: 'Salidas', value: 0 },
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

    renderListOrEmpty('#cut-cash-in-list', [], '- No hubo Entradas en Efectivo -');

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

    renderListOrEmpty('#cut-taxes', [], '- No hubo ventas -');
    renderListOrEmpty('#cut-credit-payments', [], '- No se recibieron pagos de creditos -');

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
  }

  function bind() {
    $('[data-cut-mode]').on('click', function () {
      state.mode = ($(this).data('cut-mode') || 'cashier').toString();
      $('[data-cut-mode]').removeClass('active');
      $(this).addClass('active');
      render();
    });

    $('#cut-print-btn').on('click', function () {
      window.print();
    });

    $('#cut-close-btn').on('click', function () {
      window.alert('El cierre de turno lo dejamos para el siguiente paso.');
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

  $(function () {
    if (!isCutPage()) return;
    bind();
    $.when(loadSales(), loadProducts(), loadCustomers()).done(function () {
      render();
    });
  });
})();
