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
    rawData: null,
    currentDebt: 0
  };

  function closeModal(selector) {
    $(selector).removeClass('active');
  }

  function updateDebtActionButtons() {
    const hasDebt = Number(state.currentDebt || 0) > 0;
    $('#ec-btn-abonar').prop('disabled', !hasDebt);
    $('#ec-btn-liquidar').prop('disabled', !hasDebt);
  }

  function updatePagoPendiente() {
    const debt = Number(state.currentDebt || 0);
    const amount = parseFloat($('#ec-pago-monto').val() || '0') || 0;
    const pending = Math.max(0, debt - Math.max(0, amount));
    $('#ec-pago-pendiente').val(formatMoney(pending));
  }

  function openPagoModal(mode) {
    const debt = Number(state.currentDebt || 0);
    if (!(debt > 0)) {
      window.alert('El cliente no tiene deuda pendiente.');
      return;
    }

    const isLiquidar = mode === 'liquidar';
    $('#ec-pago-mode').val(isLiquidar ? 'liquidar' : 'abonar');
    $('#ec-pago-title').text(isLiquidar ? 'Liquidar deuda' : 'Abonar a deuda');
    $('#ec-pago-deuda').val(formatMoney(debt));
    $('#ec-pago-monto').val(isLiquidar ? debt.toFixed(2) : '');
    $('#ec-pago-monto').prop('readonly', isLiquidar);
    $('#ec-pago-nota').val(isLiquidar ? 'Liquidación de deuda' : 'Abono a deuda');
    $('#modal-ec-pago').addClass('active');
    updatePagoPendiente();
    if (isLiquidar) {
      $('#ec-pago-save').focus();
    } else {
      $('#ec-pago-monto').focus();
    }
  }

  function savePago() {
    const cid = getQueryParam('cid');
    if (!cid) return;

    const mode = ($('#ec-pago-mode').val() || 'abonar').toString();
    const debt = Number(state.currentDebt || 0);
    const amount = parseFloat($('#ec-pago-monto').val() || '0') || 0;
    const note = ($('#ec-pago-nota').val() || '').toString().trim();

    if (!(amount > 0)) {
      window.alert('Ingrese un monto válido.');
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

    $.ajax({
      url: '../api/creditos_estado_cliente.php?cid=' + encodeURIComponent(cid),
      method: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        action: mode,
        amount: amount,
        description: note
      })
    }).done(function (res) {
      if (!res.ok) {
        window.alert(res.error || 'No se pudo registrar el pago.');
        return;
      }
      closeModal('#modal-ec-pago');
      load();
    }).fail(function (xhr) {
      const backendError = xhr?.responseJSON?.error || xhr?.statusText || 'No se pudo registrar el pago.';
      window.alert(backendError);
    });
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
    state.currentDebt = Number(summary.saldoActual || 0);
    updateDebtActionButtons();
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
    $('#ec-pago-monto').on('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        savePago();
      }
    });

    

    $('#ec-btn-print').on('click', function () {
      window.print();
    });

    $('#ec-btn-consulta').on('click', function () {
      // Pendiente: consultar crÃ©dito anterior
    });

    $(window).on('keydown', function (e) {
      if (e.key === 'Escape' && $('#modal-ec-pago').hasClass('active')) {
        closeModal('#modal-ec-pago');
      }
    });

    load();
  });
})();
