<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

$error = '';
$email = trim((string)($_POST['email'] ?? ''));
$flash = trim((string)($_GET['flash'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = trim((string)($_POST['password'] ?? ''));

    if (cw_login($pdo, $email, $pass)) {
        $currentUser = cw_current_user($pdo);
        $mustChangePassword = (int)($currentUser['must_change_password'] ?? 0) === 1;

        if ($mustChangePassword && file_exists(__DIR__ . '/force_password_change.php')) {
            header('Location: /force_password_change.php');
            exit;
        }

        header('Location: /');
        exit;
    }

    $error = 'Invalid email or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IPCA Academy Login</title>
<link rel="stylesheet" href="/assets/app-shell.css">
<style>
:root{
  color-scheme: light;
}

html, body{
  min-height:100%;
}

body.login-page{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at top left, rgba(110,174,252,0.16) 0%, rgba(110,174,252,0.00) 34%),
    linear-gradient(180deg, var(--shell-bg) 0%, var(--shell-bg-2) 100%);
  color:var(--text-strong);
  font-synthesis-weight:none;
  text-rendering:optimizeLegibility;
  -webkit-font-smoothing:antialiased;
  -moz-osx-font-smoothing:grayscale;
}

.login-shell{
  min-height:100vh;
  display:flex;
  align-items:stretch;
}

.login-brand-panel{
  width:min(44vw, 560px);
  min-width:320px;
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  padding:34px 28px 28px 28px;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.018) 0%, rgba(255,255,255,0.00) 100%),
    linear-gradient(180deg, var(--sidebar-bg) 0%, var(--sidebar-bg-2) 100%);
  box-shadow:
    inset -1px 0 0 rgba(255,255,255,0.03),
    16px 0 40px rgba(13, 29, 52, 0.08);
}

.login-brand-top{
  display:block;
}

.login-brand{
  display:flex;
  align-items:center;
  gap:14px;
}

.login-brand-mark{
  width:48px;
  height:48px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(180deg, rgba(255,255,255,0.10) 0%, rgba(255,255,255,0.05) 100%);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,0.08),
    0 8px 18px rgba(0,0,0,0.10);
}

.login-brand-mark img{
  width:28px;
  height:28px;
  object-fit:contain;
  display:block;
}

.login-brand-copy{
  min-width:0;
}

.login-brand-title{
  color:#ffffff;
  font-size:21px;
  line-height:1.05;
  font-weight:760;
  letter-spacing:-0.02em;
  margin:0;
}

.login-brand-subtitle{
  margin-top:6px;
  color:rgba(193,205,225,0.78);
  font-size:13px;
  font-weight:560;
  line-height:1.4;
}

.login-brand-hero{
  margin-top:54px;
}

.login-kicker{
  font-size:11px;
  line-height:1;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:rgba(193,205,225,0.58);
  font-weight:700;
  margin-bottom:14px;
}

.login-hero-title{
  margin:0;
  color:#ffffff;
  font-size:40px;
  line-height:1.02;
  letter-spacing:-0.05em;
  font-weight:800;
  max-width:420px;
}

.login-hero-text{
  margin:18px 0 0 0;
  max-width:420px;
  color:rgba(255,255,255,0.84);
  font-size:15px;
  line-height:1.7;
}

.login-brand-footer{
  color:rgba(193,205,225,0.58);
  font-size:12px;
  line-height:1.5;
}

.login-main{
  flex:1 1 auto;
  min-width:0;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:40px 28px;
}

.login-card{
  width:100%;
  max-width:480px;
  background:var(--panel-bg);
  border:1px solid var(--border-soft);
  border-radius:24px;
  box-shadow:0 18px 40px rgba(15, 23, 42, 0.10);
  overflow:hidden;
}

.login-card-head{
  padding:28px 28px 16px 28px;
  border-bottom:1px solid rgba(15,23,42,0.05);
  background:linear-gradient(180deg, rgba(248,250,252,0.95) 0%, rgba(255,255,255,1) 100%);
}

.login-card-kicker{
  font-size:11px;
  line-height:1;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:var(--text-muted);
  font-weight:700;
  margin-bottom:10px;
}

.login-card-title{
  margin:0;
  font-size:28px;
  line-height:1.06;
  letter-spacing:-0.04em;
  font-weight:800;
  color:var(--text-strong);
}

.login-card-subtitle{
  margin:12px 0 0 0;
  color:var(--text-muted);
  font-size:14px;
  line-height:1.65;
}

.login-card-body{
  padding:22px 28px 28px 28px;
}

.login-flash,
.login-error{
  border-radius:16px;
  padding:14px 16px;
  margin-bottom:16px;
  font-size:14px;
  font-weight:700;
  line-height:1.5;
}

.login-flash{
  background:#ecfdf5;
  border:1px solid #bbf7d0;
  color:#166534;
}

.login-error{
  background:#fff1f2;
  border:1px solid #fecdd3;
  color:#be123c;
}

