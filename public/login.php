<?php
require_once __DIR__ . '/../src/bootstrap.php';

if (cw_is_logged_in()) redirect('/admin/dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    if (cw_login($pdo, $email, $pass)) {
        redirect('/admin/dashboard.php');
    }
    $error = 'Invalid credentials';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login</title>
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    body.login {
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:100vh;
      background:#f6f7fb;
    }
    .login-card{
      width:min(520px, 92vw);
      padding:22px 22px 18px;
    }
    .login-card h1{margin:0 0 12px 0;}
    .login-grid{
      display:grid;
      grid-template-columns: 120px 1fr;
      gap:10px;
      align-items:center;
      margin-top:10px;
    }
    .login-grid input{
      width:100%;
      box-sizing:border-box;
    }
    .login-actions{
      margin-top:14px;
      display:flex;
      justify-content:flex-end;
    }
  </style>
</head>
<body class="login">
  <div class="card login-card">
    <h1>IPCA Courseware Admin</h1>

    <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

    <form method="post">
      <div class="login-grid">
        <label>Email</label>
        <input name="email" type="email" required autofocus>

        <label>Password</label>
        <input name="password" type="password" required>
      </div>

      <div class="login-actions">
        <button class="btn" type="submit">Login</button>
      </div>
    </form>
  </div>
</body>
</html>