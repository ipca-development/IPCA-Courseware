<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';
require_once __DIR__ . '/../src/notification_service.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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

            $service = new NotificationService($pdo);
            $service->sendSystemNotification(
                'password_reset_request',
                (string)$user['email'],
                $displayName,
                array(
                    'user_name' => $displayName,
                    'reset_link' => $resetLink,
                    'expiry_minutes' => $expiryMinutes,
                    'expiry_datetime' => $expiryDisplay,
                    'support_email' => fp_support_email(),
                ),
                null
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

cw_header('Forgot Password');
?>

<style>
.fp-page{
    max-width:560px;
    margin:0 auto;
}
.fp-hero{
    margin-bottom:18px;
}
.fp-card{
    padding:24px;
}
.fp-title{
    margin:0;
    font-size:28px;
    line-height:1.08;
    letter-spacing:-0.03em;
    font-weight:800;
    color:var(--text-strong);
}
.fp-subtitle{
    margin:10px 0 0 0;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.65;
}
.fp-flash{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:16px;
    font-size:14px;
    font-weight:700;
    line-height:1.5;
}
.fp-flash--success{
    background:#ecfdf5;
    border:1px solid #bbf7d0;
    color:#166534;
}
.fp-flash--error{
    background:#fff1f2;
    border:1px solid #fecdd3;
    color:#be123c;
}
.fp-field{
    display:flex;
    flex-direction:column;
    gap:8px;
    margin-bottom:16px;
}
.fp-label{
    font-size:12px;
    font-weight:700;
    letter-spacing:.02em;
    color:var(--text-muted);
}
.fp-input{
    width:100%;
    height:46px;
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
.fp-input:focus{
    border-color:rgba(82,133,212,0.45);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
}
.fp-help{
    color:var(--text-muted);
    font-size:13px;
    line-height:1.6;
}
.fp-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
}
.fp-btn{
    min-height:44px;
    padding:0 16px;
    border-radius:14px;
    border:1px solid rgba(15,23,42,0.10);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    background:#fff;
    color:var(--text-strong);
    text-decoration:none;
    font-size:14px;
    font-weight:800;
    cursor:pointer;
    transition:transform .16s ease,border-color .16s ease,background .16s ease;
    box-sizing:border-box;
}
.fp-btn:hover{
    transform:translateY(-1px);
    border-color:rgba(16,36,64,0.16);
    background:#f9fbfe;
    text-decoration:none;
}
.fp-btn--primary{
    background:linear-gradient(180deg,#17345d 0%,#102440 100%);
    color:#fff;
    border-color:transparent;
    box-shadow:0 10px 22px rgba(16,36,64,0.13);
}
.fp-meta{
    margin-top:18px;
    padding-top:16px;
    border-top:1px solid rgba(15,23,42,0.06);
    color:var(--text-muted);
    font-size:12px;
    line-height:1.6;
}
</style>

<div class="fp-page">
    <section class="app-section-hero fp-hero">
        <div class="hero-overline">Access Control</div>
        <h1 class="fp-title" style="color:#fff;">Forgot Password</h1>
        <p class="fp-subtitle" style="color:rgba(255,255,255,0.86);">
            Enter your email address and we will send you a secure password reset link if your account exists.
        </p>
    </section>

    <section class="card fp-card">
        <?php if ($flashMessage !== ''): ?>
            <div class="fp-flash <?php echo $flashType === 'success' ? 'fp-flash--success' : 'fp-flash--error'; ?>">
                <?php echo h($flashMessage); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/forgot_password.php" novalidate>
            <div class="fp-field">
                <label class="fp-label" for="email">Email Address</label>
                <input
                    class="fp-input"
                    id="email"
                    type="email"
                    name="email"
                    value="<?php echo h($email); ?>"
                    placeholder="you@example.com"
                    required
                    autocomplete="email"
                >
                <div class="fp-help">
                    For security, this form always shows the same success message whether or not the email exists in the system.
                </div>
            </div>

            <div class="fp-actions">
                <button class="fp-btn fp-btn--primary" type="submit">
                    Send Reset Link
                </button>

                <a class="fp-btn" href="/login.php">
                    Back to Login
                </a>
            </div>
        </form>

        <div class="fp-meta">
            Reset links expire automatically and can only be used once.
        </div>
    </section>
</div>

<?php cw_footer(); ?>