.login-form{
  display:block;
}

.login-field{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-bottom:16px;
}

.login-label{
  font-size:12px;
  font-weight:700;
  letter-spacing:.02em;
  color:var(--text-muted);
}

.login-input{
  width:100%;
  height:48px;
  border-radius:14px;
  border:1px solid rgba(15,23,42,0.10);
  background:#fff;
  box-sizing:border-box;
  color:var(--text-strong);
  font-size:14px;
  font-weight:560;
  outline:none;
  transition:border-color .16s ease, box-shadow .16s ease;
  padding:0 14px;
}

.login-input:focus{
  border-color:rgba(82,133,212,0.45);
  box-shadow:0 0 0 4px rgba(110,174,252,0.12);
}

.login-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-top:2px;
  margin-bottom:18px;
}

.login-link{
  color:#1d4ed8;
  text-decoration:none;
  font-size:13px;
  font-weight:700;
}

.login-link:hover{
  text-decoration:underline;
}

.login-btn{
  width:100%;
  min-height:48px;
  border:none;
  border-radius:14px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.018) 0%, rgba(255,255,255,0.00) 100%),
    linear-gradient(180deg, var(--sidebar-bg) 0%, var(--sidebar-bg-2) 100%);
  color:#fff;
  font-size:15px;
  font-weight:800;
  letter-spacing:.01em;
  cursor:pointer;
  box-shadow:0 12px 24px rgba(13, 29, 52, 0.14);
  transition:transform .16s ease, filter .16s ease;
}

.login-btn:hover{
  transform:translateY(-1px);
  filter:brightness(1.02);
}

.login-meta{
  margin-top:18px;
  padding-top:16px;
  border-top:1px solid rgba(15,23,42,0.06);
  color:var(--text-muted);
  font-size:12px;
  line-height:1.6;
}

@media (max-width: 980px){
  .login-shell{
    display:block;
  }

  .login-brand-panel{
    width:auto;
    min-width:0;
    padding:26px 22px 24px 22px;
  }

  .login-brand-hero{
    margin-top:34px;
  }

  .login-hero-title{
    font-size:32px;
    max-width:none;
  }

  .login-main{
    padding:24px 18px 34px 18px;
  }

  .login-card-head,
  .login-card-body{
    padding-left:22px;
    padding-right:22px;
  }
}

@media (max-width: 640px){
  .login-hero-title{
    font-size:28px;
  }

  .login-card-title{
    font-size:24px;
  }

  .login-row{
    flex-direction:column;
    align-items:flex-start;
  }
}
</style>
</head>

<body class="login-page">
<div class="login-shell">
  <aside class="login-brand-panel">
    <div class="login-brand-top">
      <div class="login-brand">
        <div class="login-brand-mark">
          <img src="/assets/logo/ipca_logo_white.png" alt="IPCA Academy">
        </div>
        <div class="login-brand-copy">
          <h1 class="login-brand-title">IPCA Academy</h1>
          <div class="login-brand-subtitle">Structured Learning. Global Standards.</div>
        </div>
      </div>

      <div class="login-brand-hero">
        <div class="login-kicker">Learning Platform Access</div>
        <h2 class="login-hero-title">Welcome back to your training environment.</h2>
        <p class="login-hero-text">
          Sign in to continue your structured training flow, progress tracking, and academy access using the official IPCA Academy portal.
        </p>
      </div>
    </div>

    <div class="login-brand-footer">
      International Pilot Center Alliance
    </div>
  </aside>

  <main class="login-main">
    <section class="login-card">
      <div class="login-card-head">
        <div class="login-card-kicker">Secure Sign-In</div>
        <h2 class="login-card-title">Account Login</h2>
        <p class="login-card-subtitle">
          Use your academy email address and password to access the platform.
        </p>
      </div>

      <div class="login-card-body">
        <?php if ($flash === 'password_reset_success'): ?>
          <div class="login-flash">
            Your password has been reset successfully. Please sign in with your new password.
          </div>
        <?php endif; ?>

        <?php if ($flash === 'password_changed_success'): ?>
          <div class="login-flash">
            Your password has been updated successfully.
          </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form class="login-form" method="post" action="/login.php" novalidate>
          <div class="login-field">
            <label class="login-label" for="email">Email Address</label>
            <input
              class="login-input"
              id="email"
              type="email"
              name="email"
              value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
              required
              autocomplete="email"
            >
          </div>

          <div class="login-field">
            <label class="login-label" for="password">Password</label>
            <input
              class="login-input"
              id="password"
              type="password"
              name="password"
              required
              autocomplete="current-password"
            >
          </div>

          <div class="login-row">
            <a class="login-link" href="/forgot_password.php">Forgot password?</a>
          </div>

          <button class="login-btn" type="submit">Login</button>
        </form>

        <div class="login-meta">
          Access is restricted to authorized users. Password reset links are time-limited and single-use.
        </div>
      </div>
    </section>
  </main>
</div>
</body>
</html>