/* global $, window, document */
(function () {
  'use strict';

  function isClientesPage() {
    return $('#cli-tbody').length > 0;
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
  }

  const state = {
    customers: [],
    selectedId: null,
    mode: 'idle' // idle | selected | new
  };

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
    $('#cli-payment-day').val(c?.paymentDueDay ?? '');
  }

  function readForm() {
    const firstName = $('#cli-first').val().toString().trim();
    const lastName = $('#cli-last').val().toString().trim();
    const name = (firstName + ' ' + lastName).trim();
    const paymentDayRaw = $('#cli-payment-day').val().toString().trim();
    const paymentDueDay = paymentDayRaw === '' ? null : Number(paymentDayRaw);
    return {
      id: $('#cli-id').val().toString().trim(),
      name,
      firstName,
      lastName,
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
      paymentDueDay: Number.isInteger(paymentDueDay) ? paymentDueDay : null
    };
  }

  function renderList() {
    const $tbody = $('#cli-tbody').empty();
    state.customers.forEach(c => {
      const selected = c.id === state.selectedId;
      const $tr = $(
        `<tr class="${selected ? 'row-selected' : ''}" data-id="${escapeHtml(c.id || '')}">
          <td style="width:120px">${escapeHtml(c.id || '')}</td>
          <td>${escapeHtml(c.name || '')}</td>
        </tr>`
      );
      $tr.on('click', () => selectCustomer(c.id));
      $tbody.append($tr);
    });
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
    if (!payload.name) {
      alert('Nombre requerido');
      return;
    }
    const isNew = state.mode === 'new';
    const method = isNew ? 'POST' : 'PATCH';
    if (!isNew && !payload.id) {
      alert('Seleccione un cliente');
      return;
    }
    $.ajax({
      url: '../api/customers.php',
      method,
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
    });
  }

  function del() {
    if (!state.selectedId) return;
    const c = state.customers.find(x => x.id === state.selectedId);
    const ok = window.confirm(`¿Eliminar cliente ${c?.name || state.selectedId}?`);
    if (!ok) return;
    $.ajax({
      url: '../api/customers.php',
      method: 'DELETE',
      contentType: 'application/json',
      data: JSON.stringify({ id: state.selectedId })
    }).done(res => {
      if (!res.ok) { alert(res.error || 'Error al eliminar'); return; }
      state.selectedId = null;
      state.mode = 'idle';
      clearForm();
      setFormTitle();
      setButtons();
      loadCustomers($('#cli-search').val().toString());
    });
  }

  function bind() {
    let t = null;
    $('#cli-search').on('input', function () {
      const q = $(this).val().toString();
      if (t) window.clearTimeout(t);
      t = window.setTimeout(() => loadCustomers(q), 180);
    });
    $('#cli-new').on('click', startNew);
    $('#cli-save').on('click', save);
    $('#cli-del').on('click', del);

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

