<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/admin_user_edit_helpers.php';
require_once __DIR__ . '/../../../src/onboarding_tokens.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

cw_require_login();

$currentUser = cw_current_user($pdo);
$currentRole = strtolower(trim((string)($currentUser['role'] ?? '')));
$actorUserId = (int)($currentUser['id'] ?? 0);

if ($currentRole !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

function auc_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function auc_post_bool(string $key): int
{
    return !empty($_POST[$key]) ? 1 : 0;
}

function auc_allowed_roles(): array
{
    return array(
        'admin' => 'Admin',
        'supervisor' => 'Instructor',
        'student' => 'Student',
        'instructor' => 'Instructor',
        'chief_instructor' => 'Chief Instructor',
    );
}

function auc_allowed_statuses(): array
{
    return array(
        'active' => 'Active',
        'pending_activation' => 'Pending Activation',
        'locked' => 'Locked',
        'retired' => 'Retired',
    );
}

$flashType = '';
$flashMessage = '';

$form = array(
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'cellphone' => '',
    'role' => 'student',
    'status' => 'active',
    'account_valid_until' => '',
    'must_change_password' => '1',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
    $form['last_name'] = trim((string)($_POST['last_name'] ?? ''));
    $form['email'] = trim((string)($_POST['email'] ?? ''));
    $form['cellphone'] = trim((string)($_POST['cellphone'] ?? ''));
    $form['role'] = strtolower(trim((string)($_POST['role'] ?? 'student')));
    $form['status'] = strtolower(trim((string)($_POST['status'] ?? 'active')));
    $form['account_valid_until'] = trim((string)($_POST['account_valid_until'] ?? ''));
    $form['must_change_password'] = auc_post_bool('must_change_password') ? '1' : '0';

    try {
        $firstName = $form['first_name'];
        $lastName = $form['last_name'];
        $email = $form['email'];
        $cellphone = $form['cellphone'];
        $role = $form['role'];
        $status = $form['status'];
        $accountValidUntil = aue_normalize_date($form['account_valid_until']);
        $mustChangePassword = $form['must_change_password'] === '1' ? 1 : 0;

        $allowedRoles = array_keys(auc_allowed_roles());
        $allowedStatuses = array_keys(auc_allowed_statuses());

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new RuntimeException('First name, last name, and email are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        if (!in_array($role, $allowedRoles, true)) {
            throw new RuntimeException('Invalid role selected.');
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Invalid status selected.');
        }

        $dupStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $dupStmt->execute(array(
            ':email' => $email,
        ));
        if ($dupStmt->fetchColumn()) {
            throw new RuntimeException('Another user already uses this email address.');
        }

        $displayName = trim($firstName . ' ' . $lastName);

        $pdo->beginTransaction();

		$uuid = bin2hex(random_bytes(16));

// Format as UUID v4 (clean + standard)
$uuid = sprintf('%s-%s-%s-%s-%s',
    substr($uuid, 0, 8),
    substr($uuid, 8, 4),
    substr($uuid, 12, 4),
    substr($uuid, 16, 4),
    substr($uuid, 20, 12)
);

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
        :role,
        :status,
        :account_valid_until,
        NULL,
        :must_change_password,
        :created_by_user_id,
        :updated_by_user_id,
        NOW(),
        NOW()
    )
");

$insertUser->execute([
    ':uuid' => $uuid,
    ':name' => $displayName,
    ':first_name' => $firstName,
    ':last_name' => $lastName,
    ':email' => $email,
    ':role' => $role,
    ':status' => $status,
    ':account_valid_until' => $accountValidUntil,
    ':must_change_password' => $mustChangePassword,
    ':created_by_user_id' => $actorUserId ?: null,
    ':updated_by_user_id' => $actorUserId ?: null,
]);

        $newUserId = (int)$pdo->lastInsertId();
        if ($newUserId <= 0) {
            throw new RuntimeException('Failed to create user.');
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
            ':cellphone' => $cellphone !== '' ? $cellphone : null,
        ));
		
		aue_recalculate_profile_requirements_status($pdo, $newUserId);

        $tokenRow = ot_create_token($pdo, $newUserId, 'set_password', $actorUserId > 0 ? $actorUserId : null, 60);

        $userRow = array(
            'id' => $newUserId,
            'name' => $displayName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'must_change_password' => $mustChangePassword,
        );

        ot_send_set_password_notification($pdo, $userRow, $tokenRow);

        $pdo->commit();

        header('Location: /admin/users/edit.php?id=' . $newUserId . '&tab=account&flash_type=success&flash_message=' . urlencode('User created successfully.'));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $flashType = 'error';
        $flashMessage = $e->getMessage();
    }
}

