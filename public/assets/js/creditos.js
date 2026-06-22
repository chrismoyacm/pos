/* global $, window, document */
(function () {
  'use strict';

  function isEstadoPage() { return $('#cred-tbody').length > 0; }
  function isReportePage() { return $('#cred-reporte-tbody').length > 0; }

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]));
  }

  function formatMoney(n) {
    return '$' + (Number(n || 0).toFixed(2));
  }

  const state = {
    customers: [],
    selectedId: null,
    reporteRows: [],
    reporteSortBy: 'balance',
    reporteSortDir: 'desc'
  };

  function reporteSortValue(row, key) {
    const current = row || {};
    switch ((key || '').toString()) {
      case 'number':
        return (current.number || '').toString().toLowerCase();
      case 'nameAddress':
        return (current.nameAddress || '').toString().toLowerCase();
      case 'phone':
        return (current.phone || '').toString().toLowerCase();
      case 'creditLimit':
        return Number(current.creditLimit || 0);
      case 'balance':
        return Number(current.balance || 0);
      case 'paymentDate':
        return Date.parse(current.paymentDate || '') || 0;
      case 'lastPayment':
        return Date.parse(current.lastPayment || '') || 0;
      default:
        return Number(current.balance || 0);
    }
  }

  function compareReporteRows(a, b) {
    const key = state.reporteSortBy || 'balance';
    const dir = state.reporteSortDir === 'asc' ? 1 : -1;
    const aValue = reporteSortValue(a, key);
    const bValue = reporteSortValue(b, key);

    if (typeof aValue === 'number' && typeof bValue === 'number') {
      if (aValue === bValue) return 0;
      return aValue > bValue ? dir : -dir;
    }

    const cmp = aValue.toString().localeCompare(bValue.toString(), 'es', {
      numeric: true,
      sensitivity: 'base'
    });
    if (cmp === 0) return 0;
    return cmp > 0 ? dir : -dir;
  }

  function updateReporteSortHeaders() {
    $('[data-cred-reporte-sort]').each(function () {
      const $th = $(this);
      const sortKey = ($th.data('cred-reporte-sort') || '').toString();
      let label = ($th.data('cred-reporte-sort-label') || '').toString();
      if (!label) {
        label = $th.text().replace(/\s+[↑↓]$/, '').trim();
        $th.data('cred-reporte-sort-label', label);
      }
      const active = sortKey === state.reporteSortBy;
      const arrow = active ? (state.reporteSortDir === 'asc' ? ' ↑' : ' ↓') : '';
      $th.text(label + arrow);
      $th.toggleClass('is-sort-active', active);
    });
  }

  function toggleReporteSort(sortBy) {
    const nextKey = (sortBy || '').toString();
    if (!nextKey) return;
    if (state.reporteSortBy === nextKey) {
      state.reporteSortDir = state.reporteSortDir === 'asc' ? 'desc' : 'asc';
    } else {
      state.reporteSortBy = nextKey;
      state.reporteSortDir = nextKey === 'paymentDate' || nextKey === 'lastPayment' || nextKey === 'balance' ? 'desc' : 'asc';
    }
    renderReporte(state.reporteRows);
  }

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
    const safeRows = Array.isArray(rows) ? rows.slice() : [];
    safeRows.sort(compareReporteRows);
    updateReporteSortHeaders();

    safeRows.forEach(r => {
      const nameAddress = (r?.nameAddress || '').toString();
      const parts = nameAddress.split('\n');
      const name = parts[0] || '';
      const addr = parts.slice(1).join('\n');
      const overdueClass = r?.isOverdue ? 'cred-rep-overdue' : '';
      const overdueRowClass = r?.isOverdue ? 'cred-rep-row-overdue' : '';
      const status = (r?.paymentStatus || '').toString();
      const paymentDate = (r?.paymentDate || 'No definido').toString();
      const $tr = $(`
        <tr class="${overdueRowClass}">
          <td>${escapeHtml(r?.number?.toString() || '')}</td>
          <td>
            <div class="cred-rep-name">${escapeHtml(name)}</div>
            ${addr ? `<div class="muted cred-rep-addr">${escapeHtml(addr)}</div>` : ''}
          </td>
          <td>${escapeHtml(r?.phone || '')}</td>
          <td>${escapeHtml(r?.creditLimit || '')}</td>
          <td class="cred-rep-balance ${overdueClass}">${formatMoney(r?.balance)}</td>
          <td class="${overdueClass}">
            <div>${escapeHtml(paymentDate)}</div>
            ${status ? `<div class="muted">${escapeHtml(status)}</div>` : ''}
          </td>
          <td>${escapeHtml(r?.lastPayment || '')}</td>
        </tr>
      `);
      $tbody.append($tr);
    });

    if (safeRows.length === 0) {
      $tbody.append('<tr><td colspan="7" class="muted">No hay saldos para mostrar.</td></tr>');
    }
  }

  function loadReporte() {
    return $.getJSON('../api/creditos_reporte_saldos.php', {
      all: 1
    }).done(res => {
      const data = (res && res.ok) ? res.data : null;
      const total = data?.totalPending ?? 0;
      $('#cred-total-pendiente').text(formatMoney(total));
      state.reporteRows = Array.isArray(data?.rows) ? data.rows : [];
      renderReporte(state.reporteRows);
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
      // Solo el boton por ahora (sin funcionalidad)
    });
    $('[data-cred-reporte-sort]').on('click', function () {
      toggleReporteSort(($(this).data('cred-reporte-sort') || '').toString());
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
