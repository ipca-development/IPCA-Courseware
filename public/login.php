<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

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
html,
body,
body.app-shell-body.login-page,
body.app-shell-body.login-page *{
  font-family:"Manrope", sans-serif;
}

:root{
  color-scheme: light;
}

html,
body{
  min-height:100%;
}

body.app-shell-body.login-page{
  margin:0;
  min-height:100vh;
  color:var(--text-strong);
  background:
    radial-gradient(circle at top left, rgba(110,174,252,0.16) 0%, rgba(110,174,252,0.00) 28%),
    linear-gradient(180deg, #f4f8fc 0%, #eef3f8 100%);
  overflow:hidden;
}

.login-page-shell{
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:28px;
  box-sizing:border-box;
}

.login-frame{
  width:100%;
  max-width:1180px;
  display:grid;
  grid-template-columns:minmax(360px, 1fr) minmax(360px, 420px);
  gap:44px;
  align-items:center;
}

.login-brand{
  min-width:0;
  padding:10px 6px;
}

.login-brand-mark{
  margin-bottom:22px;
}

.login-brand-mark img{
  width:110px;
  height:auto;
  display:block;
  object-fit:contain;
}

.login-brand-title{
  margin:0;
  font-size:52px;
  line-height:0.96;
  letter-spacing:-0.06em;
  font-weight:820;
  color:var(--text-strong);
}

.login-brand-subtitle{
  margin:12px 0 0 0;
  font-size:18px;
  line-height:1.5;
  font-weight:560;
  color:var(--text-muted);
}

.login-card-wrap{
  display:flex;
  justify-content:flex-end;
}

.login-card{
  width:100%;
  max-width:420px;
  background:rgba(255,255,255,0.92);
  border:1px solid rgba(255,255,255,0.78);
  border-radius:28px;
  box-shadow:
    0 28px 70px rgba(15,23,42,0.12),
    inset 0 1px 0 rgba(255,255,255,0.92);
  backdrop-filter:blur(20px);
  overflow:hidden;
}

.login-card-head{
  padding:28px 28px 12px 28px;
}

.login-card-overline{
  font-size:11px;
  line-height:1;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:var(--text-muted);
  font-weight:760;
  margin-bottom:10px;
}

.login-card-title{
  margin:0;
  font-size:34px;
  line-height:0.98;
  letter-spacing:-0.06em;
  font-weight:820;
  color:var(--text-strong);
}

.login-card-subtitle{
  margin:10px 0 0 0;
  color:var(--text-muted);
  font-size:14px;
  line-height:1.65;
}

.login-card-body{
  padding:10px 28px 28px 28px;
}

.login-flash,
.login-error{
  border-radius:16px;
  padding:14px 16px;
  margin-bottom:16px;
  font-size:14px;
  font-weight:720;
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

.login-passkey-btn{
  width:100%;
  min-height:52px;
  border:none;
  border-radius:16px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:12px;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.03) 0%, rgba(255,255,255,0.00) 100%),
    linear-gradient(180deg, #17345d 0%, #102440 100%);
  color:#fff;
  font-size:15px;
  font-weight:800;
  letter-spacing:.01em;
  box-shadow:0 14px 28px rgba(16,36,64,0.14);
  cursor:pointer;
  transition:transform .16s ease, filter .16s ease;
}

.login-passkey-btn:hover{
  transform:translateY(-1px);
  filter:brightness(1.02);
}

.login-passkey-icon{
  width:20px;
  height:20px;
  flex:0 0 20px;
}

.login-passkey-note{
  margin:10px 0 0 0;
  color:var(--text-muted);
  font-size:12px;
  line-height:1.55;
  text-align:center;
}

.login-divider{
  position:relative;
  margin:18px 0 16px 0;
  text-align:center;
}

.login-divider:before{
  content:"";
  position:absolute;
  left:0;
  right:0;
  top:50%;
  height:1px;
  background:rgba(15,23,42,0.08);
  transform:translateY(-50%);
}

.login-divider span{
  position:relative;
  z-index:1;
  display:inline-block;
  padding:0 12px;
  background:rgba(255,255,255,0.92);
  color:#7a889d;
  font-size:11px;
  font-weight:760;
  letter-spacing:.14em;
  text-transform:uppercase;
}

.login-form{
  display:block;
}

.login-field{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-bottom:14px;
}

.login-label{
  font-size:12px;
  font-weight:760;
  letter-spacing:.02em;
  color:var(--text-muted);
}

.login-input{
  width:100%;
  height:50px;
  border-radius:16px;
  border:1px solid rgba(15,23,42,0.10);
  background:rgba(255,255,255,0.98);
  box-sizing:border-box;
  color:var(--text-strong);
  font-size:14px;
  font-weight:560;
  outline:none;
  transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease;
  padding:0 15px;
}

.login-input:focus{
  border-color:rgba(82,133,212,0.42);
  box-shadow:0 0 0 4px rgba(110,174,252,0.11);
  transform:translateY(-1px);
}

.login-row{
  display:flex;
  align-items:center;
  justify-content:flex-start;
  gap:12px;
  margin-top:2px;
  margin-bottom:16px;
}

.login-link{
  color:#1d4ed8;
  text-decoration:none;
  font-size:13px;
  font-weight:760;
}

.login-link:hover{
  text-decoration:underline;
}

.login-btn{
  width:100%;
  min-height:50px;
  border:none;
  border-radius:16px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.00) 100%),
    linear-gradient(180deg, #17345d 0%, #102440 100%);
  color:#fff;
  font-size:15px;
  font-weight:820;
  letter-spacing:.01em;
  cursor:pointer;
  box-shadow:0 14px 28px rgba(13,29,52,0.14);
  transition:transform .16s ease, filter .16s ease;
}

