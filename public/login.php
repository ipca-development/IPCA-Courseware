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
:root{
  color-scheme: light;
}

html, body{
  min-height:100%;
}

body.app-shell-body.login-page{
  margin:0;
  min-height:100vh;
  color:var(--text-strong);
  background:
    radial-gradient(circle at 12% 14%, rgba(139, 211, 255, 0.22) 0%, rgba(139, 211, 255, 0.00) 26%),
    radial-gradient(circle at 86% 10%, rgba(118, 158, 255, 0.16) 0%, rgba(118, 158, 255, 0.00) 24%),
    radial-gradient(circle at 50% 100%, rgba(16, 36, 64, 0.10) 0%, rgba(16, 36, 64, 0.00) 34%),
    linear-gradient(180deg, #eef7ff 0%, #e8f0fa 42%, #eef3f8 100%);
  overflow-x:hidden;
}

.login-page-shell{
  position:relative;
  min-height:100vh;
  display:flex;
  align-items:stretch;
  justify-content:center;
}

.login-hero{
  position:relative;
  flex:1 1 auto;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:38px;
}

.login-stage{
  position:relative;
  width:100%;
  max-width:1520px;
  min-height:calc(100vh - 76px);
  border-radius:34px;
  overflow:hidden;
  display:grid;
  grid-template-columns:minmax(560px, 1.14fr) minmax(400px, 0.86fr);
  background:linear-gradient(135deg, rgba(255,255,255,0.42) 0%, rgba(255,255,255,0.16) 100%);
  box-shadow:
    0 30px 80px rgba(15, 23, 42, 0.12),
    inset 0 1px 0 rgba(255,255,255,0.58);
  border:1px solid rgba(255,255,255,0.62);
  backdrop-filter: blur(14px);
}

.login-stage:before{
  content:"";
  position:absolute;
  inset:0;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.02) 0%, rgba(255,255,255,0.00) 24%),
    linear-gradient(180deg, rgba(7,18,34,0.05) 0%, rgba(7,18,34,0.02) 100%);
  pointer-events:none;
}

.login-visual{
  position:relative;
  overflow:hidden;
  min-width:0;
  background:
    radial-gradient(circle at 16% 18%, rgba(172,232,255,0.28) 0%, rgba(172,232,255,0.00) 22%),
    radial-gradient(circle at 80% 16%, rgba(122,170,255,0.24) 0%, rgba(122,170,255,0.00) 26%),
    linear-gradient(180deg, rgba(9,23,45,0.26) 0%, rgba(9,23,45,0.34) 100%),
    linear-gradient(135deg, #2a619f 0%, #1c476f 46%, #14304d 100%);
}

.login-visual:after{
  content:"";
  position:absolute;
  inset:0;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.04) 0%, rgba(255,255,255,0.00) 16%),
    linear-gradient(0deg, rgba(7,17,34,0.16) 0%, rgba(7,17,34,0.00) 42%);
  pointer-events:none;
}

.login-visual-glow{
  position:absolute;
  left:46px;
  top:40px;
  width:240px;
  height:240px;
  border-radius:50%;
  background:radial-gradient(circle, rgba(183,236,255,0.28) 0%, rgba(183,236,255,0.00) 72%);
  filter:blur(16px);
  pointer-events:none;
}

.login-runway{
  position:absolute;
  left:50%;
  bottom:-3%;
  width:92%;
  max-width:980px;
  height:56%;
  transform:translateX(-50%);
  pointer-events:none;
}

.login-runway-perspective{
  position:absolute;
  left:50%;
  bottom:0;
  width:100%;
  height:100%;
  transform:translateX(-50%);
  clip-path:polygon(47% 0%, 53% 0%, 100% 100%, 0% 100%);
  background:linear-gradient(180deg, rgba(214,226,238,0.10) 0%, rgba(198,212,229,0.24) 100%);
}

.login-runway-centerline{
  position:absolute;
  left:50%;
  bottom:8%;
  width:2px;
  height:78%;
  transform:translateX(-50%);
  background:
    repeating-linear-gradient(
      to top,
      rgba(255,255,255,0.00) 0 18px,
      rgba(255,255,255,0.92) 18px 34px
    );
  box-shadow:0 0 12px rgba(255,255,255,0.24);
}

