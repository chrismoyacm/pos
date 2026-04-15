/* global $, window, document, Blob, URL */
(function () {
  'use strict';

  function isSuggestedPurchasesPage() {
    return $('#buy-suggested-module').length > 0;
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
  }

  function formatMoney(n) {
    return '$' + Number(n || 0).toFixed(2);
  }

  function formatDateTime(iso) {
    const d = new Date((iso || '').toString());
    if (Number.isNaN(d.getTime())) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const h = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    return y + '-' + m + '-' + day + ' ' + h + ':' + min;
  }

  function toDateInputValue(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  const state = {
    products: [],
    departments: [],
    providers: [],
    purchaseList: [],
    purchaseOrders: [],
    purchaseHistory: [],
    selectedSuggested: {},
    selectedListIds: [],
    suggestedPage: 1,
    suggestedPageSize: 15,
    suggestedTotal: 0,
    suggestedTotalPages: 1,
    listPage: 1,
    listPageSize: 15,
    listTotal: 0,
    listTotalPages: 1,
    ordersPage: 1,
    ordersPageSize: 15,
    ordersTotal: 0,
    ordersTotalPages: 1,
    providersPage: 1,
    providersPageSize: 15,
    providersTotal: 0,
    providersTotalPages: 1,
    historyPage: 1,
    historyPageSize: 15,
    historyTotal: 0,
    historyTotalPages: 1
  };

  function paginateRows(rows, page, pageSize) {
    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    const safePage = Math.min(Math.max(1, page), totalPages);
    const offset = (safePage - 1) * pageSize;
    return {
      pageRows: rows.slice(offset, offset + pageSize),
      page: safePage,
      total,
      totalPages
    };
  }

  function renderPager($pager, page, totalPages, total, onPrev, onNext) {
    $pager.empty();
    if ($pager.length === 0 || totalPages <= 1) {
      return;
    }

    const $prev = $('<button type="button" class="btn-secondary">Anterior</button>');
    const $next = $('<button type="button" class="btn-secondary">Siguiente</button>');
    $prev.prop('disabled', page <= 1);
    $next.prop('disabled', page >= totalPages);
    $prev.on('click', onPrev);
    $next.on('click', onNext);

    $pager.append($prev);
    $pager.append('<span class="table-pager-status">Página ' + page + ' de ' + totalPages + ' · ' + total + ' registros</span>');
    $pager.append($next);
  }

  function productProvider(product) {
    return (product?.provider || '- Sin Proveedor -').toString();
  }

  function activeSuggestedIds() {
    return Object.keys(state.selectedSuggested).filter(id => Number(state.selectedSuggested[id] || 0) > 0);
  }

  function updateActionButtons() {
    const suggestedCount = activeSuggestedIds().length;
    const listCount = state.selectedListIds.length;
    $('#buy-create-order-btn').prop('disabled', suggestedCount === 0);
    $('#buy-send-list-btn').prop('disabled', suggestedCount === 0);
    $('#buy-list-order-btn').prop('disabled', listCount === 0);
  }

  function setDepartmentOptions() {
    const $sel = $('#buy-department-filter').empty();
    $sel.append('<option value="">- Todos los departamentos -</option>');
    state.departments.forEach(dep => {
      $sel.append(`<option value="${escapeHtml(dep.name)}">${escapeHtml(dep.name)}</option>`);
    });
  }

  function setProviderOptions() {
    const providers = Array.from(new Set(state.products.map(productProvider))).sort((a, b) => a.localeCompare(b, 'es'));
    const $sel = $('#buy-provider-filter').empty();
    $sel.append('<option value="">- Todos los proveedores -</option>');
    providers.forEach(provider => {
      $sel.append(`<option value="${escapeHtml(provider)}">${escapeHtml(provider)}</option>`);
    });
  }

  function suggestedProducts() {
    const department = $('#buy-department-filter').val()?.toString() || '';
    const provider = $('#buy-provider-filter').val()?.toString() || '';

    return state.products.filter(product => {
      const stock = Number(product?.stock || 0);
      const minStock = Number(product?.minStock || 0);
      const suggested = stock <= minStock || stock <= 2;
      const matchesDepartment = department === '' || (product?.department || 'Sin Departamento') === department;
      const matchesProvider = provider === '' || productProvider(product) === provider;
      return suggested && matchesDepartment && matchesProvider;
    });
  }

  function renderTable() {
    const rows = suggestedProducts();
    const pg = paginateRows(rows, state.suggestedPage, state.suggestedPageSize);
    state.suggestedPage = pg.page;
    state.suggestedTotal = pg.total;
    state.suggestedTotalPages = pg.totalPages;

    const $tbody = $('#buy-suggested-body').empty();

    pg.pageRows.forEach(product => {
      const productId = (product.id || '').toString();
      if (!state.selectedSuggested[productId]) {
        const stock = Number(product.stock || 0);
        const minStock = Number(product.minStock || 0);
        state.selectedSuggested[productId] = String(Math.max(1, minStock - stock));
      }
      const checked = Object.prototype.hasOwnProperty.call(state.selectedSuggested, productId);
      const $tr = $(`
        <tr class="${checked ? 'row-selected' : ''}">
          <td class="catalog-center"><input type="checkbox" data-buy-check-id="${escapeHtml(productId)}" ${checked ? 'checked' : ''}></td>
          <td>${escapeHtml(product.barcode || product.id || '')}</td>
          <td>${escapeHtml(product.name || '')}</td>
          <td>${escapeHtml(product.department || 'Sin Departamento')}</td>
          <td class="catalog-center">${escapeHtml(String(product.stock ?? 0))}</td>
          <td class="catalog-center">${escapeHtml(String(product.minStock ?? 0))}</td>
          <td class="catalog-center"><input type="number" min="1" step="1" value="${escapeHtml(String(state.selectedSuggested[productId] || '1'))}" data-buy-qty-id="${escapeHtml(productId)}" class="buy-inline-qty"></td>
          <td>${escapeHtml(productProvider(product))}</td>
        </tr>
      `);
      $tbody.append($tr);
    });

    $('#buy-suggested-body input[data-buy-check-id]').off('change').on('change', function () {
      const id = $(this).data('buy-check-id').toString();
      if ($(this).is(':checked')) {
        if (!state.selectedSuggested[id]) state.selectedSuggested[id] = '1';
      } else {
        delete state.selectedSuggested[id];
      }
      updateActionButtons();
    });

    $('#buy-suggested-body input[data-buy-qty-id]').off('change').on('change', function () {
      const id = $(this).data('buy-qty-id').toString();
      const qty = Math.max(1, parseInt($(this).val().toString(), 10) || 1);
      $(this).val(String(qty));
      state.selectedSuggested[id] = String(qty);
      const $check = $('#buy-suggested-body input[data-buy-check-id="' + id + '"]');
      if ($check.length) {
        $check.prop('checked', true);
      }
      updateActionButtons();
    });

    if (pg.pageRows.length === 0) {
      $tbody.append('<tr><td colspan="8" class="muted">No hay productos sugeridos para mostrar.</td></tr>');
    }

    renderPager(
      $('#buy-suggested-pager'),
      state.suggestedPage,
      state.suggestedTotalPages,
      state.suggestedTotal,
      function () {
        if (state.suggestedPage <= 1) return;
        state.suggestedPage -= 1;
        renderTable();
      },
      function () {
        if (state.suggestedPage >= state.suggestedTotalPages) return;
        state.suggestedPage += 1;
        renderTable();
      }
    );

    updateActionButtons();
  }

  function setProviderFilterFromRegistry() {
    if (!state.providers.length) return;
    const existing = new Set(state.products.map(productProvider));
    state.providers.forEach(p => existing.add((p.name || '').toString()));
    const providers = Array.from(existing).filter(Boolean).sort((a, b) => a.localeCompare(b, 'es'));
    const $sel = $('#buy-provider-filter').empty();
    $sel.append('<option value="">- Todos los proveedores -</option>');
    providers.forEach(provider => {
      $sel.append(`<option value="${escapeHtml(provider)}">${escapeHtml(provider)}</option>`);
    });
  }

  function renderListSection() {
    const pg = paginateRows(state.purchaseList, state.listPage, state.listPageSize);
    state.listPage = pg.page;
    state.listTotal = pg.total;
    state.listTotalPages = pg.totalPages;

    const $tbody = $('#buy-list-body').empty();
    pg.pageRows.forEach(item => {
      const checked = state.selectedListIds.includes(item.id);
      $tbody.append(`
        <tr>
          <td class="catalog-center"><input type="checkbox" data-buy-list-check="${escapeHtml(item.id || '')}" ${checked ? 'checked' : ''}></td>
          <td>${escapeHtml(item.barcode || item.productId || '')}</td>
          <td>${escapeHtml(item.name || '')}</td>
          <td class="catalog-center">${escapeHtml(String(item.qty || 1))}</td>
          <td>${escapeHtml(item.provider || '- Sin Proveedor -')}</td>
          <td><button class="btn-secondary" type="button" data-buy-list-del="${escapeHtml(item.id || '')}">Quitar</button></td>
        </tr>
      `);
    });

    $('#buy-list-body input[data-buy-list-check]').off('change').on('change', function () {
      const id = $(this).data('buy-list-check').toString();
      if ($(this).is(':checked')) {
        if (!state.selectedListIds.includes(id)) state.selectedListIds.push(id);
      } else {
        state.selectedListIds = state.selectedListIds.filter(x => x !== id);
      }
      updateActionButtons();
    });

    $('#buy-list-body button[data-buy-list-del]').off('click').on('click', function () {
      const id = $(this).data('buy-list-del').toString();
      $.ajax({
        url: '../api/compras.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ action: 'remove_purchase_list_item', itemId: id })
      }).done(() => {
        loadPurchaseList();
      });
    });

    if (pg.pageRows.length === 0) {
      $tbody.append('<tr><td colspan="6" class="muted">No hay items en la lista de compras.</td></tr>');
    }

    state.selectedListIds = state.selectedListIds.filter(id => state.purchaseList.some(item => item.id === id));
    renderPager(
      $('#buy-list-pager'),
      state.listPage,
      state.listTotalPages,
      state.listTotal,
      function () {
        if (state.listPage <= 1) return;
        state.listPage -= 1;
        renderListSection();
      },
      function () {
        if (state.listPage >= state.listTotalPages) return;
        state.listPage += 1;
        renderListSection();
      }
    );
    updateActionButtons();
  }

  function renderOrdersSection() {
    const pg = paginateRows(state.purchaseOrders, state.ordersPage, state.ordersPageSize);
    state.ordersPage = pg.page;
    state.ordersTotal = pg.total;
    state.ordersTotalPages = pg.totalPages;

    const $tbody = $('#buy-orders-body').empty();
    pg.pageRows.forEach(order => {
      const canReceive = (order.status || 'pending') === 'pending';
      $tbody.append(`
        <tr>
          <td>${escapeHtml(order.folio || '')}</td>
          <td>${escapeHtml(formatDateTime(order.createdAt || ''))}</td>
          <td>${escapeHtml(order.provider || '- Sin Proveedor -')}</td>
          <td class="catalog-center">${escapeHtml(String((order.items || []).length))}</td>
          <td class="catalog-money">${formatMoney(order.total || 0)}</td>
          <td class="catalog-center">${escapeHtml((order.status || '').toString().toUpperCase())}</td>
          <td><button class="btn-secondary" type="button" data-buy-receive="${escapeHtml(order.id || '')}" ${canReceive ? '' : 'disabled'}>Recibir</button></td>
        </tr>
      `);
    });

    $('#buy-orders-body button[data-buy-receive]').off('click').on('click', function () {
      const orderId = $(this).data('buy-receive').toString();
      $.ajax({
        url: '../api/compras.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ action: 'receive_purchase_order', orderId })
      }).done(res => {
        if (!res.ok) {
          window.alert(res.error || 'No se pudo recibir la orden.');
          return;
        }
        $.when(loadProducts(), loadOrders(), loadHistory()).done();
      });
    });

    if (pg.pageRows.length === 0) {
      $tbody.append('<tr><td colspan="7" class="muted">No hay órdenes de compra para mostrar.</td></tr>');
    }

    renderPager(
      $('#buy-orders-pager'),
      state.ordersPage,
      state.ordersTotalPages,
      state.ordersTotal,
      function () {
        if (state.ordersPage <= 1) return;
        state.ordersPage -= 1;
        renderOrdersSection();
      },
      function () {
        if (state.ordersPage >= state.ordersTotalPages) return;
        state.ordersPage += 1;
        renderOrdersSection();
      }
    );
  }

  function renderProvidersSection() {
    const pg = paginateRows(state.providers, state.providersPage, state.providersPageSize);
    state.providersPage = pg.page;
    state.providersTotal = pg.total;
    state.providersTotalPages = pg.totalPages;

    const $tbody = $('#buy-providers-body').empty();
    pg.pageRows.forEach(provider => {
      $tbody.append(`
        <tr>
          <td>${escapeHtml(provider.name || '')}</td>
          <td>${escapeHtml(provider.phone || '')}</td>
          <td>${escapeHtml(provider.notes || '')}</td>
        </tr>
      `);
    });

    if (pg.pageRows.length === 0) {
      $tbody.append('<tr><td colspan="3" class="muted">No hay proveedores para mostrar.</td></tr>');
    }

    renderPager(
      $('#buy-providers-pager'),
      state.providersPage,
      state.providersTotalPages,
      state.providersTotal,
      function () {
        if (state.providersPage <= 1) return;
        state.providersPage -= 1;
        renderProvidersSection();
      },
      function () {
        if (state.providersPage >= state.providersTotalPages) return;
        state.providersPage += 1;
        renderProvidersSection();
      }
    );
  }

  function filteredHistory() {
    const from = ($('#buy-history-from').val() || '').toString();
    const to = ($('#buy-history-to').val() || '').toString();
    const q = ($('#buy-history-q').val() || '').toString().trim().toLowerCase();
    const fromDate = from ? new Date(from + 'T00:00:00') : null;
    const toDate = to ? new Date(to + 'T23:59:59') : null;

    return state.purchaseHistory.filter(row => {
      const d = new Date((row.createdAt || '').toString());
      if (Number.isNaN(d.getTime())) return false;
      if (fromDate && d < fromDate) return false;
      if (toDate && d > toDate) return false;
      if (!q) return true;
      const hay = ((row.barcode || '') + ' ' + (row.name || '')).toLowerCase();
      return hay.includes(q);
    });
  }

  function renderHistorySection() {
    const rows = filteredHistory();
    const pg = paginateRows(rows, state.historyPage, state.historyPageSize);
    state.historyPage = pg.page;
    state.historyTotal = pg.total;
    state.historyTotalPages = pg.totalPages;

    const $tbody = $('#buy-history-body').empty();
    pg.pageRows.forEach(row => {
      $tbody.append(`
        <tr>
          <td>${escapeHtml(formatDateTime(row.createdAt || ''))}</td>
          <td>${escapeHtml(row.barcode || row.productId || '')}</td>
          <td>${escapeHtml(row.name || '')}</td>
          <td class="catalog-center">${escapeHtml(String(row.qty || 0))}</td>
          <td class="catalog-money">${formatMoney(row.cost || 0)}</td>
          <td class="catalog-money">${formatMoney(Number(row.cost || 0) * Number(row.qty || 0))}</td>
          <td>${escapeHtml(row.provider || '- Sin Proveedor -')}</td>
          <td>${escapeHtml(row.orderFolio || '')}</td>
        </tr>
      `);
    });

    if (pg.pageRows.length === 0) {
      $tbody.append('<tr><td colspan="8" class="muted">No hay compras históricas para mostrar.</td></tr>');
    }

    renderPager(
      $('#buy-history-pager'),
      state.historyPage,
      state.historyTotalPages,
      state.historyTotal,
      function () {
        if (state.historyPage <= 1) return;
        state.historyPage -= 1;
        renderHistorySection();
      },
      function () {
        if (state.historyPage >= state.historyTotalPages) return;
        state.historyPage += 1;
        renderHistorySection();
      }
    );
  }

  function loadProducts() {
    return $.getJSON('../api/compras.php', { action: 'products' }).done(res => {
      state.products = (res.ok && Array.isArray(res.data)) ? res.data : [];
      setProviderOptions();
      renderTable();
    });
  }

  function loadDepartments() {
    return $.getJSON('../api/departments.php').done(res => {
      state.departments = (res.ok && Array.isArray(res.data)) ? res.data : [];
      setDepartmentOptions();
      renderTable();
    });
  }

  function loadProviders() {
    return $.getJSON('../api/compras.php', { action: 'providers' }).done(res => {
      state.providers = (res.ok && Array.isArray(res.data)) ? res.data : [];
      setProviderFilterFromRegistry();
      renderProvidersSection();
    });
  }

  function loadPurchaseList() {
    return $.getJSON('../api/compras.php', { action: 'purchase_list' }).done(res => {
      state.purchaseList = (res.ok && Array.isArray(res.data)) ? res.data : [];
      renderListSection();
    });
  }

  function loadOrders() {
    return $.getJSON('../api/compras.php', { action: 'purchase_orders' }).done(res => {
      state.purchaseOrders = (res.ok && Array.isArray(res.data)) ? res.data : [];
      renderOrdersSection();
    });
  }

  function loadHistory() {
    return $.getJSON('../api/compras.php', { action: 'purchase_history' }).done(res => {
      state.purchaseHistory = (res.ok && Array.isArray(res.data)) ? res.data : [];
      renderHistorySection();
    });
  }

  function sendSuggestedToList() {
    const ids = activeSuggestedIds();
    if (!ids.length) return;
    const items = ids.map(id => {
      const product = state.products.find(p => (p.id || '') === id) || {};
      return {
        productId: id,
        barcode: product.barcode || '',
        name: product.name || '',
        provider: productProvider(product),
        qty: Math.max(1, Number(state.selectedSuggested[id] || 1))
      };
    });

    $.ajax({
      url: '../api/compras.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ action: 'add_purchase_list_items', items })
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo actualizar lista de compra.');
        return;
      }
      window.alert('Productos enviados a lista de compra.');
      loadPurchaseList();
    });
  }

  function createOrderFromSuggested() {
    const ids = activeSuggestedIds();
    if (!ids.length) return;
    const items = ids.map(id => {
      const product = state.products.find(p => (p.id || '') === id) || {};
      return {
        productId: id,
        barcode: product.barcode || '',
        name: product.name || '',
        provider: productProvider(product),
        qty: Math.max(1, Number(state.selectedSuggested[id] || 1)),
        cost: Number(product.cost || 0)
      };
    });

    $.ajax({
      url: '../api/compras.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ action: 'create_purchase_order', source: 'suggested', items })
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo crear la orden de compra.');
        return;
      }
      window.alert('Orden de compra creada: ' + (res.data?.folio || '')); 
      loadOrders();
      loadPurchaseList();
    });
  }

  function createOrderFromList() {
    if (!state.selectedListIds.length) return;
    $.ajax({
      url: '../api/compras.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ action: 'create_purchase_order', source: 'list', listItemIds: state.selectedListIds })
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo crear la orden desde lista.');
        return;
      }
      state.selectedListIds = [];
      window.alert('Orden creada: ' + (res.data?.folio || ''));
      $.when(loadPurchaseList(), loadOrders()).done(() => {
        setSection('orders');
      });
    });
  }

  function saveProvider() {
    const name = ($('#buy-provider-name').val() || '').toString().trim();
    const phone = ($('#buy-provider-phone').val() || '').toString().trim();
    const notes = ($('#buy-provider-notes').val() || '').toString().trim();
    if (!name) {
      window.alert('El nombre del proveedor es requerido.');
      return;
    }

    $.ajax({
      url: '../api/compras.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ action: 'add_provider', name, phone, notes })
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo guardar proveedor.');
        return;
      }
      $('#buy-provider-name').val('');
      $('#buy-provider-phone').val('');
      $('#buy-provider-notes').val('');
      loadProviders();
    });
  }

  function bind() {
    $('[data-buy-nav]').on('click', function () {
      const section = ($(this).data('buy-nav') || 'suggested').toString();
      window.location.href = 'index.php?mod=compras&sub=' + encodeURIComponent(section);
    });

    $('#buy-department-filter').on('change', function () {
      state.suggestedPage = 1;
      renderTable();
    });
    $('#buy-provider-filter').on('change', function () {
      state.suggestedPage = 1;
      renderTable();
    });

    $('#buy-create-order-btn').on('click', function () {
      createOrderFromSuggested();
    });

    $('#buy-send-list-btn').on('click', function () {
      sendSuggestedToList();
    });

    $('#buy-list-refresh-btn').on('click', loadPurchaseList);
    $('#buy-list-order-btn').on('click', createOrderFromList);
    $('#buy-orders-refresh-btn').on('click', loadOrders);

    $('#buy-provider-save-btn').on('click', saveProvider);

    $('#buy-history-refresh-btn').on('click', function () {
      state.historyPage = 1;
      renderHistorySection();
    });
    $('#buy-history-from, #buy-history-to').on('change', function () {
      state.historyPage = 1;
      renderHistorySection();
    });
    $('#buy-history-q').on('input', function () {
      state.historyPage = 1;
      renderHistorySection();
    });
  }

  $(function () {
    if (!isSuggestedPurchasesPage()) return;

    const now = new Date();
    const since = new Date(now);
    since.setDate(since.getDate() - 60);
    $('#buy-history-from').val(toDateInputValue(since));
    $('#buy-history-to').val(toDateInputValue(now));

    bind();

    const url = new URL(window.location.href);
    const sub = (url.searchParams.get('sub') || 'suggested').toString();

    if (sub === 'suggested') {
      $.when(loadDepartments(), loadProducts(), loadProviders()).done(renderTable);
    } else if (sub === 'list') {
      $.when(loadProviders(), loadPurchaseList()).done(renderListSection);
    } else if (sub === 'orders') {
      loadOrders().done(renderOrdersSection);
    } else if (sub === 'providers') {
      loadProviders().done(renderProvidersSection);
    } else if (sub === 'history') {
      loadProducts();
      loadHistory().done(renderHistorySection);
    }
  });
})();
