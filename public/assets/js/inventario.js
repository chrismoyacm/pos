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
    selectedId: null
  };

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
    $('#inv-adjust-product').html(options);
    $('#inv-kardex-product').html('<option value="">Seleccione producto...</option>' + options);
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
    const rows = filteredProductsInv();
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

    renderSummaryInv(rows);
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
    const rows = filteredProductsInv();
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
      renderInventoryTableInv();
      renderLowStockInv();
    });
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

  function applyMovementInv(productId, delta, type, note) {
    return $.ajax({
      url: '../api/inventario.php',
      method: 'PATCH',
      contentType: 'application/json',
      data: JSON.stringify({
        action: 'adjust_stock',
        productId,
        delta,
        movementType: type,
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

    $('#inv-department-filter').on('change', renderInventoryTableInv);
    $('#inv-modify-btn').on('click', function () {
      if (!invState.selectedId) return;
      window.location.href = 'index.php?mod=productos&sub=modify&pid=' + encodeURIComponent(invState.selectedId);
    });
    $('#inv-export-btn').on('click', exportInventoryInv);
    $('#inv-print-btn').on('click', function () { window.print(); });

    $('#inv-add-save-btn').on('click', function () {
      const productId = ($('#inv-add-product').val() || '').toString();
      const qty = Number($('#inv-add-qty').val() || 0);
      const note = ($('#inv-add-note').val() || '').toString().trim();
      if (!productId || qty <= 0) {
        window.alert('Selecciona producto y cantidad válida.');
        return;
      }
      applyMovementInv(productId, Math.abs(qty), 'entry', note).done(res => {
        if (!res.ok) {
          window.alert(res.error || 'No se pudo registrar la entrada.');
          return;
        }
        $('#inv-add-qty').val('1');
        $('#inv-add-note').val('');
        $.when(loadProductsInv(), loadMovementsInv()).done(renderInventoryTableInv);
      });
    });

    $('#inv-adjust-save-btn').on('click', function () {
      const productId = ($('#inv-adjust-product').val() || '').toString();
      const kind = ($('#inv-adjust-type').val() || 'entry').toString();
      const qty = Number($('#inv-adjust-qty').val() || 0);
      const note = ($('#inv-adjust-note').val() || '').toString().trim();
      if (!productId || qty <= 0) {
        window.alert('Selecciona producto y cantidad válida.');
        return;
      }
      const delta = kind === 'exit' ? -Math.abs(qty) : Math.abs(qty);
      applyMovementInv(productId, delta, kind, note).done(res => {
        if (!res.ok) {
          window.alert(res.error || 'No se pudo aplicar el ajuste.');
          return;
        }
        $('#inv-adjust-qty').val('1');
        $('#inv-adjust-note').val('');
        $.when(loadProductsInv(), loadMovementsInv()).done(() => {
          renderInventoryTableInv();
          renderLowStockInv();
        });
      });
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
      $.when(loadDepartmentsInv(), loadProductsInv()).done(renderInventoryTableInv);
    } else {
      loadProductsInv();
    }
  });
})();
