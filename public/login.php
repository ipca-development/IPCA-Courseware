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
    background:
        linear-gradient(135deg, #0d1d34 0%, #102440 48%, #17345d 100%);
    background-size:140% 140%;
    animation: loginGradientShift 20s ease-in-out infinite;
}

@keyframes loginGradientShift{
    0%{ background-position:0% 50%; }
    50%{ background-position:100% 50%; }
    100%{ background-position:0% 50%; }
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

.login-brand{
    min-width:0;
    padding:8px 4px;
}

.login-logo{
    width:240px;
    max-width:100%;
    height:auto;
    display:block;
    object-fit:contain;
    margin:0 0 28px 0;
}

.login-brand-title{
    margin:0;
    color:#ffffff;
    font-size:58px;
    line-height:0.94;
    letter-spacing:-0.07em;
    font-weight:800;
}

.login-brand-subtitle{
    margin:14px 0 0 0;
    color:rgba(255,255,255,0.82);
    font-size:20px;
    line-height:1.45;
    font-weight:600;
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
    overflow:hidden;
}

.login-card-head{
    padding:28px 28px 12px 28px;
}

.login-card-overline{
    margin:0 0 10px 0;
    color:rgba(255,255,255,0.62);
    font-size:11px;
    line-height:1;
    letter-spacing:.14em;
    text-transform:uppercase;
    font-weight:700;
}

.login-card-title{
    margin:0;
    color:#ffffff;
    font-size:34px;
    line-height:0.98;
    letter-spacing:-0.06em;
    font-weight:800;
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
    line-height:1.5;
    font-weight:700;
}

.login-flash{
    background:rgba(22,101,52,0.18);
    border:1px solid rgba(187,247,208,0.26);
    color:#dcfce7;
}

.login-error{
    background:rgba(190,24,93,0.16);
    border:1px solid rgba(254,205,211,0.22);
    color:#ffe4ea;
}

.login-passkey-btn{
    width:100%;
    min-height:52px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    padding:0 16px;
    border:none;
    border-radius:16px;
    background:rgba(255,255,255,0.14);
    color:#ffffff;
    font-size:15px;
    line-height:1;
    font-weight:700;
    cursor:pointer;
    transition:transform .16s ease, background .16s ease, box-shadow .16s ease;
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.10);
}

.login-passkey-btn:hover{
    transform:translateY(-1px);
    background:rgba(255,255,255,0.18);
}

.login-passkey-icon{
    width:20px;
    height:20px;
    flex:0 0 20px;
    display:block;
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
    background:rgba(255,255,255,0.10);
    transform:translateY(-50%);
}

.login-divider span{
    position:relative;
    z-index:1;
    display:inline-block;
    padding:0 12px;
    background:transparent;
    color:rgba(255,255,255,0.56);
    font-size:11px;
    line-height:1;
    letter-spacing:.14em;
    text-transform:uppercase;
    font-weight:700;
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
    color:rgba(255,255,255,0.72);
    font-size:12px;
    line-height:1.2;
    font-weight:700;
    letter-spacing:.02em;
}

.login-input{
    width:100%;
    height:50px;
    padding:0 15px;
    border-radius:16px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.10);
    box-sizing:border-box;
    color:#ffffff;
    font-size:14px;
    font-weight:600;
    outline:none;
    transition:border-color .16s ease, box-shadow .16s ease, background .16s ease;
}

.login-input::placeholder{
    color:rgba(255,255,255,0.42);
}

.login-input:focus{
    border-color:rgba(255,255,255,0.28);
    background:rgba(255,255,255,0.12);
    box-shadow:0 0 0 4px rgba(255,255,255,0.08);
}

.login-row{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    margin-top:2px;
    margin-bottom:16px;
}

.login-link{
    color:rgba(255,255,255,0.88);
    text-decoration:none;
    font-size:13px;
    line-height:1.3;
    font-weight:700;
}

.login-link:hover{
    text-decoration:underline;
}

.login-btn{
    width:100%;
    min-height:50px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0 16px;
    border:none;
    border-radius:16px;
    background:#ffffff;
    color:#102440;
    font-size:15px;
    line-height:1;
    font-weight:800;
    letter-spacing:.01em;
    cursor:pointer;
    transition:transform .16s ease, filter .16s ease, box-shadow .16s ease;
    box-shadow:0 14px 30px rgba(0,0,0,0.18);
}

.login-btn:hover{
    transform:translateY(-1px);
    filter:brightness(0.98);
}

@media (max-width: 1024px){
    .login-shell{
        padding:20px;
    }

    .login-frame{
        max-width:980px;
        grid-template-columns:minmax(320px, 1fr) minmax(320px, 400px);
        gap:30px;
    }

    .login-logo{
        width:200px;
        margin-bottom:22px;
    }

    .login-brand-title{
        font-size:46px;
    }

    .login-brand-subtitle{
        font-size:17px;
    }
}

@media (max-width: 820px){
    .login-shell{
        min-height:100vh;
        padding:18px;
    }

    .login-frame{
        max-width:100%;
        grid-template-columns:1fr 390px;
        gap:22px;
    }

    .login-logo{
        width:170px;
        margin-bottom:18px;
    }

    .login-brand-title{
        font-size:38px;
    }

    .login-brand-subtitle{
        font-size:15px;
        margin-top:10px;
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

@media (max-width: 700px){
    body.login-page{
        overflow:auto;
    }

    .login-shell{
        min-height:auto;
    }

    .login-frame{
        grid-template-columns:1fr;
        max-width:460px;
        gap:18px;
    }

    .login-card-wrap{
        justify-content:flex-start;
    }

    .login-brand{
        padding:0;
    }
}
</style>
</head>

<body class="app-shell-body login-page">
<div class="login-shell">
    <div class="login-frame">
        <section class="login-brand" aria-label="IPCA Academy brand">
            <img class="login-logo" src="/assets/logo/ipca_logo_white.png" alt="IPCA Academy">
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

                    <button class="login-passkey-btn" type="button">
                        <span class="login-passkey-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M12 4a4 4 0 1 1 0 8a4 4 0 0 1 0-8Zm-7 14a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M18.5 7.5h1m-3-3v1m0 4v1m-2.2-3.2l.7.7m4.3-4.3l-.7.7m0 5.1l.7-.7m-4.3-4.3l-.7-.7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span>Sign in with Face ID / Touch ID</span>
                    </button>

                    <div class="login-divider">
                        <span>or continue with password</span>
                    </div>

                    <form class="login-form" method="post" action="/login.php" novalidate>
                        <div class="login-field">
                            <label class="login-label" for="email">Email</label>
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
                </div>
            </section>
        </div>
    </div>
</div>
</body>
</html>