cw_header('Create User');
?>

<style>
.user-create-page{
    display:block;
}
.user-create-page .app-section-hero{
    margin-bottom:20px;
}
.auc-back-link{
    display:inline-flex;
    align-items:center;
    gap:8px;
    margin-bottom:14px;
    color:rgba(255,255,255,0.86);
    text-decoration:none;
    font-size:13px;
    font-weight:650;
}
.auc-back-link:hover{
    color:#fff;
}
.auc-back-link svg{
    width:15px;
    height:15px;
    flex:0 0 15px;
}
.auc-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:24px;
}
.auc-title{
    margin:0;
    font-size:34px;
    line-height:1.02;
    letter-spacing:-0.04em;
    font-weight:760;
    color:#fff;
}
.auc-subtitle{
    margin:12px 0 0 0;
    color:rgba(255,255,255,0.82);
    font-size:15px;
    line-height:1.65;
    max-width:860px;
}
.auc-meta{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:18px;
}
.auc-flash{
    padding:14px 16px;
    border-radius:16px;
    margin-bottom:18px;
    font-size:14px;
    font-weight:620;
}
.auc-flash--error{
    background:rgba(185,54,54,0.09);
    color:#ac2f2f;
    border:1px solid rgba(185,54,54,0.14);
}
.auc-grid{
    display:grid;
    grid-template-columns:1.2fr 0.8fr;
    gap:18px;
}
.auc-stack{
    display:grid;
    gap:18px;
}
.auc-card{
    padding:22px;
}
.auc-card-title{
    display:flex;
    align-items:center;
    gap:10px;
    margin:0 0 16px 0;
    font-size:18px;
    font-weight:740;
    letter-spacing:-0.02em;
    color:var(--text-strong);
}
.auc-card-title svg{
    width:18px;
    height:18px;
    color:var(--text-muted);
}
.auc-card-subtitle{
    margin:-6px 0 18px 0;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.6;
}
.auc-form-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
}
.auc-field{
    display:flex;
    flex-direction:column;
    gap:7px;
    min-width:0;
}
.auc-field label{
    font-size:12px;
    font-weight:670;
    letter-spacing:.02em;
    color:var(--text-muted);
}
.auc-field--full{
    grid-column:1 / -1;
}
.auc-input,
.auc-select{
    width:100%;
    height:44px;
    border-radius:14px;
    border:1px solid rgba(15,23,42,0.08);
    background:#fff;
    box-sizing:border-box;
    color:var(--text-strong);
    font-size:14px;
    font-weight:560;
    outline:none;
    transition:border-color .16s ease, box-shadow .16s ease;
    padding:0 14px;
}
.auc-input:focus,
.auc-select:focus{
    border-color:rgba(82,133,212,0.45);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
}
.auc-checkbox{
    display:inline-flex;
    align-items:center;
    gap:10px;
    font-size:14px;
    font-weight:600;
    color:var(--text-strong);
}
.auc-checkbox input{
    width:16px;
    height:16px;
    margin:0;
}
.auc-actions-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
}
.auc-actions-row{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:18px;
}
.auc-actions-row .app-btn svg{
    width:15px;
    height:15px;
    flex:0 0 15px;
}
.auc-note{
    color:var(--text-muted);
    font-size:13px;
    line-height:1.6;
}
.auc-list{
    display:grid;
    gap:10px;
}
.auc-list-item{
    padding:14px;
    border:1px solid rgba(15,23,42,0.06);
    border-radius:14px;
    background:#fbfcfe;
}
.auc-list-title{
    font-size:14px;
    font-weight:700;
    color:var(--text-strong);
}
.auc-list-meta{
    margin-top:6px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.55;
}
@media (max-width:1200px){
    .auc-grid{
        grid-template-columns:1fr;
    }
}
@media (max-width:900px){
    .auc-form-grid{
        grid-template-columns:1fr;
    }
    .auc-title{
        font-size:28px;
    }
}
</style>

