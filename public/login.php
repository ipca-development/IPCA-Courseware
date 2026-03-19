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
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app-shell.css">
<style>
html,
body,
body.login-page,
body.login-page *{
    font-family:"Manrope", sans-serif;
}

:root{
    color-scheme:dark;
}

html,
body{
    min-height:100%;
}

body.login-page{
    margin:0;
    min-height:100vh;
    color:#ffffff;
    overflow:hidden;
    background:linear-gradient(135deg,#0d1d34 0%,#102440 45%,#17345d 100%);
    background-size:200% 200%;
    animation:gradientFlow 18s ease-in-out infinite;
}

/* ✈️ Subtle premium animated gradient */
@keyframes gradientFlow{
    0%{background-position:0% 50%;}
    50%{background-position:100% 50%;}
    100%{background-position:0% 50%;}
}

.login-shell{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    box-sizing:border-box;
}

.login-frame{
    width:100%;
    max-width:1280px;
    display:grid;
    grid-template-columns:minmax(420px, 1fr) minmax(380px, 430px);
    gap:44px;
    align-items:center;
}

/* ===== BRAND BLOCK ===== */
.login-brand{
    min-width:0;
    padding:0;
    display:flex;
    flex-direction:column;
    justify-content:flex-start;
}

/* Logo pushed UP + larger breathing space */
.login-logo{
    width:260px;
    max-width:100%;
    height:auto;
    display:block;
    object-fit:contain;
    margin:-20px 0 48px 0; /* ← pushed UP + increased spacing */
}

/* Title */
.login-brand-title{
    margin:0;
    color:#ffffff;
    font-size:58px;
    line-height:0.94;
    letter-spacing:-0.07em;
    font-weight:800;
}

/* Subtitle */
.login-brand-subtitle{
    margin:16px 0 0 0;
    color:rgba(255,255,255,0.82);
    font-size:20px;
    line-height:1.45;
    font-weight:600;
}

/* ===== CARD ===== */
.login-card-wrap{
    display:flex;
    justify-content:flex-end;
}

.login-card{
    width:100%;
    max-width:430px;
    background:rgba(255,255,255,0.12);
    border:1px solid rgba(255,255,255,0.14);
    border-radius:28px;
    box-shadow:
        0 26px 60px rgba(0,0,0,0.24),
        inset 0 1px 0 rgba(255,255,255,0.10);
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}

.login-card-head{
    padding:28px 28px 12px 28px;
}

.login-card-overline{
    margin:0 0 10px 0;
    color:rgba(255,255,255,0.62);
    font-size:11px;
    letter-spacing:.14em;
    text-transform:uppercase;
    font-weight:700;
}

.login-card-title{
    margin:0;
    font-size:34px;
    letter-spacing:-0.06em;
    font-weight:800;
}

.login-card-body{
    padding:10px 28px 28px 28px;
}

/* Alerts */
.login-flash,
.login-error{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:16px;
    font-size:14px;
    font-weight:700;
}

.login-flash{
    background:rgba(22,101,52,0.18);
    border:1px solid rgba(187,247,208,0.26);
}

.login-error{
    background:rgba(190,24,93,0.16);
    border:1px solid rgba(254,205,211,0.22);
}

/* Passkey button */
.login-passkey-btn{
    width:100%;
    height:52px;
    border-radius:16px;
    border:none;
    background:rgba(255,255,255,0.14);
    color:#fff;
    font-weight:700;
    cursor:pointer;
    transition:.15s ease;
}
.login-passkey-btn:hover{
    background:rgba(255,255,255,0.18);
}

/* Divider */
.login-divider{
    margin:18px 0;
    text-align:center;
    font-size:11px;
    color:rgba(255,255,255,0.5);
    letter-spacing:.12em;
}

/* Inputs */
.login-field{
    margin-bottom:14px;
}

.login-label{
    font-size:12px;
    color:rgba(255,255,255,0.7);
    font-weight:700;
}

.login-input{
    width:100%;
    height:50px;
    border-radius:16px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.10);
    color:#fff;
    padding:0 14px;
}

.login-input:focus{
    outline:none;
    border-color:rgba(255,255,255,0.3);
}

/* Button */
.login-btn{
    width:100%;
    height:50px;
    border-radius:16px;
    border:none;
    background:#fff;
    color:#102440;
    font-weight:800;
    cursor:pointer;
    margin-top:10px;
}

/* ===== RESPONSIVE ===== */
@media (max-width:820px){
    .login-frame{
        grid-template-columns:1fr;
        gap:20px;
    }

    .login-logo{
        margin:-10px 0 36px 0;
        width:200px;
    }

    .login-brand-title{
        font-size:40px;
    }
}
</style>
</head>

<body class="login-page">
<div class="login-shell">
    <div class="login-frame">

        <section class="login-brand">
            <img class="login-logo" src="/assets/logo/ipca_logo_white.png" alt="IPCA Academy">
            <h1 class="login-brand-title">IPCA Academy</h1>
            <p class="login-brand-subtitle">Structured Learning. Global Standards.</p>
        </section>

        <div class="login-card-wrap">
            <section class="login-card">

                <div class="login-card-head">
                    <div class="login-card-overline">Welcome Back</div>
                    <h2 class="login-card-title">Sign In</h2>
                </div>

                <div class="login-card-body">

                    <?php if ($error): ?>
                        <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <button class="login-passkey-btn">Sign in with Face ID / Touch ID</button>

                    <div class="login-divider">or continue</div>

                    <form method="post">
                        <div class="login-field">
                            <div class="login-label">Email</div>
                            <input class="login-input" type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>

                        <div class="login-field">
                            <div class="login-label">Password</div>
                            <input class="login-input" type="password" name="password" required>
                        </div>

                        <div style="margin-bottom:10px;">
                            <a class="login-link" href="/forgot_password.php" style="color:#fff;font-size:13px;">Forgot password?</a>
                        </div>

                        <button class="login-btn">Enter Academy</button>
                    </form>

                </div>
            </section>
        </div>

    </div>
</div>
</body>
</html>