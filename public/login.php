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
</head>
<body class="login">
  <div class="card">
    <h1>Courseware Admin</h1>
    <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <label>Email</label>
      <input name="email" type="email" required>
      <label>Password</label>
      <input name="password" type="password" required>
      <button class="btn" type="submit">Login</button>
    </form>
  </div>
</body>
</html>