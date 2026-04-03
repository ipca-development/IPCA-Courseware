<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/notification_service.php';
require_once __DIR__ . '/../src/automation_runtime.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function fp_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fp_client_ip(): string
{
    $keys = array(
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    );

    foreach ($keys as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim((string)($parts[0] ?? ''));
        }

        if ($value !== '') {
            return substr($value, 0, 255);
        }
    }

    return '';
}

function fp_base_url(): string
{
    $scheme = 'https';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        $appUrl = trim((string)($_ENV['APP_URL'] ?? ''));
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }
        return '';
    }

    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif ((string)($_SERVER['SERVER_PORT'] ?? '') === '80') {
        $scheme = 'http';
    }

    return $scheme . '://' . $host;
}

function fp_support_email(): string
{
    $candidates = array(
        trim((string)($_ENV['SUPPORT_EMAIL'] ?? '')),
        trim((string)($_ENV['MAIL_FROM_ADDRESS'] ?? '')),
    );

    foreach ($candidates as $candidate) {
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'support@ipca.aero';
}

$flashType = '';
$flashMessage = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    try {
        if ($email === '') {
            throw new RuntimeException('Please enter your email address.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $stmt = $pdo->prepare("
            SELECT id, name, first_name, last_name, email, status
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute(array(
            ':email' => $email,
        ));
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($user)) {
            $userId = (int)($user['id'] ?? 0);
            $displayName = trim((string)($user['name'] ?? ''));

            if ($displayName === '') {
                $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = 'User';
            }

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $expiresAtTs = time() + (60 * 60);
            $expiresAtDb = gmdate('Y-m-d H:i:s', $expiresAtTs);
            $expiryMinutes = '60';
            $expiryDisplay = date('D, M j, Y g:i A', $expiresAtTs);

            $ip = fp_client_ip();
            $userAgent = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 1000);

            $insert = $pdo->prepare("
                INSERT INTO password_reset_tokens (
                    user_id,
                    token_hash,
                    expires_at,
                    used_at,
                    requested_ip,
                    requested_user_agent,
                    created_at
                ) VALUES (
                    :user_id,
                    :token_hash,
                    :expires_at,
                    NULL,
                    :requested_ip,
                    :requested_user_agent,
                    NOW()
                )
            ");
            $insert->execute(array(
                ':user_id' => $userId,
                ':token_hash' => $tokenHash,
                ':expires_at' => $expiresAtDb,
                ':requested_ip' => $ip !== '' ? $ip : null,
                ':requested_user_agent' => $userAgent !== '' ? $userAgent : null,
            ));

            $baseUrl = fp_base_url();
            $resetLink = rtrim($baseUrl, '/') . '/reset_password.php?token=' . urlencode($rawToken);

			
//AUTOMATION TRIGGER 			
			
			$automationRuntime = new AutomationRuntime();
			$automationRuntime->dispatchEvent(
    		$pdo,
    		'password_reset_requested',
    			array(
					'user_name' => $displayName,
					'reset_link' => $resetLink,
					'expiry_minutes' => $expiryMinutes,
					'expiry_datetime' => $expiryDisplay,
					'support_email' => fp_support_email(),
					'to_email' => (string)$user['email'],
					'to_name' => $displayName,
    			)
			);
        }

        $flashType = 'success';
        $flashMessage = 'If an account with that email exists, a password reset link has been sent.';
        $email = '';
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password · IPCA Academy</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app-shell.css">
<style>
html,
body,
body.access-page,
body.access-page *{
    font-family:"Manrope", sans-serif;
}

:root{
    color-scheme:dark;
}

html,
body{
    min-height:100%;
}

body.access-page{
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

body.access-page::before,
body.access-page::after{
    content:"";
    position:absolute;
    inset:auto;
    border-radius:50%;
    pointer-events:none;
    z-index:0;
}

body.access-page::before{
    width:480px;
    height:480px;
    left:-120px;
    top:-90px;
    background:radial-gradient(circle, rgba(110,174,252,0.22) 0%, rgba(110,174,252,0.00) 72%);
    filter:blur(10px);
    animation:orbFloatOne 18s ease-in-out infinite;
}

body.access-page::after{
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

.access-shell{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    box-sizing:border-box;
    position:relative;
    z-index:1;
}

.access-shell::before{
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

.access-shell::after{
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

.access-frame{
    width:100%;
    max-width:1280px;
    display:grid;
    grid-template-columns:minmax(460px, 0.95fr) minmax(380px, 430px);
    gap:56px;
    align-items:center;
}

.access-brand{
    min-width:0;
    padding:0 0 0 42px;
    display:flex;
    flex-direction:column;
    justify-content:flex-start;
    position:relative;
}

.access-brand::before{
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

.access-brand-mark{
    margin:0 0 74px 0;
    position:relative;
    z-index:1;
}

.access-brand-mark img{
    width:260px;
    max-width:100%;
    height:auto;
    display:block;
    object-fit:contain;
}

.access-brand-title{
    margin:0;
    color:#ffffff;
    font-size:58px;
    line-height:0.94;
    letter-spacing:-0.07em;
    font-weight:800;
    position:relative;
    z-index:1;
}

.access-brand-subtitle{
    margin:16px 0 0 0;
    color:rgba(255,255,255,0.82);
    font-size:20px;
    line-height:1.45;
    font-weight:600;
    position:relative;
    z-index:1;
}

.access-card-wrap{
    display:flex;
    justify-content:flex-end;
}

.access-card{
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

.access-card-head{
    padding:28px 28px 12px 28px;
}

.access-card-overline{
    margin:0 0 10px 0;
    color:rgba(255,255,255,0.62);
    font-size:11px;
    letter-spacing:.14em;
    text-transform:uppercase;
    font-weight:700;
}

.access-card-title{
    margin:0;
    font-size:34px;
    letter-spacing:-0.06em;
    font-weight:800;
    color:#ffffff;
}

.access-card-subtitle{
    margin:10px 0 0 0;
    color:rgba(255,255,255,0.76);
    font-size:14px;
    line-height:1.6;
    font-weight:600;
}

.access-card-body{
    padding:10px 28px 28px 28px;
}

.access-flash{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:16px;
    font-size:14px;
    font-weight:700;
    line-height:1.5;
}

.access-flash--success{
    background:rgba(22,101,52,0.18);
    border:1px solid rgba(187,247,208,0.26);
    color:#d1fae5;
}

.access-flash--error{
    background:rgba(190,24,93,0.16);
    border:1px solid rgba(254,205,211,0.22);
    color:#ffe4ea;
}

.access-field{
    display:flex;
    flex-direction:column;
    gap:8px;
    margin-bottom:14px;
}

.access-label{
    font-size:12px;
    color:rgba(255,255,255,0.72);
    font-weight:700;
    letter-spacing:.02em;
}

.access-input{
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

.access-input::placeholder{
    color:rgba(255,255,255,0.42);
}

.access-input:focus{
    outline:none;
    border-color:rgba(255,255,255,0.30);
    box-shadow:0 0 0 4px rgba(110,174,252,0.11);
    background:rgba(255,255,255,0.13);
    transform:translateY(-1px);
}

.access-help{
    color:rgba(255,255,255,0.66);
    font-size:12px;
    line-height:1.55;
}

.access-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
}

.access-btn{
    min-height:46px;
    padding:0 16px;
    border-radius:16px;
    border:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    font-size:14px;
    font-weight:800;
    cursor:pointer;
    transition:.16s ease;
    box-sizing:border-box;
}

.access-btn:hover{
    transform:translateY(-1px);
    text-decoration:none;
}

.access-btn--primary{
    background:#ffffff;
    color:#102440;
    box-shadow:0 12px 24px rgba(0,0,0,0.14);
}

.access-btn--secondary{
    background:rgba(255,255,255,0.14);
    color:#ffffff;
    border:1px solid rgba(255,255,255,0.12);
}

.access-meta{
    margin-top:16px;
    padding-top:14px;
    border-top:1px solid rgba(255,255,255,0.08);
    color:rgba(255,255,255,0.50);
    font-size:12px;
    line-height:1.55;
    text-align:center;
}

@media (max-width: 1024px){
    .access-shell{
        padding:22px;
    }

    .access-frame{
        gap:36px;
        grid-template-columns:minmax(360px, 0.92fr) minmax(320px, 400px);
    }

    .access-brand{
        padding:0 0 0 18px;
    }

    .access-brand-title{
        font-size:46px;
    }

    .access-brand-subtitle{
        font-size:17px;
    }

    .access-brand-mark{
        margin-bottom:60px;
    }

    .access-brand-mark img{
        width:220px;
    }

    .access-shell::after{
        width:620px;
        height:620px;
        left:2%;
    }
}

@media (max-width: 820px){
    body.access-page{
        overflow:auto;
    }

    body.access-page::before,
    body.access-page::after,
    .access-shell::after{
        opacity:0.45;
    }

    .access-shell{
        min-height:auto;
        padding:20px 18px 24px 18px;
    }

    .access-frame{
        grid-template-columns:1fr;
        gap:24px;
        max-width:520px;
    }

    .access-brand{
        padding:0;
        text-align:left;
    }

    .access-brand::before{
        left:2px;
        top:6px;
    }

    .access-brand-mark{
        margin-bottom:44px;
    }

    .access-brand-mark img{
        width:200px;
    }

    .access-brand-title{
        font-size:38px;
    }

    .access-brand-subtitle{
        margin-top:12px;
        font-size:16px;
    }

    .access-card-wrap{
        justify-content:flex-start;
    }
}

@media (max-width: 560px){
    .access-shell{
        padding:16px;
    }

    .access-card-head,
    .access-card-body{
        padding-left:20px;
        padding-right:20px;
    }

    .access-card-title{
        font-size:30px;
    }

    .access-brand-title{
        font-size:32px;
    }

    .access-brand-mark{
        margin-bottom:34px;
    }

    .access-brand-mark img{
        width:176px;
    }
}
</style>
</head>
<body class="access-page">
<div class="access-shell">
    <div class="access-frame">
        <section class="access-brand" aria-label="IPCA Academy brand">
            <div class="access-brand-mark">
                <img src="/assets/logo/ipca_logo_white.png" alt="IPCA Academy">
            </div>
            <h1 class="access-brand-title">IPCA Academy</h1>
            <p class="access-brand-subtitle">Structured Learning. Global Standards.</p>
        </section>

        <div class="access-card-wrap">
            <section class="access-card" aria-label="Forgot password">
                <div class="access-card-head">
                    <div class="access-card-overline">Access Control</div>
                    <h2 class="access-card-title">Forgot Password</h2>
                    <p class="access-card-subtitle">
                        Enter your email address to request a secure password reset link.
                    </p>
                </div>

                <div class="access-card-body">
                    <?php if ($flashMessage !== ''): ?>
                        <div class="access-flash <?php echo $flashType === 'success' ? 'access-flash--success' : 'access-flash--error'; ?>">
                            <?php echo fp_h($flashMessage); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/forgot_password.php" novalidate>
                        <div class="access-field">
                            <label class="access-label" for="email">Email</label>
                            <input
                                class="access-input"
                                id="email"
                                type="email"
                                name="email"
                                value="<?php echo fp_h($email); ?>"
                                placeholder="you@example.com"
                                required
                                autocomplete="email">
                            <div class="access-help">
                                For security, this form shows the same success message whether or not the email exists.
                            </div>
                        </div>

                        <div class="access-actions">
                            <button class="access-btn access-btn--primary" type="submit">Send Reset Link</button>
                            <a class="access-btn access-btn--secondary" href="/login.php">Back to Login</a>
                        </div>
                    </form>

                    <div class="access-meta">
                        Reset links expire automatically and can only be used once.
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
</body>
</html>