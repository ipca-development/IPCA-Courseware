<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

cw_require_login();

$currentUser = cw_current_user($pdo);
$userId = (int)($currentUser['id'] ?? 0);

if ($userId <= 0) {
    http_response_code(403);
    exit('Forbidden');
}

$mustChangePassword = (int)($currentUser['must_change_password'] ?? 0) === 1;

if (!$mustChangePassword) {
    header('Location: /?flash=password_changed_success');
    exit;
}

function fpc_display_name(array $user): string
{
    $displayName = trim((string)($user['name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    }
    if ($displayName === '') {
        $displayName = trim((string)($user['email'] ?? 'User'));
    }
    return $displayName !== '' ? $displayName : 'User';
}

$flashType = '';
$flashMessage = '';
$password = '';
$passwordConfirm = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if ($password === '') {
            throw new RuntimeException('Please enter a new password.');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('Your new password must be at least 8 characters long.');
        }

        if ($password !== $passwordConfirm) {
            throw new RuntimeException('The password confirmation does not match.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('Failed to generate password hash.');
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET
                password_hash = :password_hash,
                must_change_password = 0,
                password_changed_at = UTC_TIMESTAMP(),
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(array(
            ':password_hash' => $passwordHash,
            ':id' => $userId,
        ));

        header('Location: /login.php?flash=password_changed_success');
        exit;
    } catch (Throwable $e) {
        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

cw_header('Password Update Required');
?>

<style>
.fpc-page{
    max-width:620px;
    margin:0 auto;
}
.fpc-hero{
    margin-bottom:18px;
}
.fpc-card{
    padding:24px;
}
.fpc-title{
    margin:0;
    font-size:28px;
    line-height:1.08;
    letter-spacing:-0.03em;
    font-weight:800;
    color:var(--text-strong);
}
.fpc-subtitle{
    margin:10px 0 0 0;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.65;
}
.fpc-flash{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:16px;
    font-size:14px;
    font-weight:700;
    line-height:1.5;
}
.fpc-flash--error{
    background:#fff1f2;
    border:1px solid #fecdd3;
    color:#be123c;
}
.fpc-field{
    display:flex;
    flex-direction:column;
    gap:8px;
    margin-bottom:16px;
}
.fpc-label{
    font-size:12px;
    font-weight:700;
    letter-spacing:.02em;
    color:var(--text-muted);
}
.fpc-input{
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
.fpc-input:focus{
    border-color:rgba(82,133,212,0.45);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
}
.fpc-help{
    color:var(--text-muted);
    font-size:13px;
    line-height:1.6;
}
.fpc-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
}
.fpc-btn{
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
.fpc-btn:hover{
    transform:translateY(-1px);
    border-color:rgba(16,36,64,0.16);
    background:#f9fbfe;
    text-decoration:none;
}
.fpc-btn--primary{
    background:linear-gradient(180deg,#17345d 0%,#102440 100%);
    color:#fff;
    border-color:transparent;
    box-shadow:0 10px 22px rgba(16,36,64,0.13);
}
.fpc-meta{
    margin-top:18px;
    padding-top:16px;
    border-top:1px solid rgba(15,23,42,0.06);
    color:var(--text-muted);
    font-size:12px;
    line-height:1.6;
}
</style>

<div class="fpc-page">
    <section class="app-section-hero fpc-hero">
        <div class="hero-overline">Access Control</div>
        <h1 class="fpc-title" style="color:#fff;">Password Update Required</h1>
        <p class="fpc-subtitle" style="color:rgba(255,255,255,0.86);">
            Hello <?php echo h(fpc_display_name($currentUser)); ?>. For security reasons, you must set a new password before continuing.
        </p>
    </section>

    <section class="card fpc-card">
        <?php if ($flashMessage !== ''): ?>
            <div class="fpc-flash fpc-flash--error">
                <?php echo h($flashMessage); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/force_password_change.php" novalidate>
            <div class="fpc-field">
                <label class="fpc-label" for="password">New Password</label>
                <input
                    class="fpc-input"
                    id="password"
                    type="password"
                    name="password"
                    value="<?php echo h($password); ?>"
                    required
                    autocomplete="new-password"
                >
                <div class="fpc-help">
                    Use at least 8 characters.
                </div>
            </div>

            <div class="fpc-field">
                <label class="fpc-label" for="password_confirm">Confirm New Password</label>
                <input
                    class="fpc-input"
                    id="password_confirm"
                    type="password"
                    name="password_confirm"
                    value="<?php echo h($passwordConfirm); ?>"
                    required
                    autocomplete="new-password"
                >
            </div>

            <div class="fpc-actions">
                <button class="fpc-btn fpc-btn--primary" type="submit">
                    Save New Password
                </button>
            </div>
        </form>

        <div class="fpc-meta">
            You cannot access the platform normally until this password update is completed.
        </div>
    </section>
</div>

<?php cw_footer(); ?>