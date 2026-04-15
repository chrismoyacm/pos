/* global $, window, document, Blob, URL */
(function () {
  'use strict';

  function isInventoryPage() {
    return $('#inv-report-module').length > 0;
  }

  function escapeHtmlInv(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
  }

  function formatMoneyInv(n) {
    return '$' + Number(n || 0).toFixed(2);
  }

  function formatDateTimeInv(iso) {
    const d = new Date((iso || '').toString());
    if (Number.isNaN(d.getTime())) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const h = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    return y + '-' + m + '-' + day + ' ' + h + ':' + min;
  }

  function toDateInputValueInv(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function movementTypeLabelInv(type) {
    if (type === 'entry') return 'Entrada';
    if (type === 'exit') return 'Salida';
    if (type === 'sale') return 'Venta';
    return 'Ajuste';
  }

  const invState = {
    products: [],
    departments: [],
    movements: [],
    reportRows: null,
    reportSummary: null,
    selectedId: null,
    addSelectedId: null,
    adjustSelectedId: null,
    addSuggestions: [],
    addSuggestionIndex: -1,
    adjustSuggestions: [],
    adjustSuggestionIndex: -1,
    reportPage: 1,
    reportPageSize: 25,
    reportTotal: 0,
    reportTotalPages: 1
  };

  function showNoticeInv(message, type) {
    const text = (message || '').toString().trim();
    if (!text) return;

    let host = document.getElementById('app-notices');
    if (!host) {
      host = document.createElement('div');
      host.id = 'app-notices';
      host.className = 'app-notices';
      document.body.appendChild(host);
    }

    const item = document.createElement('div');
    item.className = 'app-notice app-notice--' + ((type || 'info').toString());
    item.textContent = text;
    host.appendChild(item);

    window.setTimeout(function () {
      item.classList.add('is-leaving');
      window.setTimeout(function () {
        if (item.parentNode) item.parentNode.removeChild(item);
      }, 220);
    }, 2600);
  }

  function productByIdInv(id) {
    return invState.products.find(p => (p.id || '') === id) || null;
  }

  function setProductSelectOptionsInv() {
    const options = invState.products
      .map(product => {
        const label = (product.barcode || product.id || '') + ' - ' + (product.name || 'Producto');
        return '<option value="' + escapeHtmlInv(product.id || '') + '">' + escapeHtmlInv(label) + '</option>';
      })
      .join('');

    $('#inv-add-product').html(options);
    $('#inv-kardex-product').html('<option value="">Seleccione producto...</option>' + options);
  }

  function deriveMarginInv(product) {
    const stored = Number(product?.margin || 0);
    if (stored > 0) return stored;
    const cost = Number(product?.cost || 0);
    const price = Number(product?.price || 0);
    if (cost <= 0 || price <= 0) return 0;
    return ((price - cost) / cost) * 100;
  }

  function resetAdjustFormInv() {
    invState.adjustSelectedId = null;
    $('#inv-adjust-name').val('');
    $('#inv-adjust-current-stock').val('');
    $('#inv-adjust-new-stock').val('');
    $('#inv-adjust-entry-cost').val('0.00');
    $('#inv-adjust-new-cost').val('');
    $('#inv-adjust-margin').val('');
    $('#inv-adjust-new-price').val('');
    clearAdjustSuggestionsInv();
  }

  function clearAdjustSuggestionsInv() {
    invState.adjustSuggestions = [];
    invState.adjustSuggestionIndex = -1;
    $('#inv-adjust-suggest').prop('hidden', true).empty();
  }

  function adjustSuggestionsByQueryInv(query) {
    const q = (query || '').toString().trim().toLowerCase();
    if (!q) return [];
    const matched = invState.products.filter(product => {
      const barcode = (product.barcode || '').toString().toLowerCase();
      const id = (product.id || '').toString().toLowerCase();
      const name = (product.name || '').toString().toLowerCase();
      return barcode.includes(q) || id.includes(q) || name.includes(q);
    });
    return matched.slice(0, 15);
  }

  function selectAdjustSuggestionInv(index) {
    const product = invState.adjustSuggestions[index] || null;
    if (!product) return;
    const code = (product.barcode || product.id || '').toString();
    invState.adjustSelectedId = (product.id || '').toString();
    $('#inv-adjust-code').val(code);
    $('#inv-adjust-entry-cost').val(Number(product.cost || 0).toFixed(2));
    updateAdjustPreviewInv();
    clearAdjustSuggestionsInv();
  }

  function renderAdjustSuggestionsInv() {
    const $box = $('#inv-adjust-suggest').empty();
    if (!Array.isArray(invState.adjustSuggestions) || invState.adjustSuggestions.length === 0) {
      $box.prop('hidden', true);
      return;
    }

    invState.adjustSuggestions.forEach((product, index) => {
      const isActive = index === invState.adjustSuggestionIndex;
      const code = (product.barcode || product.id || '').toString();
      const name = (product.name || 'Producto').toString();
      const $item = $(
        '<div class="inv-add-suggest-item ' + (isActive ? 'active' : '') + '" data-adjust-suggest-idx="' + index + '">' +
          '<span class="inv-add-suggest-code">' + escapeHtmlInv(code) + '</span>' +
          '<span class="inv-add-suggest-name">' + escapeHtmlInv(name) + '</span>' +
        '</div>'
      );
      $item.on('click', function () {
        selectAdjustSuggestionInv(index);
      });
      $box.append($item);
    });

    $box.prop('hidden', false);
  }

  function findAdjustProductInv(query) {
    const q = (query || '').toString().trim().toLowerCase();
    if (!q) return null;

    const exact = invState.products.find(product => {
      const barcode = (product.barcode || '').toString().toLowerCase();
      const id = (product.id || '').toString().toLowerCase();
      return barcode === q || id === q;
    });
    if (exact) return exact;

    const matched = invState.products.filter(product => {
      const barcode = (product.barcode || '').toString().toLowerCase();
      const id = (product.id || '').toString().toLowerCase();
      const name = (product.name || '').toString().toLowerCase();
      return barcode.includes(q) || id.includes(q) || name.includes(q);
    });

    return matched.length === 1 ? matched[0] : null;
  }

  function computeAdjustPreviewInv(product, kind, qty, entryCost, marginOverride) {
    const currentStock = Number(product?.stock || 0);
    const currentCost = Number(product?.cost || 0);
    const parsedMargin = Number(marginOverride);
    const margin = Number.isFinite(parsedMargin) && parsedMargin >= 0
      ? parsedMargin
      : deriveMarginInv(product);
    const absQty = Math.max(0, Math.floor(Number(qty || 0)));
    const isExit = kind === 'exit';
    const newStock = isExit
      ? Math.max(0, currentStock - absQty)
      : Math.max(0, currentStock + absQty);

    let newCost = currentCost;
    if (!isExit && absQty > 0 && Number(entryCost) > 0 && newStock > 0) {
      newCost = ((currentStock * currentCost) + (absQty * Number(entryCost))) / newStock;
    }

    const newPrice = Math.max(0, newCost * (1 + (margin / 100)));
    return {
      currentStock,
      currentCost,
      margin,
      newStock,
      newCost,
      newPrice
    };
  }

  function updateAdjustPreviewInv() {
    const product = productByIdInv((invState.adjustSelectedId || '').toString());
    if (!product) {
      resetAdjustFormInv();
      return;
    }

    const kind = ($('#inv-adjust-type').val() || 'entry').toString();
    const qty = Number($('#inv-adjust-qty').val() || 0);
    const entryCost = Number($('#inv-adjust-entry-cost').val() || 0);
    const marginRaw = ($('#inv-adjust-margin').val() || '').toString().trim();
    const marginInput = marginRaw === '' ? Number.NaN : Number(marginRaw);
    const preview = computeAdjustPreviewInv(product, kind, qty, entryCost, marginInput);

    $('#inv-adjust-name').val((product.name || '').toString());
    $('#inv-adjust-current-stock').val(String(preview.currentStock));
    $('#inv-adjust-new-stock').val(String(preview.newStock));
    $('#inv-adjust-new-cost').val(formatMoneyInv(preview.newCost));
    const marginEl = $('#inv-adjust-margin').get(0);
    if (!marginEl || document.activeElement !== marginEl) {
      $('#inv-adjust-margin').val(Number(preview.margin || 0).toFixed(2));
    }
    $('#inv-adjust-new-price').val(formatMoneyInv(preview.newPrice));
    $('#inv-adjust-entry-cost').prop('disabled', kind === 'exit');
  }

  function loadAdjustFormByCodeInv() {
    const query = ($('#inv-adjust-code').val() || '').toString().trim();
    if (!query) {
      window.alert('Ingrese un código o ID de producto.');
      return;
    }

    const product = findAdjustProductInv(query);
    if (product) {
      invState.adjustSelectedId = (product.id || '').toString();
      const code = (product.barcode || product.id || '').toString();
      $('#inv-adjust-code').val(code);
      $('#inv-adjust-entry-cost').val(Number(product.cost || 0).toFixed(2));
      updateAdjustPreviewInv();
      clearAdjustSuggestionsInv();
      return;
    }

    const suggestions = adjustSuggestionsByQueryInv(query);
    if (suggestions.length === 0) {
      window.alert('Producto no encontrado.');
      resetAdjustFormInv();
      return;
    }

    invState.adjustSuggestions = suggestions;
    invState.adjustSuggestionIndex = 0;
    renderAdjustSuggestionsInv();
  }

  function resetAddFormInv() {
    invState.addSelectedId = null;
    $('#inv-add-name').val('');
    $('#inv-add-stock').val('');
    $('#inv-add-cost').val('0.00');
    $('#inv-add-price').val('0.00');
    $('#inv-add-wholesale').val('');
    clearAddSuggestionsInv();
  }

  function fillAddFormInv(product) {
    if (!product) {
      resetAddFormInv();
      return;
    }
    invState.addSelectedId = (product.id || '').toString();
    $('#inv-add-name').val((product.name || '').toString());
    const stockValue = (product.stock === null || product.stock === undefined) ? '' : String(product.stock);
    $('#inv-add-stock').val(stockValue);
    $('#inv-add-cost').val(Number(product.cost || 0).toFixed(2));
    $('#inv-add-price').val(Number(product.price || 0).toFixed(2));
    const wholesale = product.wholesale && Number(product.wholesale.price || 0) > 0
      ? Number(product.wholesale.price || 0).toFixed(2)
      : '';
    $('#inv-add-wholesale').val(wholesale);
    clearAddSuggestionsInv();
  }

  function clearAddSuggestionsInv() {
    invState.addSuggestions = [];
    invState.addSuggestionIndex = -1;
    $('#inv-add-suggest').prop('hidden', true).empty();
  }

  function renderAddSuggestionsInv() {
    const $box = $('#inv-add-suggest').empty();
    if (!Array.isArray(invState.addSuggestions) || invState.addSuggestions.length === 0) {
      $box.prop('hidden', true);
      return;
    }

    invState.addSuggestions.forEach((product, index) => {
      const isActive = index === invState.addSuggestionIndex;
      const code = (product.barcode || product.id || '').toString();
      const name = (product.name || 'Producto').toString();
      const $item = $(
        '<div class="inv-add-suggest-item ' + (isActive ? 'active' : '') + '" data-suggest-idx="' + index + '">' +
          '<span class="inv-add-suggest-code">' + escapeHtmlInv(code) + '</span>' +
          '<span class="inv-add-suggest-name">' + escapeHtmlInv(name) + '</span>' +
        '</div>'
      );
      $item.on('click', function () {
        selectAddSuggestionInv(index);
      });
      $box.append($item);
    });

    $box.prop('hidden', false);
  }

  function selectAddSuggestionInv(index) {
    const product = invState.addSuggestions[index] || null;
    if (!product) return;
    const code = (product.barcode || product.id || '').toString();
    $('#inv-add-code').val(code);
    fillAddFormInv(product);
  }

  function addSuggestionsByQueryInv(query) {
    const q = (query || '').toString().trim().toLowerCase();
    if (!q) return [];
    const matched = invState.products.filter(product => {
      const barcode = (product.barcode || '').toString().toLowerCase();
      const id = (product.id || '').toString().toLowerCase();
      const name = (product.name || '').toString().toLowerCase();
      return barcode.includes(q) || id.includes(q) || name.includes(q);
    });
    return matched.slice(0, 15);
  }

  function findAddProductInv(query) {
    const q = (query || '').toString().trim().toLowerCase();
    if (!q) return null;

    const exact = invState.products.find(product => {
      const barcode = (product.barcode || '').toString().toLowerCase();
      const id = (product.id || '').toString().toLowerCase();
      return barcode === q || id === q;
    });
    if (exact) return exact;

    const byName = invState.products.filter(product => {
      const barcode = (product.barcode || '').toString().toLowerCase();
      const id = (product.id || '').toString().toLowerCase();
      const name = (product.name || '').toString().toLowerCase();
      return barcode.includes(q) || id.includes(q) || name.includes(q);
    });

    if (byName.length === 1) return byName[0];
    return null;
  }

  function loadAddFormByCodeInv() {
    const query = ($('#inv-add-code').val() || '').toString().trim();
    if (!query) {
      window.alert('Ingrese un código o ID de producto.');
      return;
    }
    const product = findAddProductInv(query);
    if (product) {
      fillAddFormInv(product);
      return;
    }

    const suggestions = addSuggestionsByQueryInv(query);
    if (suggestions.length === 0) {
      window.alert('Producto no encontrado.');
      resetAddFormInv();
      return;
    }

    invState.addSuggestions = suggestions;
    invState.addSuggestionIndex = 0;
    renderAddSuggestionsInv();
  }

  function setDepartmentOptionsInv() {
    const $sel = $('#inv-department-filter').empty();
    $sel.append('<option value="">-Todos-</option>');
    invState.departments.forEach(dep => {
      $sel.append('<option value="' + escapeHtmlInv(dep.name) + '">' + escapeHtmlInv(dep.name) + '</option>');
    });
  }

  function filteredProductsInv() {
    const department = ($('#inv-department-filter').val() || '').toString();
    return invState.products.filter(product => {
      const dep = (product.department || 'Sin Departamento').toString();
      return !department || dep === department;
    });
  }

  function renderSummaryInv(rows) {
    const totalCost = rows.reduce((sum, p) => sum + Number(p.cost || 0) * Number(p.stock || 0), 0);
    const totalStock = rows.reduce((sum, p) => sum + Number(p.stock || 0), 0);
    $('#inv-total-cost').text(formatMoneyInv(totalCost));
    $('#inv-total-stock').text(String(totalStock));
  }

  function renderInventoryTableInv() {
    const rows = Array.isArray(invState.reportRows) ? invState.reportRows : filteredProductsInv();
    const $tbody = $('#inv-report-body').empty();

    rows.forEach(product => {
      const selected = (product.id || '') === invState.selectedId;
      const $tr = $(
        '<tr class="' + (selected ? 'row-selected' : '') + '">' +
          '<td>' + escapeHtmlInv(product.barcode || product.id || '') + '</td>' +
          '<td>' + escapeHtmlInv(product.name || '') + '</td>' +
          '<td class="catalog-money">' + formatMoneyInv(product.cost || 0) + '</td>' +
          '<td class="catalog-money">' + formatMoneyInv(product.price || 0) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(product.stock ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(product.minStock ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(product.maxStock ?? 0)) + '</td>' +
        '</tr>'
      );

      $tr.on('click', function () {
        invState.selectedId = product.id;
        $('#inv-modify-btn').prop('disabled', false);
        renderInventoryTableInv();
      });

      $tbody.append($tr);
    });

    if (!rows.some(product => (product.id || '') === invState.selectedId)) {
      invState.selectedId = null;
      $('#inv-modify-btn').prop('disabled', true);
    }

    if (invState.reportSummary && typeof invState.reportSummary === 'object') {
      $('#inv-total-cost').text(formatMoneyInv(Number(invState.reportSummary.totalCost || 0)));
      $('#inv-total-stock').text(String(Number(invState.reportSummary.totalStock || 0)));
    } else {
      renderSummaryInv(rows);
    }
    renderReportPagerInv();
  }

  function renderReportPagerInv() {
    const $pager = $('#inv-report-pager').empty();
    if (invState.reportTotalPages <= 1) {
      return;
    }

    const $prev = $('<button type="button" class="btn-secondary">Anterior</button>');
    const $next = $('<button type="button" class="btn-secondary">Siguiente</button>');
    $prev.prop('disabled', invState.reportPage <= 1);
    $next.prop('disabled', invState.reportPage >= invState.reportTotalPages);
    $prev.on('click', function () {
      if (invState.reportPage <= 1) return;
      invState.reportPage -= 1;
      loadReportProductsInv();
    });
    $next.on('click', function () {
      if (invState.reportPage >= invState.reportTotalPages) return;
      invState.reportPage += 1;
      loadReportProductsInv();
    });

    $pager.append($prev);
    $pager.append('<span class="table-pager-status">Página ' + invState.reportPage + ' de ' + invState.reportTotalPages + ' · ' + invState.reportTotal + ' registros</span>');
    $pager.append($next);
  }

  function renderLowStockInv() {
    const rows = invState.products
      .filter(p => Number(p.stock || 0) <= Number(p.minStock || 0))
      .sort((a, b) => Number(a.stock || 0) - Number(b.stock || 0));
    const $tbody = $('#inv-low-body').empty();

    rows.forEach(product => {
      const missing = Math.max(0, Number(product.minStock || 0) - Number(product.stock || 0));
      $tbody.append(
        '<tr>' +
          '<td>' + escapeHtmlInv(product.barcode || product.id || '') + '</td>' +
          '<td>' + escapeHtmlInv(product.name || '') + '</td>' +
          '<td>' + escapeHtmlInv(product.department || 'Sin Departamento') + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(product.stock ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(product.minStock ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(missing)) + '</td>' +
        '</tr>'
      );
    });
  }

  function filteredMovementsInv() {
    const from = ($('#inv-mov-from').val() || '').toString();
    const to = ($('#inv-mov-to').val() || '').toString();
    const type = ($('#inv-mov-type').val() || '').toString();
    const fromDate = from ? new Date(from + 'T00:00:00') : null;
    const toDate = to ? new Date(to + 'T23:59:59') : null;

    return invState.movements.filter(mv => {
      const d = new Date((mv.createdAt || '').toString());
      if (Number.isNaN(d.getTime())) return false;
      if (fromDate && d < fromDate) return false;
      if (toDate && d > toDate) return false;
      if (type && (mv.type || '') !== type) return false;
      return true;
    });
  }

  function renderMovementsInv() {
    const rows = filteredMovementsInv().sort((a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || '')));
    const $tbody = $('#inv-mov-body').empty();
    rows.forEach(mv => {
      const product = productByIdInv((mv.productId || '').toString());
      $tbody.append(
        '<tr>' +
          '<td>' + escapeHtmlInv(formatDateTimeInv(mv.createdAt)) + '</td>' +
          '<td>' + escapeHtmlInv(product?.barcode || mv.productId || '') + '</td>' +
          '<td>' + escapeHtmlInv(product?.name || mv.productName || '') + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(movementTypeLabelInv(mv.type || 'adjust')) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(mv.delta ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(mv.before ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(mv.after ?? 0)) + '</td>' +
          '<td>' + escapeHtmlInv(mv.note || '') + '</td>' +
        '</tr>'
      );
    });
  }

  function renderKardexInv() {
    const productId = ($('#inv-kardex-product').val() || '').toString();
    const from = ($('#inv-kardex-from').val() || '').toString();
    const to = ($('#inv-kardex-to').val() || '').toString();
    const fromDate = from ? new Date(from + 'T00:00:00') : null;
    const toDate = to ? new Date(to + 'T23:59:59') : null;

    const rows = invState.movements.filter(mv => {
      if (!productId || (mv.productId || '') !== productId) return false;
      const d = new Date((mv.createdAt || '').toString());
      if (Number.isNaN(d.getTime())) return false;
      if (fromDate && d < fromDate) return false;
      if (toDate && d > toDate) return false;
      return true;
    }).sort((a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || '')));

    const $tbody = $('#inv-kardex-body').empty();
    rows.forEach(mv => {
      $tbody.append(
        '<tr>' +
          '<td>' + escapeHtmlInv(formatDateTimeInv(mv.createdAt)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(movementTypeLabelInv(mv.type || 'adjust')) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(mv.delta ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(mv.before ?? 0)) + '</td>' +
          '<td class="catalog-center">' + escapeHtmlInv(String(mv.after ?? 0)) + '</td>' +
          '<td>' + escapeHtmlInv(mv.note || '') + '</td>' +
        '</tr>'
      );
    });
  }

  function exportInventoryInv() {
    const rows = Array.isArray(invState.reportRows) ? invState.reportRows : filteredProductsInv();
    const headers = ['Codigo', 'Descripcion del Producto', 'Costo', 'Precio Venta', 'Existencia', 'Inventario Minimo', 'Inventario Maximo'];
    const lines = [headers.join(',')];
    rows.forEach(product => {
      const values = [
        product.barcode || product.id || '',
        product.name || '',
        Number(product.cost || 0).toFixed(2),
        Number(product.price || 0).toFixed(2),
        String(product.stock ?? 0),
        String(product.minStock ?? 0),
        String(product.maxStock ?? 0)
      ].map(value => '"' + String(value).replace(/"/g, '""') + '"');
      lines.push(values.join(','));
    });

    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'reporte_inventario.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  function loadProductsInv() {
    return $.getJSON('../api/inventario.php', { action: 'products' }).done(res => {
      invState.products = (res.ok && Array.isArray(res.data)) ? res.data : [];
      setProductSelectOptionsInv();
      if (invState.addSelectedId) {
        fillAddFormInv(productByIdInv(invState.addSelectedId));
      }
      if (invState.adjustSelectedId) {
        updateAdjustPreviewInv();
      }
      renderInventoryTableInv();
      renderLowStockInv();
    });
  }

  function loadReportProductsInv() {
    return $.getJSON('../api/inventario.php', {
      action: 'products_report',
      department: ($('#inv-department-filter').val() || '').toString(),
      page: invState.reportPage,
      pageSize: invState.reportPageSize
    }).done(res => {
      const payload = (res.ok && res.data && typeof res.data === 'object') ? res.data : { items: [], summary: null, pagination: null };
      invState.reportRows = Array.isArray(payload.items) ? payload.items : [];
      invState.reportSummary = payload.summary && typeof payload.summary === 'object' ? payload.summary : null;
      invState.reportTotal = Number(payload.pagination?.total || invState.reportRows.length || 0);
      invState.reportTotalPages = Number(payload.pagination?.totalPages || 1);
      invState.reportPage = Number(payload.pagination?.page || 1);
      renderInventoryTableInv();
    });
  }

  function printInventoryTableInv() {
    const rows = Array.isArray(invState.reportRows) ? invState.reportRows : [];
    if (rows.length === 0) {
      showNoticeInv('No hay datos para imprimir.', 'warning');
      return;
    }

    const bodyRows = rows.map(product => (
      '<tr>' +
      '<td>' + escapeHtmlInv(product.barcode || product.id || '') + '</td>' +
      '<td>' + escapeHtmlInv(product.name || '') + '</td>' +
      '<td style="text-align:right">' + formatMoneyInv(product.cost || 0) + '</td>' +
      '<td style="text-align:right">' + formatMoneyInv(product.price || 0) + '</td>' +
      '<td style="text-align:center">' + escapeHtmlInv(String(product.stock ?? 0)) + '</td>' +
      '<td style="text-align:center">' + escapeHtmlInv(String(product.minStock ?? 0)) + '</td>' +
      '<td style="text-align:center">' + escapeHtmlInv(String(product.maxStock ?? 0)) + '</td>' +
      '</tr>'
    )).join('');

    const html = '<!doctype html><html><head><meta charset="utf-8"><title>Reporte de Inventario</title><style>' +
      'body{font-family:Segoe UI,Arial,sans-serif;margin:20px;color:#111827}' +
      'table{width:100%;border-collapse:collapse;font-size:12px}' +
      'th,td{border:1px solid #cbd5e1;padding:6px 8px}' +
      'thead th{background:#eef2ff}' +
      'h1{font-size:18px;margin:0 0 12px}' +
      '</style></head><body>' +
      '<table><thead><tr><th>Código</th><th>Descripción del Producto</th><th>Costo</th><th>Precio Venta</th><th>Existencia</th><th>Inventario Mínimo</th><th>Inventario Máximo</th></tr></thead><tbody>' + bodyRows + '</tbody></table>' +
      '</body></html>';

    const win = window.open('', '_blank', 'width=960,height=700');
    if (!win) {
      showNoticeInv('No se pudo abrir la ventana de impresión.', 'error');
      return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
    win.focus();
    win.print();
  }

  function loadDepartmentsInv() {
    return $.getJSON('../api/departments.php').done(res => {
      invState.departments = (res.ok && Array.isArray(res.data)) ? res.data : [];
      setDepartmentOptionsInv();
      renderInventoryTableInv();
    });
  }

  function loadMovementsInv() {
    return $.getJSON('../api/inventario.php', { action: 'inventory_movements' }).done(res => {
      invState.movements = (res.ok && Array.isArray(res.data)) ? res.data : [];
      renderMovementsInv();
      renderKardexInv();
    });
  }

  function applyMovementInv(productId, delta, type, note, entryCost, marginPct, productUpdates) {
    const resolvedEntryCost = Number.isFinite(Number(entryCost))
      ? Number(entryCost)
      : Number($('#inv-adjust-entry-cost').val() || 0);
    const resolvedMarginPct = Number.isFinite(Number(marginPct))
      ? Number(marginPct)
      : null;
    const updates = (productUpdates && typeof productUpdates === 'object') ? productUpdates : {};
    return $.ajax({
      url: '../api/inventario.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        action: 'adjust_stock',
        productId,
        delta,
        movementType: type,
        entryUnitCost: resolvedEntryCost,
        marginPct: resolvedMarginPct,
        productName: (updates.name || '').toString(),
        salePrice: Number.isFinite(Number(updates.salePrice)) ? Number(updates.salePrice) : null,
        wholesalePrice: Number.isFinite(Number(updates.wholesalePrice)) ? Number(updates.wholesalePrice) : null,
        note: note || '',
        source: 'inventario'
      })
    });
  }

  function setDefaultRangesInv() {
    const today = new Date();
    const monthStart = new Date(today);
    monthStart.setDate(monthStart.getDate() - 30);
    $('#inv-mov-from').val(toDateInputValueInv(monthStart));
    $('#inv-mov-to').val(toDateInputValueInv(today));
    $('#inv-kardex-from').val(toDateInputValueInv(monthStart));
    $('#inv-kardex-to').val(toDateInputValueInv(today));
  }

  function bindInventoryInv() {
    $('[data-inv-nav]').on('click', function () {
      const section = ($(this).data('inv-nav') || 'reporte').toString();
      window.location.href = 'index.php?mod=inventario&sub=' + encodeURIComponent(section);
    });

    $('#inv-department-filter').on('change', function () {
      invState.reportPage = 1;
      loadReportProductsInv();
    });
    $('#inv-modify-btn').on('click', function () {
      if (!invState.selectedId) return;
      window.location.href = 'index.php?mod=productos&sub=modify&pid=' + encodeURIComponent(invState.selectedId);
    });
    $('#inv-export-btn').on('click', exportInventoryInv);
    $('#inv-print-btn').on('click', printInventoryTableInv);

    $('#inv-add-save-btn').on('click', function () {
      const productId = (invState.addSelectedId || '').toString();
      const qty = Number($('#inv-add-qty').val() || 0);
      const addCost = Number($('#inv-add-cost').val() || 0);
      const addName = ($('#inv-add-name').val() || '').toString().trim();
      const addSalePrice = Number($('#inv-add-price').val() || 0);
      const wholesaleRaw = ($('#inv-add-wholesale').val() || '').toString().trim();
      const addWholesale = wholesaleRaw === '' ? null : Number(wholesaleRaw);
      const note = ($('#inv-add-note').val() || '').toString().trim();
      if (!productId || qty <= 0) {
        window.alert('Busque/cargue un producto y cantidad válida.');
        return;
      }
      if (addCost <= 0) {
        window.alert('Capture un precio costo válido.');
        return;
      }
      if (!addName) {
        window.alert('Capture una descripción válida.');
        return;
      }
      if (!Number.isFinite(addSalePrice) || addSalePrice < 0) {
        window.alert('Capture un precio de venta válido.');
        return;
      }
      if (addWholesale !== null && (!Number.isFinite(addWholesale) || addWholesale < 0)) {
        window.alert('Capture un precio mayoreo válido.');
        return;
      }
      applyMovementInv(productId, Math.abs(qty), 'entry', note, addCost, null, {
        name: addName,
        salePrice: addSalePrice,
        wholesalePrice: addWholesale
      }).done(res => {
        if (!res.ok) {
          showNoticeInv(res.error || 'No se pudo registrar la entrada.', 'error');
          return;
        }
        $('#inv-add-qty').val('1');
        $('#inv-add-note').val('');
        showNoticeInv('Entrada registrada correctamente.', 'success');
        $.when(loadProductsInv(), loadMovementsInv(), loadReportProductsInv()).done(renderInventoryTableInv);
      });
    });

    $('#inv-add-load-btn').on('click', loadAddFormByCodeInv);
    $('#inv-add-code').on('input', function () {
      const query = ($(this).val() || '').toString().trim();
      if (!query) {
        clearAddSuggestionsInv();
        return;
      }

      const exact = findAddProductInv(query);
      if (exact) {
        fillAddFormInv(exact);
        clearAddSuggestionsInv();
        return;
      }

      // Keep stock/value fields blank until user loads an existing product.
      invState.addSelectedId = null;
      $('#inv-add-stock').val('');

      invState.addSuggestions = addSuggestionsByQueryInv(query);
      invState.addSuggestionIndex = invState.addSuggestions.length > 0 ? 0 : -1;
      renderAddSuggestionsInv();
    });
    $('#inv-add-code').on('keydown', function (e) {
      if (e.key === 'ArrowDown' && invState.addSuggestions.length > 0) {
        e.preventDefault();
        invState.addSuggestionIndex = Math.min(invState.addSuggestions.length - 1, invState.addSuggestionIndex + 1);
        renderAddSuggestionsInv();
        return;
      }
      if (e.key === 'ArrowUp' && invState.addSuggestions.length > 0) {
        e.preventDefault();
        invState.addSuggestionIndex = Math.max(0, invState.addSuggestionIndex - 1);
        renderAddSuggestionsInv();
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (invState.addSuggestions.length > 0 && invState.addSuggestionIndex >= 0) {
          selectAddSuggestionInv(invState.addSuggestionIndex);
          return;
        }
        loadAddFormByCodeInv();
      }
      if (e.key === 'Escape') {
        clearAddSuggestionsInv();
      }
    });

    $(document).on('click', function (e) {
      if (!$(e.target).closest('#inv-add-code, #inv-add-suggest').length) {
        clearAddSuggestionsInv();
      }
    });

    $('#inv-adjust-save-btn').on('click', function () {
      const productId = (invState.adjustSelectedId || '').toString();
      const kind = ($('#inv-adjust-type').val() || 'entry').toString();
      const qty = Number($('#inv-adjust-qty').val() || 0);
      const entryCost = Number($('#inv-adjust-entry-cost').val() || 0);
      const marginPct = Number($('#inv-adjust-margin').val() || 0);
      const note = ($('#inv-adjust-note').val() || '').toString().trim();
      if (!productId || qty <= 0) {
        window.alert('Busque/cargue un producto y capture cantidad válida.');
        return;
      }
      if (marginPct < 0) {
        window.alert('Capture un % de ganancia válido.');
        return;
      }
      if (kind === 'entry' && entryCost <= 0) {
        window.alert('Capture un costo de entrada válido para calcular costo promedio y precio venta.');
        return;
      }
      const delta = kind === 'exit' ? -Math.abs(qty) : Math.abs(qty);
      applyMovementInv(productId, delta, kind, note, entryCost, marginPct, null).done(res => {
        if (!res.ok) {
          showNoticeInv(res.error || 'No se pudo aplicar el ajuste.', 'error');
          return;
        }
        $('#inv-adjust-qty').val('1');
        $('#inv-adjust-note').val('');
        showNoticeInv('Ajuste aplicado correctamente.', 'success');
        $.when(loadProductsInv(), loadMovementsInv(), loadReportProductsInv()).done(() => {
          updateAdjustPreviewInv();
          renderInventoryTableInv();
          renderLowStockInv();
        });
      });
    });

    $('#inv-adjust-load-btn').on('click', loadAdjustFormByCodeInv);
    $('#inv-adjust-code').on('input', function () {
      const query = ($(this).val() || '').toString().trim();
      if (!query) {
        clearAdjustSuggestionsInv();
        return;
      }

      const exact = findAdjustProductInv(query);
      if (exact) {
        clearAdjustSuggestionsInv();
        return;
      }

      invState.adjustSuggestions = adjustSuggestionsByQueryInv(query);
      invState.adjustSuggestionIndex = invState.adjustSuggestions.length > 0 ? 0 : -1;
      renderAdjustSuggestionsInv();
    });
    $('#inv-adjust-code').on('keydown', function (e) {
      if (e.key === 'ArrowDown' && invState.adjustSuggestions.length > 0) {
        e.preventDefault();
        invState.adjustSuggestionIndex = Math.min(invState.adjustSuggestions.length - 1, invState.adjustSuggestionIndex + 1);
        renderAdjustSuggestionsInv();
        return;
      }
      if (e.key === 'ArrowUp' && invState.adjustSuggestions.length > 0) {
        e.preventDefault();
        invState.adjustSuggestionIndex = Math.max(0, invState.adjustSuggestionIndex - 1);
        renderAdjustSuggestionsInv();
        return;
      }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (invState.adjustSuggestions.length > 0 && invState.adjustSuggestionIndex >= 0) {
          selectAdjustSuggestionInv(invState.adjustSuggestionIndex);
          return;
        }
        loadAdjustFormByCodeInv();
      }
      if (e.key === 'Escape') {
        clearAdjustSuggestionsInv();
      }
    });
    $('#inv-adjust-type, #inv-adjust-qty, #inv-adjust-entry-cost, #inv-adjust-margin').on('input change', updateAdjustPreviewInv);
    $('#inv-adjust-margin').on('focus', function () {
      this.select();
    });
    $('#inv-adjust-margin').on('blur', function () {
      const raw = ($(this).val() || '').toString().trim();
      if (raw === '') {
        return;
      }
      const parsed = Number(raw);
      if (!Number.isFinite(parsed) || parsed < 0) {
        window.alert('Capture un % de ganancia válido.');
        return;
      }
      $(this).val(parsed.toFixed(2));
      updateAdjustPreviewInv();
    });

    $(document).on('click', function (e) {
      if (!$(e.target).closest('#inv-adjust-code, #inv-adjust-suggest').length) {
        clearAdjustSuggestionsInv();
      }
    });

    $('#inv-mov-refresh-btn').on('click', renderMovementsInv);
    $('#inv-mov-from, #inv-mov-to, #inv-mov-type').on('change', renderMovementsInv);
    $('#inv-kardex-refresh-btn').on('click', renderKardexInv);
    $('#inv-kardex-product, #inv-kardex-from, #inv-kardex-to').on('change', renderKardexInv);
  }

  $(function () {
    if (!isInventoryPage()) return;

    bindInventoryInv();
    setDefaultRangesInv();

    const url = new URL(window.location.href);
    const sub = (url.searchParams.get('sub') || 'reporte').toString();

    if (sub === 'movimientos' || sub === 'kardex') {
      $.when(loadProductsInv(), loadMovementsInv());
    } else if (sub === 'reporte') {
      $.when(loadDepartmentsInv(), loadReportProductsInv());
    } else {
      loadProductsInv();
      resetAddFormInv();
      resetAdjustFormInv();
    }
  });
})();
