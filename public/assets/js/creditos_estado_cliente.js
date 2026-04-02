/* global $, window, document */
(function () {
  'use strict';

  function formatMoney(n) {
    return '$' + (Number(n || 0).toFixed(2));
  }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;'}[s]));
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
    selectedIndex: 0,
    rawData: null
  };

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
  }

  function renderEstado() {
    const data = state.rawData || {};
    const client = data.client || {};
    const summary = data.summary || {};
    const movs = Array.isArray(data.movimientos) ? data.movimientos : [];
    state.movimientos = movs;

    if (state.selectedIndex >= movs.length) {
      state.selectedIndex = 0;
    }

    const nick = (client.name || '?').toString().trim().slice(0, 2).toUpperCase();
    $('#ec-nick').text(nick);
    $('#ec-client-name').text(client.name || 'Cliente');
    $('#ec-client-id').text('ID: ' + (client.id || ''));
    $('#ec-limit').text(formatMoney(client.limit || 0));
    $('#ec-saldo').text(formatMoney(summary.saldoActual || 0));
    $('#ec-total-mov').text(formatMoney(summary.totalMovimientos || 0));
    $('#ec-ultimo-pago').text(summary.ultimoPago || '');

    const $tbody = $('#ec-mov-tbody').empty();
    movs.forEach((m, idx) => {
      const selected = idx === state.selectedIndex;
      const $tr = $(`
        <tr class="${selected ? 'row-selected' : ''}">
          <td>${escapeHtml(m?.fechaHora || '')}</td>
          <td>${escapeHtml(m?.folio || '')}</td>
          <td><span class="ec-mov-badge ec-mov-badge--${String(m?.movimiento || '').toLowerCase()}">${escapeHtml(m?.movimiento || '')}</span></td>
          <td>${escapeHtml(m?.descripcion || '')}</td>
          <td class="ec-money">${formatMoney(m?.monto || 0)}</td>
          <td class="ec-money ec-money--saldo">${formatMoney(m?.saldoActual || 0)}</td>
          <td>${escapeHtml(m?.cajero || '')}</td>
        </tr>
      `);
      $tr.on('click', function () {
        state.selectedIndex = idx;
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

    

    $('#ec-btn-print').on('click', function () {
      window.print();
    });

    $('#ec-btn-consulta').on('click', function () {
      // Pendiente: consultar crÃ©dito anterior
    });

    load();
  });
})();
