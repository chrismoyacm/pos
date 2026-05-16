<?php
declare(strict_types=1);

require_once __DIR__ . '/../../utils/persistence.php';

$userName = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Usuario';
$persistenceStatus = persistenceRefreshStatus('header');
$showPersistenceAlert = (bool)($persistenceStatus['active'] ?? false);
$persistenceMessage = trim((string)($persistenceStatus['message'] ?? ''));
?>
<header class="topbar">
  <div class="logo">
    <img src="assets/img/logo.svg" alt="logo">
    <span>Punto de Venta</span>
  </div>
  <div class="topbar-user">
    <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
    <button class="logout-btn" type="button" onclick="openShiftExitPanel()">Salir</button>
  </div>
</header>
<div
  class="topbar-db-alert<?php echo $showPersistenceAlert ? ' is-visible' : ''; ?>"
  id="topbar-db-alert"
  role="alert"
  <?php echo $showPersistenceAlert ? '' : 'hidden'; ?>
>
  <strong>Modo respaldo:</strong>
  <span id="topbar-db-alert-text"><?php echo htmlspecialchars($persistenceMessage !== '' ? $persistenceMessage : 'Sin conexion a base de datos. Operando con respaldo JSON.'); ?></span>
</div>

<div class="shift-panel-overlay" id="shift-exit-overlay" hidden onclick="closeShiftPanels()">
  <div class="shift-panel-window" onclick="event.stopPropagation()">
    <div class="shift-panel-titlebar">
      <span>Cierre de turno</span>
      <button type="button" class="shift-panel-close" onclick="closeShiftPanels()">x</button>
    </div>
    <div class="shift-panel-body">
      <h3 class="shift-panel-heading">CIERRE DE TURNO</h3>
      <div class="shift-panel-options">
        <button type="button" class="shift-panel-btn shift-panel-btn--primary" onclick="openShiftClosingPanel()">Cerrar turno</button>
        <button type="button" class="shift-panel-btn" onclick="leaveShiftOpenAndExit()">Dejar turno abierto y salir</button>
        <button type="button" class="shift-panel-btn" onclick="closeShiftPanels()">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<div class="shift-panel-overlay" id="shift-close-overlay" hidden onclick="closeShiftPanels()">
  <div class="shift-panel-window shift-panel-window--wide" onclick="event.stopPropagation()">
    <div class="shift-panel-titlebar">
      <span>Cierre de turno</span>
      <button type="button" class="shift-panel-close" onclick="closeShiftClosingPanel()">x</button>
    </div>
    <div class="shift-panel-body shift-panel-body--close">
      <h3 class="shift-panel-heading">CIERRE DE TURNO</h3>
      <p class="shift-panel-text">Por favor cuenta el dinero en caja e ingresalo para proceder con el cierre de turno.</p>

      <div class="shift-close-grid">
        <div class="shift-close-row">
          <span>Efectivo esperado en caja</span>
          <strong id="shift-expected-cash">$0.00</strong>
        </div>
        <div class="shift-close-row">
          <label for="shift-actual-cash">Cuanto efectivo hay en caja?</label>
          <input id="shift-actual-cash" type="number" step="0.01" min="0" value="0.00">
        </div>
        <div class="shift-close-row">
          <span>Diferencia</span>
          <strong id="shift-difference">$0.00</strong>
        </div>
      </div>

      <div class="shift-close-status" id="shift-close-status">Excelente! Todo en orden</div>
      <div class="shift-close-error" id="shift-close-error"></div>

      <div class="shift-panel-footer">
        <button type="button" class="shift-panel-btn shift-panel-btn--primary" id="shift-close-confirm-btn" onclick="confirmShiftClose()">Cerrar Turno</button>
        <button type="button" class="shift-panel-btn" onclick="closeShiftClosingPanel()">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    var shiftExpectedCash = 0;

    function getNode(id) {
      return document.getElementById(id);
    }

    function money(value) {
      return '$' + Number(value || 0).toFixed(2);
    }

    function hideOverlay(id) {
      var node = getNode(id);
      node.hidden = true;
      node.style.display = 'none';
    }

    function showOverlay(id) {
      var node = getNode(id);
      node.hidden = false;
      node.style.display = 'flex';
    }

    function setShiftCloseError(message) {
      getNode('shift-close-error').textContent = message || '';
    }

    function updateShiftDifference() {
      var actual = Number(getNode('shift-actual-cash').value || 0);
      var difference = actual - shiftExpectedCash;
      getNode('shift-difference').textContent = money(difference);

      var status = getNode('shift-close-status');
      if (difference === 0) {
        status.textContent = 'Excelente! Todo en orden';
        status.className = 'shift-close-status shift-close-status--ok';
      } else if (difference > 0) {
        status.textContent = 'Hay un sobrante en caja';
        status.className = 'shift-close-status shift-close-status--warn';
      } else {
        status.textContent = 'Hay un faltante en caja';
        status.className = 'shift-close-status shift-close-status--danger';
      }
    }

    window.openShiftExitPanel = function () {
      showOverlay('shift-exit-overlay');
    };

    window.closeShiftPanels = function () {
      hideOverlay('shift-exit-overlay');
      hideOverlay('shift-close-overlay');
      setShiftCloseError('');
    };

    window.closeShiftClosingPanel = function () {
      hideOverlay('shift-close-overlay');
      showOverlay('shift-exit-overlay');
      setShiftCloseError('');
    };

    window.openShiftClosingPanel = function () {
      setShiftCloseError('');
      fetch('../api/shift.php')
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) {
            setShiftCloseError(data.error || 'No se pudo consultar el turno.');
            return;
          }

          if (!data.data || !data.data.hasOpenShift) {
            setShiftCloseError('No hay un turno abierto para cerrar.');
            return;
          }

          shiftExpectedCash = Number(data.data.expectedCash || 0);
          getNode('shift-expected-cash').textContent = money(shiftExpectedCash);
          getNode('shift-actual-cash').value = shiftExpectedCash.toFixed(2);
          updateShiftDifference();
          hideOverlay('shift-exit-overlay');
          showOverlay('shift-close-overlay');
          getNode('shift-actual-cash').focus();
          getNode('shift-actual-cash').select();
        })
        .catch(function () {
          setShiftCloseError('Error de conexion.');
        });
    };

    window.leaveShiftOpenAndExit = function () {
      fetch('../api/shift.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'leave_open' })
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) {
            setShiftCloseError(data.error || 'No se pudo salir.');
            return;
          }
          window.location.href = data.redirect || '../api/auth.php?action=logout';
        })
        .catch(function () {
          setShiftCloseError('Error de conexion.');
        });
    };

    window.confirmShiftClose = function () {
      var actualCash = Number(getNode('shift-actual-cash').value || 0);
      if (!isFinite(actualCash) || actualCash < 0) {
        setShiftCloseError('Ingrese un monto valido.');
        return;
      }

      var button = getNode('shift-close-confirm-btn');
      button.disabled = true;
      setShiftCloseError('');

      fetch('../api/shift.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'close_shift', actualCash: actualCash })
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          button.disabled = false;
          if (!data.ok) {
            setShiftCloseError(data.error || 'No se pudo cerrar el turno.');
            return;
          }
          window.location.href = data.redirect || '../api/auth.php?action=logout';
        })
        .catch(function () {
          button.disabled = false;
          setShiftCloseError('Error de conexion.');
        });
    };

    hideOverlay('shift-exit-overlay');
    hideOverlay('shift-close-overlay');
    getNode('shift-actual-cash').addEventListener('input', updateShiftDifference);

    function updatePersistenceBanner(status) {
      var alertNode = getNode('topbar-db-alert');
      var textNode = getNode('topbar-db-alert-text');
      if (!alertNode || !textNode || !status) return;

      var active = Boolean(status.active);
      var message = (status.message || 'Sin conexion a base de datos. Operando con respaldo JSON.').toString();
      textNode.textContent = message;
      alertNode.hidden = !active;
      alertNode.classList.toggle('is-visible', active);
    }

    function refreshPersistenceBanner() {
      fetch('../api/system_status.php', { cache: 'no-store' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data || !data.ok || !data.data) return;
          updatePersistenceBanner(data.data);
        })
        .catch(function () {
          updatePersistenceBanner({
            active: true,
            message: 'Sin conexion a base de datos. Operando con respaldo JSON.'
          });
        });
    }

    window.setInterval(refreshPersistenceBanner, 15000);
  })();
</script>
