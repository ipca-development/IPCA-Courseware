<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/admin_user_edit_helpers.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

cw_require_login();

$currentUser = cw_current_user($pdo);
$currentRole = strtolower(trim((string)($currentUser['role'] ?? '')));

if ($currentRole !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    exit('Missing user id.');
}

$activeTab = aue_active_tab((string)($_GET['tab'] ?? 'account'));
$flashType = strtolower(trim((string)($_GET['flash_type'] ?? '')));
$flashMessage = trim((string)($_GET['flash_message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedUserId = (int)($_POST['user_id'] ?? 0);
    $section = strtolower(trim((string)($_POST['form_section'] ?? '')));
    $postedTab = aue_active_tab((string)($_POST['tab'] ?? $activeTab));
    $actorId = (int)($currentUser['id'] ?? 0);

    if ($postedUserId !== $userId) {
        aue_flash_redirect($userId, $postedTab, 'error', 'User context mismatch.');
    }

    try {
        switch ($section) {
            case 'account':
                aue_update_account_tab($pdo, $userId, $actorId);
                aue_flash_redirect($userId, 'account', 'success', 'Account details updated.');
                break;

            case 'profile':
                aue_update_profile_tab($pdo, $userId);
                aue_flash_redirect($userId, 'profile', 'success', 'Profile details updated.');
                break;

            case 'emergency':
                aue_update_emergency_tab($pdo, $userId);
                aue_flash_redirect($userId, 'emergency', 'success', 'Emergency contacts updated.');
                break;

            case 'billing':
                aue_update_billing_tab($pdo, $userId);
                aue_flash_redirect($userId, 'billing', 'success', 'Billing details updated.');
                break;

            case 'activate_user':
                aue_activate_pending_user($pdo, $userId, $actorId);
                aue_flash_redirect($userId, 'account', 'success', 'User activated and onboarding email sent.');
                break;

            default:
                aue_flash_redirect($userId, $postedTab, 'error', 'Unknown form action.');
        }
    } catch (Throwable $e) {
        aue_flash_redirect($userId, $postedTab, 'error', $e->getMessage());
    }
}

$workspace = aue_load_user_workspace($pdo, $userId);

if (!$workspace) {
    http_response_code(404);
    exit('User not found.');
}

$user = $workspace['user'];
$emergency = $workspace['emergency'];
$emergencyPrimary = $workspace['emergency_primary'];
$emergencySecondary = $workspace['emergency_secondary'];
$missingFields = $workspace['missing_fields'];
$displayName = $workspace['display_name'];
$missingCount = (int)($user['missing_count'] ?? 0);
$tabs = aue_tabs();

cw_header('User Workspace');
?>

<style>
.user-edit-page{
    display:block;
}
.user-edit-page .app-section-hero{
    margin-bottom:20px;
}
.ue-back-link{
    display:inline-flex;
    align-items:center;
    gap:8px;
    margin-bottom:14px;
    color:rgba(255,255,255,0.86);
    text-decoration:none;
    font-size:13px;
    font-weight:650;
}
.ue-back-link:hover{
    color:#fff;
}
.ue-back-link svg{
    width:15px;
    height:15px;
    flex:0 0 15px;
}
.ue-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:24px;
}
.ue-identity{
    display:flex;
    gap:18px;
    min-width:0;
}
.ue-avatar{
    width:84px;
    height:84px;
    border-radius:24px;
    overflow:hidden;
    flex:0 0 84px;
    background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);
    border:1px solid rgba(15,23,42,0.07);
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.45);
}
.ue-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
.ue-avatar-fallback{
    width:34px;
    height:34px;
    color:#7b8aa0;
}
.ue-title{
    margin:0;
    font-size:34px;
    line-height:1.02;
    letter-spacing:-0.04em;
    font-weight:760;
    color:#fff;
}
.ue-meta{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:18px;
}
.ue-flash{
    padding:14px 16px;
    border-radius:16px;
    margin-bottom:18px;
    font-size:14px;
    font-weight:620;
}
.ue-flash--success{
    background:rgba(32,135,90,0.09);
    color:#1f7a54;
    border:1px solid rgba(32,135,90,0.14);
}
.ue-flash--error{
    background:rgba(185,54,54,0.09);
    color:#ac2f2f;
    border:1px solid rgba(185,54,54,0.14);
}
.ue-tabs-card{
    padding:10px;
}
.ue-tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.ue-tab{
    min-height:42px;
    padding:0 14px;
}
.ue-grid{
    display:grid;
    grid-template-columns:1.2fr 0.8fr;
    gap:18px;
    margin-top:18px;
}
.ue-stack{
    display:grid;
    gap:18px;
}
.ue-card{
    padding:22px;
}
.ue-card-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin:0 0 16px 0;
    font-size:18px;
    font-weight:740;
    letter-spacing:-0.02em;
    color:var(--text-strong);
}
.ue-card-title-main{
    display:flex;
    align-items:center;
    gap:10px;
}
.ue-card-title svg{
    width:18px;
    height:18px;
    color:var(--text-muted);
}
.ue-card-subtitle{
    margin:-6px 0 18px 0;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.6;
}
.ue-side-section{
    display:grid;
    gap:14px;
}
.ue-mini-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:0 12px;
    height:32px;
    border-radius:999px;
    border:1px solid rgba(15,23,42,0.08);
    background:#f8fafc;
    color:#334255;
    font-size:12px;
    font-weight:700;
}
.ue-mini-chip svg{
    width:14px;
    height:14px;
    flex:0 0 14px;
}
.ue-note{
    color:var(--text-muted);
    font-size:13px;
    line-height:1.6;
}
.ue-list{
    display:grid;
    gap:10px;
}
.ue-list-item{
    padding:14px;
    border:1px solid rgba(15,23,42,0.06);
    border-radius:14px;
    background:#fbfcfe;
}
.ue-list-title{
    font-size:14px;
    font-weight:700;
    color:var(--text-strong);
}
.ue-list-meta{
    margin-top:6px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.55;
}
.ue-collapse-toggle{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:34px;
    padding:0 12px;
    border-radius:999px;
    border:1px solid rgba(15,23,42,0.08);
    background:#fff;
    color:var(--text-strong);
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    transition:background .16s ease,border-color .16s ease,transform .16s ease;
}
.ue-collapse-toggle:hover{
    background:#f8fafc;
    border-color:rgba(15,23,42,0.14);
    transform:translateY(-1px);
}
.ue-collapse-toggle svg{
    width:14px;
    height:14px;
}
.ue-collapse-content[hidden]{
    display:none !important;
}
@media (max-width:1200px){
    .ue-grid{
        grid-template-columns:1fr;
    }
    .ue-header{
        flex-direction:column;
        align-items:flex-start;
    }
}
@media (max-width:820px){
    .ue-title{
        font-size:28px;
    }
    .ue-card-title{
        align-items:flex-start;
        flex-direction:column;
    }
}
</style>

