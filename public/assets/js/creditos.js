/* global $, window, document */
(function () {
  'use strict';

  function isEstadoPage() { return $('#cred-tbody').length > 0; }
  function isReportePage() { return $('#cred-reporte-tbody').length > 0; }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
  }

  function formatMoney(n) {
    return '$' + (Number(n || 0).toFixed(2));
  }

  const state = {
    customers: [],
    selectedId: null
  };

  function setAcceptEnabled() {
    $('#cred-accept').prop('disabled', !state.selectedId);
  }

  function render() {
    const $tbody = $('#cred-tbody').empty();
    state.customers.forEach(c => {
      const selected = c.id === state.selectedId;
      const $tr = $(`
        <tr class="cred-row ${selected ? 'row-selected' : ''}" data-id="${escapeHtml(c.id || '')}">
          <td class="cred-avatar">${escapeHtml((c.name || '?').toString().slice(0, 2).toUpperCase())}</td>
          <td class="cred-name">
            <div class="cred-name-main">${escapeHtml(c.name || '')}</div>
            <div class="muted cred-name-sub">${escapeHtml(c.id || '')}</div>
          </td>
          <td class="cred-balance">${formatMoney(c.balance)}</td>
        </tr>
      `);
      $tr.on('click', () => {
        state.selectedId = c.id;
        render();
        setAcceptEnabled();
      });
      $tbody.append($tr);
    });
  }

  function renderReporte(rows) {
    const $tbody = $('#cred-reporte-tbody').empty();
    (rows || []).forEach(r => {
      const nameAddress = (r?.nameAddress || '').toString();
      const parts = nameAddress.split('\n');
      const name = parts[0] || '';
      const addr = parts.slice(1).join('\n');
      const $tr = $(`
        <tr>
          <td>${escapeHtml(r?.number?.toString() || '')}</td>
          <td>
            <div class="cred-rep-name">${escapeHtml(name)}</div>
            ${addr ? `<div class="muted cred-rep-addr">${escapeHtml(addr)}</div>` : ''}
          </td>
          <td>${escapeHtml(r?.phone || '')}</td>
          <td>${escapeHtml(r?.creditLimit || '')}</td>
          <td class="cred-rep-balance">${formatMoney(r?.balance)}</td>
          <td>${escapeHtml(r?.lastPayment || '')}</td>
        </tr>
      `);
      $tbody.append($tr);
    });
  }

  function loadReporte() {
    return $.getJSON('../api/creditos_reporte_saldos.php').done(res => {
      const data = (res && res.ok) ? res.data : null;
      const total = data?.totalPending ?? 0;
      $('#cred-total-pendiente').text(formatMoney(total));
      renderReporte(data?.rows || []);
    });
  }

  function load(q) {
    return $.getJSON('../api/creditos_customers.php', { q: q || '' }).done(res => {
      state.customers = (res.ok && Array.isArray(res.data)) ? res.data : [];
      if (state.selectedId && !state.customers.some(c => c.id === state.selectedId)) {
        state.selectedId = null;
      }
      render();
      setAcceptEnabled();
    });
  }

  function bindEstado() {
    let t = null;
    $('#cred-search').on('input', function () {
      const q = $(this).val().toString();
      if (t) window.clearTimeout(t);
      t = window.setTimeout(() => load(q), 180);
    });

    $('#cred-btn-reporte').on('click', function () {
      window.location.href = 'index.php?mod=creditos&sub=reporte';
    });

    $('#cred-btn-estado').on('click', function () {
      // ya estamos en Estado de Cuenta
    });

    $('#cred-accept').on('click', function () {
      if (!state.selectedId) {
        alert('Seleccione un cliente');
        return;
      }
      window.location.href = 'index.php?mod=creditos&sub=estado-cliente&cid=' + encodeURIComponent(state.selectedId);
    });
  }

  function bindReporte() {
    $('#cred-btn-estado').on('click', function () {
      window.location.href = 'index.php?mod=creditos&sub=estado';
    });
    $('#cred-btn-reporte').on('click', function () {
      // ya estamos en Reporte de Saldos
    });
    $('#cred-print').on('click', function () {
      // Solo el botón por ahora (sin funcionalidad)
    });
  }

  $(function () {
    if (isEstadoPage()) {
      bindEstado();
      load('');
      $('#cred-search').focus();
      return;
    }
    if (isReportePage()) {
      bindReporte();
      loadReporte();
    }
  });
})();