<div class="user-create-page">
    <?php if ($flashMessage !== ''): ?>
        <div class="auc-flash auc-flash--error">
            <?php echo auc_h($flashMessage); ?>
        </div>
    <?php endif; ?>

    <section class="app-section-hero">
        <a class="auc-back-link" href="/admin/users/index.php">
            <?php echo aue_svg('archive'); ?>
            <span>Back to Users</span>
        </a>

        <div class="hero-overline">Operations · User Accounts</div>

        <div class="auc-header">
            <div style="min-width:0;">
                <h2 class="auc-title">Create User</h2>
                <p class="auc-subtitle">
                    Create a new internal account, generate a secure onboarding token, and send the user a password setup link without exposing any plain-text credentials.
                </p>

                <div class="auc-meta">
                    <span class="app-badge app-badge-accent">Email Login Identity</span>
                    <span class="app-badge app-badge-sky">Secure Onboarding</span>
                    <span class="app-badge app-badge-neutral">No Plain-Text Password</span>
                </div>
            </div>
        </div>
    </section>

    <div class="auc-grid">
        <div class="auc-stack">
            <section class="card auc-card">
                <h3 class="auc-card-title"><?php echo aue_svg('users'); ?><span>Account Identity and Onboarding</span></h3>
                <p class="auc-card-subtitle">
                    The account root is created in the canonical <code>users</code> table. A secure onboarding email is sent immediately after creation.
                </p>

                <form method="post" action="/admin/users/create.php" novalidate>
                    <div class="auc-form-grid">
                        <div class="auc-field">
                            <label for="first_name">First Name</label>
                            <input class="auc-input" id="first_name" type="text" name="first_name" value="<?php echo auc_h($form['first_name']); ?>" required>
                        </div>

                        <div class="auc-field">
                            <label for="last_name">Last Name</label>
                            <input class="auc-input" id="last_name" type="text" name="last_name" value="<?php echo auc_h($form['last_name']); ?>" required>
                        </div>

                        <div class="auc-field">
                            <label for="email">Primary Email</label>
                            <input class="auc-input" id="email" type="email" name="email" value="<?php echo auc_h($form['email']); ?>" required>
                        </div>

                        <div class="auc-field">
                            <label for="cellphone">Mobile Phone</label>
                            <input class="auc-input" id="cellphone" type="text" name="cellphone" value="<?php echo auc_h($form['cellphone']); ?>">
                        </div>

                        <div class="auc-field">
                            <label for="role">Role</label>
                            <select class="auc-select" id="role" name="role">
                                <?php foreach (auc_allowed_roles() as $roleValue => $roleLabel): ?>
                                    <option value="<?php echo auc_h($roleValue); ?>"<?php echo $form['role'] === $roleValue ? ' selected' : ''; ?>>
                                        <?php echo auc_h($roleLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="auc-field">
                            <label for="status">Status</label>
                            <select class="auc-select" id="status" name="status">
                                <?php foreach (auc_allowed_statuses() as $statusValue => $statusLabel): ?>
                                    <option value="<?php echo auc_h($statusValue); ?>"<?php echo $form['status'] === $statusValue ? ' selected' : ''; ?>>
                                        <?php echo auc_h($statusLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="auc-field">
                            <label for="account_valid_until">Account Valid Until</label>
                            <input class="auc-input" id="account_valid_until" type="date" name="account_valid_until" value="<?php echo auc_h($form['account_valid_until']); ?>">
                        </div>

                        <div class="auc-field auc-field--full">
                            <label class="auc-checkbox">
                                <input type="checkbox" name="must_change_password" value="1"<?php echo $form['must_change_password'] === '1' ? ' checked' : ''; ?>>
                                <span>Require password update after first successful sign-in</span>
                            </label>
                        </div>
                    </div>

                    <div class="auc-actions-row">
    <button class="app-btn app-btn-primary" type="submit">
        <?php echo aue_svg('save'); ?>
        <span>Create User</span>
    </button>

    <a class="app-btn app-btn-secondary" href="/admin/users/index.php">
        <?php echo aue_svg('archive'); ?>
        <span>Back to Users</span>
    </a>
					</div>
                </form>
            </section>
        </div>

        <aside class="auc-stack">
            <section class="card auc-card">
                <h3 class="auc-card-title"><?php echo aue_svg('mail'); ?><span>Onboarding Behavior</span></h3>

                <div class="auc-list">
                    <div class="auc-list-item">
                        <div class="auc-list-title">Email as Login Identity</div>
                        <div class="auc-list-meta">The user signs in with email only. No public username dependency is introduced.</div>
                    </div>

                    <div class="auc-list-item">
                        <div class="auc-list-title">Secure Password Setup</div>
                        <div class="auc-list-meta">A one-time onboarding token is created and sent by email. No password is sent in plain text.</div>
                    </div>

                    <div class="auc-list-item">
                        <div class="auc-list-title">Immediate Redirect</div>
                        <div class="auc-list-meta">After creation, the workflow returns directly to the canonical user workspace.</div>
                    </div>
                </div>
            </section>

            <section class="card auc-card">
                <h3 class="auc-card-title"><?php echo aue_svg('shield'); ?><span>Implementation Notes</span></h3>
                <div class="auc-note">
                    This page creates the canonical user root first, stores the mobile number in the profile layer, then generates the onboarding token and sends the secure set-password email.
                </div>
            </section>
        </aside>
    </div>
</div>

<?php cw_footer(); ?>