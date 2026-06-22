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
    totalPages: 1,
    importHeaders: [],
    importRows: [],
    importFailedRows: [],
    importBusy: false
  };

  const IMPORT_BATCH_SIZE = 250;

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

  function splitCsvLine(line, delimiter) {
    const out = [];
    let current = '';
    let quoted = false;
    for (let i = 0; i < line.length; i += 1) {
      const ch = line.charAt(i);
      if (ch === '"') {
        if (quoted && line.charAt(i + 1) === '"') {
          current += '"';
          i += 1;
        } else {
          quoted = !quoted;
        }
      } else if (ch === delimiter && !quoted) {
        out.push(current);
        current = '';
      } else {
        current += ch;
      }
    }
    out.push(current);
    return out;
  }

  function detectDelimiter(line) {
    const semicolonCount = splitCsvLine(line, ';').length;
    const commaCount = splitCsvLine(line, ',').length;
    return semicolonCount >= commaCount ? ';' : ',';
  }

  function normalizeImportHeader(value) {
    return (value || '').toString()
      .replace(/^\uFEFF/, '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9_]+/g, '');
  }

  function customerImportAliasMap() {
    return {
      id: 'legacyId',
      folio: 'folio',
      nombres: 'firstName',
      nombre: 'firstName',
      apellidos: 'lastName',
      apellido: 'lastName',
      identificacion: 'taxId',
      cedula: 'taxId',
      ruc: 'taxId',
      email: 'email',
      correo: 'email',
      telefono: 'phone',
      celular: 'phone',
      domicilio1: 'address1',
      direccion: 'address1',
      domicilio2: 'address2',
      colonia: 'parish',
      parroquia: 'parish',
      municipio: 'canton',
      canton: 'canton',
      estado: 'province',
      provincia: 'province',
      pais: 'country',
      codigo_postal: 'zip',
      codigopostal: 'zip',
      notas: 'notes',
      total_ventas: 'totalSales',
      totalventas: 'totalSales',
      total_ganancias: 'totalProfit',
      totalganancias: 'totalProfit',
      total_tickets: 'totalTickets',
      totaltickets: 'totalTickets',
      de_sistema: 'systemFlag',
      desistema: 'systemFlag',
      old_cliente_id: 'oldCustomerId',
      oldclienteid: 'oldCustomerId',
      old_facturacion_clientes_id: 'oldBillingCustomerId',
      oldfacturacionclientesid: 'oldBillingCustomerId'
    };
  }

  function normalizeImportRow(row, index) {
    const alias = customerImportAliasMap();
    const normalized = { _line: index + 2, _source: row };
    Object.keys(row).forEach(function (header) {
      const key = alias[normalizeImportHeader(header)];
      if (key) normalized[key] = (row[header] || '').toString().trim();
    });
    normalized.name = ((normalized.firstName || '') + ' ' + (normalized.lastName || '')).trim();
    normalized._importIssues = [];
    if (!normalized.name) {
      normalized._importIssues.push('Sin nombres o apellidos');
    }
    const legacyId = (normalized.legacyId || '').toString().trim();
    if (legacyId && !/^\d+$/.test(legacyId)) {
      normalized._importIssues.push('ID invalido');
    }
    normalized._importStatus = normalized._importIssues.length ? 'warning' : 'ok';
    return normalized;
  }

  function parseCustomerCsv(text) {
    const clean = (text || '').toString().replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    const lines = clean.split('\n').filter(function (line) { return line.trim() !== ''; });
    if (lines.length < 2) {
      throw new Error('El archivo no tiene datos para importar.');
    }
    const delimiter = detectDelimiter(lines[0]);
    const headers = splitCsvLine(lines[0], delimiter).map(function (header) {
      return header.replace(/^\uFEFF/, '').trim();
    });
    const rows = [];
    for (let i = 1; i < lines.length; i += 1) {
      const values = splitCsvLine(lines[i], delimiter);
      const row = {};
      headers.forEach(function (header, idx) {
        row[header] = values[idx] !== undefined ? values[idx] : '';
      });
      rows.push(normalizeImportRow(row, i - 1));
    }
    return { headers, rows };
  }

  function updateCustomerImportProgress(done, total, label) {
    const safeTotal = Math.max(1, Number(total || 0));
    const percent = Math.max(0, Math.min(100, (Number(done || 0) / safeTotal) * 100));
    $('#cli-import-progress').prop('hidden', false);
    $('#cli-import-progress-fill').css('width', percent.toFixed(2) + '%');
    $('#cli-import-progress-meta').text(label || (percent.toFixed(0) + '%'));
  }

  function resetCustomerImportProgress() {
    $('#cli-import-progress').prop('hidden', true);
    $('#cli-import-progress-fill').css('width', '0%');
    $('#cli-import-progress-meta').text('0%');
  }

  function setCustomerImportBusy(isBusy) {
    state.importBusy = !!isBusy;
    $('#cli-import-file, #cli-import-preview-btn, #cli-import-run-btn').prop('disabled', !!isBusy);
    $('#cli-import-run-btn').text(isBusy ? 'Importando...' : 'Importar');
    if (!isBusy) {
      $('#cli-import-run-btn').prop('disabled', state.importRows.length === 0);
    }
  }

  function renderCustomerImportPreview() {
    const $tbody = $('#cli-import-preview-body').empty();
    const rows = state.importRows.slice(0, 250);
    rows.forEach(function (row) {
      const ok = row._importStatus !== 'warning';
      const status = ok ? 'OK - Apta' : 'Revisar: ' + row._importIssues.join(', ');
      $tbody.append(
        '<tr class="' + (ok ? '' : 'prod-import-row-warning') + '">' +
          '<td>' + escapeHtml(row._line || '') + '</td>' +
          '<td>' + escapeHtml(row.legacyId || '') + '</td>' +
          '<td>' + escapeHtml(row.name || '') + '</td>' +
          '<td>' + escapeHtml(row.phone || '') + '</td>' +
          '<td>' + escapeHtml(row.email || '') + '</td>' +
          '<td class="' + (ok ? 'prod-import-status-ok' : 'prod-import-status-warning') + '">' + escapeHtml(status) + '</td>' +
        '</tr>'
      );
    });
    if (!rows.length) {
      $tbody.append('<tr><td colspan="6" class="muted">No hay filas para mostrar.</td></tr>');
    }
  }

  function readCustomerImportFile() {
    const file = $('#cli-import-file')[0]?.files?.[0];
    if (!file) {
      alert('Seleccione un archivo CSV.');
      return;
    }
    const reader = new FileReader();
    reader.onload = function () {
      try {
        const parsed = parseCustomerCsv(reader.result || '');
        state.importHeaders = parsed.headers;
        state.importRows = parsed.rows;
        state.importFailedRows = [];
        renderCustomerImportFailedRows([]);
        renderCustomerImportPreview();
        const okCount = parsed.rows.filter(function (row) { return row._importStatus !== 'warning'; }).length;
        const warnCount = parsed.rows.length - okCount;
        $('#cli-import-result').text('Vista previa lista. Aptos: ' + okCount + ' | Revisar: ' + warnCount + ' | Total: ' + parsed.rows.length);
        $('#cli-import-run-btn').prop('disabled', okCount === 0);
        resetCustomerImportProgress();
      } catch (err) {
        state.importRows = [];
        renderCustomerImportPreview();
        $('#cli-import-run-btn').prop('disabled', true);
        $('#cli-import-result').text(err?.message || 'No se pudo leer el archivo.');
      }
    };
    reader.readAsText(file, 'UTF-8');
  }

  function ajaxErrorMessage(xhr, fallback) {
    return xhr?.responseJSON?.error || xhr?.statusText || fallback;
  }

  function renderCustomerImportFailedRows(rows) {
    state.importFailedRows = Array.isArray(rows) ? rows.filter(Boolean) : [];
    const list = state.importFailedRows;
    const $box = $('#cli-import-failed');
    if (!list.length) {
      $box.prop('hidden', true).empty();
      return;
    }
    const body = list.map(function (row, index) {
      return '<tr>' +
        '<td>' + escapeHtml(row.line || '') + '</td>' +
        '<td><input class="prod-import-failed-input cli-import-failed-input" data-failed-index="' + index + '" data-field="legacyId" value="' + escapeHtml(row.legacyId || '') + '"></td>' +
        '<td><input class="prod-import-failed-input cli-import-failed-input" data-failed-index="' + index + '" data-field="firstName" value="' + escapeHtml(row.firstName || '') + '"></td>' +
        '<td><input class="prod-import-failed-input cli-import-failed-input" data-failed-index="' + index + '" data-field="lastName" value="' + escapeHtml(row.lastName || '') + '"></td>' +
        '<td>' + escapeHtml(row.error || '') + '</td>' +
      '</tr>';
    }).join('');
    $box.prop('hidden', false).html(
      '<div class="prod-import-failed-head">' +
        '<strong>Filas no importadas: ' + list.length + '</strong>' +
        '<div class="prod-import-failed-actions">' +
          '<button type="button" class="btn-secondary" id="cli-import-failed-download">Descargar errores CSV</button>' +
          '<button type="button" class="btn-primary" id="cli-import-failed-retry">Guardar correcciones</button>' +
        '</div>' +
      '</div>' +
      '<div class="catalog-table-wrap prod-import-failed-wrap">' +
        '<table class="grid grid-compact">' +
          '<thead><tr><th>Fila</th><th>ID</th><th>Nombres</th><th>Apellidos</th><th>Error</th></tr></thead>' +
          '<tbody>' + body + '</tbody>' +
        '</table>' +
      '</div>'
    );
    $('#cli-import-failed-download').on('click', downloadCustomerImportFailedRows);
    $('#cli-import-failed-retry').on('click', retryCustomerImportFailedRows);
  }

  function syncCustomerFailedInputs() {
    $('.cli-import-failed-input').each(function () {
      const index = Number($(this).data('failed-index'));
      const field = ($(this).data('field') || '').toString();
      if (Number.isInteger(index) && state.importFailedRows[index] && field) {
        state.importFailedRows[index][field] = ($(this).val() || '').toString();
      }
    });
  }

  function csvEscape(value) {
    const text = (value ?? '').toString();
    return /[",;\n\r]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
  }

  function downloadCustomerImportFailedRows() {
    syncCustomerFailedInputs();
    const headers = ['ID', 'NOMBRES', 'APELLIDOS', 'EMAIL', 'TELEFONO', 'DOMICILIO1', 'DOMICILIO2', 'COLONIA', 'MUNICIPIO', 'ESTADO', 'CODIGO_POSTAL', 'NOTAS', 'ERROR'];
    const lines = [headers.map(csvEscape).join(';')];
    state.importFailedRows.forEach(function (row) {
      lines.push([
        row.legacyId || '',
        row.firstName || '',
        row.lastName || '',
        row.email || '',
        row.phone || '',
        row.address1 || '',
        row.address2 || '',
        row.parish || '',
        row.canton || '',
        row.province || '',
        row.zip || '',
        row.notes || '',
        row.error || ''
      ].map(csvEscape).join(';'));
    });
    const blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'clientes_no_importados.csv';
    link.click();
    URL.revokeObjectURL(url);
  }

  function failedCustomerRowSource(row) {
    const source = $.extend({}, row);
    source.name = ((source.firstName || '') + ' ' + (source.lastName || '')).trim();
    source._importIssues = [];
    if (!source.name) source._importIssues.push('Sin nombres o apellidos');
    if (source.legacyId && !/^\d+$/.test((source.legacyId || '').toString())) source._importIssues.push('ID invalido');
    source._importStatus = source._importIssues.length ? 'warning' : 'ok';
    return source;
  }

  function runCustomerImportRows(rows, doneCallback) {
    const failedRows = rows
      .filter(function (row) { return row._importStatus === 'warning'; })
      .map(function (row) {
        return $.extend({}, row, { line: row._line || '', error: row._importIssues.join(', ') });
      });
    const validRows = rows.filter(function (row) { return row._importStatus !== 'warning'; });
    if (!validRows.length) {
      renderCustomerImportFailedRows(failedRows);
      $('#cli-import-result').text('No hay filas aptas para importar.');
      return;
    }
    let createdTotal = 0;
    let updatedTotal = 0;
    let processed = 0;
    setCustomerImportBusy(true);
    const sendBatch = function (startIndex) {
      const batchRows = validRows.slice(startIndex, startIndex + IMPORT_BATCH_SIZE);
      const currentBatch = Math.floor(startIndex / IMPORT_BATCH_SIZE) + 1;
      const totalBatches = Math.ceil(validRows.length / IMPORT_BATCH_SIZE);
      updateCustomerImportProgress(processed, validRows.length, 'Procesando lote ' + currentBatch + ' de ' + totalBatches + '...');
      $.ajax({
        url: '../api/customers.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ action: 'import_customers', rows: batchRows, finalBatch: startIndex + IMPORT_BATCH_SIZE >= validRows.length })
      }).done(function (res) {
        if (!res.ok) {
          failedRows.push({ line: '', legacyId: '', firstName: 'Lote ' + currentBatch, lastName: '', error: res.error || 'No se pudo completar la importacion.' });
          renderCustomerImportFailedRows(failedRows);
          setCustomerImportBusy(false);
          $('#cli-import-result').text('Importacion pausada. Revisa las filas con error.');
          return;
        }
        const data = res.data || {};
        createdTotal += Number(data.created || 0);
        updatedTotal += Number(data.updated || 0);
        (data.failedRows || []).forEach(function (row) { failedRows.push(row); });
        processed += batchRows.length;
        updateCustomerImportProgress(processed, validRows.length, Math.min(100, Math.round((processed / Math.max(1, validRows.length)) * 100)) + '%');
        if (startIndex + IMPORT_BATCH_SIZE < validRows.length) {
          sendBatch(startIndex + IMPORT_BATCH_SIZE);
          return;
        }
        setCustomerImportBusy(false);
        renderCustomerImportFailedRows(failedRows);
        $('#cli-import-result').text('Importacion completada. Creados: ' + createdTotal + ' | Actualizados: ' + updatedTotal + ' | No importados: ' + failedRows.length);
        loadCustomers($('#cli-search').val().toString());
        if (typeof doneCallback === 'function') doneCallback();
      }).fail(function (xhr) {
        failedRows.push({ line: '', legacyId: '', firstName: 'Lote ' + currentBatch, lastName: '', error: ajaxErrorMessage(xhr, 'No se pudo completar la importacion.') });
        renderCustomerImportFailedRows(failedRows);
        setCustomerImportBusy(false);
        $('#cli-import-result').text('Importacion pausada. Revisa las filas con error.');
      });
    };
    sendBatch(0);
  }

  function retryCustomerImportFailedRows() {
    syncCustomerFailedInputs();
    const rows = state.importFailedRows.map(failedCustomerRowSource);
    runCustomerImportRows(rows);
  }

  function toggleImportPanel() {
    const $panel = $('#cli-import-panel');
    const visible = !$panel.prop('hidden');
    $panel.prop('hidden', visible);
    $('#cli-import-toggle').text(visible ? 'Importar CSV' : 'Ocultar importacion');
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
    $('#cli-import-toggle').on('click', toggleImportPanel);
    $('#cli-import-preview-btn').on('click', readCustomerImportFile);
    $('#cli-import-run-btn').on('click', function () { runCustomerImportRows(state.importRows); });
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

