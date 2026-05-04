/* global $, window, document */
(function () {
  'use strict';

  function isClientesPage() {
    return $('#cli-tbody').length > 0;
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
  }

  function normalizeTaxId(value) {
    return (value || '').toString().replace(/\D+/g, '').trim();
  }

  function isValidProvinceCode(code) {
    const n = Number(code || 0);
    return n >= 1 && n <= 24;
  }

  function modulo10Check(digits10) {
    let sum = 0;
    for (let i = 0; i < 9; i += 1) {
      let n = Number(digits10.charAt(i));
      if (i % 2 === 0) {
        n *= 2;
        if (n > 9) n -= 9;
      }
      sum += n;
    }
    const verifier = (10 - (sum % 10)) % 10;
    return verifier === Number(digits10.charAt(9));
  }

  function modulo11Verifier(base, coeffs, verifierDigit) {
    let sum = 0;
    for (let i = 0; i < coeffs.length; i += 1) {
      sum += Number(base.charAt(i)) * coeffs[i];
    }
    const mod = 11 - (sum % 11);
    const expected = mod === 11 ? 0 : (mod === 10 ? 0 : mod);
    return expected === Number(verifierDigit);
  }

  function validateCedulaEcuador(digits) {
    if (digits.length !== 10) return false;
    if (!isValidProvinceCode(digits.slice(0, 2))) return false;
    const third = Number(digits.charAt(2));
    if (third < 0 || third > 5) return false;
    return modulo10Check(digits);
  }

  function validateRucEcuador(digits) {
    if (digits.length !== 13) return false;
    if (digits === '9999999999999') return true;
    if (!isValidProvinceCode(digits.slice(0, 2))) return false;

    const third = Number(digits.charAt(2));
    const estab = digits.slice(10, 13);
    if (estab === '000') return false;

    if (third >= 0 && third <= 5) {
      return validateCedulaEcuador(digits.slice(0, 10));
    }
    if (third === 6) {
      if (!modulo11Verifier(digits.slice(0, 8), [3, 2, 7, 6, 5, 4, 3, 2], digits.charAt(8))) return false;
      return digits.slice(9, 13) !== '0000';
    }
    if (third === 9) {
      if (!modulo11Verifier(digits.slice(0, 9), [4, 3, 2, 7, 6, 5, 4, 3, 2], digits.charAt(9))) return false;
      return estab !== '000';
    }
    return false;
  }

  function validateTaxId(value) {
    const digits = normalizeTaxId(value);
    if (!digits) {
      return { ok: true, value: '', message: '' };
    }
    if (digits.length === 10) {
      if (!validateCedulaEcuador(digits)) {
        return { ok: false, value: digits, message: 'Cedula invalida. Revise provincia, tercer digito y digito verificador.' };
      }
      return { ok: true, value: digits, message: 'Cedula valida.' };
    }
    if (digits.length === 13) {
      if (!validateRucEcuador(digits)) {
        return { ok: false, value: digits, message: 'RUC invalido. Revise tipo de contribuyente, establecimiento y digito verificador.' };
      }
      return { ok: true, value: digits, message: 'RUC valido.' };
    }
    return { ok: false, value: digits, message: 'La identificacion debe tener 10 digitos (cedula) o 13 digitos (RUC).' };
  }

  function setTaxIdFeedback(validation) {
    const $help = $('#cli-tax-id-help');
    if (!$help.length) return;
    const msg = (validation && validation.message) ? validation.message : '';
    if (!msg) {
      $help.text('').css('color', '');
      return;
    }
    $help.text(msg).css('color', validation.ok ? '#047857' : '#b91c1c');
  }

  const state = {
    customers: [],
    selectedId: null,
    mode: 'idle', // idle | selected | new
    page: 1,
    pageSize: 15,
    total: 0,
    totalPages: 1
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

  function renderPager() {
    const $pager = $('#cli-pager').empty();
    if ($pager.length === 0 || state.totalPages <= 1) {
      return;
    }

    const $prev = $('<button type="button" class="btn-secondary">Anterior</button>');
    const $next = $('<button type="button" class="btn-secondary">Siguiente</button>');
    $prev.prop('disabled', state.page <= 1);
    $next.prop('disabled', state.page >= state.totalPages);

    $prev.on('click', function () {
      if (state.page <= 1) return;
      state.page -= 1;
      renderList();
    });

    $next.on('click', function () {
      if (state.page >= state.totalPages) return;
      state.page += 1;
      renderList();
    });

    $pager.append($prev);
    $pager.append('<span class="table-pager-status">Página ' + state.page + ' de ' + state.totalPages + ' · ' + state.total + ' registros</span>');
    $pager.append($next);
  }

  const ECUADOR = {
    'Azuay': ['Cuenca', 'Gualaceo', 'Nabón', 'Paute', 'Pucará', 'San Fernando', 'Santa Isabel', 'Sevilla de Oro', 'Sígsig', 'Oña', 'Chordeleg', 'El Pan', 'Girón', 'Guachapala', 'Camilo Ponce Enríquez'],
    'Bolívar': ['Guaranda', 'Chillanes', 'Chimbo', 'Echeandía', 'San Miguel', 'Caluma', 'Las Naves'],
    'Cañar': ['Azogues', 'Biblián', 'Cañar', 'La Troncal', 'El Tambo', 'Déleg', 'Suscal'],
    'Carchi': ['Tulcán', 'Bolívar', 'Espejo', 'Mira', 'Montúfar', 'San Pedro de Huaca'],
    'Chimborazo': ['Riobamba', 'Alausí', 'Colta', 'Chambo', 'Chunchi', 'Guamote', 'Guano', 'Pallatanga', 'Penipe', 'Cumandá'],
    'Cotopaxi': ['Latacunga', 'La Maná', 'Pangua', 'Pujilí', 'Salcedo', 'Saquisilí', 'Sigchos'],
    'El Oro': ['Machala', 'Arenillas', 'Atahualpa', 'Balsas', 'Chilla', 'El Guabo', 'Huaquillas', 'Marcabelí', 'Pasaje', 'Piñas', 'Portovelo', 'Santa Rosa', 'Zaruma', 'Las Lajas'],
    'Esmeraldas': ['Esmeraldas', 'Eloy Alfaro', 'Muisne', 'Quinindé', 'San Lorenzo', 'Atacames', 'Rioverde'],
    'Galápagos': ['San Cristóbal', 'Santa Cruz', 'Isabela'],
    'Guayas': ['Guayaquil', 'Alfredo Baquerizo Moreno (Jujan)', 'Balao', 'Balzar', 'Colimes', 'Daule', 'Durán', 'El Empalme', 'El Triunfo', 'General Antonio Elizalde (Bucay)', 'Isidro Ayora', 'Lomas de Sargentillo', 'Milagro', 'Naranjal', 'Naranjito', 'Palestina', 'Pedro Carbo', 'Playas', 'Salitre', 'Samborondón', 'Santa Lucía', 'Simón Bolívar', 'Yaguachi', 'Coronel Marcelino Maridueña', 'Nobol', 'La Libertad'],
    'Imbabura': ['Ibarra', 'Antonio Ante', 'Cotacachi', 'Otavalo', 'Pimampiro', 'San Miguel de Urcuquí'],
    'Loja': ['Loja', 'Calvas', 'Catamayo', 'Celica', 'Chaguarpamba', 'Espíndola', 'Gonzanamá', 'Macará', 'Paltas', 'Puyango', 'Saraguro', 'Sozoranga', 'Zapotillo', 'Pindal', 'Quilanga', 'Olmedo'],
    'Los Ríos': ['Babahoyo', 'Baba', 'Montalvo', 'Puebloviejo', 'Quevedo', 'Urdaneta', 'Ventanas', 'Vínces', 'Palenque', 'Buena Fe', 'Valencia', 'Mocache', 'Quinsaloma'],
    'Manabí': ['Portoviejo', 'Bolívar', 'Chone', 'El Carmen', 'Flavio Alfaro', 'Jipijapa', 'Junín', 'Manta', 'Montecristi', 'Paján', 'Pichincha', 'Rocafuerte', 'Santa Ana', 'Sucre', 'Tosagua', '24 de Mayo', 'Pedernales', 'Olmedo', 'Puerto López', 'Jama', 'Jaramijó', 'San Vicente'],
    'Morona Santiago': ['Macas', 'Gualaquiza', 'Limón Indanza', 'Palora', 'Santiago', 'Sucúa', 'Huamboya', 'San Juan Bosco', 'Taisha', 'Logroño', 'Pablo Sexto', 'Tiwintza'],
    'Napo': ['Tena', 'Archidona', 'El Chaco', 'Quijos', 'Carlos Julio Arosemena Tola'],
    'Orellana': ['Francisco de Orellana', 'Aguarico', 'La Joya de los Sachas', 'Loreto'],
    'Pastaza': ['Puyo', 'Arajuno', 'Mera', 'Santa Clara'],
    'Pichincha': ['Quito', 'Cayambe', 'Mejía', 'Pedro Moncayo', 'Rumiñahui', 'San Miguel de los Bancos', 'Pedro Vicente Maldonado', 'Puerto Quito'],
    'Santa Elena': ['Santa Elena', 'La Libertad', 'Salinas'],
    'Santo Domingo de los Tsáchilas': ['Santo Domingo', 'La Concordia'],
    'Sucumbíos': ['Nueva Loja', 'Cascales', 'Cuyabeno', 'Gonzalo Pizarro', 'Putumayo', 'Shushufindi', 'Sucumbíos'],
    'Tungurahua': ['Ambato', 'Baños de Agua Santa', 'Cevallos', 'Mocha', 'Patate', 'Pelileo', 'Píllaro', 'Quero', 'Tisaleo'],
    'Zamora Chinchipe': ['Zamora', 'Chinchipe', 'Nangaritza', 'Yacuambi', 'Yantzaza', 'El Pangui', 'Centinela del Cóndor', 'Palanda', 'Paquisha']
  };

  function provinces() {
    return Object.keys(ECUADOR).sort((a, b) => a.localeCompare(b, 'es'));
  }

  function getCantons(province) {
    return Array.isArray(ECUADOR[province]) ? ECUADOR[province] : [];
  }

  function setSelectOptions($sel, options, placeholder) {
    $sel.empty();
    $sel.append(`<option value="">${escapeHtml(placeholder || 'Seleccione...')}</option>`);
    options.forEach(o => {
      $sel.append(`<option value="${escapeHtml(o)}">${escapeHtml(o)}</option>`);
    });
  }

  function initEcuadorSelectors() {
    const $prov = $('#cli-province');
    const $canton = $('#cli-canton');

    setSelectOptions($prov, provinces(), 'Seleccione provincia...');
    setSelectOptions($canton, [], 'Seleccione cantón...');
    $canton.prop('disabled', true);

    $prov.on('change', function () {
      const p = $(this).val().toString();
      const cantons = getCantons(p);
      setSelectOptions($canton, cantons, 'Seleccione cantón...');
      $canton.prop('disabled', cantons.length === 0);
    });
  }

  function setButtons() {
    const canDelete = state.mode === 'selected' && !!state.selectedId;
    const canSave = state.mode === 'selected' || state.mode === 'new';
    $('#cli-del').prop('disabled', !canDelete);
    $('#cli-save').prop('disabled', !canSave);
  }

  function setFormTitle() {
    if (state.mode === 'selected') {
      const c = state.customers.find(x => x.id === state.selectedId);
      $('#cli-form-title').text(c?.name ? c.name : 'Cliente');
      return;
    }
    $('#cli-form-title').text('Nuevo cliente');
  }

  function clearForm() {
    $('#cli-id').val('');
    $('#cli-first').val('');
    $('#cli-last').val('');
    $('#cli-tax-id').val('');
    setTaxIdFeedback({ ok: true, message: '' });
    $('#cli-phone').val('');
    $('#cli-email').val('');
    $('#cli-address1').val('');
    $('#cli-address2').val('');
    $('#cli-province').val('');
    setSelectOptions($('#cli-canton'), [], 'Seleccione cantón...');
    $('#cli-canton').prop('disabled', true).val('');
    $('#cli-parish').val('');
    $('#cli-zip').val('');
    $('#cli-notes').val('');
    $('#cli-credit').prop('checked', false);
    $('#cli-payment-day').val('');
  }

  function fillForm(c) {
    $('#cli-id').val(c?.id || '');
    $('#cli-first').val(c?.firstName || '');
    $('#cli-last').val(c?.lastName || '');
    const taxId = (c?.taxId || c?.identification || '').toString();
    $('#cli-tax-id').val(taxId);
    setTaxIdFeedback(validateTaxId(taxId));
    $('#cli-phone').val(c?.phone || '');
    $('#cli-email').val(c?.email || '');
    $('#cli-address1').val(c?.address1 || '');
    $('#cli-address2').val(c?.address2 || '');
    const province = (c?.province || c?.state || '').toString();
    const canton = (c?.canton || c?.city || '').toString();
    const parish = (c?.parish || c?.colonia || '').toString();
    $('#cli-province').val(province);
    const cantons = getCantons(province);
    setSelectOptions($('#cli-canton'), cantons, 'Seleccione cantón...');
    $('#cli-canton').prop('disabled', cantons.length === 0).val(canton);
    $('#cli-parish').val(parish);
    $('#cli-zip').val(c?.zip || '');
    $('#cli-notes').val(c?.notes || '');
    $('#cli-credit').prop('checked', !!c?.creditAuthorized);
    const dueDate = (c?.paymentDueDate || '').toString();
    if (dueDate) {
      $('#cli-payment-day').val(dueDate);
    } else if (Number(c?.paymentDueDay || 0) >= 1) {
      const now = new Date();
      const y = now.getFullYear();
      const m = String(now.getMonth() + 1).padStart(2, '0');
      const d = String(Number(c.paymentDueDay)).padStart(2, '0');
      $('#cli-payment-day').val(y + '-' + m + '-' + d);
    } else {
      $('#cli-payment-day').val('');
    }
  }

  function readForm() {
    const firstName = $('#cli-first').val().toString().trim();
    const lastName = $('#cli-last').val().toString().trim();
    const name = (firstName + ' ' + lastName).trim();
    const paymentDueDate = ($('#cli-payment-day').val() || '').toString().trim();
    const taxIdValidation = validateTaxId($('#cli-tax-id').val().toString());
    setTaxIdFeedback(taxIdValidation);
    return {
      id: $('#cli-id').val().toString().trim(),
      name,
      firstName,
      lastName,
      taxId: taxIdValidation.value,
      phone: $('#cli-phone').val().toString().trim(),
      email: $('#cli-email').val().toString().trim(),
      address1: $('#cli-address1').val().toString().trim(),
      address2: $('#cli-address2').val().toString().trim(),
      province: $('#cli-province').val().toString().trim(),
      canton: $('#cli-canton').val().toString().trim(),
      parish: $('#cli-parish').val().toString().trim(),
      zip: $('#cli-zip').val().toString().trim(),
      notes: $('#cli-notes').val().toString().trim(),
      creditAuthorized: $('#cli-credit').is(':checked'),
      paymentDueDate: paymentDueDate || null
    };
  }

  function renderList() {
    const $tbody = $('#cli-tbody').empty();
    const pg = paginateRows(state.customers, state.page, state.pageSize);
    state.page = pg.page;
    state.total = pg.total;
    state.totalPages = pg.totalPages;

    pg.pageRows.forEach(c => {
      const selected = c.id === state.selectedId;
      const $tr = $(
        `<tr class="${selected ? 'row-selected' : ''}" data-id="${escapeHtml(c.id || '')}">
          <td style="width:120px">${escapeHtml(c.id || '')}</td>
          <td>${escapeHtml(c.name || '')}</td>
          <td>${escapeHtml(c.taxId || c.identification || '')}</td>
        </tr>`
      );
      $tr.on('click', () => selectCustomer(c.id));
      $tbody.append($tr);
    });

    if (pg.pageRows.length === 0) {
      $tbody.append('<tr><td colspan="3" class="muted">No hay clientes para mostrar.</td></tr>');
    }

    renderPager();
  }

  function selectCustomer(id) {
    const c = state.customers.find(x => x.id === id);
    if (!c) return;
    state.selectedId = id;
    state.mode = 'selected';
    fillForm(c);
    setFormTitle();
    setButtons();
    renderList();
  }

  function loadCustomers(q) {
    return $.getJSON('../api/customers.php', { q: q || '' }).done(res => {
      state.customers = (res.ok && Array.isArray(res.data)) ? res.data : [];
      // if selected was deleted/filtered out, keep selection only if still present
      if (state.selectedId && !state.customers.some(c => c.id === state.selectedId)) {
        state.selectedId = null;
        state.mode = 'idle';
        clearForm();
      }
      renderList();
      setFormTitle();
      setButtons();
    });
  }

  function startNew() {
    state.selectedId = null;
    state.mode = 'new';
    clearForm();
    setFormTitle();
    setButtons();
    $('#cli-first').focus();
    renderList();
  }

  function save() {
    const payload = readForm();
    const taxIdValidation = validateTaxId(payload.taxId || '');
    setTaxIdFeedback(taxIdValidation);
    if (!taxIdValidation.ok) {
      alert(taxIdValidation.message);
      $('#cli-tax-id').focus();
      return;
    }
    if (!payload.name) {
      alert('Nombre requerido');
      return;
    }
    const isNew = state.mode === 'new';
    if (!isNew && !payload.id) {
      alert('Seleccione un cliente');
      return;
    }
    payload.action = isNew ? 'create' : 'update';
    $.ajax({
      url: '../api/customers.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify(payload)
    }).done(res => {
      if (!res.ok) { alert(res.error || 'Error al guardar'); return; }
      const saved = res.data;
      state.mode = 'selected';
      state.selectedId = saved.id;
      fillForm(saved);
      setFormTitle();
      setButtons();
      loadCustomers($('#cli-search').val().toString());
    }).fail(xhr => {
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'Error al guardar';
      alert(backendError);
    });
  }

  function del() {
    if (!state.selectedId) return;
    const c = state.customers.find(x => x.id === state.selectedId);
    const ok = window.confirm(`¿Eliminar cliente ${c?.name || state.selectedId}?`);
    if (!ok) return;
    $.ajax({
      url: '../api/customers.php',
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ action: 'delete', id: state.selectedId })
    }).done(res => {
      if (!res.ok) { alert(res.error || 'Error al eliminar'); return; }
      state.selectedId = null;
      state.mode = 'idle';
      clearForm();
      setFormTitle();
      setButtons();
      loadCustomers($('#cli-search').val().toString());
    }).fail(xhr => {
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'Error al eliminar';
      alert(backendError);
    });
  }

  function bind() {
    let t = null;
    $('#cli-search').on('input', function () {
      const q = $(this).val().toString();
      if (t) window.clearTimeout(t);
      t = window.setTimeout(() => {
        state.page = 1;
        loadCustomers(q);
      }, 180);
    });
    $('#cli-new').on('click', startNew);
    $('#cli-save').on('click', save);
    $('#cli-del').on('click', del);
    $('#cli-tax-id').on('input blur', function () {
      const validation = validateTaxId($(this).val().toString());
      if (validation.value !== $(this).val().toString()) {
        $(this).val(validation.value);
      }
      setTaxIdFeedback(validation);
    });

    // start locked until selection or "Nuevo"
    state.mode = 'idle';
    clearForm();
    setButtons();
  }

  $(function () {
    if (!isClientesPage()) return;
    initEcuadorSelectors();
    bind();
    loadCustomers('');
  });
})();

