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
    position:relative;
    background:
        linear-gradient(135deg, #0d1d34 0%, #102440 46%, #17345d 100%);
    background-size:200% 200%;
    animation:gradientFlow 20s ease-in-out infinite;
}

body.login-page::before,
body.login-page::after{
    content:"";
    position:absolute;
    inset:auto;
    border-radius:50%;
    pointer-events:none;
    z-index:0;
}

body.login-page::before{
    width:480px;
    height:480px;
    left:-120px;
    top:-90px;
    background:radial-gradient(circle, rgba(110,174,252,0.22) 0%, rgba(110,174,252,0.00) 72%);
    filter:blur(10px);
    animation:orbFloatOne 18s ease-in-out infinite;
}

body.login-page::after{
    width:420px;
    height:420px;
    right:-100px;
    bottom:-80px;
    background:radial-gradient(circle, rgba(165,214,255,0.14) 0%, rgba(165,214,255,0.00) 72%);
    filter:blur(12px);
    animation:orbFloatTwo 24s ease-in-out infinite;
}

@keyframes gradientFlow{
    0%{ background-position:0% 50%; }
    50%{ background-position:100% 50%; }
    100%{ background-position:0% 50%; }
}

@keyframes orbFloatOne{
    0%{ transform:translate3d(0,0,0); }
    50%{ transform:translate3d(34px,22px,0); }
    100%{ transform:translate3d(0,0,0); }
}

@keyframes orbFloatTwo{
    0%{ transform:translate3d(0,0,0); }
    50%{ transform:translate3d(-28px,-18px,0); }
    100%{ transform:translate3d(0,0,0); }
}

.login-shell{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    box-sizing:border-box;
    position:relative;
    z-index:1;
}

.login-shell::before{
    content:"";
    position:absolute;
    left:10%;
    right:10%;
    top:18%;
    height:180px;
    border-radius:999px;
    background:radial-gradient(ellipse at center, rgba(151,213,255,0.10) 0%, rgba(151,213,255,0.00) 72%);
    filter:blur(20px);
    pointer-events:none;
    animation:horizonSweep 16s ease-in-out infinite;
}

.login-shell::after{
    content:"";
    position:absolute;
    width:760px;
    height:760px;
    left:8%;
    top:50%;
    transform:translateY(-50%) rotate(-8deg);
    border:1px solid rgba(255,255,255,0.06);
    border-left-color:transparent;
    border-bottom-color:transparent;
    border-radius:50%;
    pointer-events:none;
    opacity:0.55;
    animation:routeArcDrift 22s ease-in-out infinite;
}

@keyframes horizonSweep{
    0%{
        transform:translate3d(-20px,0,0);
        opacity:0.55;
    }
    50%{
        transform:translate3d(22px,-6px,0);
        opacity:0.82;
    }
    100%{
        transform:translate3d(-20px,0,0);
        opacity:0.55;
    }
}

@keyframes routeArcDrift{
    0%{
        transform:translateY(-50%) rotate(-8deg) scale(1);
        opacity:0.30;
    }
    50%{
        transform:translateY(-50%) rotate(-5deg) scale(1.02);
        opacity:0.48;
    }
    100%{
        transform:translateY(-50%) rotate(-8deg) scale(1);
        opacity:0.30;
    }
}

.login-frame{
    width:100%;
    max-width:1280px;
    display:grid;
    grid-template-columns:minmax(460px, 0.95fr) minmax(380px, 430px);
    gap:56px;
    align-items:center;
}

.login-brand{
    min-width:0;
    padding:0 0 0 42px;
    display:flex;
    flex-direction:column;
    justify-content:flex-start;
    position:relative;
}

.login-brand::before{
    content:"";
    position:absolute;
    left:8px;
    top:18px;
    width:12px;
    height:12px;
    border-radius:50%;
    background:rgba(167,225,255,0.75);
    box-shadow:
        0 0 0 0 rgba(167,225,255,0.30),
        0 0 18px rgba(167,225,255,0.45);
    animation:navPulse 3.2s ease-in-out infinite;
    pointer-events:none;
}

@keyframes navPulse{
    0%{
        transform:scale(0.95);
        box-shadow:
            0 0 0 0 rgba(167,225,255,0.30),
            0 0 14px rgba(167,225,255,0.30);
        opacity:0.75;
    }
    50%{
        transform:scale(1.08);
        box-shadow:
            0 0 0 14px rgba(167,225,255,0.00),
            0 0 24px rgba(167,225,255,0.52);
        opacity:1;
    }
    100%{
        transform:scale(0.95);
        box-shadow:
            0 0 0 0 rgba(167,225,255,0.00),
            0 0 14px rgba(167,225,255,0.30);
        opacity:0.75;
    }
}

.login-brand-mark{
    margin:0 0 74px 0;
    position:relative;
    z-index:1;
}

.login-brand-mark img{
    width:260px;
    max-width:100%;
    height:auto;
    display:block;
    object-fit:contain;
}

.login-brand-title{
    margin:0;
    color:#ffffff;
    font-size:58px;
    line-height:0.94;
    letter-spacing:-0.07em;
    font-weight:800;
    position:relative;
    z-index:1;
}

.login-brand-subtitle{
    margin:16px 0 0 0;
    color:rgba(255,255,255,0.82);
    font-size:20px;
    line-height:1.45;
    font-weight:600;
    position:relative;
    z-index:1;
}

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
    color:#ffffff;
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
    font-weight:700;
    line-height:1.5;
}

.login-flash{
    background:rgba(22,101,52,0.18);
    border:1px solid rgba(187,247,208,0.26);
    color:#d1fae5;
}

