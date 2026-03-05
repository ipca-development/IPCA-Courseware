<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

// If already logged in, go to correct portal (NOT always admin!)
if (cw_is_logged_in()) {
    $u = cw_current_user($pdo);
    if ($u) {
        redirect(cw_home_path_for_role((string)($u['role'] ?? '')));
    }
    // If session exists but user not found, logout and continue
    cw_logout();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if ($email === '' || $pass === '') {
        $error = 'Please enter email and password.';
    } else {
        if (cw_login($pdo, $email, $pass)) {
            $u = cw_current_user($pdo);
            if ($u) {
                redirect(cw_home_path_for_role((string)($u['role'] ?? '')));
            }
            // fallback
            redirect('/admin/dashboard.php');
        } else {
            $error = 'Invalid credentials.';
        }
    }
}

cw_header('Login');
?>

<div class="card" style="max-width:520px;">
  <h2 style="margin-top:0;">Login</h2>

  <?php if ($error !== ''): ?>
    <div class="card" style="border-color:#f2c2c2;background:#fff5f5;">
      <strong style="color:#a00000;">Error:</strong>
      <span><?= h($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="post" class="form-grid" style="grid-template-columns: 140px 1fr;">
    <label>Email</label>
    <input name="email" type="email" required autofocus>

    <label>Password</label>
    <input name="password" type="password" required>

    <div></div>
    <button class="btn" type="submit">Login</button>
  </form>
</div>

<?php cw_footer(); ?>