<div class="user-edit-page">
    <?php if ($flashMessage !== ''): ?>
        <div class="ue-flash <?php echo $flashType === 'success' ? 'ue-flash--success' : 'ue-flash--error'; ?>">
            <?php echo h($flashMessage); ?>
        </div>
    <?php endif; ?>

    <section class="app-section-hero">
        <a class="ue-back-link" href="/admin/users/index.php">
            <?php echo aue_svg('archive'); ?>
            <span>Back to Users</span>
        </a>

        <div class="hero-overline">Operations · User Accounts</div>

        <div class="ue-header">
            <div class="ue-identity">
                <div class="ue-avatar">
                    <?php if (!empty($user['photo_path'])): ?>
                        <img src="<?php echo h((string)$user['photo_path']); ?>" alt="<?php echo h($displayName); ?>">
                    <?php else: ?>
                        <span class="ue-avatar-fallback"><?php echo aue_svg('users'); ?></span>
                    <?php endif; ?>
                </div>

                <div style="min-width:0;">
                    <h2 class="ue-title"><?php echo h($displayName); ?></h2>

                    <div class="ue-meta">
                        <span class="<?php echo aue_role_class((string)$user['role']); ?>">
                            <?php echo h(aue_role_label((string)$user['role'])); ?>
                        </span>

                        <span class="<?php echo aue_status_class((string)$user['status']); ?>">
                            <?php echo h(aue_status_label((string)$user['status'])); ?>
                        </span>

                        <span class="<?php echo aue_completeness_class($missingCount); ?>">
                            <?php echo $missingCount > 0 ? ('Missing ' . $missingCount) : 'Profile Complete'; ?>
                        </span>

                        <span class="<?php echo aue_validity_class((string)($user['account_valid_until'] ?? '')); ?>">
                            <?php echo h(aue_validity_label((string)($user['account_valid_until'] ?? ''))); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card ue-tabs-card">
        <nav class="ue-tabs" aria-label="User workspace sections">
            <a class="app-tab-pill ue-tab" href="/admin/users/index.php">
                <?php echo aue_svg('archive'); ?>
                <span>Back to Users</span>
            </a>

            <a class="app-tab-pill ue-tab<?php echo $activeTab === 'account' ? ' is-active' : ''; ?>" href="<?php echo aue_edit_url($userId, 'account'); ?>">
                <?php echo aue_svg('users'); ?><span>Account</span>
            </a>

            <a class="app-tab-pill ue-tab<?php echo $activeTab === 'profile' ? ' is-active' : ''; ?>" href="<?php echo aue_edit_url($userId, 'profile'); ?>">
                <?php echo aue_svg('profile'); ?><span>Profile</span>
            </a>

            <a class="app-tab-pill ue-tab<?php echo $activeTab === 'emergency' ? ' is-active' : ''; ?>" href="<?php echo aue_edit_url($userId, 'emergency'); ?>">
                <?php echo aue_svg('warning'); ?><span>Emergency</span>
            </a>

            <a class="app-tab-pill ue-tab<?php echo $activeTab === 'billing' ? ' is-active' : ''; ?>" href="<?php echo aue_edit_url($userId, 'billing'); ?>">
                <?php echo aue_svg('billing'); ?><span>Billing</span>
            </a>

            <a class="app-tab-pill ue-tab" href="javascript:void(0)" style="opacity:.45;pointer-events:none;">
                <?php echo aue_svg('mail'); ?><span>Integrations</span>
            </a>

            <a class="app-tab-pill ue-tab" href="javascript:void(0)" style="opacity:.45;pointer-events:none;">
                <?php echo aue_svg('lock'); ?><span>Credentials Vault</span>
            </a>

            <a class="app-tab-pill ue-tab" href="javascript:void(0)" style="opacity:.45;pointer-events:none;">
                <?php echo aue_svg('shield'); ?><span>Security</span>
            </a>

            <a class="app-tab-pill ue-tab" href="javascript:void(0)" style="opacity:.45;pointer-events:none;">
                <?php echo aue_svg('activity'); ?><span>Audit</span>
            </a>
        </nav>
    </section>

    <div class="ue-grid">
        <div class="ue-stack">
            <?php
            $partialMap = array(
                'account' => __DIR__ . '/partials/account_tab.php',
                'profile' => __DIR__ . '/partials/profile_tab.php',
                'emergency' => __DIR__ . '/partials/emergency_tab.php',
                'billing' => __DIR__ . '/partials/billing_tab.php',
            );

            $partialFile = $partialMap[$activeTab] ?? $partialMap['account'];
            require $partialFile;
            ?>
        </div>

        <aside class="ue-stack">
            <section class="card ue-card">
                <h3 class="ue-card-title">
                    <span class="ue-card-title-main"><?php echo aue_svg('check'); ?><span>Readiness Summary</span></span>
                </h3>

                <div class="ue-side-section">
                    <div class="<?php echo aue_completeness_class($missingCount); ?>">
                        <?php echo $missingCount > 0 ? ('Missing ' . $missingCount . ' field' . ($missingCount === 1 ? '' : 's')) : 'Profile complete'; ?>
                    </div>

                    <div class="ue-mini-chip">
                        <?php echo aue_svg('clock'); ?>
                        <span><?php echo h(aue_human_datetime((string)($user['last_evaluated_at'] ?? ''))); ?></span>
                    </div>
                </div>
            </section>

            <section class="card ue-card">
                <h3 class="ue-card-title">
                    <span class="ue-card-title-main"><?php echo aue_svg('warning'); ?><span>Missing Data</span></span>
                    <button
                        type="button"
                        class="ue-collapse-toggle"
                        data-target="ue-missing-data-content"
                        aria-expanded="false"
                        aria-controls="ue-missing-data-content">
                        <?php echo aue_svg('archive'); ?>
                        <span>Show / Hide</span>
                    </button>
                </h3>

                <div id="ue-missing-data-content" class="ue-collapse-content" hidden>
                    <?php if ($missingFields): ?>
                        <div class="ue-list">
                            <?php foreach ($missingFields as $field): ?>
                                <div class="ue-list-item">
                                    <div class="ue-list-title"><?php echo h((string)$field); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="ue-note">
                            No missing data items.
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card ue-card">
                <h3 class="ue-card-title">
                    <span class="ue-card-title-main"><?php echo aue_svg('activity'); ?><span>Account Snapshot</span></span>
                </h3>

                <div class="ue-list">
                    <div class="ue-list-item">
                        <div class="ue-list-title">Email</div>
                        <div class="ue-list-meta"><?php echo h((string)$user['email']); ?></div>
                    </div>

                    <div class="ue-list-item">
                        <div class="ue-list-title">Username</div>
                        <div class="ue-list-meta"><?php echo h(trim((string)($user['email'] ?? '')) !== '' ? (string)$user['email'] : '—'); ?></div>
                    </div>

                    <div class="ue-list-item">
                        <div class="ue-list-title">Last Login</div>
                        <div class="ue-list-meta"><?php echo h(aue_human_datetime((string)($user['last_login_at'] ?? ''))); ?></div>
                    </div>

                    <div class="ue-list-item">
                        <div class="ue-list-title">Password Changed</div>
                        <div class="ue-list-meta"><?php echo h(aue_human_datetime((string)($user['password_changed_at'] ?? ''))); ?></div>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>

<script>
(function () {
    var buttons = document.querySelectorAll('.ue-collapse-toggle');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            var targetId = this.getAttribute('data-target');
            if (!targetId) {
                return;
            }

            var target = document.getElementById(targetId);
            if (!target) {
                return;
            }

            var isHidden = target.hasAttribute('hidden');
            if (isHidden) {
                target.removeAttribute('hidden');
                this.setAttribute('aria-expanded', 'true');
            } else {
                target.setAttribute('hidden', 'hidden');
                this.setAttribute('aria-expanded', 'false');
            }
        });
    }
})();
</script>

<?php cw_footer(); ?>