.login-btn:hover{
  transform:translateY(-1px);
  filter:brightness(1.03);
}

.login-meta{
  margin-top:16px;
  padding-top:14px;
  border-top:1px solid rgba(15,23,42,0.06);
  color:#7a889d;
  font-size:12px;
  line-height:1.55;
  text-align:center;
}

@media (max-width: 1024px){
  .login-page-shell{
    padding:22px;
  }

  .login-frame{
    gap:28px;
    grid-template-columns:minmax(320px, 1fr) minmax(320px, 400px);
  }

  .login-brand-title{
    font-size:44px;
  }

  .login-brand-subtitle{
    font-size:16px;
  }
}

@media (max-width: 820px){
  body.app-shell-body.login-page{
    overflow:auto;
  }

  .login-page-shell{
    min-height:auto;
    padding:20px 18px 24px 18px;
  }

  .login-frame{
    grid-template-columns:1fr;
    gap:20px;
    max-width:520px;
  }

  .login-brand{
    padding:0;
    text-align:left;
  }

  .login-brand-mark{
    margin-bottom:18px;
  }

  .login-brand-mark img{
    width:88px;
  }

  .login-brand-title{
    font-size:34px;
  }

  .login-brand-subtitle{
    margin-top:10px;
    font-size:15px;
  }

  .login-card-wrap{
    justify-content:flex-start;
  }
}

@media (max-width: 560px){
  .login-page-shell{
    padding:16px;
  }

  .login-card-head,
  .login-card-body{
    padding-left:20px;
    padding-right:20px;
  }

  .login-card-title{
    font-size:30px;
  }

  .login-brand-title{
    font-size:30px;
  }
}
</style>
</head>

<body class="app-shell-body login-page">
<div class="login-page-shell">
  <div class="login-frame">
    <section class="login-brand" aria-label="IPCA Academy brand">
      <div class="login-brand-mark">
        <img src="/assets/logo/ipca_logo_dark.png" alt="IPCA Academy">
      </div>

      <h1 class="login-brand-title">IPCA Academy</h1>
      <p class="login-brand-subtitle">Structured Learning. Global Standards.</p>
    </section>

    <div class="login-card-wrap">
      <section class="login-card" aria-label="Sign in">
        <div class="login-card-head">
          <div class="login-card-overline">Welcome Back</div>
          <h2 class="login-card-title">Sign In</h2>
          <p class="login-card-subtitle">
            Enter your academy environment with secure credentials or biometric sign-in.
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

          <button class="login-passkey-btn" type="button">
            <span class="login-passkey-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 4a4 4 0 1 1 0 8a4 4 0 0 1 0-8Zm-7 14a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M18.5 7.5h1m-3-3v1m0 4v1m-2.2-3.2l.7.7m4.3-4.3l-.7.7m0 5.1l.7-.7m-4.3-4.3l-.7-.7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
              </svg>
            </span>
            <span>Sign in with Face ID / Touch ID</span>
          </button>

          <p class="login-passkey-note">
            Use Face ID or Touch ID where enabled.
          </p>

          <div class="login-divider">
            <span>or continue with password</span>
          </div>

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

            <button class="login-btn" type="submit">Enter Academy</button>
          </form>

          <div class="login-meta">
            Authorized access only. Reset links are time-limited and single-use.
          </div>
        </div>
      </section>
    </div>
  </div>
</div>
</body>
</html>