.login-runway-glow{
  position:absolute;
  left:50%;
  bottom:15%;
  width:56%;
  height:28%;
  transform:translateX(-50%);
  border-radius:50%;
  background:radial-gradient(circle, rgba(142,214,255,0.34) 0%, rgba(142,214,255,0.00) 72%);
  filter:blur(20px);
}

.login-aircraft{
  position:absolute;
  left:50%;
  top:24%;
  transform:translateX(-50%);
  width:min(68%, 620px);
  opacity:0.90;
  pointer-events:none;
}

.login-aircraft svg{
  width:100%;
  height:auto;
  display:block;
  filter:
    drop-shadow(0 20px 32px rgba(4,12,24,0.42))
    drop-shadow(0 0 28px rgba(165,229,255,0.14));
}

.login-content{
  position:relative;
  z-index:2;
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  min-height:100%;
  padding:34px 38px 34px 38px;
}

.login-brandbar{
  display:flex;
  align-items:center;
  gap:20px;
}

.login-brandmark-wrap{
  position:relative;
  flex:0 0 auto;
}

.login-brandmark-wrap:before{
  content:"";
  position:absolute;
  inset:-18px;
  border-radius:34px;
  background:radial-gradient(circle, rgba(191,239,255,0.30) 0%, rgba(191,239,255,0.00) 72%);
  filter:blur(10px);
  pointer-events:none;
}

.login-brandmark{
  position:relative;
  width:104px;
  height:104px;
  border-radius:28px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(180deg, rgba(255,255,255,0.22) 0%, rgba(255,255,255,0.09) 100%);
  border:1px solid rgba(255,255,255,0.20);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,0.18),
    0 18px 34px rgba(6,15,30,0.20);
  backdrop-filter: blur(12px);
}

.login-brandmark img{
  width:70px;
  height:70px;
  object-fit:contain;
  display:block;
}

.login-brandcopy{
  min-width:0;
}

.login-brandtitle{
  margin:0;
  color:#ffffff;
  font-size:38px;
  line-height:0.98;
  letter-spacing:-0.06em;
  font-weight:840;
  text-shadow:0 2px 18px rgba(0,0,0,0.08);
}

.login-brandsubtitle{
  margin-top:8px;
  color:rgba(233,241,250,0.90);
  font-size:15px;
  font-weight:600;
  line-height:1.45;
}

.login-hero-copy{
  max-width:620px;
  margin-top:10px;
}

.login-overline{
  display:inline-flex;
  align-items:center;
  min-height:34px;
  padding:0 14px;
  border-radius:999px;
  background:rgba(255,255,255,0.11);
  border:1px solid rgba(255,255,255,0.16);
  color:rgba(255,255,255,0.92);
  font-size:11px;
  font-weight:780;
  letter-spacing:.14em;
  text-transform:uppercase;
}

.login-hero-title{
  margin:18px 0 0 0;
  color:#ffffff;
  font-size:66px;
  line-height:0.92;
  letter-spacing:-0.078em;
  font-weight:850;
  max-width:640px;
  text-shadow:0 6px 24px rgba(0,0,0,0.10);
}

.login-hero-sub{
  margin:22px 0 0 0;
  color:rgba(234,241,249,0.92);
  font-size:18px;
  line-height:1.7;
  max-width:520px;
}

.login-hero-pills{
  display:flex;
  flex-wrap:wrap;
  gap:12px;
  margin-top:24px;
}

.login-hero-pill{
  display:inline-flex;
  align-items:center;
  min-height:38px;
  padding:0 14px;
  border-radius:999px;
  background:rgba(255,255,255,0.10);
  border:1px solid rgba(255,255,255,0.14);
  color:#ffffff;
  font-size:13px;
  font-weight:720;
  letter-spacing:.01em;
}

.login-visual-footer{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
  margin-top:30px;
  color:rgba(221,232,245,0.74);
  font-size:12px;
  line-height:1.55;
}

.login-visual-footer-chip{
  display:inline-flex;
  align-items:center;
  min-height:34px;
  padding:0 13px;
  border-radius:999px;
  background:rgba(255,255,255,0.08);
  border:1px solid rgba(255,255,255,0.12);
  color:#ffffff;
  font-size:12px;
  font-weight:720;
}

.login-panel{
  position:relative;
  z-index:2;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:36px;
  min-width:0;
}

