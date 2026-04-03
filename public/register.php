<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/onboarding_tokens.php';
require_once __DIR__ . '/../src/admin_user_edit_helpers.php';
require_once __DIR__ . '/../src/automation_runtime.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function reg_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reg_client_ip(): string
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
            return substr($value, 0, 64);
        }
    }

    return '';
}

function reg_user_agent(): string
{
    return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

function reg_find_user_by_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, email, status, role, first_name, last_name, name
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute(array(
        ':email' => $email,
    ));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function reg_log_duplicate_attempt(PDO $pdo, string $email, string $firstName, string $lastName, string $cellphone): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO auth_security_events (
                user_id,
                event_type,
                event_status,
                ip_address,
                user_agent,
                meta_json,
                created_at
            ) VALUES (
                NULL,
                :event_type,
                :event_status,
                :ip_address,
                :user_agent,
                :meta_json,
                NOW()
            )
        ");
        $stmt->execute(array(
            ':event_type' => 'public_registration_duplicate_email',
            ':event_status' => 'info',
            ':ip_address' => reg_client_ip() !== '' ? reg_client_ip() : null,
            ':user_agent' => reg_user_agent() !== '' ? reg_user_agent() : null,
            ':meta_json' => json_encode(array(
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'cellphone' => $cellphone,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));
    } catch (Throwable $e) {
    }
}



$flashType = '';
$flashMessage = '';
$isSuccess = false;

$form = array(
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'cellphone' => '',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $form['last_name'] = trim((string)($_POST['last_name'] ?? ''));
    $form['email'] = trim((string)($_POST['email'] ?? ''));
    $form['cellphone'] = trim((string)($_POST['cellphone'] ?? ''));

    try {
        $firstName = $form['first_name'];
        $lastName = $form['last_name'];
        $email = $form['email'];
        $cellphone = $form['cellphone'];

        if ($firstName === '' || $lastName === '' || $email === '' || $cellphone === '') {
            throw new RuntimeException('Please complete all required fields.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $existingUser = reg_find_user_by_email($pdo, $email);


		if ($existingUser === null) {
    $displayName = trim($firstName . ' ' . $lastName);

    // Generate UUID for canonical users row
    $uuid = bin2hex(random_bytes(16));
    $uuid = sprintf(
        '%s-%s-%s-%s-%s',
        substr($uuid, 0, 8),
        substr($uuid, 8, 4),
        substr($uuid, 12, 4),
        substr($uuid, 16, 4),
        substr($uuid, 20, 12)
    );

    $pdo->beginTransaction();

    $insertUser = $pdo->prepare("
        INSERT INTO users (
            uuid,
            name,
            first_name,
            last_name,
            email,
            username,
            role,
            status,
            account_valid_until,
            password_hash,
            must_change_password,
            created_by_user_id,
            updated_by_user_id,
            created_at,
            updated_at
        ) VALUES (
            :uuid,
            :name,
            :first_name,
            :last_name,
            :email,
            NULL,
            'student',
            'pending_activation',
            NULL,
            NULL,
            1,
            NULL,
            NULL,
            NOW(),
            NOW()
        )
    ");
		
$insertUser->execute(array(
    ':uuid' => $uuid,
    ':name' => $displayName,
    ':first_name' => $firstName,
    ':last_name' => $lastName,
    ':email' => $email,
));

            $newUserId = (int)$pdo->lastInsertId();
            if ($newUserId <= 0) {
                throw new RuntimeException('Unable to process your request at this time.');
            }

            $upsertProfile = $pdo->prepare("
                INSERT INTO user_profiles (
                    user_id,
                    cellphone,
                    created_at,
                    updated_at
                ) VALUES (
                    :user_id,
                    :cellphone,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    cellphone = VALUES(cellphone),
                    updated_at = NOW()
            ");
            $upsertProfile->execute(array(
                ':user_id' => $newUserId,
                ':cellphone' => $cellphone,
            ));

			aue_recalculate_profile_requirements_status($pdo, $newUserId);
			
            try {
                $securityStmt = $pdo->prepare("
                    INSERT INTO auth_security_events (
                        user_id,
                        event_type,
                        event_status,
                        ip_address,
                        user_agent,
                        meta_json,
                        created_at
                    ) VALUES (
                        :user_id,
                        :event_type,
                        :event_status,
                        :ip_address,
                        :user_agent,
                        :meta_json,
                        NOW()
                    )
                ");
                $securityStmt->execute(array(
                    ':user_id' => $newUserId,
                    ':event_type' => 'public_registration_created',
                    ':event_status' => 'success',
                    ':ip_address' => reg_client_ip() !== '' ? reg_client_ip() : null,
                    ':user_agent' => reg_user_agent() !== '' ? reg_user_agent() : null,
                    ':meta_json' => json_encode(array(
                        'email' => $email,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'cellphone' => $cellphone,
                        'role' => 'student',
                        'status' => 'pending_activation',
                    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ));
            } catch (Throwable $e) {
            }

            $pdo->commit();

            try {
                $automationRuntime = new AutomationRuntime();
                $automationRuntime->dispatchEvent(
                    $pdo,
                    'public_registration_submitted',
                    array(
                        'user_id' => $newUserId,
                        'user_name' => $displayName,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'login_email' => $email,
                        'email' => $email,
                        'mobile_phone' => $cellphone,
                        'support_email' => ot_support_email(),
                        'to_email' => $email,
                        'to_name' => $displayName
                    )
                );
            } catch (Throwable $e) {
            }
        } else {
            reg_log_duplicate_attempt($pdo, $email, $firstName, $lastName, $cellphone);
        }

        $flashType = 'success';
        $flashMessage = 'Your request has been received.';
        $isSuccess = true;

        $form = array(
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'cellphone' => '',
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

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
<title>Register · IPCA Academy</title>
<meta name="theme-color" content="#1e3c72">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="IPCA Academy">

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">

<link rel="icon" type="image/svg+xml" href="/favicon.svg">

<link rel="apple-touch-icon" href="/favicon-180x180.png">

<link rel="manifest" href="/site.webmanifest">
	
	
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

.access-form-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
}

.access-field{
    display:flex;
    flex-direction:column;
    gap:8px;
    margin-bottom:0;
}

.access-field--full{
    grid-column:1 / -1;
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
    margin-top:14px;
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

    .access-form-grid{
        grid-template-columns:1fr;
    }

    .access-field--full{
        grid-column:auto;
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
            <section class="access-card" aria-label="Public registration">
                <div class="access-card-head">
                    <div class="access-card-overline">Student Intake</div>
                    <h2 class="access-card-title">Register Interest</h2>
                    <p class="access-card-subtitle">
                        Submit your details to begin the intake process for IPCA Academy.
                    </p>
                </div>

                <div class="access-card-body">
                    <?php if ($flashMessage !== ''): ?>
                        <div class="access-flash <?php echo $flashType === 'success' ? 'access-flash--success' : 'access-flash--error'; ?>">
                            <?php echo reg_h($flashMessage); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isSuccess): ?>
                        <form method="post" action="/register.php" novalidate>
                            <div class="access-form-grid">
                                <div class="access-field">
                                    <label class="access-label" for="first_name">First Name</label>
                                    <input
                                        class="access-input"
                                        id="first_name"
                                        type="text"
                                        name="first_name"
                                        value="<?php echo reg_h($form['first_name']); ?>"
                                        required
                                        autocomplete="given-name">
                                </div>

                                <div class="access-field">
                                    <label class="access-label" for="last_name">Last Name</label>
                                    <input
                                        class="access-input"
                                        id="last_name"
                                        type="text"
                                        name="last_name"
                                        value="<?php echo reg_h($form['last_name']); ?>"
                                        required
                                        autocomplete="family-name">
                                </div>

                                <div class="access-field access-field--full">
                                    <label class="access-label" for="email">Email</label>
                                    <input
                                        class="access-input"
                                        id="email"
                                        type="email"
                                        name="email"
                                        value="<?php echo reg_h($form['email']); ?>"
                                        required
                                        autocomplete="email"
                                        placeholder="you@example.com">
                                </div>

                                <div class="access-field access-field--full">
                                    <label class="access-label" for="cellphone">Mobile Phone</label>
                                    <input
                                        class="access-input"
                                        id="cellphone"
                                        type="text"
                                        name="cellphone"
                                        value="<?php echo reg_h($form['cellphone']); ?>"
                                        required
                                        autocomplete="tel"
                                        placeholder="+1 ...">
                                </div>
                            </div>

                            <div class="access-help">
                                Your submission is reviewed before any account access is granted.
                            </div>

                            <div class="access-actions">
                                <button class="access-btn access-btn--primary" type="submit">Submit Request</button>
                                <a class="access-btn access-btn--secondary" href="/login.php">Back to Login</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="access-actions">
                            <a class="access-btn access-btn--primary" href="/login.php">Back to Login</a>
                        </div>
                    <?php endif; ?>

                    <div class="access-meta">
                        Public registration is an intake request only. Access is granted only after review and activation.
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
</body>
</html>