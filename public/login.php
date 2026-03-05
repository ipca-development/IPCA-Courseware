<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = trim((string)($_POST['password'] ?? ''));

    if (cw_login($pdo,$email,$pass)) {
        header("Location: /");
        exit;
    }

    $error = "Invalid email or password.";
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPCA Courseware Login</title>
<link rel="stylesheet" href="/assets/app.css">
</head>

<body class="login-bg">

<div class="login-wrap">

  <!-- Updated logo path -->
  <img class="login-logo" src="/assets/logo/ipca_logo_white.png">

  <div class="login-card">

      <div class="login-title">IPCA Courseware</div>

      <?php if($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post">

        <label>Email</label>
        <input type="email" name="email" required>

        <label style="margin-top:10px;">Password</label>
        <input type="password" name="password" required>

        <button class="btn" type="submit">Login</button>

      </form>

  </div>

</div>

</body>
</html>