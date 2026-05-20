/* global $, window, document, Blob, URL */
(function () {
  'use strict';

  function isProductosPage() {
    return $('#prod-module').length > 0;
  }

  function isDepartmentsPage() {
    return $('#prod-departments-module').length > 0;
  }

  function isCatalogPage() {
    return $('#prod-catalog-module').length > 0;
  }

  function focusBarcodeInputProd(force) {
    if (!isProductosPage()) {
      return;
    }

    const active = document.activeElement;
    const activeTag = (active?.tagName || '').toUpperCase();
    if (!force && active && active !== document.body && (activeTag === 'INPUT' || activeTag === 'TEXTAREA' || activeTag === 'SELECT')) {
      return;
    }

    const preferredSelector = state.mode === 'new' ? '#prod-barcode' : '#prod-search';
    const $input = $(preferredSelector);
    if ($input.length === 0 || !$input.is(':visible') || $input.prop('disabled')) {
      return;
    }

    window.setTimeout(function () {
      const el = $input[0];
      if (!el || el.disabled) {
        return;
      }
      el.focus();
      if (el.tagName === 'INPUT' && preferredSelector !== '#prod-search') {
        const len = (el.value || '').toString().length;
        if (typeof el.setSelectionRange === 'function') {
          el.setSelectionRange(len, len);
        }
      }
    }, 0);
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
  }

  function formatMoney(n) {
    return '$' + Number(n || 0).toFixed(2);
  }

  function ajaxErrorMessage(xhr, fallbackMessage) {
    const backendError = xhr?.responseJSON?.error;
    const responseText = (xhr?.responseText || '').toString().trim();
    if (backendError) {
      return backendError.toString();
    }
    if (responseText) {
      return responseText;
    }
    return fallbackMessage;
  }

  let _feedbackTimer = null;
  const PRODUCT_NEW_DRAFT_KEY = 'pos.products.newDraft';

  function showSaveFeedback(message, tone) {
    const $box = $('#prod-save-feedback');
    if ($box.length === 0) {
      return;
    }

    if (_feedbackTimer) {
      window.clearTimeout(_feedbackTimer);
      _feedbackTimer = null;
    }

    $box.removeClass('is-success is-error is-info');

    if (!message) {
      $box.prop('hidden', true).text('');
      return;
    }

    const safeTone = (tone || 'info').toString();
    $box.addClass('is-' + safeTone);
    $box.text(message.toString());
    $box.prop('hidden', false);

    if (safeTone === 'success') {
      _feedbackTimer = window.setTimeout(function () {
        $box.prop('hidden', true).text('');
        $box.removeClass('is-success is-error is-info');
        _feedbackTimer = null;
      }, 5000);
    }
  }

  function setSaveBusy(isBusy) {
    const $btn = $('#prod-save-btn');
    if ($btn.length === 0) {
      return;
    }

    if (isBusy) {
      if (!$btn.data('default-label')) {
        $btn.data('default-label', $btn.text());
      }
      $btn.prop('disabled', true).text('Guardando...');
      return;
    }

    const defaultLabel = ($btn.data('default-label') || 'Guardar Producto').toString();
    $btn.prop('disabled', false).text(defaultLabel);
  }

  function upsertProductInState(product) {
    if (!product || !product.id) {
      return;
    }

    const idx = state.products.findIndex(function (row) {
      return (row?.id || '') === product.id;
    });

    if (idx >= 0) {
      state.products[idx] = product;
      return;
    }

    state.products.unshift(product);
  }

  function unitTypeLabel(unitType) {
    if (unitType === 'bulk') return 'GRANEL';
    if (unitType === 'package') return 'KIT';
    return 'UNIDAD';
  }

  function formatIvaPercent(value) {
    const rate = Number(value || 0);
    if (!(rate > 0)) return '';
    return (Number.isInteger(rate) ? String(rate) : String(rate.toFixed(2)).replace(/\.00$/, '').replace(/(\.\d*[1-9])0+$/, '$1')) + '%';
  }

  function normalizedTaxOptions() {
    return (Array.isArray(state.taxOptions) ? state.taxOptions : [])
      .map(function (row) {
        return {
          id: (row && row.id !== undefined ? row.id : '').toString().trim(),
          percentage: Number(row && row.percentage !== undefined ? row.percentage : 0),
          active: row && row.active !== false
        };
      })
      .filter(function (row) { return row.active && row.percentage > 0; });
  }

  function findTaxById(id) {
    const needle = (id || '').toString().trim();
    if (!needle) return null;
    return normalizedTaxOptions().find(function (opt) { return opt.id === needle; }) || null;
  }

  function availableIvaLabels() {
    const labels = normalizedTaxOptions()
      .map(function (opt) { return formatIvaPercent(opt.percentage); })
      .filter(Boolean);
    const unique = Array.from(new Set(labels));
    unique.sort(function (a, b) { return Number(a.replace('%', '')) - Number(b.replace('%', '')); });
    return ['No'].concat(unique);
  }

  function syncIvaSelectOptions(preferredValue) {
    const $sel = $('#prod-iva');
    if ($sel.length === 0) return;
    const current = (preferredValue || $sel.val() || 'No').toString();
    const options = availableIvaLabels();
    $sel.empty();
    options.forEach(function (label) {
      const text = label === 'No' ? 'Sin IVA' : ('IVA ' + label);
      $sel.append('<option value="' + escapeHtml(label) + '">' + escapeHtml(text) + '</option>');
    });
    if (options.indexOf(current) < 0) {
      $sel.append('<option value="' + escapeHtml(current) + '">' + escapeHtml(current === 'No' ? 'Sin IVA' : ('IVA ' + current)) + '</option>');
    }
    $sel.val(current);
  }

  function normalizeIvaLabel(iva) {
    const raw = (iva || '').toString().trim().toLowerCase();
    if (!raw || raw === 'no' || raw === '0' || raw === 'false') return 'No';
    const byId = findTaxById(raw);
    if (byId) return formatIvaPercent(byId.percentage) || 'No';
    const numeric = Number(raw.replace('iva', '').replace('%', '').trim());
    if (numeric > 0) return formatIvaPercent(numeric) || 'No';
    if (raw === 'si' || raw === 'sï¿½' || raw === 'yes' || raw === 'true') {
      const labels = availableIvaLabels().filter(function (label) { return label !== 'No'; });
      return labels[0] || 'No';
    }
    return 'No';
  }

  const state = {
    mode: 'new',
    products: [],
    selectedId: null,
    packageItems: [],
    selectedPackageIndex: -1,
    packagePreview: null,
    departments: [],
    selectedDepartmentId: null,
    catalogSelectedId: null,
    catalogSelectedIds: [],
    catalogBulkStep: 'config',
    catalogBulkAction: '',
    catalogBulkValue: null,
    catalogBulkBusy: false,
    promotions: [],
    selectedPromotionId: null,
    promoSelectedProductIds: [],
    importRows: [],
    catalogPage: 1,
    catalogPageSize: 20,
    catalogTotal: 0,
    catalogTotalPages: 1,
    taxOptions: [],
    suspendDraftSync: false
  };

  const NEW_DEPARTMENT_OPTION_VALUE = '__new_department__';

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

  function renderCatalogPager() {
    const $pager = $('#catalog-pager').empty();
    if ($pager.length === 0 || state.catalogTotalPages <= 1) {
      return;
    }

    const $prev = $('<button type="button" class="btn-secondary">Anterior</button>');
    const $next = $('<button type="button" class="btn-secondary">Siguiente</button>');
    $prev.prop('disabled', state.catalogPage <= 1);
    $next.prop('disabled', state.catalogPage >= state.catalogTotalPages);

    $prev.on('click', function () {
      if (state.catalogPage <= 1) return;
      state.catalogPage -= 1;
      renderCatalog();
    });

    $next.on('click', function () {
      if (state.catalogPage >= state.catalogTotalPages) return;
      state.catalogPage += 1;
      renderCatalog();
    });

    $pager.append($prev);
    $pager.append('<span class="table-pager-status">Página ' + state.catalogPage + ' de ' + state.catalogTotalPages + ' · ' + state.catalogTotal + ' registros</span>');
    $pager.append($next);
  }

  function navigateToProductSub(view) {
    if (view === 'departments') {
      window.location.href = 'index.php?mod=productos&sub=departamentos';
      return;
    }
    if (view === 'periods') {
      window.location.href = 'index.php?mod=productos&sub=periodos';
      return;
    }
    if (view === 'promotions') {
      window.location.href = 'index.php?mod=productos&sub=promociones';
      return;
    }
    if (view === 'import') {
      window.location.href = 'index.php?mod=productos&sub=importar';
      return;
    }
    if (view === 'catalog') {
      window.location.href = 'index.php?mod=productos&sub=catalogo';
      return;
    }
    window.location.href = 'index.php?mod=productos&sub=' + encodeURIComponent(view || 'nuevo');
  }

  function setDepartmentOptions() {
    const $sel = $('#prod-department').empty();
    const currentValue = ($sel.data('selected') || $sel.val() || 'Sin Departamento').toString();
    state.departments.forEach(dep => {
      $sel.append(`<option value="${escapeHtml(dep.name)}">${escapeHtml(dep.name)}</option>`);
    });
    if ($('#prod-department option').length === 0) {
      $sel.append('<option value="Sin Departamento">Sin Departamento</option>');
    }
    const hasCurrent = $sel.find('option').toArray().some(function (opt) {
      return ($(opt).val() || '').toString() === currentValue;
    });
    if (!hasCurrent) {
      $sel.append(`<option value="${escapeHtml(currentValue)}">${escapeHtml(currentValue)}</option>`);
    }
    $sel.append('<option value="' + NEW_DEPARTMENT_OPTION_VALUE + '">+ Crear nuevo departamento...</option>');
    $sel.val(currentValue);
    $sel.data('selected', currentValue);
  }

  function setCatalogDepartmentOptions() {
    const $sel = $('#catalog-department-filter').empty();
    $sel.append('<option value="">-Todos-</option>');
    state.departments.forEach(dep => {
      $sel.append(`<option value="${escapeHtml(dep.name)}">${escapeHtml(dep.name)}</option>`);
    });
  }

  function defaultFormValues() {
    return {
      id: '',
      barcode: '',
      name: '',
      cost: 0,
      margin: 20,
      price: 0,
      specialPrice: 0,
      wholesalePrice: 0,
      department: 'Sin Departamento',
      iva: 'No',
      unitType: 'unit',
      packageItems: [],
      inventoryEnabled: true,
      stock: 0,
      minStock: 0,
      maxStock: 0
    };
  }

  function withDraftSyncSuspended(fn) {
    state.suspendDraftSync = true;
    try {
      fn();
    } finally {
      state.suspendDraftSync = false;
    }
  }

  function saveNewProductDraft() {
    if (!isProductosPage() || state.mode !== 'new' || state.suspendDraftSync) {
      return;
    }

    try {
      window.localStorage.setItem(PRODUCT_NEW_DRAFT_KEY, JSON.stringify(readForm()));
    } catch (err) {
      void err;
    }
  }

  function clearNewProductDraft() {
    try {
      window.localStorage.removeItem(PRODUCT_NEW_DRAFT_KEY);
    } catch (err) {
      void err;
    }
  }

  function readNewProductDraft() {
    try {
      const raw = window.localStorage.getItem(PRODUCT_NEW_DRAFT_KEY);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return null;
      }
      return Object.assign(defaultFormValues(), parsed, {
        id: '',
        packageItems: Array.isArray(parsed.packageItems) ? parsed.packageItems : []
      });
    } catch (err) {
      void err;
      return null;
    }
  }

  function productToForm(product) {
    return {
      id: product?.id || '',
      barcode: product?.barcode || '',
      name: product?.name || '',
      cost: Number(product?.cost || 0),
      margin: Number(product?.margin || 20),
      price: Number(product?.price || 0),
      specialPrice: Number(product?.specialPrice || 0),
      wholesalePrice: Number(product?.wholesale?.price || 0),
      department: product?.department || 'Sin Departamento',
      iva: normalizeIvaLabel(product?.iva),
      unitType: product?.unitType || 'unit',
      packageItems: Array.isArray(product?.packageItems) ? product.packageItems.map(function (item) {
        return {
          productId: item?.productId || '',
          barcode: item?.barcode || '',
          name: item?.name || '',
          qty: Number(item?.qty || 1),
          stock: Number(item?.stock || 0)
        };
      }) : [],
      inventoryEnabled: product?.inventoryEnabled !== false,
      stock: Number(product?.stock || 0),
      minStock: Number(product?.minStock || 0),
      maxStock: Number(product?.maxStock || 0)
    };
  }

  function fillForm(values) {
    const data = values || defaultFormValues();
    withDraftSyncSuspended(function () {
      state.packageItems = Array.isArray(data.packageItems) ? data.packageItems.map(function (item) {
        return {
          productId: item.productId || '',
          barcode: item.barcode || '',
          name: item.name || '',
          qty: Number(item.qty || 1),
          stock: Number(item.stock || 0)
        };
      }) : [];
      state.selectedPackageIndex = -1;
      $('#prod-form').data('product-id', data.id || '');
      $('#prod-barcode').val(data.barcode);
      $('#prod-name').val(data.name);
      $('#prod-cost').val(Number(data.cost).toFixed(2));
      $('#prod-margin').val(Number(data.margin).toFixed(2));
      $('#prod-price').val(Number(data.price).toFixed(2));
      $('#prod-special-price').val(Number(data.specialPrice || 0).toFixed(2));
      $('#prod-wholesale').val(Number(data.wholesalePrice).toFixed(2));
      $('#prod-department').val(data.department);
      $('#prod-department').data('selected', data.department);
      syncIvaSelectOptions(normalizeIvaLabel(data.iva));
      $('input[name="prod-unit-type"][value="' + data.unitType + '"]').prop('checked', true);
      $('#prod-inventory-enabled').prop('checked', !!data.inventoryEnabled);
      $('#prod-stock').val(String(data.stock));
      $('#prod-min-stock').val(String(data.minStock));
      $('#prod-max-stock').val(String(data.maxStock));
      syncInventoryControls();
      syncPackagePanelVisibility();
      renderPackageItems();
    });
  }

  function recalcSalePriceFromMargin() {
    const cost = parseFloat($('#prod-cost').val().toString()) || 0;
    const margin = parseFloat($('#prod-margin').val().toString()) || 0;
    const salePrice = Math.max(0, cost * (1 + (margin / 100)));
    $('#prod-price').val(Number(salePrice).toFixed(2));
  }

  function recalcMarginFromSalePrice() {
    const cost = parseFloat($('#prod-cost').val().toString()) || 0;
    const salePrice = parseFloat($('#prod-price').val().toString()) || 0;
    if (!(cost > 0)) {
      $('#prod-margin').val('0.00');
      return;
    }
    const margin = ((salePrice - cost) / cost) * 100;
    $('#prod-margin').val(Number(Math.max(0, margin)).toFixed(2));
  }

  function syncInventoryControls() {
    const $enabled = $('#prod-inventory-enabled');
    const $stockInputs = $('#prod-stock, #prod-min-stock, #prod-max-stock');
    const isKit = isPackageProduct();
    $enabled.prop('disabled', false);
    $stockInputs.prop('disabled', false);
    $('.prod-inventory-box').prop('hidden', isKit);
    $('#prod-kit-inventory-note').prop('hidden', !isKit);
  }

  function readForm() {
    const inventoryEnabled = $('#prod-inventory-enabled').is(':checked');
    const stock = parseInt($('#prod-stock').val().toString(), 10) || 0;
    const minStock = parseInt($('#prod-min-stock').val().toString(), 10) || 0;
    const maxStock = parseInt($('#prod-max-stock').val().toString(), 10) || 0;

    return {
      id: $('#prod-form').data('product-id') || '',
      barcode: $('#prod-barcode').val().toString().trim(),
      name: $('#prod-name').val().toString().trim(),
      cost: parseFloat($('#prod-cost').val().toString()) || 0,
      margin: parseFloat($('#prod-margin').val().toString()) || 0,
      price: parseFloat($('#prod-price').val().toString()) || 0,
      specialPrice: parseFloat($('#prod-special-price').val().toString()) || 0,
      wholesalePrice: parseFloat($('#prod-wholesale').val().toString()) || 0,
      department: $('#prod-department').val().toString(),
      iva: normalizeIvaLabel($('#prod-iva').val()),
      unitType: $('input[name="prod-unit-type"]:checked').val()?.toString() || 'unit',
      packageItems: state.packageItems.map(function (item) {
        return {
          productId: item.productId || '',
          barcode: item.barcode || '',
          name: item.name || '',
          qty: Number(item.qty || 1),
          stock: Number(item.stock || 0)
        };
      }),
      inventoryEnabled: inventoryEnabled,
      stock: stock,
      minStock: minStock,
      maxStock: maxStock
    };
  }

  function selectedProduct() {
    return state.products.find(p => p.id === state.selectedId) || null;
  }

  function selectedDepartment() {
    return state.departments.find(dep => dep.id === state.selectedDepartmentId) || null;
  }

  function selectedCatalogProduct() {
    return state.products.find(product => product.id === state.catalogSelectedId) || null;
  }

  function isCatalogProductSelected(productId) {
    return state.catalogSelectedIds.indexOf((productId || '').toString()) >= 0;
  }

  function selectedCatalogProducts() {
    const ids = new Set(state.catalogSelectedIds.map(function (id) { return (id || '').toString(); }));
    return state.products.filter(function (product) { return ids.has((product?.id || '').toString()); });
  }

  function toggleCatalogProductSelection(productId, forceChecked) {
    const id = (productId || '').toString();
    if (!id) return;
    const selected = isCatalogProductSelected(id);
    const shouldSelect = forceChecked === undefined ? !selected : !!forceChecked;
    if (shouldSelect && !selected) {
      state.catalogSelectedIds.push(id);
      return;
    }
    if (!shouldSelect && selected) {
      state.catalogSelectedIds = state.catalogSelectedIds.filter(function (rowId) { return rowId !== id; });
    }
  }

  function setPackagePreviewHtml(html, className) {
    const $preview = $('#prod-package-preview');
    if ($preview.length === 0) {
      return;
    }
    $preview.removeClass('is-empty is-error is-loading').addClass(className || '').html(html);
  }

  function clearPackagePreview() {
    state.packagePreview = null;
    setPackagePreviewHtml('Escriba un codigo para ver la previsualizacion del articulo.', 'is-empty');
  }

  function renderPackagePreview(product) {
    if (!product) {
      clearPackagePreview();
      return;
    }

    state.packagePreview = {
      id: product.id || '',
      barcode: product.barcode || '',
      name: product.name || '',
      stock: product.stock ?? '',
      department: product.department || 'Sin Departamento'
    };

    setPackagePreviewHtml(
      '<strong>' + escapeHtml(state.packagePreview.name) + '</strong>' +
      '<small>Codigo: ' + escapeHtml(state.packagePreview.barcode || state.packagePreview.id || '') + '</small>' +
      '<small>Departamento: ' + escapeHtml(state.packagePreview.department) + '</small>' +
      '<small>Existencia: ' + escapeHtml(String(state.packagePreview.stock)) + '</small>',
      ''
    );
  }

  function findProductByPackageItem(item) {
    const productId = (item?.productId || '').toString();
    const barcode = (item?.barcode || '').toString().trim();
    return state.products.find(function (product) {
      if ((product?.id || '').toString() === productId && productId) {
        return true;
      }
      return barcode && (product?.barcode || '').toString().trim() === barcode;
    }) || null;
  }

  function packageItemStock(item) {
    const product = findProductByPackageItem(item);
    if (product) {
      return Number(product.stock ?? 0);
    }
    return Number(item?.stock || 0);
  }

  function packageKitLimitSummary() {
    if (!state.packageItems.length) {
      return {
        tone: 'muted',
        message: 'No hay articulos agregados al paquete.'
      };
    }

    const limits = [];
    for (let i = 0; i < state.packageItems.length; i += 1) {
      const item = state.packageItems[i];
      const qty = Number(item?.qty || 0);
      const stock = packageItemStock(item);
      if (!(qty > 0)) {
        return {
          tone: 'warn',
          message: 'No se pudo calcular el limite de kits con los articulos actuales.'
        };
      }
      limits.push(Math.floor(stock / qty));
    }

    const limit = limits.length ? Math.min.apply(null, limits) : 0;
    return {
      tone: limit > 0 ? 'info' : 'warn',
      message: 'Limite de kits que se puede armar: ' + String(Math.max(0, limit))
    };
  }

  function lookupPackagePreview() {
    const code = ($('#prod-package-code').val() || '').toString().trim();
    if (!code) {
      clearPackagePreview();
      return;
    }

    setPackagePreviewHtml('Buscando articulo...', 'is-loading');
    $.getJSON('../api/products.php', { q: code }).done(function (res) {
      if (!res.ok || !Array.isArray(res.data) || res.data.length === 0) {
        state.packagePreview = null;
        setPackagePreviewHtml('No se encontro un articulo con ese codigo.', 'is-error');
        return;
      }
      renderPackagePreview(res.data[0]);
    }).fail(function () {
      state.packagePreview = null;
      setPackagePreviewHtml('No se pudo consultar el articulo.', 'is-error');
    });
  }

  function isPackageProduct() {
    return $('input[name="prod-unit-type"]:checked').val()?.toString() === 'package';
  }

  function syncPackagePanelVisibility() {
    const show = isProductosPage() && state.mode !== 'delete' && isPackageProduct();
    $('#prod-package-panel').prop('hidden', !show);
    $('#prod-package-code, #prod-package-qty, #prod-package-add-btn').prop('disabled', !show);
    $('#prod-package-remove-btn').prop('disabled', !show || state.selectedPackageIndex < 0);
    if (!show) {
      clearPackagePreview();
    }
  }

  function renderPackageItems() {
    const $tbody = $('#prod-package-body').empty();
    const $summary = $('#prod-package-summary');
    if ($tbody.length === 0) {
      return;
    }

    if (!state.packageItems.length) {
      $tbody.append('<tr><td colspan="3" class="prod-package-empty">No hay articulos agregados al paquete.</td></tr>');
      $('#prod-package-remove-btn').prop('disabled', true);
      if ($summary.length) {
        $summary.removeClass('is-info is-warn').text('No hay articulos agregados al paquete.');
      }
      return;
    }

    state.packageItems.forEach(function (item, index) {
      const selected = index === state.selectedPackageIndex;
      const label = (item.barcode ? (item.barcode + ' - ') : '') + (item.name || 'Producto');
      const stock = packageItemStock(item);
      const $tr = $(`
        <tr class="${selected ? 'row-selected' : ''}">
          <td>${escapeHtml(label)}</td>
          <td class="catalog-center">${escapeHtml(String(item.qty || 1))}</td>
          <td class="catalog-center">${escapeHtml(String(stock))}</td>
        </tr>
      `);
      $tr.on('click', function () {
        state.selectedPackageIndex = index;
        renderPackageItems();
        syncPackagePanelVisibility();
      });
      $tbody.append($tr);
    });

    $('#prod-package-remove-btn').prop('disabled', state.selectedPackageIndex < 0);
    if ($summary.length) {
      const summary = packageKitLimitSummary();
      $summary.removeClass('is-info is-warn').addClass(summary.tone === 'warn' ? 'is-warn' : 'is-info').text(summary.message);
    }
  }

  function clearPackageInputs(focusCode) {
    $('#prod-package-code').val('');
    $('#prod-package-qty').val('1');
    clearPackagePreview();
    if (focusCode) {
      $('#prod-package-code').focus();
    }
  }

  function addPackageItem() {
    const code = ($('#prod-package-code').val() || '').toString().trim();
    const qty = parseInt(($('#prod-package-qty').val() || '1').toString(), 10) || 0;

    if (!isPackageProduct()) {
      window.alert('Seleccione "Como paquete (kit)" para agregar contenido.');
      return;
    }
    if (!code) {
      window.alert('Ingrese un codigo de barra.');
      return;
    }
    if (qty <= 0) {
      window.alert('Ingrese una cantidad valida.');
      return;
    }

    const appendProduct = function (product) {
      const existingIndex = state.packageItems.findIndex(function (item) {
        return (item.productId || '') === (product.id || '');
      });

      if (existingIndex >= 0) {
        state.packageItems[existingIndex].qty += qty;
        state.packageItems[existingIndex].stock = Number(product.stock || 0);
        state.selectedPackageIndex = existingIndex;
      } else {
        state.packageItems.push({
          productId: product.id || '',
          barcode: product.barcode || '',
          name: product.name || '',
          qty: qty,
          stock: Number(product.stock || 0)
        });
        state.selectedPackageIndex = state.packageItems.length - 1;
      }

      renderPackageItems();
      syncPackagePanelVisibility();
      saveNewProductDraft();
      clearPackageInputs(true);
    };

    if (state.packagePreview && ((state.packagePreview.barcode || '') === code || (state.packagePreview.id || '') === code)) {
      appendProduct(state.packagePreview);
      return;
    }

    $.getJSON('../api/products.php', { q: code }).done(function (res) {
      if (!res.ok || !Array.isArray(res.data) || res.data.length === 0) {
        state.packagePreview = null;
        setPackagePreviewHtml('No se encontro un articulo con ese codigo.', 'is-error');
        window.alert('Producto no encontrado.');
        return;
      }

      const product = res.data[0];
      renderPackagePreview(product);
      appendProduct(product);
    });
  }

  function removeSelectedPackageItem() {
    if (state.selectedPackageIndex < 0) {
      return;
    }
    state.packageItems.splice(state.selectedPackageIndex, 1);
    state.selectedPackageIndex = Math.min(state.selectedPackageIndex, state.packageItems.length - 1);
    renderPackageItems();
    syncPackagePanelVisibility();
    saveNewProductDraft();
  }

  function updateDeleteSummary() {
    const product = selectedProduct();
    if (!product) {
      $('#prod-delete-summary').text('No hay producto seleccionado.');
      return;
    }
    $('#prod-delete-summary').html(
      '<strong>' + escapeHtml(product.name || '') + '</strong><br>' +
      'Código: ' + escapeHtml(product.barcode || product.id || '') + '<br>' +
      'Precio: ' + formatMoney(product.price || 0) + '<br>' +
      'Existencia: ' + escapeHtml(String(product.stock ?? 0))
    );
  }

  function renderProductList() {
    const $tbody = $('#prod-list-body').empty();
    state.products.forEach(product => {
      const selected = product.id === state.selectedId;
      const $tr = $(`
        <tr class="${selected ? 'row-selected' : ''}" data-id="${escapeHtml(product.id || '')}">
          <td>${escapeHtml(product.barcode || product.id || '')}</td>
          <td>
            <div class="prod-list-name">${escapeHtml(product.name || '')}</div>
            <div class="muted prod-list-sub">${escapeHtml(product.department || 'Sin Departamento')}</div>
          </td>
          <td class="prod-list-price">${formatMoney(product.price || 0)}</td>
        </tr>
      `);
      $tr.on('click', function () {
        state.selectedId = product.id;
        if (state.mode === 'modify') {
          fillForm(productToForm(product));
        }
        updateDeleteSummary();
        renderProductList();
      });
      $tbody.append($tr);
    });
  }

  function renderDepartmentList() {
    const q = $('#prod-department-search').val()?.toString().trim().toLowerCase() || '';
    const rows = state.departments.filter(dep => dep.name.toLowerCase().includes(q));
    const $tbody = $('#prod-department-list-body').empty();

    rows.forEach(dep => {
      const selected = dep.id === state.selectedDepartmentId;
      const $tr = $(`
        <tr class="${selected ? 'row-selected' : ''}">
          <td>
            <div class="prod-department-row">
              <span class="prod-department-icon">Dept.</span>
              <span>${escapeHtml(dep.name)}</span>
            </div>
          </td>
        </tr>
      `);
      $tr.on('click', function () {
        state.selectedDepartmentId = dep.id;
        $('#prod-department-name').val(dep.name);
        $('#prod-department-title').text('DEPARTAMENTO');
        $('#prod-department-delete-btn').prop('disabled', false);
        renderDepartmentList();
      });
      $tbody.append($tr);
    });
  }

  function filteredCatalogProducts() {
    const q = $('#catalog-search').val()?.toString().trim().toLowerCase() || '';
    const department = $('#catalog-department-filter').val()?.toString() || '';

    return state.products.filter(product => {
      const code = (product.barcode || product.id || '').toString().toLowerCase();
      const name = (product.name || '').toString().toLowerCase();
      const prodDepartment = (product.department || 'Sin Departamento').toString();
      const matchesQuery = q === '' || code.includes(q) || name.includes(q);
      const matchesDepartment = department === '' || prodDepartment === department;
      return matchesQuery && matchesDepartment;
    });
  }

  function renderCatalog() {
    const rows = filteredCatalogProducts();
    const pg = paginateRows(rows, state.catalogPage, state.catalogPageSize);
    state.catalogPage = pg.page;
    state.catalogTotal = pg.total;
    state.catalogTotalPages = pg.totalPages;

    const $tbody = $('#catalog-body').empty();
    const visibleIds = pg.pageRows.map(function (product) { return (product?.id || '').toString(); }).filter(Boolean);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every(function (id) { return isCatalogProductSelected(id); });
    const anyVisibleSelected = visibleIds.some(function (id) { return isCatalogProductSelected(id); });
    $('#catalog-select-all')
      .prop('checked', allVisibleSelected)
      .prop('indeterminate', !allVisibleSelected && anyVisibleSelected);

    pg.pageRows.forEach(product => {
      const selected = product.id === state.catalogSelectedId;
      const checked = isCatalogProductSelected(product.id);
      const wholesalePrice = product?.wholesale?.price ?? 0;
      const iva = normalizeIvaLabel(product?.iva);
      const provider = product?.provider || '';
      const $tr = $(`
        <tr class="${selected ? 'row-selected' : ''}">
          <td><input type="checkbox" ${checked ? 'checked' : ''}></td>
          <td>${escapeHtml(product.barcode || product.id || '')}</td>
          <td>${escapeHtml(product.name || '')}</td>
          <td>${escapeHtml(product.department || 'Sin Departamento')}</td>
          <td class="catalog-money">${formatMoney(product.cost || 0)}</td>
          <td class="catalog-money">${formatMoney(product.price || 0)}</td>
          <td class="catalog-money">${formatMoney(wholesalePrice)}</td>
          <td class="catalog-center">${escapeHtml(String(product.stock ?? 0))}</td>
          <td class="catalog-center">${escapeHtml(String(product.minStock ?? 0))}</td>
          <td class="catalog-center">${escapeHtml(String(product.maxStock ?? 0))}</td>
          <td class="catalog-center">${escapeHtml(unitTypeLabel(product.unitType || 'unit'))}</td>
          <td>${escapeHtml(provider)}</td>
          <td class="catalog-center">${escapeHtml(String(iva))}</td>
        </tr>
      `);
      const $check = $tr.find('input[type=checkbox]');
      $check.on('click', function (event) {
        event.stopPropagation();
      });
      $check.on('change', function () {
        toggleCatalogProductSelection(product.id, $(this).is(':checked'));
        updateCatalogActionsState();
        renderCatalog();
      });
      $tr.on('click', function (e) {
        if ($(e.target).is('input[type=checkbox]')) return;
        state.catalogSelectedId = product.id;
        updateCatalogActionsState();
        renderCatalog();
      });
      $tbody.append($tr);
    });

    if (!rows.some(product => product.id === state.catalogSelectedId)) {
      state.catalogSelectedId = null;
    }

    if (pg.pageRows.length === 0) {
      $tbody.append('<tr><td colspan="13" class="muted">No hay productos para mostrar.</td></tr>');
    }

    updateCatalogActionsState();
    renderCatalogPager();
  }

  function updateCatalogActionsState() {
    const selectedCount = state.catalogSelectedIds.length;
    if (selectedCount === 1 && !state.catalogSelectedId) {
      state.catalogSelectedId = state.catalogSelectedIds[0];
    }
    const canModify = selectedCount === 1 && !!selectedCatalogProduct();
    $('#catalog-modify-btn').prop('disabled', !canModify);
    $('#catalog-refresh-btn')
      .prop('disabled', selectedCount === 0)
      .text(selectedCount > 0 ? ('Actualizar varios (' + selectedCount + ')...') : 'Actualizar varios...');
  }

  function showCatalogBulkFeedback(message, tone) {
    const $box = $('#catalog-bulk-feedback');
    if ($box.length === 0) return;
    $box.removeClass('is-success is-error is-info');
    if (!message) {
      $box.prop('hidden', true).text('');
      return;
    }
    const safeTone = (tone || 'info').toString();
    $box.addClass('is-' + safeTone).text(message).prop('hidden', false);
  }

  function createProductUpdatePayload(product) {
    const wholesalePrice = Number(product?.wholesale?.price || 0);
    const wholesaleMinQty = Number(product?.wholesale?.minQty || 6);
    return {
      action: 'update_product',
      id: product?.id || '',
      barcode: product?.barcode || '',
      name: product?.name || '',
      cost: Number(product?.cost || 0),
      margin: Number(product?.margin || 20),
      price: Number(product?.price || 0),
      specialPrice: Number(product?.specialPrice || 0),
      wholesalePrice: wholesalePrice,
      wholesaleMinQty: wholesaleMinQty,
      stock: Number(product?.stock || 0),
      minStock: Number(product?.minStock || 0),
      maxStock: Number(product?.maxStock || 0),
      inventoryEnabled: product?.inventoryEnabled !== false,
      department: product?.department || 'Sin Departamento',
      unitType: product?.unitType || 'unit',
      provider: product?.provider || '',
      iva: normalizeIvaLabel(product?.iva),
      packageItems: Array.isArray(product?.packageItems) ? product.packageItems : []
    };
  }

  function renderCatalogBulkActionConfig() {
    const action = (state.catalogBulkAction || '').toString();
    const $box = $('#catalog-bulk-config-area');
    if ($box.length === 0) return;

    if (!action) {
      $box.html('');
      return;
    }

    if (action === 'price_delta') {
      $box.html(`
        <div class="catalog-bulk-inline">
          <select id="catalog-bulk-price-direction">
            <option value="increase">Aumentar</option>
            <option value="decrease">Disminuir</option>
          </select>
          <input type="number" id="catalog-bulk-price-value" step="0.01" min="0" placeholder="Valor">
          <select id="catalog-bulk-price-mode">
            <option value="percent">Porcentaje (%)</option>
            <option value="fixed">Valor fijo ($)</option>
          </select>
        </div>
      `);
      return;
    }

    if (action === 'margin') {
      $box.html(`
        <div class="catalog-bulk-inline">
          <span>Nueva ganancia/utilidad (%)</span>
          <input type="number" id="catalog-bulk-margin-value" step="0.01" min="0" placeholder="Ej: 30">
        </div>
      `);
      return;
    }

    if (action === 'tax') {
      const options = availableIvaLabels()
        .map(function (label) {
          const text = label === 'No' ? 'Sin IVA' : ('IVA ' + label);
          return '<option value="' + escapeHtml(label) + '">' + escapeHtml(text) + '</option>';
        })
        .join('');
      $box.html(`
        <div class="catalog-bulk-inline">
          <span>Nuevo impuesto</span>
          <select id="catalog-bulk-tax-value">${options}</select>
        </div>
      `);
      return;
    }

    if (action === 'unit_type') {
      $box.html(`
        <div class="catalog-bulk-inline">
          <span>Nuevo tipo de venta</span>
          <select id="catalog-bulk-unit-type-value">
            <option value="unit">Unidad</option>
            <option value="bulk">Granel</option>
          </select>
        </div>
      `);
      return;
    }

    if (action === 'department') {
      const values = ['Sin Departamento'].concat(state.departments.map(function (dep) { return dep.name || ''; }).filter(Boolean));
      const uniqueValues = Array.from(new Set(values));
      const options = uniqueValues.map(function (name) {
        return '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
      }).join('');
      $box.html(`
        <div class="catalog-bulk-inline">
          <span>Nuevo departamento</span>
          <select id="catalog-bulk-department-value">${options}</select>
        </div>
      `);
      return;
    }

    if (action === 'inventory_delta') {
      $box.html(`
        <div class="catalog-bulk-inline">
          <select id="catalog-bulk-stock-direction">
            <option value="increase">Aumentar</option>
            <option value="decrease">Disminuir</option>
          </select>
          <input type="number" id="catalog-bulk-stock-value" step="1" min="1" placeholder="Cantidad">
        </div>
      `);
      return;
    }

    if (action === 'provider') {
      const providers = Array.from(new Set(state.products.map(function (product) { return (product?.provider || '').toString().trim(); }).filter(Boolean)));
      const options = ['<option value="">-- Escribir proveedor --</option>'].concat(providers.map(function (name) {
        return '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
      })).join('');
      $box.html(`
        <div class="catalog-bulk-inline">
          <span>Nuevo proveedor</span>
          <select id="catalog-bulk-provider-existing">${options}</select>
          <input type="text" id="catalog-bulk-provider-value" placeholder="Nombre del proveedor">
        </div>
      `);
      $('#catalog-bulk-provider-existing').on('change', function () {
        const val = ($(this).val() || '').toString();
        if (val) {
          $('#catalog-bulk-provider-value').val(val);
        }
      });
      return;
    }

    if (action === 'delete') {
      $box.html('<p class="muted">Se eliminaran los productos seleccionados del catalogo.</p>');
      return;
    }
  }

  function readCatalogBulkConfig() {
    const action = (state.catalogBulkAction || '').toString();
    if (!action) {
      return { ok: false, error: 'Selecciona una accion para continuar.' };
    }

    if (action === 'price_delta') {
      const raw = Number($('#catalog-bulk-price-value').val());
      if (!(raw > 0)) {
        return { ok: false, error: 'Ingresa un valor valido para el precio.' };
      }
      return {
        ok: true,
        action,
        direction: ($('#catalog-bulk-price-direction').val() || 'increase').toString(),
        mode: ($('#catalog-bulk-price-mode').val() || 'percent').toString(),
        amount: raw
      };
    }

    if (action === 'margin') {
      const raw = Number($('#catalog-bulk-margin-value').val());
      if (raw < 0 || Number.isNaN(raw)) {
        return { ok: false, error: 'Ingresa una utilidad valida.' };
      }
      return { ok: true, action, margin: raw };
    }

    if (action === 'tax') {
      return { ok: true, action, iva: normalizeIvaLabel($('#catalog-bulk-tax-value').val()) };
    }

    if (action === 'unit_type') {
      const unitType = ($('#catalog-bulk-unit-type-value').val() || 'unit').toString();
      return { ok: true, action, unitType: unitType === 'bulk' ? 'bulk' : 'unit' };
    }

    if (action === 'department') {
      const department = ($('#catalog-bulk-department-value').val() || 'Sin Departamento').toString();
      return { ok: true, action, department: department || 'Sin Departamento' };
    }

    if (action === 'inventory_delta') {
      const qty = Number($('#catalog-bulk-stock-value').val());
      if (!(qty > 0)) {
        return { ok: false, error: 'Ingresa una cantidad valida para inventario.' };
      }
      return {
        ok: true,
        action,
        direction: ($('#catalog-bulk-stock-direction').val() || 'increase').toString(),
        amount: Math.round(qty)
      };
    }

    if (action === 'provider') {
      const provider = ($('#catalog-bulk-provider-value').val() || '').toString().trim();
      if (!provider) {
        return { ok: false, error: 'Ingresa el nombre del proveedor.' };
      }
      return { ok: true, action, provider };
    }

    if (action === 'delete') {
      return { ok: true, action };
    }

    return { ok: false, error: 'Accion no soportada.' };
  }

  function catalogBulkSummaryText(config) {
    const action = (config?.action || '').toString();
    if (action === 'price_delta') {
      const op = config.direction === 'decrease' ? 'Disminuir precios' : 'Aumentar precios';
      const unit = config.mode === 'percent' ? '%' : '$';
      return {
        title: 'Accion: ' + op,
        value: 'Nuevo valor: ' + String(config.amount) + unit
      };
    }
    if (action === 'margin') {
      return { title: 'Accion: Modificar ganancia/utilidad', value: 'Nuevo valor: ' + String(config.margin) + '%' };
    }
    if (action === 'tax') {
      return { title: 'Accion: Modificar impuesto', value: 'Nuevo valor: ' + (config.iva === 'No' ? 'Sin IVA' : ('IVA(' + config.iva + ')')) };
    }
    if (action === 'unit_type') {
      return { title: 'Accion: Modificar tipo de venta', value: 'Nuevo valor: ' + unitTypeLabel(config.unitType) };
    }
    if (action === 'department') {
      return { title: 'Accion: Cambiar departamento', value: 'Nuevo valor: ' + String(config.department || 'Sin Departamento') };
    }
    if (action === 'inventory_delta') {
      return {
        title: 'Accion: Modificar inventario',
        value: 'Nuevo valor: ' + (config.direction === 'decrease' ? 'Disminuir' : 'Aumentar') + ' ' + String(config.amount) + ' unidades'
      };
    }
    if (action === 'provider') {
      return { title: 'Accion: Cambiar proveedor', value: 'Nuevo valor: ' + String(config.provider || '') };
    }
    return { title: 'Accion: Eliminar productos', value: 'Se eliminaran los productos seleccionados.' };
  }

  function renderCatalogBulkSummary(config) {
    const text = catalogBulkSummaryText(config);
    $('#catalog-bulk-summary-title').text(text.title || '');
    $('#catalog-bulk-summary-value').text(text.value || '');
    const $tbody = $('#catalog-bulk-summary-body').empty();
    selectedCatalogProducts().forEach(function (product) {
      $tbody.append(
        '<tr><td>' + escapeHtml(product?.barcode || product?.id || '') + '</td><td>' + escapeHtml(product?.name || '') + '</td></tr>'
      );
    });
  }

  function showCatalogBulkStep(stepName) {
    state.catalogBulkStep = stepName;
    $('#catalog-bulk-step-config').prop('hidden', stepName !== 'config');
    $('#catalog-bulk-step-summary').prop('hidden', stepName !== 'summary');
    $('#catalog-bulk-step-progress').prop('hidden', stepName !== 'progress');
    const inProgress = stepName === 'progress' && state.catalogBulkBusy;
    $('#catalog-bulk-back-btn').prop('disabled', inProgress || stepName === 'config');
    $('#catalog-bulk-next-btn').prop('disabled', inProgress).text(stepName === 'summary' ? 'Comenzar' : 'Siguiente');
    if (stepName === 'progress') {
      $('#catalog-bulk-next-btn').prop('disabled', true).text('Procesando...');
      $('#catalog-bulk-back-btn').prop('disabled', true);
    }
  }

  function resetCatalogBulkModal() {
    state.catalogBulkStep = 'config';
    state.catalogBulkAction = '';
    state.catalogBulkValue = null;
    state.catalogBulkBusy = false;
    $('input[name="catalog-bulk-action"]').prop('checked', false);
    $('#catalog-bulk-selected-text').text('Seleccionaste ' + state.catalogSelectedIds.length + ' productos, deseas...');
    $('#catalog-bulk-config-area').empty();
    $('#catalog-bulk-summary-title').text('');
    $('#catalog-bulk-summary-value').text('');
    $('#catalog-bulk-summary-body').empty();
    $('#catalog-bulk-progress-fill').css('width', '0%');
    $('#catalog-bulk-progress-text').text('0%');
    $('#catalog-bulk-progress-detail').text('0 de 0 completados');
    $('#catalog-bulk-result-list').empty();
    showCatalogBulkFeedback('', 'info');
    showCatalogBulkStep('config');
  }

  function closeCatalogBulkModal() {
    if (state.catalogBulkBusy) return;
    $('#catalog-bulk-modal').removeClass('active').attr('aria-hidden', 'true');
    resetCatalogBulkModal();
  }

  function openCatalogBulkModal() {
    if (state.catalogSelectedIds.length === 0) {
      window.alert('Selecciona al menos un producto para actualizar varios.');
      return;
    }
    resetCatalogBulkModal();
    $('#catalog-bulk-modal').addClass('active').attr('aria-hidden', 'false');
  }

  function runCatalogBulkExecution() {
    const config = state.catalogBulkValue;
    const rows = selectedCatalogProducts();
    const total = rows.length;
    if (!config || total === 0) {
      showCatalogBulkFeedback('No hay productos para procesar.', 'error');
      return;
    }

    showCatalogBulkStep('progress');
    state.catalogBulkBusy = true;
    showCatalogBulkFeedback('Ejecutando cambios, espera un momento...', 'info');

    let doneCount = 0;
    let successCount = 0;
    let errorCount = 0;

    const updateProgress = function () {
      const pct = total > 0 ? Math.round((doneCount / total) * 100) : 0;
      $('#catalog-bulk-progress-fill').css('width', pct + '%');
      $('#catalog-bulk-progress-text').text(pct + '%');
      $('#catalog-bulk-progress-detail').text(doneCount + ' de ' + total + ' completados');
    };

    const appendResult = function (message, tone) {
      const safeTone = (tone || 'info').toString();
      const color = safeTone === 'error' ? '#b91c1c' : (safeTone === 'success' ? '#166534' : '#1d4ed8');
      $('#catalog-bulk-result-list').append('<p style="color:' + color + ';">' + escapeHtml(message) + '</p>');
    };

    const applyRow = function (index) {
      if (index >= rows.length) {
        state.catalogBulkBusy = false;
        $('#catalog-bulk-next-btn').prop('disabled', false).text('Cerrar');
        $('#catalog-bulk-cancel-btn').prop('disabled', false);
        $('#catalog-bulk-back-btn').prop('disabled', true);
        if (errorCount === 0) {
          showCatalogBulkFeedback('Actualizacion completada: ' + successCount + ' productos procesados.', 'success');
        } else if (successCount > 0) {
          showCatalogBulkFeedback('Proceso completado con errores: ' + successCount + ' ok, ' + errorCount + ' con error.', 'info');
        } else {
          showCatalogBulkFeedback('No se pudo procesar ningun producto.', 'error');
        }
        updateCatalogActionsState();
        renderCatalog();
        return;
      }

      const product = rows[index];
      const code = product?.barcode || product?.id || '';
      let method = 'POST';
      let payload = null;

      if (config.action === 'delete') {
        payload = { action: 'delete_product', id: product.id };
      } else if (config.action === 'tax') {
        payload = { action: 'update_product_tax', id: product.id, iva: config.iva };
      } else {
        payload = createProductUpdatePayload(product);
        if (config.action === 'price_delta') {
          const sign = config.direction === 'decrease' ? -1 : 1;
          const basePrice = Number(product?.price || 0);
          const delta = config.mode === 'percent'
            ? (basePrice * Number(config.amount || 0) / 100)
            : Number(config.amount || 0);
          const nextPrice = Math.max(0, basePrice + (sign * delta));
          payload.price = Number(nextPrice.toFixed(2));
          if (Number(payload.cost || 0) > 0) {
            payload.margin = Number((((payload.price - payload.cost) / payload.cost) * 100).toFixed(2));
          }
        } else if (config.action === 'margin') {
          payload.margin = Number(config.margin || 0);
          payload.price = Number((Number(payload.cost || 0) * (1 + (payload.margin / 100))).toFixed(2));
        } else if (config.action === 'unit_type') {
          payload.unitType = config.unitType === 'bulk' ? 'bulk' : 'unit';
          if (payload.unitType !== 'package') {
            payload.packageItems = [];
          }
        } else if (config.action === 'department') {
          payload.department = (config.department || 'Sin Departamento').toString();
        } else if (config.action === 'inventory_delta') {
          const sign = config.direction === 'decrease' ? -1 : 1;
          const amount = Number(config.amount || 0);
          payload.stock = Math.max(0, Math.round(Number(payload.stock || 0) + (sign * amount)));
        } else if (config.action === 'provider') {
          payload.provider = (config.provider || '').toString();
        }
      }

      $.ajax({
        url: '../api/products.php',
        method: method,
        contentType: 'application/json',
        data: JSON.stringify(payload)
      }).done(function (res) {
        if (!res.ok) {
          errorCount += 1;
          appendResult('Error en [' + code + ']: ' + (res.error || 'No se pudo actualizar.'), 'error');
          return;
        }

        successCount += 1;
        if (config.action === 'delete') {
          state.products = state.products.filter(function (row) { return (row?.id || '') !== (product?.id || ''); });
          state.catalogSelectedIds = state.catalogSelectedIds.filter(function (rowId) { return rowId !== (product?.id || ''); });
          if ((state.catalogSelectedId || '') === (product?.id || '')) {
            state.catalogSelectedId = null;
          }
        } else if (res.data) {
          upsertProductInState(res.data);
        }
        appendResult('OK [' + code + ']', 'success');
      }).fail(function (xhr) {
        errorCount += 1;
        appendResult('Error en [' + code + ']: ' + ajaxErrorMessage(xhr, 'No se pudo actualizar.'), 'error');
      }).always(function () {
        doneCount += 1;
        updateProgress();
        applyRow(index + 1);
      });
    };

    $('#catalog-bulk-cancel-btn').prop('disabled', true);
    applyRow(0);
  }

  function resetDepartmentForm() {
    state.selectedDepartmentId = null;
    $('#prod-department-name').val('');
    $('#prod-department-title').text('NUEVO DEPARTAMENTO');
    $('#prod-department-delete-btn').prop('disabled', true);
    renderDepartmentList();
  }

  function applyProductMode() {
    const titles = {
      new: 'NUEVO PRODUCTO',
      modify: 'MODIFICAR PRODUCTO',
      delete: 'ELIMINAR PRODUCTO'
    };

    $('[data-prod-mode]').removeClass('active');
    $('[data-prod-mode="' + state.mode + '"]').addClass('active');
    $('#prod-title').text(titles[state.mode] || 'NUEVO PRODUCTO');

    const showList = state.mode !== 'new';
    $('#prod-list-panel').prop('hidden', !showList);
    $('#prod-search-wrap').prop('hidden', !showList);
    $('#prod-delete-box').prop('hidden', state.mode !== 'delete');
    $('#prod-save-btn').prop('hidden', state.mode === 'delete');
    $('#prod-delete-btn').prop('hidden', state.mode !== 'delete');
    $('#prod-cancel-btn').prop('hidden', false);

    const disableForm = state.mode === 'delete';
    $('#prod-form').find('input, select').prop('disabled', disableForm);

    if (state.mode === 'new') {
      state.selectedId = null;
      fillForm(readNewProductDraft() || defaultFormValues());
      showSaveFeedback('Modo nuevo activo. Completa los datos y presiona Guardar Producto.', 'info');
    } else if (state.mode === 'modify') {
      const sel = selectedProduct();
      if (sel) {
        fillForm(productToForm(sel));
        showSaveFeedback('Modo modificar activo. Edita los campos y presiona Guardar Producto.', 'info');
      } else if (state.products.length === 0) {
        showSaveFeedback('No hay productos disponibles para modificar.', 'error');
      } else {
        showSaveFeedback('Selecciona un producto de la lista para poder modificarlo.', 'error');
      }
    } else {
      updateDeleteSummary();
      showSaveFeedback('', 'info');
    }

    renderProductList();
    syncPackagePanelVisibility();
    focusBarcodeInputProd(false);
  }

  function loadProducts(q) {
    return $.getJSON('../api/products.php', { q: q || '' }).done(res => {
      state.products = (res.ok && Array.isArray(res.data)) ? res.data : [];
      if (state.selectedId && !state.products.some(product => product.id === state.selectedId)) {
        if ((state.mode === 'modify' || state.mode === 'delete') && !q) {
          showSaveFeedback('El producto seleccionado ya no existe en el cat\u00e1logo.', 'error');
        }
        state.selectedId = state.products[0]?.id || null;
      }
      if ((state.mode === 'modify' || state.mode === 'delete') && !state.selectedId && state.products.length > 0) {
        state.selectedId = state.products[0].id;
      }
      const available = new Set(state.products.map(function (product) { return (product?.id || '').toString(); }));
      state.catalogSelectedIds = state.catalogSelectedIds.filter(function (id) { return available.has((id || '').toString()); });
      if (!available.has((state.catalogSelectedId || '').toString())) {
        state.catalogSelectedId = null;
      }
      if (isProductosPage()) {
        applyProductMode();
      }
      if (isCatalogPage()) {
        renderCatalog();
      }
    });
  }

  function loadTaxOptions() {
    return $.getJSON('../api/products.php', { action: 'taxes' }).done(function (res) {
      state.taxOptions = (res.ok && Array.isArray(res.data)) ? res.data : [];
      syncIvaSelectOptions($('#prod-iva').val() || 'No');
    }).fail(function () {
      state.taxOptions = [];
      syncIvaSelectOptions($('#prod-iva').val() || 'No');
    });
  }

  function loadDepartments() {
    return $.getJSON('../api/departments.php').done(res => {
      state.departments = (res.ok && Array.isArray(res.data)) ? res.data : [];
      if (isProductosPage()) {
        setDepartmentOptions();
      }
      if (isDepartmentsPage()) {
        renderDepartmentList();
      }
      if (isCatalogPage()) {
        setCatalogDepartmentOptions();
        renderCatalog();
      }
    });
  }

  function saveProduct() {
    showSaveFeedback('', 'info');
    const payload = readForm();
    if (!payload.name) {
      showSaveFeedback('La descripcion es requerida.', 'error');
      return;
    }
    if ((payload.department || '') === NEW_DEPARTMENT_OPTION_VALUE) {
      showSaveFeedback('Selecciona un departamento valido.', 'error');
      return;
    }

    const isNew = state.mode === 'new';
    const method = 'POST';
    payload.action = isNew ? 'create_product' : 'update_product';

    if (!isNew && !payload.id) {
      showSaveFeedback('Selecciona un producto para modificar.', 'error');
      return;
    }

    setSaveBusy(true);
    $.ajax({
      url: '../api/products.php',
      method,
      contentType: 'application/json',
      data: JSON.stringify(payload)
    }).done(res => {
      if (!res.ok) {
        showSaveFeedback(res.error || 'No se pudo guardar el producto.', 'error');
        return;
      }

      const savedProduct = res.data || null;
      if (savedProduct) {
        upsertProductInState(savedProduct);
      }

      if (state.mode === 'new') {
        showSaveFeedback('Producto registrado correctamente.', 'success');
        clearNewProductDraft();
        state.selectedId = null;
        fillForm(defaultFormValues());
      } else {
        showSaveFeedback('Producto actualizado correctamente.', 'success');
        state.selectedId = null;
        $('#prod-search').val('');
        fillForm(defaultFormValues());
      }

      renderProductList();
      updateDeleteSummary();
      syncPackagePanelVisibility();
    }).fail(function (xhr) {
      showSaveFeedback(ajaxErrorMessage(xhr, 'No se pudo guardar el producto.'), 'error');
    }).always(function () {
      setSaveBusy(false);
    });
  }

  function deleteProduct() {
    const product = selectedProduct();
    if (!product) {
      showSaveFeedback('Selecciona un producto de la lista para eliminar.', 'error');
      return;
    }
    if (!window.confirm('¿Eliminar el producto "' + (product.name || product.id) + '"?')) {
      return;
    }

    $.ajax({
      url: '../api/products.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ action: 'delete_product', id: product.id })
    }).done(res => {
      if (!res.ok) {
        showSaveFeedback(res.error || 'No se pudo eliminar el producto.', 'error');
        return;
      }
      showSaveFeedback('Producto eliminado correctamente.', 'success');
      state.selectedId = null;
      state.products = state.products.filter(function (p) { return (p?.id || '') !== product.id; });
      renderProductList();
      updateDeleteSummary();
    }).fail(function (xhr) {
      showSaveFeedback(ajaxErrorMessage(xhr, 'No se pudo eliminar el producto.'), 'error');
    });
  }

  function saveDepartment() {
    const name = $('#prod-department-name').val().toString().trim();
    if (!name) {
      window.alert('El nombre del departamento es requerido.');
      return;
    }

    $.ajax({
      url: '../api/departments.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ name })
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo guardar el departamento.');
        return;
      }
      loadDepartments().done(() => {
        resetDepartmentForm();
      });
    }).fail(function (xhr) {
      window.alert(ajaxErrorMessage(xhr, 'No se pudo guardar el departamento.'));
    });
  }

  function createDepartmentInlineFromSelect() {
    const $select = $('#prod-department');
    const previous = ($select.data('selected') || 'Sin Departamento').toString();
    const rawName = window.prompt('Nuevo departamento:', '');

    if (rawName === null) {
      $select.val(previous);
      return;
    }

    const name = rawName.toString().trim();
    if (!name) {
      window.alert('El nombre del departamento es requerido.');
      $select.val(previous);
      return;
    }

    $.ajax({
      url: '../api/departments.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ name })
    }).done(function (res) {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo guardar el departamento.');
        $select.val(previous);
        return;
      }

      const selectedName = (res.data?.name || name).toString();
      loadDepartments().done(function () {
        $('#prod-department').val(selectedName);
        $('#prod-department').data('selected', selectedName);
        saveNewProductDraft();
      });
    }).fail(function (xhr) {
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'No se pudo guardar el departamento.';
      window.alert(backendError);
      $select.val(previous);
    });
  }

  function deleteDepartment() {
    const department = selectedDepartment();
    if (!department) {
      return;
    }
    if (!window.confirm('¿Eliminar el departamento "' + department.name + '"?')) {
      return;
    }

    $.ajax({
      url: '../api/departments.php',
      method: 'DELETE',
      contentType: 'application/json',
      data: JSON.stringify({ id: department.id })
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo eliminar el departamento.');
        return;
      }
      loadDepartments().done(() => {
        resetDepartmentForm();
      });
    });
  }

  function exportCatalog() {
    const rows = filteredCatalogProducts();
    const headers = [
      'Codigo',
      'Descripcion del Producto',
      'Departamento',
      'Costo',
      'Precio Venta',
      'Precio Mayoreo',
      'Existencia',
      'Inventario Minimo',
      'Inventario Maximo',
      'Tipo Venta',
      'Proveedor',
      'IVA'
    ];
    const lines = [headers.join(',')];
    rows.forEach(product => {
      const values = [
        product.barcode || product.id || '',
        product.name || '',
        product.department || 'Sin Departamento',
        Number(product.cost || 0).toFixed(2),
        Number(product.price || 0).toFixed(2),
        Number(product?.wholesale?.price || 0).toFixed(2),
        String(product.stock ?? 0),
        String(product.minStock ?? 0),
        String(product.maxStock ?? 0),
        unitTypeLabel(product.unitType || 'unit'),
        product.provider || '',
        normalizeIvaLabel(product.iva)
      ].map(value => `"${String(value).replace(/"/g, '""')}"`);
      lines.push(values.join(','));
    });

    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'catalogo_productos.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  function bindCommonNavigation() {
    $('[data-prod-nav]').on('click', function () {
      navigateToProductSub($(this).data('prod-nav').toString());
    });
  }

  function bindProductsPage() {
    let timer = null;

    $('[data-prod-mode]').on('click', function () {
      state.mode = $(this).data('prod-mode').toString();
      applyProductMode();
    });

    $('#prod-search').on('input', function () {
      const query = $(this).val().toString();
      if (timer) {
        window.clearTimeout(timer);
      }
      timer = window.setTimeout(() => loadProducts(query), 180);
    });

    $('#prod-save-btn').on('click', saveProduct);
    $('#prod-delete-btn').on('click', deleteProduct);
    $('#prod-department').on('change', function () {
      const value = ($(this).val() || '').toString();
      if (value === NEW_DEPARTMENT_OPTION_VALUE) {
        createDepartmentInlineFromSelect();
        return;
      }
      $(this).data('selected', value || 'Sin Departamento');
      saveNewProductDraft();
    });
    $('input[name="prod-unit-type"]').on('change', function () {
      state.selectedPackageIndex = -1;
      syncInventoryControls();
      syncPackagePanelVisibility();
      renderPackageItems();
      saveNewProductDraft();
    });
    $('#prod-inventory-enabled').on('change', function () {
      syncInventoryControls();
      saveNewProductDraft();
    });
    $('#prod-cost, #prod-margin').on('input', function () {
      recalcSalePriceFromMargin();
      saveNewProductDraft();
    });
    $('#prod-price').on('input', function () {
      recalcMarginFromSalePrice();
      saveNewProductDraft();
    });
    $('#prod-special-price, #prod-wholesale, #prod-stock, #prod-min-stock, #prod-max-stock, #prod-barcode, #prod-name').on('input', saveNewProductDraft);
    $('#prod-iva').on('change', saveNewProductDraft);
    let previewTimer = null;
    $('#prod-package-code').on('input', function () {
      if (previewTimer) {
        window.clearTimeout(previewTimer);
      }
      previewTimer = window.setTimeout(lookupPackagePreview, 180);
    });
    $('#prod-package-add-btn').on('click', addPackageItem);
    $('#prod-package-remove-btn').on('click', removeSelectedPackageItem);
    $('#prod-package-code').on('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        addPackageItem();
      }
    });
    $('#prod-package-qty').on('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        addPackageItem();
      }
    });
    $('#prod-cancel-btn').on('click', function () {
      if (state.mode === 'new') {
        clearNewProductDraft();
        fillForm(defaultFormValues());
        focusBarcodeInputProd(true);
        return;
      }
      fillForm(productToForm(selectedProduct()));
      updateDeleteSummary();
      focusBarcodeInputProd(true);
    });

    $('#prod-barcode, #prod-search').on('blur', function () {
      window.setTimeout(function () { focusBarcodeInputProd(false); }, 0);
    });

    $(document).on('click', function (e) {
      if ($(e.target).closest('.modal.active, #prod-barcode, #prod-search, #prod-package-code').length > 0) {
        return;
      }
      window.setTimeout(function () { focusBarcodeInputProd(false); }, 0);
    });
  }

  function bindDepartmentsPage() {
    $('#prod-department-search').on('input', renderDepartmentList);
    $('#prod-department-new-btn').on('click', resetDepartmentForm);
    $('#prod-department-save-btn').on('click', saveDepartment);
    $('#prod-department-cancel-btn').on('click', resetDepartmentForm);
    $('#prod-department-delete-btn').on('click', deleteDepartment);
  }

  function bindCatalogPage() {
    let timer = null;

    $('#catalog-search').on('input', function () {
      if (timer) {
        window.clearTimeout(timer);
      }
      timer = window.setTimeout(function () {
        state.catalogPage = 1;
        renderCatalog();
      }, 150);
    });

    $('#catalog-department-filter').on('change', function () {
      state.catalogPage = 1;
      renderCatalog();
    });

    $('#catalog-select-all').on('change', function () {
      const checked = $(this).is(':checked');
      const visibleRows = paginateRows(filteredCatalogProducts(), state.catalogPage, state.catalogPageSize).pageRows;
      visibleRows.forEach(function (product) {
        toggleCatalogProductSelection(product.id, checked);
      });
      updateCatalogActionsState();
      renderCatalog();
    });

    $('#catalog-refresh-btn').on('click', function () {
      openCatalogBulkModal();
    });

    $('#catalog-modify-btn').on('click', function () {
      const selected = selectedCatalogProducts();
      const product = selected.length === 1 ? selected[0] : selectedCatalogProduct();
      if (!product) {
        window.alert('Selecciona un solo producto para modificar.');
        return;
      }
      window.location.href = 'index.php?mod=productos&sub=modify&pid=' + encodeURIComponent(product.id);
    });

    $('input[name="catalog-bulk-action"]').on('change', function () {
      state.catalogBulkAction = ($(this).val() || '').toString();
      showCatalogBulkFeedback('', 'info');
      renderCatalogBulkActionConfig();
    });

    $('#catalog-bulk-cancel-btn').on('click', function () {
      closeCatalogBulkModal();
    });

    $('#catalog-bulk-back-btn').on('click', function () {
      if (state.catalogBulkBusy) return;
      if (state.catalogBulkStep === 'summary') {
        showCatalogBulkStep('config');
      }
    });

    $('#catalog-bulk-next-btn').on('click', function () {
      if (state.catalogBulkBusy) return;
      if (state.catalogBulkStep === 'config') {
        const config = readCatalogBulkConfig();
        if (!config.ok) {
          showCatalogBulkFeedback(config.error || 'Completa los datos para continuar.', 'error');
          return;
        }
        state.catalogBulkValue = config;
        renderCatalogBulkSummary(config);
        showCatalogBulkFeedback('', 'info');
        showCatalogBulkStep('summary');
        return;
      }

      if (state.catalogBulkStep === 'summary') {
        runCatalogBulkExecution();
        return;
      }

      if (state.catalogBulkStep === 'progress' && !state.catalogBulkBusy) {
        closeCatalogBulkModal();
      }
    });

    $('#catalog-bulk-modal').on('click', function (event) {
      if (event.target === this) {
        closeCatalogBulkModal();
      }
    });

    $('#catalog-export-btn').on('click', exportCatalog);
  }

  function toDateInputValue(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function buildPeriodRows(sales) {
    const from = $('#prod-period-from').val()?.toString() || '';
    const to = $('#prod-period-to').val()?.toString() || '';
    const q = ($('#prod-period-q').val() || '').toString().trim().toLowerCase();
    const dep = ($('#prod-period-department').val() || '').toString();

    const fromDate = from ? new Date(from + 'T00:00:00') : null;
    const toDate = to ? new Date(to + 'T23:59:59') : null;
    const byProduct = {};

    (sales || []).forEach(sale => {
      const createdAt = new Date((sale?.createdAt || '').toString());
      if (Number.isNaN(createdAt.getTime())) {
        return;
      }
      if (fromDate && createdAt < fromDate) {
        return;
      }
      if (toDate && createdAt > toDate) {
        return;
      }

      (sale.items || []).forEach(item => {
        const qty = Number(item?.qty || 0);
        const price = Number(item?.price || 0);
        if (qty <= 0) {
          return;
        }

        const id = (item?.id || '').toString();
        const barcode = (item?.barcode || '').toString();
        const key = id || barcode || (item?.name || '').toString();
        if (!key) {
          return;
        }

        const product = state.products.find(p => (p.id || '') === id || (p.barcode || '') === barcode) || null;
        const name = (item?.name || product?.name || 'Producto').toString();
        const department = (product?.department || 'Sin Departamento').toString();
        const code = (barcode || product?.barcode || id || '').toString();
        const cost = Number(product?.cost || 0);

        if (!byProduct[key]) {
          byProduct[key] = {
            code,
            name,
            department,
            qty: 0,
            total: 0,
            profit: 0,
            ticketIds: {}
          };
        }

        byProduct[key].qty += qty;
        byProduct[key].total += qty * price;
        byProduct[key].profit += qty * (price - cost);
        byProduct[key].ticketIds[sale.ticketId || sale.createdAt || key] = true;
      });
    });

    return Object.values(byProduct)
      .map(row => ({
        code: row.code,
        name: row.name,
        department: row.department,
        qty: row.qty,
        total: row.total,
        profit: row.profit,
        tickets: Object.keys(row.ticketIds).length
      }))
      .filter(row => {
        const haystack = (row.code + ' ' + row.name).toLowerCase();
        const matchesQ = !q || haystack.includes(q);
        const matchesDep = !dep || row.department === dep;
        return matchesQ && matchesDep;
      })
      .sort((a, b) => b.total - a.total);
  }

  function renderPeriodRows(rows) {
    const $tbody = $('#prod-period-body').empty();
    let units = 0;
    let total = 0;
    let profit = 0;

    rows.forEach(row => {
      units += Number(row.qty || 0);
      total += Number(row.total || 0);
      profit += Number(row.profit || 0);

      $tbody.append(`
        <tr>
          <td>${escapeHtml(row.code || '')}</td>
          <td>${escapeHtml(row.name || '')}</td>
          <td>${escapeHtml(row.department || 'Sin Departamento')}</td>
          <td class="catalog-center">${escapeHtml(String(row.qty || 0))}</td>
          <td class="catalog-money">${formatMoney(row.total || 0)}</td>
          <td class="catalog-money">${formatMoney(row.profit || 0)}</td>
          <td class="catalog-center">${escapeHtml(String(row.tickets || 0))}</td>
        </tr>
      `);
    });

    $('#prod-period-units').text(String(units));
    $('#prod-period-total').text(formatMoney(total));
    $('#prod-period-profit').text(formatMoney(profit));
  }

  function exportPeriodRows(rows) {
    const headers = ['Codigo', 'Descripcion', 'Departamento', 'Cantidad', 'Venta', 'Utilidad', 'Tickets'];
    const lines = [headers.join(',')];
    rows.forEach(row => {
      const values = [
        row.code || '',
        row.name || '',
        row.department || 'Sin Departamento',
        String(row.qty || 0),
        Number(row.total || 0).toFixed(2),
        Number(row.profit || 0).toFixed(2),
        String(row.tickets || 0)
      ].map(value => `"${String(value).replace(/"/g, '""')}"`);
      lines.push(values.join(','));
    });

    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'ventas_por_periodo.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function bindPeriodSalesPage() {
    const today = new Date();
    const start = new Date(today);
    start.setDate(start.getDate() - 30);
    $('#prod-period-from').val(toDateInputValue(start));
    $('#prod-period-to').val(toDateInputValue(today));

    function loadReport() {
      $.when(loadDepartments(), loadProducts(''), $.getJSON('../api/sales.php')).done(function (_d, _p, salesRes) {
        const payload = Array.isArray(salesRes) ? salesRes[0] : salesRes;
        const sales = (payload && payload.ok && Array.isArray(payload.data)) ? payload.data : [];
        const rows = buildPeriodRows(sales);

        const $dep = $('#prod-period-department');
        const current = $dep.val()?.toString() || '';
        $dep.empty().append('<option value="">-Todos-</option>');
        state.departments.forEach(dep => {
          $dep.append(`<option value="${escapeHtml(dep.name)}">${escapeHtml(dep.name)}</option>`);
        });
        $dep.val(current);
        renderPeriodRows(rows);
      });
    }

    $('#prod-period-refresh-btn').on('click', loadReport);
    $('#prod-period-q').on('input', loadReport);
    $('#prod-period-from, #prod-period-to, #prod-period-department').on('change', loadReport);
    $('#prod-period-export-btn').on('click', function () {
      $.getJSON('../api/sales.php').done(res => {
        const sales = (res.ok && Array.isArray(res.data)) ? res.data : [];
        exportPeriodRows(buildPeriodRows(sales));
      });
    });

    loadReport();
  }

  function selectedPromotion() {
    return state.promotions.find(promo => promo.id === state.selectedPromotionId) || null;
  }

  function promotionDefaults() {
    const today = toDateInputValue(new Date());
    const nextWeek = new Date();
    nextWeek.setDate(nextWeek.getDate() + 7);
    return {
      id: '',
      name: '',
      type: 'percent',
      value: 0,
      startDate: today,
      endDate: toDateInputValue(nextWeek),
      active: true,
      productIds: [],
      notes: ''
    };
  }

  function fillPromotionForm(promo) {
    const data = promo || promotionDefaults();
    $('#promo-form').data('promo-id', data.id || '');
    $('#promo-name').val(data.name || '');
    $('#promo-type').val(data.type || 'percent');
    $('#promo-value').val(Number(data.value || 0).toFixed(2));
    $('#promo-start-date').val(data.startDate || '');
    $('#promo-end-date').val(data.endDate || '');
    $('#promo-active').prop('checked', data.active !== false);
    $('#promo-notes').val(data.notes || '');
    const ids = Array.isArray(data.productIds) ? data.productIds.map(function (id) { return id.toString(); }) : [];
    state.promoSelectedProductIds = Array.from(new Set(ids));
    $('#promo-products-search').val('');
    setPromotionProductOptions();
    renderPromotionSelectedProducts();
  }

  function readPromotionForm() {
    syncPromotionSelectionFromSelect();
    const productIds = Array.from(new Set((state.promoSelectedProductIds || []).map(function (id) { return id.toString(); }).filter(Boolean)));
    return {
      id: $('#promo-form').data('promo-id') || '',
      name: ($('#promo-name').val() || '').toString().trim(),
      type: ($('#promo-type').val() || 'percent').toString(),
      value: Number($('#promo-value').val() || 0),
      startDate: ($('#promo-start-date').val() || '').toString(),
      endDate: ($('#promo-end-date').val() || '').toString(),
      active: $('#promo-active').is(':checked'),
      productIds,
      notes: ($('#promo-notes').val() || '').toString().trim()
    };
  }

  function promotionProductMatchesQuery(product, query) {
    const q = (query || '').toString().trim().toLowerCase();
    if (!q) return true;
    const barcode = (product?.barcode || '').toString().toLowerCase();
    const id = (product?.id || '').toString().toLowerCase();
    const name = (product?.name || '').toString().toLowerCase();
    return barcode.includes(q) || id.includes(q) || name.includes(q);
  }

  function syncPromotionSelectionFromSelect() {
    const $sel = $('#promo-products');
    const visibleIds = $sel.find('option').toArray().map(function (opt) {
      return ($(opt).val() || '').toString();
    });
    const rawSelected = $sel.val() || [];
    const selectedVisible = (Array.isArray(rawSelected) ? rawSelected : [rawSelected]).map(function (id) { return (id || '').toString(); }).filter(Boolean);
    const keptHidden = (state.promoSelectedProductIds || []).filter(function (id) {
      return visibleIds.indexOf((id || '').toString()) < 0;
    });
    state.promoSelectedProductIds = Array.from(new Set(keptHidden.concat(selectedVisible)));
  }

  function renderPromotionSelectedProducts() {
    const $box = $('#promo-selected-products').empty();
    const selectedIds = Array.from(new Set((state.promoSelectedProductIds || []).map(function (id) { return id.toString(); }).filter(Boolean)));
    if (selectedIds.length === 0) {
      $box.append('<div class="promo-selected-empty">No hay productos seleccionados.</div>');
      return;
    }

    selectedIds.forEach(function (id) {
      const product = state.products.find(function (row) { return (row?.id || '').toString() === id; }) || null;
      const code = (product?.barcode || id || '').toString();
      const name = (product?.name || 'Producto').toString();
      $box.append(
        '<div class="promo-selected-item">' +
          '<span>' + escapeHtml(code) + ' - ' + escapeHtml(name) + '</span>' +
          '<small>' + escapeHtml(id) + '</small>' +
        '</div>'
      );
    });
  }

  function setPromotionProductOptions() {
    const query = ($('#promo-products-search').val() || '').toString();
    const selectedIds = Array.from(new Set((state.promoSelectedProductIds || []).map(function (id) { return id.toString(); })));
    const $sel = $('#promo-products').empty();
    state.products.forEach(product => {
      if (!promotionProductMatchesQuery(product, query)) {
        return;
      }
      const label = (product.barcode || product.id || '') + ' - ' + (product.name || 'Producto');
      $sel.append(`<option value="${escapeHtml(product.id || '')}">${escapeHtml(label)}</option>`);
    });
    $sel.val(selectedIds);
    renderPromotionSelectedProducts();
  }

  function renderPromotionList() {
    const q = ($('#promo-search').val() || '').toString().trim().toLowerCase();
    const rows = state.promotions.filter(promo => (promo.name || '').toLowerCase().includes(q));
    const $tbody = $('#promo-list-body').empty();
    rows.forEach(promo => {
      const selected = promo.id === state.selectedPromotionId;
      $tbody.append(`
        <tr class="${selected ? 'row-selected' : ''}" data-id="${escapeHtml(promo.id || '')}">
          <td>
            <div class="prod-list-name">${escapeHtml(promo.name || '')}</div>
            <div class="muted prod-list-sub">${escapeHtml((promo.startDate || '') + ' a ' + (promo.endDate || ''))}</div>
          </td>
          <td class="catalog-center">${promo.active ? 'Activa' : 'Inactiva'}</td>
        </tr>
      `);
    });

    $('#promo-list-body tr').on('click', function () {
      const id = $(this).data('id').toString();
      state.selectedPromotionId = id;
      fillPromotionForm(selectedPromotion());
      $('#promo-delete-btn').prop('disabled', false);
      renderPromotionList();
    });
  }

  function loadPromotions() {
    return $.getJSON('../api/products.php', { action: 'promotions' }).done(res => {
      state.promotions = (res.ok && Array.isArray(res.data)) ? res.data : [];
      if (state.selectedPromotionId && !state.promotions.some(promo => promo.id === state.selectedPromotionId)) {
        state.selectedPromotionId = null;
      }
      renderPromotionList();
    });
  }

  function savePromotion() {
    const payload = readPromotionForm();
    if (!payload.name) {
      window.alert('Nombre de promoción requerido.');
      return;
    }
    if (!payload.startDate || !payload.endDate) {
      window.alert('Debes indicar rango de fechas.');
      return;
    }
    if (payload.endDate < payload.startDate) {
      window.alert('La fecha fin no puede ser menor a la fecha inicio.');
      return;
    }
    if (payload.value <= 0) {
      window.alert('El valor debe ser mayor que cero.');
      return;
    }

    const method = payload.id ? 'PATCH' : 'POST';
    payload.action = payload.id ? 'update_promotion' : 'create_promotion';
    $.ajax({
      url: '../api/products.php',
      method,
      contentType: 'application/json',
      data: JSON.stringify(payload)
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo guardar la promoción.');
        return;
      }
      state.selectedPromotionId = res.data?.id || null;
      loadPromotions().done(() => {
        fillPromotionForm(selectedPromotion() || promotionDefaults());
      });
    }).fail(function (xhr) {
      window.alert(ajaxErrorMessage(xhr, 'No se pudo guardar la promoción.'));
    });
  }

  function deletePromotion() {
    const promo = selectedPromotion();
    if (!promo) {
      return;
    }
    if (!window.confirm('¿Eliminar la promoción "' + (promo.name || promo.id) + '"?')) {
      return;
    }

    $.ajax({
      url: '../api/products.php',
      method: 'DELETE',
      contentType: 'application/json',
      data: JSON.stringify({ action: 'delete_promotion', id: promo.id })
    }).done(res => {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo eliminar la promoción.');
        return;
      }
      state.selectedPromotionId = null;
      $('#promo-delete-btn').prop('disabled', true);
      fillPromotionForm(promotionDefaults());
      loadPromotions();
    });
  }

  function bindPromotionsPage() {
    $('#promo-search').on('input', renderPromotionList);
    $('#promo-products-search').on('input', function () {
      syncPromotionSelectionFromSelect();
      setPromotionProductOptions();
    });
    $('#promo-products').on('change', function () {
      syncPromotionSelectionFromSelect();
      renderPromotionSelectedProducts();
    });
    $('#promo-new-btn').on('click', function () {
      state.selectedPromotionId = null;
      $('#promo-delete-btn').prop('disabled', true);
      fillPromotionForm(promotionDefaults());
      renderPromotionList();
    });
    $('#promo-save-btn').on('click', savePromotion);
    $('#promo-cancel-btn').on('click', function () {
      fillPromotionForm(selectedPromotion() || promotionDefaults());
    });
    $('#promo-delete-btn').on('click', deletePromotion);

    $.when(loadProducts(''), loadPromotions()).done(() => {
      setPromotionProductOptions();
      fillPromotionForm(promotionDefaults());
    });
  }

  function parseCsvLine(line) {
    const result = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i += 1) {
      const ch = line[i];
      if (ch === '"') {
        if (inQuotes && line[i + 1] === '"') {
          current += '"';
          i += 1;
        } else {
          inQuotes = !inQuotes;
        }
      } else if (ch === ',' && !inQuotes) {
        result.push(current.trim());
        current = '';
      } else {
        current += ch;
      }
    }
    result.push(current.trim());
    return result;
  }

  function parseCsvText(text) {
    const lines = text.split(/\r?\n/).filter(line => line.trim() !== '');
    if (lines.length < 2) {
      return [];
    }
    const headers = parseCsvLine(lines[0]).map(h => h.toLowerCase());
    const rows = [];
    for (let i = 1; i < lines.length; i += 1) {
      const cols = parseCsvLine(lines[i]);
      const row = {};
      headers.forEach((h, idx) => {
        row[h] = cols[idx] || '';
      });
      rows.push(row);
    }
    return rows;
  }

  function renderImportPreview(rows) {
    const $tbody = $('#prod-import-preview-body').empty();
    rows.slice(0, 300).forEach(row => {
      $tbody.append(`
        <tr>
          <td>${escapeHtml(row.barcode || '')}</td>
          <td>${escapeHtml(row.name || '')}</td>
          <td class="catalog-money">${formatMoney(row.price || 0)}</td>
          <td class="catalog-money">${formatMoney(row.cost || 0)}</td>
          <td>${escapeHtml(row.department || 'Sin Departamento')}</td>
          <td class="catalog-center">${escapeHtml(String(row.stock || 0))}</td>
          <td>${escapeHtml(row.provider || '')}</td>
          <td class="catalog-center">${escapeHtml(normalizeIvaLabel(row.iva))}</td>
        </tr>
      `);
    });
  }

  function bindImportPage() {
    function readSelectedFile(done) {
      const file = $('#prod-import-file')[0]?.files?.[0];
      if (!file) {
        window.alert('Selecciona un archivo CSV.');
        return;
      }
      const reader = new FileReader();
      reader.onload = function () {
        done((reader.result || '').toString());
      };
      reader.readAsText(file, 'utf-8');
    }

    $('#prod-import-preview-btn').on('click', function () {
      readSelectedFile(text => {
        const rows = parseCsvText(text);
        state.importRows = rows;
        renderImportPreview(rows);
        $('#prod-import-result').text('Filas detectadas: ' + rows.length);
        $('#prod-import-run-btn').prop('disabled', rows.length === 0);
      });
    });

    $('#prod-import-run-btn').on('click', function () {
      if (!state.importRows.length) {
        window.alert('Primero genera vista previa.');
        return;
      }

      const mode = ($('#prod-import-mode').val() || 'merge').toString();
      if (mode === 'replace' && !window.confirm('Vas a reemplazar el catálogo completo. ¿Deseas continuar?')) {
        return;
      }

      $.ajax({
        url: '../api/products.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ action: 'import_products', mode, rows: state.importRows })
      }).done(res => {
        if (!res.ok) {
          window.alert(res.error || 'No se pudo completar la importación.');
          return;
        }
        const created = Number(res.data?.created || 0);
        const updated = Number(res.data?.updated || 0);
        $('#prod-import-result').text('Importación completada. Creados: ' + created + ' | Actualizados: ' + updated);
      }).fail(function (xhr) {
        window.alert(ajaxErrorMessage(xhr, 'No se pudo completar la importación.'));
      });
    });
  }

  $(function () {
    if (!isProductosPage() && !isDepartmentsPage() && !isCatalogPage()) {
      return;
    }

    bindCommonNavigation();

    if (isProductosPage()) {
      bindProductsPage();
      const url = new URL(window.location.href);
      const sub = url.searchParams.get('sub') || 'nuevo';
      const pid = url.searchParams.get('pid') || '';

      if (sub === 'periodos') {
        bindPeriodSalesPage();
        return;
      }

      if (sub === 'promociones') {
        bindPromotionsPage();
        return;
      }

      if (sub === 'importar') {
        bindImportPage();
        return;
      }

      state.mode = 'new';
      if (sub === 'modify') state.mode = 'modify';
      if (sub === 'delete') state.mode = 'delete';
      $('#prod-title').text(state.mode === 'modify' ? 'MODIFICAR PRODUCTO' : (state.mode === 'delete' ? 'ELIMINAR PRODUCTO' : 'NUEVO PRODUCTO'));
      $.when(loadTaxOptions(), loadDepartments(), loadProducts('')).done(() => {
        if (pid) {
          state.selectedId = pid;
        }
        fillForm(defaultFormValues());
        applyProductMode();
        focusBarcodeInputProd(true);
      });
      return;
    }

    if (isDepartmentsPage()) {
      bindDepartmentsPage();
      loadDepartments().done(() => {
        resetDepartmentForm();
      });
      return;
    }

    if (isCatalogPage()) {
      bindCatalogPage();
      $.when(loadTaxOptions(), loadDepartments(), loadProducts('')).done(() => {
        setCatalogDepartmentOptions();
        renderCatalog();
      });
    }
  });
})();


