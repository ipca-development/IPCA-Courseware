<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/layout.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

function rp_find_valid_reset_token(PDO $pdo, string $rawToken): ?array
{
    $rawToken = trim($rawToken);
    if ($rawToken === '') {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare("
        SELECT
            prt.id,
            prt.user_id,
            prt.token_hash,
            prt.expires_at,
            prt.used_at,
            prt.created_at,
            u.email,
            u.name,
            u.first_name,
            u.last_name,
            u.status
        FROM password_reset_tokens prt
        INNER JOIN users u
            ON u.id = prt.user_id
        WHERE prt.token_hash = :token_hash
          AND prt.used_at IS NULL
          AND prt.expires_at >= UTC_TIMESTAMP()
        ORDER BY prt.id DESC
        LIMIT 1
    ");
    $stmt->execute(array(
        ':token_hash' => $tokenHash,
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function rp_mark_token_used(PDO $pdo, int $tokenId): void
{
    $stmt = $pdo->prepare("
        UPDATE password_reset_tokens
        SET used_at = UTC_TIMESTAMP()
        WHERE id = :id
          AND used_at IS NULL
        LIMIT 1
    ");
    $stmt->execute(array(
        ':id' => $tokenId,
    ));
}

function rp_display_name(array $row): string
{
    $displayName = trim((string)($row['name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    }
    if ($displayName === '') {
        $displayName = (string)($row['email'] ?? 'User');
    }
    return $displayName;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$flashType = '';
$flashMessage = '';
$password = '';
$passwordConfirm = '';
$isSuccess = false;

$tokenRow = rp_find_valid_reset_token($pdo, $token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($token === '') {
            throw new RuntimeException('Missing reset token.');
        }

        if (!$tokenRow) {
            throw new RuntimeException('This password reset link is invalid or has expired.');
        }

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

        $pdo->beginTransaction();

        $updateUser = $pdo->prepare("
            UPDATE users
            SET
                password_hash = :password_hash,
                must_change_password = 0,
                password_changed_at = UTC_TIMESTAMP(),
                updated_at = NOW()
            WHERE id = :user_id
            LIMIT 1
        ");
        $updateUser->execute(array(
            ':password_hash' => $passwordHash,
            ':user_id' => (int)$tokenRow['user_id'],
        ));

        rp_mark_token_used($pdo, (int)$tokenRow['id']);

        $expireOthers = $pdo->prepare("
            UPDATE password_reset_tokens
            SET used_at = UTC_TIMESTAMP()
            WHERE user_id = :user_id
              AND used_at IS NULL
              AND id <> :current_id
        ");
        $expireOthers->execute(array(
            ':user_id' => (int)$tokenRow['user_id'],
            ':current_id' => (int)$tokenRow['id'],
        ));

        $pdo->commit();

        $flashType = 'success';
        $flashMessage = 'Your password has been reset successfully. You can now sign in with your new password.';
        $isSuccess = true;
        $password = '';
        $passwordConfirm = '';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

cw_header('Reset Password');
?>

<style>
.rp-page{
    max-width:620px;
    margin:0 auto;
}
.rp-hero{
    margin-bottom:18px;
}
.rp-card{
    padding:24px;
}
.rp-title{
    margin:0;
    font-size:28px;
    line-height:1.08;
    letter-spacing:-0.03em;
    font-weight:800;
    color:var(--text-strong);
}
.rp-subtitle{
    margin:10px 0 0 0;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.65;
}
.rp-flash{
    border-radius:16px;
    padding:14px 16px;
    margin-bottom:16px;
    font-size:14px;
    font-weight:700;
    line-height:1.5;
}
.rp-flash--success{
    background:#ecfdf5;
    border:1px solid #bbf7d0;
    color:#166534;
}
.rp-flash--error{
    background:#fff1f2;
    border:1px solid #fecdd3;
    color:#be123c;
}
.rp-field{
    display:flex;
    flex-direction:column;
    gap:8px;
    margin-bottom:16px;
}
.rp-label{
    font-size:12px;
    font-weight:700;
    letter-spacing:.02em;
    color:var(--text-muted);
}
.rp-input{
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
.rp-input:focus{
    border-color:rgba(82,133,212,0.45);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
}
.rp-help{
    color:var(--text-muted);
    font-size:13px;
    line-height:1.6;
}
.rp-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
}
.rp-btn{
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
.rp-btn:hover{
    transform:translateY(-1px);
    border-color:rgba(16,36,64,0.16);
    background:#f9fbfe;
    text-decoration:none;
}
.rp-btn--primary{
    background:linear-gradient(180deg,#17345d 0%,#102440 100%);
    color:#fff;
    border-color:transparent;
    box-shadow:0 10px 22px rgba(16,36,64,0.13);
}
.rp-meta{
    margin-top:18px;
    padding-top:16px;
    border-top:1px solid rgba(15,23,42,0.06);
    color:var(--text-muted);
    font-size:12px;
    line-height:1.6;
}
.rp-invalid{
    color:var(--text-muted);
    font-size:14px;
    line-height:1.65;
}
</style>

<div class="rp-page">
    <section class="app-section-hero rp-hero">
        <div class="hero-overline">Access Control</div>
        <h1 class="rp-title" style="color:#fff;">Reset Password</h1>
        <p class="rp-subtitle" style="color:rgba(255,255,255,0.86);">
            Choose a new password for your IPCA account.
        </p>
    </section>

    <section class="card rp-card">
        <?php if ($flashMessage !== ''): ?>
            <div class="rp-flash <?php echo $flashType === 'success' ? 'rp-flash--success' : 'rp-flash--error'; ?>">
                <?php echo h($flashMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($isSuccess): ?>
            <div class="rp-actions">
                <a class="rp-btn rp-btn--primary" href="/login.php">
                    Go to Login
                </a>
            </div>
        <?php elseif (!$tokenRow): ?>
            <div class="rp-invalid">
                This password reset link is invalid, expired, or has already been used.
            </div>

            <div class="rp-actions">
                <a class="rp-btn rp-btn--primary" href="/forgot_password.php">
                    Request New Reset Link
                </a>

                <a class="rp-btn" href="/login.php">
                    Back to Login
                </a>
            </div>
        <?php else: ?>
            <div class="rp-help" style="margin-bottom:16px;">
                Resetting password for <strong><?php echo h(rp_display_name($tokenRow)); ?></strong>.
            </div>

            <form method="post" action="/reset_password.php" novalidate>
                <input type="hidden" name="token" value="<?php echo h($token); ?>">

                <div class="rp-field">
                    <label class="rp-label" for="password">New Password</label>
                    <input
                        class="rp-input"
                        id="password"
                        type="password"
                        name="password"
                        value="<?php echo h($password); ?>"
                        required
                        autocomplete="new-password"
                    >
                    <div class="rp-help">
                        Use at least 8 characters.
                    </div>
                </div>

                <div class="rp-field">
                    <label class="rp-label" for="password_confirm">Confirm New Password</label>
                    <input
                        class="rp-input"
                        id="password_confirm"
                        type="password"
                        name="password_confirm"
                        value="<?php echo h($passwordConfirm); ?>"
                        required
                        autocomplete="new-password"
                    >
                </div>

                <div class="rp-actions">
                    <button class="rp-btn rp-btn--primary" type="submit">
                        Save New Password
                    </button>

                    <a class="rp-btn" href="/login.php">
                        Back to Login
                    </a>
                </div>
            </form>

            <div class="rp-meta">
                This reset link can only be used once.
            </div>
        <?php endif; ?>
    </section>
</div>

<?php cw_footer(); ?>