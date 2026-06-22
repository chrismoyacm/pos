/* global $, window, document */
(function () {
  'use strict';

  function formatMoney(n) {
    return '$' + (Number(n || 0).toFixed(2));
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
  }

  function getQueryParam(name) {
    const url = new URL(window.location.href);
    return url.searchParams.get(name) || '';
  }

  function isEstadoClientePage() {
    return $('#ec-mov-tbody').length > 0;
  }

  const state = {
    movimientos: [],
    allMovimientos: [],
    selectedIndex: 0,
    selectedMovementKey: '',
    rawData: null,
    currentDebt: 0,
    sortBy: 'fechaHora',
    sortDir: 'desc',
    movementFilter: 'all',
    periodFilter: 'since_last_liquidation'
  };

  function closeModal(selector) {
    $(selector).removeClass('active');
  }

  function getSelectedMovimiento() {
    return state.movimientos[state.selectedIndex] || null;
  }

  function matchesMovementFilter(movement) {
    const filter = String(state.movementFilter || 'all').toLowerCase();
    if (filter === 'all') return true;

    const type = String(movement?.movimiento || '').trim().toUpperCase();
    const description = String(movement?.descripcion || '').trim().toLowerCase();
    const isLiquidationText = description.indexOf('liquid') >= 0;
    const isPaymentMovement = type === 'LIQUIDAR' || type === 'COBRO' || type === 'ABONO';

    if (filter === 'venta') {
      return type === 'VENTA';
    }
    if (filter === 'cobro') {
      return isPaymentMovement && !isLiquidationText;
    }
    if (filter === 'liquidar') {
      return type === 'LIQUIDAR' && isLiquidationText;
    }
    return true;
  }

  function parseMovementDate(movement) {
    const raw = String(movement?.createdAt || movement?.fechaHora || '').trim();
    if (!raw) return null;
    const parsed = new Date(raw);
    if (!Number.isNaN(parsed.getTime())) {
      return parsed;
    }
    const match = raw.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (!match) return null;
    const day = Number(match[1]);
    const month = Number(match[2]) - 1;
    const year = Number(match[3]);
    const hours = Number(match[4] || 0);
    const minutes = Number(match[5] || 0);
    const seconds = Number(match[6] || 0);
    return new Date(year, month, day, hours, minutes, seconds);
  }

  function isLiquidationMovement(movement) {
    const type = String(movement?.movimiento || '').trim().toUpperCase();
    const description = String(movement?.descripcion || '').trim().toLowerCase();
    return type === 'LIQUIDAR' && description.indexOf('liquid') >= 0;
  }

  function startOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0, 0);
  }

  function matchesPeriodFilter(movement, allMovements) {
    const filter = String(state.periodFilter || 'since_last_liquidation').toLowerCase();
    if (filter === 'all_time') return true;

    const movementDate = parseMovementDate(movement);
    if (!(movementDate instanceof Date)) {
      return filter === 'since_last_liquidation';
    }

    const today = new Date();
    const todayStart = startOfDay(today);

    if (filter === 'this_week') {
      const weekStart = new Date(todayStart);
      const day = weekStart.getDay();
      const diff = day === 0 ? 6 : day - 1;
      weekStart.setDate(weekStart.getDate() - diff);
      return movementDate >= weekStart;
    }

    if (filter === 'this_month') {
      const monthStart = new Date(todayStart.getFullYear(), todayStart.getMonth(), 1, 0, 0, 0, 0);
      return movementDate >= monthStart;
    }

    if (filter === 'last_90_days') {
      const rangeStart = new Date(todayStart);
      rangeStart.setDate(rangeStart.getDate() - 90);
      return movementDate >= rangeStart;
    }

    if (filter === 'since_last_liquidation') {
      const latestLiquidation = (Array.isArray(allMovements) ? allMovements : [])
        .filter(isLiquidationMovement)
        .map(parseMovementDate)
        .filter(function (date) { return date instanceof Date; })
        .sort(function (a, b) { return b.getTime() - a.getTime(); })[0] || null;
      if (!(latestLiquidation instanceof Date)) {
        return true;
      }
      return movementDate >= latestLiquidation;
    }

    return true;
  }

  function movementKey(movement) {
    if (!movement) return '';
    return [
      movement?.folio || '',
      movement?.createdAt || '',
      movement?.movimiento || '',
      movement?.monto || 0
    ].join('|');
  }

  function movementSortValue(movement, key) {
    if (key === 'monto') return Number(movement?.monto || 0);
    if (key === 'saldoActual') return Number(movement?.saldoActual || 0);
    if (key === 'fechaHora') return String(movement?.createdAt || movement?.fechaHora || '');
    if (key === 'movimiento') {
      const type = String(movement?.movimiento || '').toUpperCase();
      if (type === 'LIQUIDAR') return 1;
      if (type === 'VENTA') return 2;
      if (type === 'SALDO_INICIAL') return 3;
      return 9;
    }
    return String(movement?.[key] || '').toLowerCase();
  }

  function compareMovements(a, b) {
    const key = state.sortBy || 'fechaHora';
    const dir = state.sortDir === 'asc' ? 1 : -1;
    const aValue = movementSortValue(a, key);
    const bValue = movementSortValue(b, key);

    if (typeof aValue === 'number' && typeof bValue === 'number') {
      if (aValue === bValue) return 0;
      return aValue > bValue ? dir : -dir;
    }

    const cmp = String(aValue).localeCompare(String(bValue), 'es', { numeric: true, sensitivity: 'base' });
    if (cmp === 0) return 0;
    return cmp > 0 ? dir : -dir;
  }

  function updateSortHeaders() {
    $('[data-ec-sort]').each(function () {
      const $th = $(this);
      const sortKey = ($th.data('ec-sort') || '').toString();
      let label = ($th.data('ec-sort-label') || '').toString();
      if (!label) {
        label = $th.text().replace(/\s+[↑↓]$/, '').trim();
        $th.data('ec-sort-label', label);
      }
      const active = sortKey === state.sortBy;
      const arrow = active ? (state.sortDir === 'asc' ? ' ↑' : ' ↓') : '';
      $th.text(label + arrow);
      $th.toggleClass('is-sort-active', active);
    });
  }

  function toggleMovementSort(sortBy) {
    const nextKey = (sortBy || '').toString();
    if (!nextKey) return;
    if (state.sortBy === nextKey) {
      state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      state.sortBy = nextKey;
      state.sortDir = nextKey === 'fechaHora' ? 'desc' : 'asc';
    }
    renderEstado();
  }

  function getSelectedSaleMovimiento() {
    const mov = getSelectedMovimiento();
    if (!mov || !mov.canReceivePayment) {
      return null;
    }
    return Number(mov?.ticket?.montoPendiente || 0) > 0 ? mov : null;
  }

  function updateDebtActionButtons() {
    const hasDebt = Number(state.currentDebt || 0) > 0;
    const sourceMovs = Array.isArray(state.allMovimientos) && state.allMovimientos.length ? state.allMovimientos : state.movimientos;
    const hasOpenSale = sourceMovs.some(m => Boolean(m?.canReceivePayment) && Number(m?.ticket?.montoPendiente || 0) > 0);
    $('#ec-btn-abonar').prop('disabled', !hasOpenSale);
    $('#ec-btn-liquidar').prop('disabled', !hasDebt);
  }

  function thermalPaperWidthMm() {
    const raw = (window.localStorage.getItem('pos.print.paperWidthMm') || '').toString().trim();
    if (raw === '80') return 80;
    return 58;
  }

  function openPrintableTicket(ticket) {
    const data = ticket || {};
    const items = Array.isArray(data.items) ? data.items : [];
    if (items.length === 0) {
      window.alert('El movimiento seleccionado no tiene articulos para imprimir.');
      return false;
    }

    const paperMm = thermalPaperWidthMm();
    const rows = items.map(function (item) {
      return (
        '<tr>' +
          '<td class="c-qty">' + escapeHtml(String(item?.cantidad || '')) + '</td>' +
          '<td class="c-desc">' + escapeHtml(String(item?.descripcion || '')) + '</td>' +
          '<td class="c-amt">' + formatMoney(Number(item?.importe || 0)) + '</td>' +
        '</tr>'
      );
    }).join('');

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
      '.c-amt{width:30%;text-align:right;white-space:nowrap}',
      '.tot{font-size:10px;line-height:1.45;margin-top:3px}',
      '.tot .line{display:flex;justify-content:space-between;gap:8px}',
      '.tot .line.total{font-weight:700;font-size:11px}',
      '.footer{margin-top:4px;text-align:center;font-size:9px}',
      '</style></head><body>',
      '<div class="ticket">',
      '<div class="center title">CREDITO - TICKET ' + escapeHtml(String(data.folio || '')) + '</div>',
      '<div class="head">',
      '<div>Fecha: ' + escapeHtml(String(data.fechaHora || '')) + '</div>',
      '<div>Cliente: ' + escapeHtml(String(data.cliente || '')) + '</div>',
      '<div>Cajero: ' + escapeHtml(String(data.cajero || '')) + '</div>',
      '<div>Pago con: ' + escapeHtml(String(data.pagoCon || '')) + '</div>',
      '</div>',
      '<div class="sep"></div>',
      '<table><thead><tr><th class="c-qty">Cant</th><th class="c-desc">Descripcion</th><th class="c-amt">Importe</th></tr></thead><tbody>',
      rows,
      '</tbody></table>',
      '<div class="sep"></div>',
      '<div class="tot">',
      '<div class="line total"><span>Total:</span><span>' + formatMoney(Number(data.total || 0)) + '</span></div>',
      '<div class="line"><span>Pendiente:</span><span>' + formatMoney(Number(data.montoPendiente || 0)) + '</span></div>',
      '</div>',
      '<div class="footer">Reimpresion de credito</div>',
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
      window.alert('No se pudo abrir la impresion.');
      return false;
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
    }, 250);

    return true;
  }

  function updateTicketActionButtons(ticket) {
    const items = Array.isArray(ticket?.items) ? ticket.items : [];
    $('#ec-ticket-reprint-btn').prop('disabled', items.length === 0);
  }

  function reprintSelectedTicket() {
    const movement = getSelectedMovimiento();
    const ticket = movement?.ticket || null;
    if (!ticket) {
      window.alert('Seleccione un movimiento.');
      return;
    }
    openPrintableTicket(ticket);
  }

  function updatePagoPendiente() {
    const mode = ($('#ec-pago-mode').val() || 'abonar').toString();
    const destino = ($('#ec-pago-destino').val() || 'selected_only').toString();
    const base = mode === 'liquidar' || destino === 'all_equal'
      ? Number(state.currentDebt || 0)
      : Number($('#ec-pago-venta-pendiente').data('pending') || 0);
    const amount = parseFloat($('#ec-pago-monto').val() || '0') || 0;
    const pending = Math.max(0, base - Math.max(0, amount));
    $('#ec-pago-pendiente').val(formatMoney(pending));
  }

  function updatePagoDestinoUi() {
    const mode = ($('#ec-pago-mode').val() || 'abonar').toString();
    const destino = ($('#ec-pago-destino').val() || 'selected_only').toString();
    const isLiquidar = mode === 'liquidar';
    $('#ec-pago-destino').prop('disabled', isLiquidar);
    $('#ec-pago-pendiente-label').text(
      isLiquidar || destino === 'all_equal' ? 'Saldo despues del pago' : 'Pendiente de la venta'
    );
    updatePagoPendiente();
  }

  function openPagoModal(mode) {
    const debt = Number(state.currentDebt || 0);
    if (!(debt > 0)) {
      window.alert('El cliente no tiene deuda pendiente.');
      return;
    }

    const isLiquidar = mode === 'liquidar';
    const selectedSale = getSelectedSaleMovimiento();
    if (!isLiquidar && !selectedSale) {
      window.alert('Seleccione una venta con saldo pendiente para abonar.');
      return;
    }

    const selectedFolio = isLiquidar
      ? 'Todas las ventas pendientes'
      : (selectedSale?.folio || selectedSale?.ticket?.folio || '');
    const selectedPending = isLiquidar
      ? debt
      : Number(selectedSale?.ticket?.montoPendiente || 0);

    $('#ec-pago-mode').val(isLiquidar ? 'liquidar' : 'abonar');
    $('#ec-pago-title').text(isLiquidar ? 'Liquidar deuda' : 'Abonar a deuda');
    $('#ec-pago-venta').val(selectedFolio);
    $('#ec-pago-venta-pendiente')
      .val(formatMoney(selectedPending))
      .data('pending', selectedPending);
    $('#ec-pago-deuda').val(formatMoney(debt));
    $('#ec-pago-monto').val(isLiquidar ? debt.toFixed(2) : '');
    $('#ec-pago-monto').prop('readonly', isLiquidar);
    $('#ec-pago-destino').val('selected_only');
    $('#ec-pago-nota').val(isLiquidar ? 'Liquidacion de deuda' : ('Abono a ' + selectedFolio));
    $('#modal-ec-pago').addClass('active');
    updatePagoDestinoUi();
    if (isLiquidar) {
      $('#ec-pago-save').focus();
    } else {
      $('#ec-pago-monto').focus();
    }
  }

  function submitPago(confirmOverflow) {
    const cid = getQueryParam('cid');
    if (!cid) return;

    const mode = ($('#ec-pago-mode').val() || 'abonar').toString();
    const debt = Number(state.currentDebt || 0);
    const amount = parseFloat($('#ec-pago-monto').val() || '0') || 0;
    const note = ($('#ec-pago-nota').val() || '').toString().trim();
    const destino = ($('#ec-pago-destino').val() || 'selected_only').toString();
    const selectedSale = getSelectedSaleMovimiento();

    if (!(amount > 0)) {
      window.alert('Ingrese un monto valido.');
      return;
    }

    if (mode === 'liquidar' && Math.abs(amount - debt) > 0.009) {
      window.alert('En liquidar, el monto debe ser igual al total de la deuda.');
      return;
    }

    if (mode === 'abonar' && amount >= debt) {
      window.alert('El abono debe ser menor que la deuda total. Para pagar todo use Liquidar.');
      return;
    }

    if (mode === 'abonar' && !selectedSale) {
      window.alert('Seleccione una venta con saldo pendiente para abonar.');
      return;
    }

    if (mode === 'abonar' && destino === 'selected_only') {
      const selectedPending = Number(selectedSale?.ticket?.montoPendiente || 0);
      if (amount - selectedPending > 0.009) {
        window.alert('El monto supera el pendiente de la venta seleccionada. Cambie el destino a todas las ventas o reduzca el abono.');
        return;
      }
    }

    $.ajax({
      url: '../api/creditos_estado_cliente.php?cid=' + encodeURIComponent(cid),
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        action: mode,
        amount: amount,
        description: note,
        destination: destino,
        selectedSaleKey: selectedSale?.saleKey || '',
        confirmOverflow: Boolean(confirmOverflow)
      })
    }).done(function (res) {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo registrar el pago.');
        return;
      }
      closeModal('#modal-ec-pago');
      load();
    }).fail(function (xhr) {
      const details = xhr?.responseJSON?.details || null;
      if (details?.requiresConfirmation) {
        const message = xhr?.responseJSON?.error || 'El pago se repartira entre otras ventas.';
        if (window.confirm(message)) {
          submitPago(true);
        }
        return;
      }
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'No se pudo registrar el pago.';
      window.alert(backendError);
    });
  }

  function savePago() {
    submitPago(false);
  }

  function renderTicket(ticket) {
    const data = ticket || {};
    const items = Array.isArray(data.items) ? data.items : [];

    $('#ec-ticket-folio').text(data.folio || '');
    $('#ec-ticket-cajero').text(data.cajero || '');
    $('#ec-ticket-cliente').text(data.cliente || '');
    $('#ec-ticket-fecha').text(data.fechaHora || '');
    $('#ec-ticket-pago-con').text(data.pagoCon || '');
    $('#ec-pago-total').text(formatMoney(data.total || 0));
    $('#ec-pendiente').text(formatMoney(data.montoPendiente || 0));

    const $tbody = $('#ec-ticket-items').empty();
    items.forEach(item => {
      $tbody.append(`
        <tr>
          <td>${escapeHtml(String(item?.cantidad || ''))}</td>
          <td>${escapeHtml(item?.descripcion || '')}</td>
          <td class="ec-money">${formatMoney(item?.importe || 0)}</td>
        </tr>
      `);
    });

    updateTicketActionButtons(data);
  }

  function renderEstado() {
    const data = state.rawData || {};
    const client = data.client || {};
    const summary = data.summary || {};
    const currentSelected = getSelectedMovimiento();
    if (currentSelected) {
      state.selectedMovementKey = movementKey(currentSelected);
    }
    const allMovs = Array.isArray(data.movimientos) ? data.movimientos.slice() : [];
    state.allMovimientos = allMovs.slice();
    const movs = allMovs.filter(function (movement) {
      return matchesMovementFilter(movement) && matchesPeriodFilter(movement, allMovs);
    });
    movs.sort(compareMovements);
    state.movimientos = movs;

    if (state.selectedMovementKey) {
      const selectedIdx = movs.findIndex(m => movementKey(m) === state.selectedMovementKey);
      state.selectedIndex = selectedIdx >= 0 ? selectedIdx : 0;
    } else if (state.selectedIndex >= movs.length) {
      state.selectedIndex = 0;
    }

    const nick = (client.name || '?').toString().trim().slice(0, 2).toUpperCase();
    $('#ec-nick').text(nick);
    $('#ec-client-name').text(client.name || 'Cliente');
    $('#ec-client-id').text('ID: ' + (client.id || ''));
    $('#ec-limit').text(formatMoney(client.limit || 0));
    $('#ec-saldo').text(formatMoney(summary.saldoActual || 0));
    state.currentDebt = Number(summary.saldoActual || 0);
    updateDebtActionButtons();
    updateSortHeaders();
    $('#ec-total-mov').text(formatMoney(summary.totalMovimientos || 0));
    $('#ec-ultimo-pago').text(summary.ultimoPago || '');

    const $tbody = $('#ec-mov-tbody').empty();
    movs.forEach((m, idx) => {
      const selected = idx === state.selectedIndex;
      const overdue = Boolean(m?.isOverdue);
      const dueDate = (m?.ticket?.dueDate || '').toString().trim();
      const $tr = $(`
        <tr class="${selected ? 'row-selected' : ''} ${overdue ? 'row-overdue' : ''}">
          <td>${escapeHtml(m?.fechaHora || '')}</td>
          <td>${escapeHtml(m?.folio || '')}</td>
          <td><span class="ec-mov-badge ec-mov-badge--${String(m?.movimiento || '').toLowerCase()}">${escapeHtml(m?.movimiento || '')}</span></td>
          <td>${escapeHtml(m?.descripcion || '')}</td>
          <td>${escapeHtml(dueDate || '--')}</td>
          <td class="ec-money">${formatMoney(m?.monto || 0)}</td>
          <td class="ec-money ec-money--saldo">${formatMoney(m?.saldoActual || 0)}</td>
          <td>${escapeHtml(m?.cajero || '')}</td>
        </tr>
      `);
      $tr.on('click', function () {
        state.selectedIndex = idx;
        state.selectedMovementKey = movementKey(m);
        renderEstado();
      });
      $tbody.append($tr);
    });

    renderTicket(movs[state.selectedIndex]?.ticket || null);
  }

  function load() {
    const cid = getQueryParam('cid');
    if (!cid) return;
    $.getJSON('../api/creditos_estado_cliente.php', { cid: cid }).done(res => {
      const data = (res && res.ok) ? res.data : null;
      if (!data) return;
      state.rawData = data;
      state.selectedIndex = 0;
      state.selectedMovementKey = '';
      renderEstado();
    });
  }

  $(function () {
    if (!isEstadoClientePage()) return;

    $('#ec-btn-estado').on('click', function () {
      window.location.href = 'index.php?mod=creditos&sub=estado';
    });

    $('#ec-btn-reporte').on('click', function () {
      window.location.href = 'index.php?mod=creditos&sub=reporte';
    });

    $('#ec-btn-abonar').on('click', function () {
      openPagoModal('abonar');
    });

    $('#ec-btn-liquidar').on('click', function () {
      openPagoModal('liquidar');
    });

    $('#ec-pago-cancel').on('click', function () {
      closeModal('#modal-ec-pago');
    });

    $('#ec-pago-save').on('click', savePago);
    $('#ec-pago-monto').on('input', updatePagoPendiente);
    $('#ec-pago-destino').on('change', updatePagoDestinoUi);
    $('#ec-pago-monto').on('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        savePago();
      }
    });

    $('#ec-btn-print').on('click', function () {
      window.print();
    });

    $('[data-ec-sort]').on('click', function () {
      toggleMovementSort(($(this).data('ec-sort') || '').toString());
    });

    $('#ec-mov-filter').on('change', function () {
      state.movementFilter = (($(this).val() || 'all').toString());
      state.selectedIndex = 0;
      state.selectedMovementKey = '';
      renderEstado();
    });

    $('#ec-period-filter').on('change', function () {
      state.periodFilter = (($(this).val() || 'since_last_liquidation').toString());
      state.selectedIndex = 0;
      state.selectedMovementKey = '';
      renderEstado();
    });

    $('#ec-ticket-reprint-btn').on('click', function () {
      reprintSelectedTicket();
    });

    $('#ec-btn-consulta').on('click', function () {
      // Pendiente: consultar credito anterior
    });

    $(window).on('keydown', function (e) {
      if (e.key === 'Escape' && $('#modal-ec-pago').hasClass('active')) {
        closeModal('#modal-ec-pago');
      }
    });

    load();
  });
})();
