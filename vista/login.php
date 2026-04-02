<?php
declare(strict_types=1);

$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
$username = isset($_GET['user']) ? htmlspecialchars($_GET['user']) : '';
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - POS Minimarket</title>
  <link rel="stylesheet" href="/pos/public/assets/css/pos.css">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    
    .login-container {
      background: white;
      border-radius: 12px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      width: 100%;
      max-width: 400px;
      padding: 40px;
    }
    
    .login-header {
      text-align: center;
      margin-bottom: 35px;
    }
    
    .login-header h1 {
      margin: 0 0 8px 0;
      font-size: 28px;
      color: #333;
    }
    
    .login-header p {
      margin: 0;
      color: #999;
      font-size: 14px;
    }
    
    .login-form {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    
    .form-group label {
      font-size: 13px;
      font-weight: 600;
      color: #333;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .form-group input {
      padding: 12px 14px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 14px;
      transition: all 0.2s;
    }
    
    .form-group input:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .login-button {
      padding: 12px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-top: 8px;
    }
    
    .login-button:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
    }
    
    .login-button:active:not(:disabled) {
      transform: translateY(0);
    }
    
    .login-button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }
    
    .error-message {
      padding: 12px;
      background: #fee;
      color: #c33;
      border: 1px solid #fcc;
      border-radius: 6px;
      font-size: 13px;
      margin-bottom: 16px;
      display: <?php echo $error ? 'block' : 'none'; ?>;
    }
    
    .login-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 12px;
      color: #999;
    }
    
    .demo-credentials {
      background: #f5f5f5;
      padding: 12px;
      border-radius: 6px;
      font-size: 12px;
      color: #666;
      margin-top: 16px;
      line-height: 1.5;
    }
    
    .demo-credentials strong {
      display: block;
      color: #333;
      margin-bottom: 4px;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="login-header">
      <h1>POS Minimarket</h1>
      <p>Sistema de Punto de Venta</p>
    </div>
    
    <div class="error-message" id="errorMsg">
      <?php echo $error; ?>
    </div>
    
    <form class="login-form" id="loginForm" method="POST" action="/pos/api/auth.php">
      <input type="hidden" name="action" value="login">
      
      <div class="form-group">
        <label for="username">Usuario</label>
        <input 
          type="text" 
          id="username" 
          name="username" 
          placeholder="Ingrese su usuario"
          value="<?php echo $username; ?>"
          required 
          autofocus
        >
      </div>
      
      <div class="form-group">
        <label for="password">Contraseña</label>
        <input 
          type="password" 
          id="password" 
          name="password" 
          placeholder="Ingrese su contraseña"
          required
        >
      </div>
      
      <button type="submit" class="login-button">Ingresar</button>
    </form>
    
    <div class="demo-credentials">
      <strong>Credenciales de Demostración:</strong>
      Usuario: <code>admin</code><br>
      Contraseña: <code>admin123</code>
    </div>
    
    <div class="login-footer">
      &copy; 2026 - Sistema POS
    </div>
  </div>
  
  <script>
    document.getElementById('loginForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const username = document.getElementById('username').value;
      const password = document.getElementById('password').value;
      
      if (!username || !password) {
        document.getElementById('errorMsg').textContent = 'Usuario y contraseña son requeridos';
        document.getElementById('errorMsg').style.display = 'block';
        return;
      }
      
      const formData = new FormData();
      formData.append('action', 'login');
      formData.append('username', username);
      formData.append('password', password);
      
      const btn = this.querySelector('button');
      btn.disabled = true;
      btn.textContent = 'Ingresando...';
      
      fetch('/pos/api/auth.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          window.location.href = data.redirect || '/pos/vista/caja-inicial.php';
        } else {
          document.getElementById('errorMsg').textContent = data.error || 'Error de autenticación';
          document.getElementById('errorMsg').style.display = 'block';
          btn.disabled = false;
          btn.textContent = 'Ingresar';
          document.getElementById('password').value = '';
        }
      })
      .catch(err => {
        document.getElementById('errorMsg').textContent = 'Error de conexión';
        document.getElementById('errorMsg').style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Ingresar';
      });
    });
  </script>
</body>
</html>