.login-error{
    background:rgba(190,24,93,0.16);
    border:1px solid rgba(254,205,211,0.22);
    color:#ffe4ea;
}

.login-passkey-btn{
    width:100%;
    height:52px;
    border-radius:16px;
    border:none;
    background:rgba(255,255,255,0.14);
    color:#ffffff;
    font-weight:700;
    font-size:15px;
    cursor:pointer;
    transition:.15s ease;
}

.login-passkey-btn:hover{
    background:rgba(255,255,255,0.18);
    transform:translateY(-1px);
}

.login-passkey-note{
    margin:10px 0 0 0;
    color:rgba(255,255,255,0.66);
    font-size:12px;
    line-height:1.55;
    text-align:center;
}

.login-divider{
    position:relative;
    margin:18px 0 16px 0;
    text-align:center;
}

.login-divider::before{
    content:"";
    position:absolute;
    left:0;
    right:0;
    top:50%;
    height:1px;
    background:rgba(255,255,255,0.12);
    transform:translateY(-50%);
}

.login-divider span{
    position:relative;
    z-index:1;
    display:inline-block;
    padding:0 12px;
    background:rgba(16,36,64,0.35);
    color:rgba(255,255,255,0.52);
    font-size:11px;
    font-weight:760;
    letter-spacing:.12em;
    text-transform:uppercase;
    border-radius:999px;
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
    color:rgba(255,255,255,0.72);
    font-weight:700;
    letter-spacing:.02em;
}

.login-input{
    width:100%;
    height:50px;
    border-radius:16px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.10);
    box-sizing:border-box;
    color:#ffffff;
    padding:0 14px;
    font-size:14px;
    font-weight:560;
    outline:none;
    transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease;
}

.login-input::placeholder{
    color:rgba(255,255,255,0.42);
}

.login-input:focus{
    outline:none;
    border-color:rgba(255,255,255,0.30);
    box-shadow:0 0 0 4px rgba(110,174,252,0.11);
    background:rgba(255,255,255,0.13);
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
    color:#ffffff;
    text-decoration:none;
    font-size:13px;
    font-weight:760;
}

.login-link:hover{
    text-decoration:underline;
}

.login-btn{
    width:100%;
    height:50px;
    border-radius:16px;
    border:none;
    background:#ffffff;
    color:#102440;
    font-weight:800;
    font-size:15px;
    cursor:pointer;
    margin-top:10px;
    transition:transform .16s ease, filter .16s ease, box-shadow .16s ease;
    box-shadow:0 12px 24px rgba(0,0,0,0.14);
}

.login-btn:hover{
    transform:translateY(-1px);
    filter:brightness(1.01);
}

.login-meta{
    margin-top:16px;
    padding-top:14px;
    border-top:1px solid rgba(255,255,255,0.08);
    color:rgba(255,255,255,0.50);
    font-size:12px;
    line-height:1.55;
    text-align:center;
}

@media (max-width: 1024px){
    .login-page-shell{
        padding:22px;
    }

    .login-frame{
        gap:36px;
        grid-template-columns:minmax(360px, 0.92fr) minmax(320px, 400px);
    }

    .login-brand{
        padding:0 0 0 18px;
    }

    .login-brand-title{
        font-size:46px;
    }

    .login-brand-subtitle{
        font-size:17px;
    }

    .login-brand-mark{
        margin-bottom:60px;
    }

    .login-brand-mark img{
        width:220px;
    }

    .login-shell::after{
        width:620px;
        height:620px;
        left:2%;
    }
}

@media (max-width: 820px){
    body.login-page{
        overflow:auto;
    }

    body.login-page::before,
    body.login-page::after,
    .login-shell::after{
        opacity:0.45;
    }

    .login-page-shell{
        min-height:auto;
        padding:20px 18px 24px 18px;
    }

    .login-frame{
        grid-template-columns:1fr;
        gap:24px;
        max-width:520px;
    }

    .login-brand{
        padding:0;
        text-align:left;
    }

    .login-brand::before{
        left:2px;
        top:6px;
    }

    .login-brand-mark{
        margin-bottom:44px;
    }

    .login-brand-mark img{
        width:200px;
    }

    .login-brand-title{
        font-size:38px;
    }

    .login-brand-subtitle{
        margin-top:12px;
        font-size:16px;
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
        font-size:32px;
    }

    .login-brand-mark{
        margin-bottom:34px;
    }

    .login-brand-mark img{
        width:176px;
    }
}
</style>
</head>

<body class="login-page">
<div class="login-shell">
    <div class="login-frame">
        <section class="login-brand" aria-label="IPCA Academy brand">
            <div class="login-brand-mark">
                <img src="/assets/logo/ipca_logo_white.png" alt="IPCA Academy">
            </div>

            <h1 class="login-brand-title">IPCA Academy</h1>
            <p class="login-brand-subtitle">Structured Learning. Global Standards.</p>
        </section>

        <div class="login-card-wrap">
            <section class="login-card" aria-label="Sign in">
                <div class="login-card-head">
                    <div class="login-card-overline">Welcome Back</div>
                    <h2 class="login-card-title">Sign In</h2>
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

                    <button class="login-passkey-btn" type="button">Sign in with Face ID / Touch ID</button>

                    <div class="login-divider"><span>or continue</span></div>

                    <form method="post" action="/login.php" novalidate>
                        <div class="login-field">
                            <label class="login-label" for="email">Email</label>
                            <input class="login-input" id="email" type="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="email">
                        </div>

                        <div class="login-field">
                            <label class="login-label" for="password">Password</label>
                            <input class="login-input" id="password" type="password" name="password" required autocomplete="current-password">
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