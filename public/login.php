<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

if (cw_is_logged_in()) {
    redirect('/admin/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if ($email !== '' && $pass !== '' && cw_login($pdo, $email, $pass)) {
        redirect('/admin/dashboard.php');
    } else {
        $error = 'Invalid email or password.';
    }
}

cw_header('Login');
?>
<div class="card" style="max-width:520px;">
  <?php if ($error): ?>
    <div class="muted" style="color:#b00020; margin-bottom:10px;"><?= h($error) ?></div>
  <?php endif; ?>
  <form method="post" class="form-grid">
    <label>Email</label>
    <input name="email" type="email" required>

    <label>Password</label>
    <input name="password" type="password" required>

    <div></div>
    <button class="btn" type="submit">Login</button>
  </form>
</div>
<?php cw_footer(); ?>