.login-card{
  width:100%;
  max-width:430px;
  background:rgba(255,255,255,0.92);
  border:1px solid rgba(255,255,255,0.78);
  border-radius:30px;
  box-shadow:
    0 34px 80px rgba(15, 23, 42, 0.16),
    inset 0 1px 0 rgba(255,255,255,0.95);
  backdrop-filter: blur(24px);
  overflow:hidden;
}

.login-card-head{
  padding:30px 30px 16px 30px;
}

.login-card-overline{
  font-size:11px;
  line-height:1;
  letter-spacing:.14em;
  text-transform:uppercase;
  color:#6f8098;
  font-weight:760;
  margin-bottom:10px;
}

.login-card-title{
  margin:0;
  color:var(--text-strong);
  font-size:36px;
  line-height:0.98;
  letter-spacing:-0.06em;
  font-weight:830;
}

.login-card-sub{
  margin:10px 0 0 0;
  color:var(--text-muted);
  font-size:14px;
  line-height:1.68;
}

.login-card-body{
  padding:4px 30px 28px 30px;
}

.login-flash,
.login-error{
  border-radius:18px;
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
  min-height:54px;
  border:none;
  border-radius:18px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:12px;
  background:
    linear-gradient(180deg, rgba(255,255,255,0.030) 0%, rgba(255,255,255,0.00) 100%),
    linear-gradient(180deg, #17345d 0%, #102440 100%);
  color:#fff;
  font-size:15px;
  font-weight:800;
  letter-spacing:.01em;
  box-shadow:0 16px 28px rgba(16,36,64,0.16);
  cursor:pointer;
  transition:transform .16s ease, filter .16s ease, box-shadow .16s ease;
  font-family:inherit;
}

.login-passkey-btn:hover{
  transform:translateY(-1px);
  filter:brightness(1.03);
  box-shadow:0 18px 32px rgba(16,36,64,0.18);
}

.login-passkey-icon{
  width:20px;
  height:20px;
  flex:0 0 20px;
  opacity:0.95;
}

.login-passkey-note{
  margin:12px 0 0 0;
  color:#6f8098;
  font-size:12px;
  line-height:1.55;
  text-align:center;
}

.login-divider{
  position:relative;
  margin:20px 0 18px 0;
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
  background:rgba(255,255,255,0.90);
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
  margin-bottom:15px;
}

.login-label{
  font-size:12px;
  font-weight:760;
  letter-spacing:.02em;
  color:#6f8098;
}

.login-input{
  width:100%;
  height:52px;
  border-radius:17px;
  border:1px solid rgba(15,23,42,0.10);
  background:rgba(255,255,255,0.97);
  box-sizing:border-box;
  color:var(--text-strong);
  font-size:14px;
  font-weight:560;
  outline:none;
  transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease;
  padding:0 16px;
  font-family:inherit;
}

.login-input:focus{
  border-color:rgba(82,133,212,0.42);
  box-shadow:0 0 0 4px rgba(110,174,252,0.11);
  transform:translateY(-1px);
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
  font-weight:760;
}

.login-link:hover{
  text-decoration:underline;
}

.login-btn{
  width:100%;
  min-height:52px;
  border:none;
  border-radius:17px;
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
  font-family:inherit;
}

.login-btn:hover{
  transform:translateY(-1px);
  filter:brightness(1.03);
}

.login-meta{
  margin-top:18px;
  padding-top:16px;
  border-top:1px solid rgba(15,23,42,0.06);
  color:#7a889d;
  font-size:12px;
  line-height:1.6;
  text-align:center;
}

@media (max-width: 1240px){
  .login-stage{
    grid-template-columns:1fr;
    min-height:auto;
  }

  .login-visual{
    min-height:640px;
  }

  .login-panel{
    padding-top:10px;
    padding-bottom:34px;
  }
}

@media (max-width: 860px){
  .login-hero{
    padding:18px;
  }

  .login-stage{
    border-radius:24px;
  }

  .login-content{
    padding:24px 22px;
  }

  .login-brandbar{
    gap:16px;
  }

  .login-brandmark{
    width:84px;
    height:84px;
    border-radius:24px;
  }

  .login-brandmark img{
    width:58px;
    height:58px;
  }

  .login-brandtitle{
    font-size:30px;
  }

  .login-hero-title{
    font-size:44px;
    max-width:none;
  }

  .login-hero-sub{
    font-size:16px;
    max-width:none;
  }

  .login-panel{
    padding:20px 18px 28px 18px;
  }

  .login-card-head,
  .login-card-body{
    padding-left:22px;
    padding-right:22px;
  }

  .login-card-title{
    font-size:30px;
  }
}

@media (max-width: 560px){
  .login-stage{
    border-radius:0;
    min-height:100vh;
  }

  .login-hero{
    padding:0;
  }

  .login-visual{
    min-height:520px;
  }

  .login-content{
    padding:20px 18px;
  }

  .login-brandtitle{
    font-size:26px;
  }

  .login-brandsubtitle{
    font-size:13px;
  }

  .login-hero-title{
    font-size:36px;
  }

  .login-row{
    flex-direction:column;
    align-items:flex-start;
  }

  .login-card{
    border-radius:24px;
  }
}
</style>
</head>

<body class="app-shell-body login-page">
<div class="login-page-shell">
  <main class="login-hero">
    <section class="login-stage">
      <div class="login-visual">
        <div class="login-visual-glow"></div>

        <div class="login-runway">
          <div class="login-runway-perspective"></div>
          <div class="login-runway-centerline"></div>
          <div class="login-runway-glow"></div>
        </div>

        <div class="login-aircraft" aria-hidden="true">
          <svg viewBox="0 0 900 360" xmlns="http://www.w3.org/2000/svg">
            <g fill="none" fill-rule="evenodd">
              <path d="M454 54c18 0 35 8 46 22l53 67 178 44c18 4 28 13 28 26c0 9-7 15-20 18l-193 36-62 62c-10 10-20 15-30 15h-9c-7 0-12-5-12-12v-80l-128 20-31 39c-8 10-17 15-27 15h-15c-9 0-14-8-10-16l20-44-86 13c-12 2-23-6-25-18c-2-12 6-23 18-25l93-17-20-41c-4-8 1-16 10-16h15c10 0 19 5 27 15l31 39 128 20v-80c0-7 5-12 12-12h9z" fill="rgba(255,255,255,0.95)"/>
              <path d="M454 54c18 0 35 8 46 22l53 67 178 44c18 4 28 13 28 26c0 9-7 15-20 18l-193 36-62 62c-10 10-20 15-30 15h-9c-7 0-12-5-12-12v-80l-128 20-31 39c-8 10-17 15-27 15h-15c-9 0-14-8-10-16l20-44-86 13c-12 2-23-6-25-18c-2-12 6-23 18-25l93-17-20-41c-4-8 1-16 10-16h15c10 0 19 5 27 15l31 39 128 20v-80c0-7 5-12 12-12h9z" stroke="rgba(214,231,255,0.42)" stroke-width="3"/>
            </g>
          </svg>
        </div>

        <div class="login-content">
          <div>
            <div class="login-brandbar">
              <div class="login-brandmark-wrap">
                <div class="login-brandmark">
                  <img src="/assets/logo/ipca_logo_white.png" alt="IPCA Academy">
                </div>
              </div>

              <div class="login-brandcopy">
                <h1 class="login-brandtitle">IPCA Academy</h1>
                <div class="login-brandsubtitle">Structured Learning. Global Standards.</div>
              </div>
            </div>

            <div class="login-hero-copy">
              <div class="login-overline">Premium Aviation Learning Platform</div>
              <h2 class="login-hero-title">Train with purpose. Progress with precision.</h2>
              <p class="login-hero-sub">
                A refined academy experience built for focused training, elevated standards, and professional aviation learning.
              </p>

              <div class="login-hero-pills">
                <span class="login-hero-pill">Elite training environment</span>
                <span class="login-hero-pill">Passkey-ready access</span>
                <span class="login-hero-pill">Global academy standards</span>
              </div>
            </div>
          </div>

          <div class="login-visual-footer">
            <div>International Pilot Center Alliance</div>
            <div class="login-visual-footer-chip">Academy Sign-In</div>
          </div>
        </div>
      </div>

      <div class="login-panel">
        <section class="login-card">
          <div class="login-card-head">
            <div class="login-card-overline">Welcome Back</div>
            <h3 class="login-card-title">Sign In</h3>
            <p class="login-card-sub">
              Continue into your academy environment with secure credentials or biometric sign-in.
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
    </section>
  </main>
</div>
</body>
</html>