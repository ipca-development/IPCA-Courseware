<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$next = (string)($_GET['next'] ?? '/admin/dashboard.php');
if ($next === '' || $next[0] !== '/') $next = '/admin/dashboard.php'; // only allow local paths

// If already logged in, go to next (or dashboard)
if (cw_is_logged_in()) {
    redirect($next);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $nextP = (string)($_POST['next'] ?? $next);

    if ($nextP === '' || $nextP[0] !== '/') $nextP = '/admin/dashboard.php';

    if ($email !== '' && $pass !== '' && cw_login($pdo, $email, $pass)) {
        redirect($nextP);
    } else {
        $error = 'Invalid email or password.';
    }
}

cw_header('Login');
?>
<div class="card" style="max-width:520px;">
  <h2 style="margin-top:0;">Login</h2>

  <?php if ($error): ?>
    <div class="muted" style="color:#b00020; margin-bottom:10px;"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="form-grid" autocomplete="on">
    <input type="hidden" name="next" value="<?= h($next) ?>">

    <label>Email</label>
    <input name="email" type="email" required autocomplete="username">

    <label>Password</label>
    <input name="password" type="password" required autocomplete="current-password">

    <div></div>
    <button class="btn" type="submit">Login</button>
  </form>
</div>
<?php cw_footer(); ?>