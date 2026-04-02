<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

if (!($_SESSION['pending_cash_opening'] ?? false)) {
    header('Location: ../public/index.php?mod=ventas');
    exit;
}

$userName = htmlspecialchars((string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'Usuario'));
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Efectivo Inicial en Caja</title>
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(180deg, #d7dde6 0%, #b3bcc8 100%);
      font-family: Georgia, "Times New Roman", serif;
    }

    .cash-window {
      width: 340px;
      border: 1px solid #6f7b88;
      background: #f2f3f5;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
    }

    .cash-titlebar {
      background: #4b5968;
      color: #fff;
      font-family: "Segoe UI", Tahoma, sans-serif;
      font-size: 13px;
      padding: 7px 10px;
    }

    .cash-body {
      padding: 18px 26px 22px;
      text-align: center;
    }

    .cash-user {
      margin: 0 0 8px;
      color: #5b6570;
      font-size: 13px;
      font-family: "Segoe UI", Tahoma, sans-serif;
    }

    .cash-question {
      margin: 0 0 16px;
      font-size: 20px;
      color: #2f2f2f;
    }

    .cash-input-wrap {
      display: flex;
      justify-content: center;
      margin-bottom: 16px;
    }

    .cash-input {
      width: 150px;
      border: 1px solid #c8cdd4;
      background: #fff;
      padding: 8px 10px;
      font-size: 18px;
      text-align: center;
      box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08);
    }

    .cash-button {
      border: 1px solid #c8ced6;
      background: linear-gradient(180deg, #ffffff 0%, #e8edf2 100%);
      color: #4b4f54;
      padding: 10px 16px;
      font-size: 14px;
      cursor: pointer;
      border-radius: 4px;
    }

    .cash-button:hover:not(:disabled) {
      background: linear-gradient(180deg, #ffffff 0%, #dfe6ed 100%);
    }

    .cash-button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .cash-error {
      min-height: 18px;
      margin: 10px 0 0;
      color: #b63737;
      font-family: "Segoe UI", Tahoma, sans-serif;
      font-size: 12px;
    }
  </style>
</head>
<body>
  <div class="cash-window">
    <div class="cash-titlebar">Dinero en caja</div>
    <div class="cash-body">
      <p class="cash-user">Usuario: <?php echo $userName; ?></p>
      <h1 class="cash-question">Efectivo inicial en caja</h1>
      <div class="cash-input-wrap">
        <input class="cash-input" id="cashOpeningAmount" type="number" step="0.01" min="0" value="0.00" autofocus>
      </div>
      <button class="cash-button" id="cashOpeningBtn" type="button">Registrar dinero inicial en caja</button>
      <div class="cash-error" id="cashOpeningError"></div>
    </div>
  </div>

  <script>
    (function () {
      const amountInput = document.getElementById('cashOpeningAmount');
      const button = document.getElementById('cashOpeningBtn');
      const errorNode = document.getElementById('cashOpeningError');

      function setError(message) {
        errorNode.textContent = message || '';
      }

      function submitOpening() {
        const amount = Number(amountInput.value || 0);
        if (!Number.isFinite(amount) || amount < 0) {
          setError('Ingrese un monto valido.');
          return;
        }

        setError('');
        button.disabled = true;
        button.textContent = 'Registrando...';

        fetch('/pos/api/cash_opening.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ amount: amount })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (!data.ok) {
            setError(data.error || 'No se pudo registrar el monto inicial.');
            button.disabled = false;
            button.textContent = 'Registrar dinero inicial en caja';
            return;
          }
          window.location.href = data.redirect || '/pos/public/index.php?mod=ventas';
        })
        .catch(function () {
          setError('Error de conexion.');
          button.disabled = false;
          button.textContent = 'Registrar dinero inicial en caja';
        });
      }

      button.addEventListener('click', submitOpening);
      amountInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
          submitOpening();
        }
      });
      amountInput.focus();
      amountInput.select();
    })();
  </script>
</body>
</html>
