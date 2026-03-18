<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/auth.php';

cw_require_admin();

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Database connection not available.');
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cw_users_idx_query_int(string $key, ?int $default = null): ?int
{
    if (!isset($_GET[$key])) {
        return $default;
    }
    $v = filter_var($_GET[$key], FILTER_VALIDATE_INT);
    return ($v === false || $v === null) ? $default : (int)$v;
}

function cw_users_idx_str(string $key, string $default = ''): string
{
    $v = isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    return $v;
}

function cw_users_idx_scalar(PDO $pdo, string $sql, array $params = array(), int $default = 0): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function cw_users_idx_rows(PDO $pdo, string $sql, array $params = array()): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Throwable $e) {
        return array();
    }
}

function cw_users_idx_human_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '—';
    }
    return date('M j, Y', $ts);
}

function cw_users_idx_days_until(?string $date): ?int
{
    if (!$date) {
        return null;
    }
    $today = strtotime(date('Y-m-d'));
    $target = strtotime($date);
    if (!$today || !$target) {
        return null;
    }
    return (int)floor(($target - $today) / 86400);
}

function cw_users_idx_role_label(string $role): string
{
    $role = strtolower(trim($role));
    return match ($role) {
        'admin' => 'Admin',
        'supervisor' => 'Supervisor',
        'student' => 'Student',
        default => ucfirst($role),
    };
}

function cw_users_idx_status_label(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'pending_activation' => 'Pending Activation',
        'active' => 'Active',
        'locked' => 'Locked',
        'retired' => 'Retired',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function cw_users_idx_badge_class(string $type, string $value = ''): string
{
    $value = strtolower(trim($value));

    if ($type === 'status') {
        return match ($value) {
            'active' => 'ui-badge ui-badge--ok',
            'pending_activation' => 'ui-badge ui-badge--warn',
            'locked' => 'ui-badge ui-badge--danger',
            'retired' => 'ui-badge ui-badge--neutral',
            default => 'ui-badge ui-badge--neutral',
        };
    }

    if ($type === 'completeness') {
        return match ($value) {
            'complete' => 'ui-badge ui-badge--ok',
            'incomplete' => 'ui-badge ui-badge--warn',
            default => 'ui-badge ui-badge--neutral',
        };
    }

    if ($type === 'expiry') {
        return match ($value) {
            'expired' => 'ui-badge ui-badge--danger',
            'soon' => 'ui-badge ui-badge--warn',
            'healthy' => 'ui-badge ui-badge--neutral',
            default => 'ui-badge ui-badge--neutral',
        };
    }

    return 'ui-badge ui-badge--neutral';
}

function cw_users_idx_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'U';
    }
    $parts = preg_split('/\s+/', $name) ?: array();
    $first = mb_substr((string)($parts[0] ?? 'U'), 0, 1);
    $second = mb_substr((string)($parts[1] ?? ''), 0, 1);
    return strtoupper($first . $second);
}

function cw_users_idx_icon(string $name): string
{
    $stroke = 'currentColor';

    return match ($name) {
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
        'filter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
        'key' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8.5" cy="14.5" r="4.5"/><path d="M13 14.5h8"/><path d="M18 14.5v3"/><path d="M21 14.5v2"/></svg>',
        'power' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v10"/><path d="M6.2 6.2a8 8 0 1 0 11.6 0"/></svg>',
        'rotate' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>',
        'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v6c0 5-3.5 8-7 9-3.5-1-7-4-7-9V6l7-3z"/></svg>',
        'warning' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.6 19a1 1 0 0 0 .86 1.5h17.08A1 1 0 0 0 21.4 19L12 3z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>',
        'open' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6"/><path d="M10 14 20 4"/><path d="M20 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5"/></svg>',
        'spark' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8L12 3z"/><path d="M19 16l.9 2.1L22 19l-2.1.9L19 22l-.9-2.1L16 19l2.1-.9L19 16z"/><path d="M5 15l.9 2.1L8 18l-2.1.9L5 21l-.9-2.1L2 18l2.1-.9L5 15z"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>',
    };
}

$currentUser = cw_current_user($pdo);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/users/index.php', PHP_URL_PATH) ?: '/admin/users/index.php';

$q = cw_users_idx_str('q');
$roleFilter = strtolower(cw_users_idx_str('role'));
$statusFilter = strtolower(cw_users_idx_str('status'));
$completenessFilter = strtolower(cw_users_idx_str('completeness'));
$expiringFilter = strtolower(cw_users_idx_str('expiring'));
$page = max(1, cw_users_idx_query_int('page', 1) ?? 1);
$perPage = 18;
$offset = ($page - 1) * $perPage;

$where = array();
$params = array();

if ($q !== '') {
    $where[] = "(u.name LIKE :q OR u.email LIKE :q OR u.username LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if (in_array($roleFilter, array('admin', 'supervisor', 'student'), true)) {
    $where[] = "u.role = :role";
    $params[':role'] = $roleFilter;
}

if (in_array($statusFilter, array('active', 'pending_activation', 'locked', 'retired'), true)) {
    $where[] = "u.status = :status";
    $params[':status'] = $statusFilter;
}

if ($completenessFilter === 'complete') {
    $where[] = "COALESCE(ups.is_profile_complete, 0) = 1";
} elseif ($completenessFilter === 'incomplete') {
    $where[] = "COALESCE(ups.is_profile_complete, 0) = 0";
}

if ($expiringFilter === 'soon') {
    $where[] = "u.account_valid_until IS NOT NULL AND u.account_valid_until >= CURDATE() AND u.account_valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($expiringFilter === 'expired') {
    $where[] = "u.account_valid_until IS NOT NULL AND u.account_valid_until < CURDATE()";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countSql = "
    SELECT COUNT(*)
    FROM users u
    LEFT JOIN user_profile_requirements_status ups ON ups.user_id = u.id
    {$whereSql}
";

$listSql = "
    SELECT
        u.id,
        u.uuid,
        u.name,
        u.first_name,
        u.last_name,
        u.email,
        u.username,
        u.role,
        u.status,
        u.account_valid_until,
        u.photo_path,
        u.last_login_at,
        COALESCE(ups.missing_count, 0) AS missing_count,
        COALESCE(ups.is_profile_complete, 0) AS is_profile_complete,
        up.cellphone
    FROM users u
    LEFT JOIN user_profile_requirements_status ups ON ups.user_id = u.id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    {$whereSql}
    ORDER BY
        CASE u.status
            WHEN 'pending_activation' THEN 0
            WHEN 'locked' THEN 1
            WHEN 'active' THEN 2
            WHEN 'retired' THEN 3
            ELSE 4
        END,
        COALESCE(ups.is_profile_complete, 0) ASC,
        u.name ASC
    LIMIT {$perPage} OFFSET {$offset}
";

$totalUsers = cw_users_idx_scalar($pdo, "SELECT COUNT(*) FROM users");
$pendingActivations = cw_users_idx_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'pending_activation'");
$incompleteProfiles = cw_users_idx_scalar($pdo, "SELECT COUNT(*) FROM users u LEFT JOIN user_profile_requirements_status ups ON ups.user_id = u.id WHERE COALESCE(ups.is_profile_complete, 0) = 0");
$expiringSoon = cw_users_idx_scalar($pdo, "SELECT COUNT(*) FROM users WHERE account_valid_until IS NOT NULL AND account_valid_until >= CURDATE() AND account_valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$retiredUsers = cw_users_idx_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'retired'");

$totalFiltered = cw_users_idx_scalar($pdo, $countSql, $params);
$users = cw_users_idx_rows($pdo, $listSql, $params);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));

$baseQuery = $_GET;
unset($baseQuery['page']);

if (function_exists('cw_header')) {
    cw_header('User Accounts');
}
?>
<style>
.user-accounts-page{
    display:flex;
    flex-direction:column;
    gap:22px;
}
.user-accounts-page .page-actions{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:18px;
}
.user-accounts-page .page-action{
    display:inline-flex;
    align-items:center;
    gap:10px;
    min-height:42px;
    padding:0 16px;
    border-radius:12px;
    text-decoration:none;
    border:1px solid rgba(255,255,255,0.10);
    background:rgba(255,255,255,0.08);
    color:#ffffff;
    font-size:14px;
    font-weight:620;
    transition:background .16s ease, transform .16s ease;
}
.user-accounts-page .page-action:hover{
    background:rgba(255,255,255,0.14);
    transform:translateY(-1px);
}
.user-accounts-page .page-action--ghost{
    background:rgba(255,255,255,0.04);
}
.user-accounts-page .svg-icon{
    width:18px;
    height:18px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:currentColor;
    flex:0 0 18px;
}
.user-accounts-page .svg-icon svg{
    width:18px;
    height:18px;
    stroke:currentColor;
    fill:none;
    stroke-width:1.9;
    stroke-linecap:round;
    stroke-linejoin:round;
}
.users-kpi-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:18px;
}
.user-kpi-card{
    padding:22px 22px 20px;
}
.user-kpi-label{
    display:flex;
    align-items:center;
    gap:10px;
    color:var(--text-muted);
    font-size:13px;
    font-weight:650;
}
.user-kpi-value{
    margin-top:12px;
    font-size:34px;
    line-height:1;
    font-weight:760;
    letter-spacing:-0.03em;
    color:var(--text-strong);
}
.user-kpi-note{
    margin-top:8px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.45;
}
.users-filter-card{
    padding:18px;
}
.users-filter-form{
    display:grid;
    grid-template-columns:minmax(240px,2fr) repeat(4,minmax(140px,1fr)) auto;
    gap:12px;
    align-items:end;
}
.users-filter-field{
    display:flex;
    flex-direction:column;
    gap:8px;
}
.users-filter-label{
    font-size:12px;
    font-weight:680;
    letter-spacing:.04em;
    text-transform:uppercase;
    color:var(--text-muted);
}
.users-filter-input,
.users-filter-select{
    width:100%;
    min-height:46px;
    border-radius:14px;
    border:1px solid rgba(15,23,42,0.08);
    background:#ffffff;
    color:var(--text-strong);
    padding:0 14px;
    font-size:14px;
    font-weight:560;
    outline:none;
}
.users-filter-input:focus,
.users-filter-select:focus{
    border-color:rgba(31,103,197,0.35);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
}
.users-filter-actions{
    display:flex;
    gap:10px;
    justify-content:flex-end;
}
.users-btn{
    min-height:46px;
    border:none;
    border-radius:14px;
    padding:0 16px;
    display:inline-flex;
    align-items:center;
    gap:10px;
    font-size:14px;
    font-weight:650;
    text-decoration:none;
    cursor:pointer;
    transition:transform .16s ease, box-shadow .16s ease, background .16s ease;
}
.users-btn:hover{
    transform:translateY(-1px);
}
.users-btn--primary{
    color:#ffffff;
    background:linear-gradient(180deg, #173459 0%, #102440 100%);
    box-shadow:0 10px 24px rgba(13, 29, 52, 0.18);
}
.users-btn--secondary{
    color:var(--text-strong);
    background:#ffffff;
    border:1px solid rgba(15,23,42,0.08);
}
.users-list{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:18px;
}
.user-card{
    padding:22px;
    display:flex;
    flex-direction:column;
    gap:18px;
}
.user-card-top{
    display:flex;
    gap:16px;
    align-items:flex-start;
}
.user-avatar{
    width:64px;
    height:64px;
    border-radius:18px;
    overflow:hidden;
    background:linear-gradient(180deg, #dfe8f4 0%, #cdd8e6 100%);
    color:#173459;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    font-weight:760;
    letter-spacing:-0.03em;
    flex:0 0 64px;
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.5);
}
.user-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
.user-card-meta{
    min-width:0;
    flex:1 1 auto;
}
.user-name-row{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
}
.user-name{
    margin:0;
    font-size:20px;
    line-height:1.08;
    font-weight:750;
    letter-spacing:-0.03em;
    color:var(--text-strong);
}
.user-identity{
    margin-top:6px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.45;
    display:flex;
    flex-wrap:wrap;
    gap:6px 10px;
}
.user-identity span{
    white-space:nowrap;
}
.user-card-badges{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.ui-badge{
    min-height:30px;
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:0 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    letter-spacing:.02em;
    border:1px solid transparent;
}
.ui-badge .svg-icon{
    width:14px;
    height:14px;
    flex:0 0 14px;
}
.ui-badge .svg-icon svg{
    width:14px;
    height:14px;
    stroke-width:2;
}
.ui-badge--ok{
    color:#196b43;
    background:#ecfbf3;
    border-color:#c8efd9;
}
.ui-badge--warn{
    color:#8d5b00;
    background:#fff8e8;
    border-color:#f1deb0;
}
.ui-badge--danger{
    color:#9c2f2f;
    background:#fff0f0;
    border-color:#f0c8c8;
}
.ui-badge--neutral{
    color:#5d6c84;
    background:#f3f6fa;
    border-color:#dde5ef;
}
.user-card-actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
}
.user-card-action{
    min-height:40px;
    display:inline-flex;
    align-items:center;
    gap:9px;
    padding:0 14px;
    border-radius:12px;
    text-decoration:none;
    font-size:13px;
    font-weight:680;
    border:1px solid rgba(15,23,42,0.08);
    color:var(--text-strong);
    background:#ffffff;
}
.user-card-action:hover{
    border-color:rgba(15,23,42,0.16);
    box-shadow:0 8px 16px rgba(15,23,42,0.05);
}
.user-card-action--primary{
    background:linear-gradient(180deg, #173459 0%, #102440 100%);
    color:#ffffff;
    border-color:transparent;
}
.user-card-action--warn{
    background:#fff8e8;
    color:#8d5b00;
    border-color:#f1deb0;
}
.user-card-action--danger{
    background:#fff0f0;
    color:#9c2f2f;
    border-color:#f0c8c8;
}
.user-card-footer{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding-top:12px;
    border-top:1px solid rgba(15,23,42,0.06);
}
.user-card-footer-meta{
    color:var(--text-muted);
    font-size:13px;
    line-height:1.45;
}
.user-card-footer-meta strong{
    color:var(--text-strong);
    font-weight:680;
}
.empty-state-card{
    padding:34px 28px;
}
.users-pagination{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:18px 20px;
}
.users-pagination-meta{
    color:var(--text-muted);
    font-size:13px;
    font-weight:560;
}
.users-pagination-actions{
    display:flex;
    gap:10px;
}
@media (max-width: 1380px){
    .users-kpi-grid{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
    .users-filter-form{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
    .users-list{
        grid-template-columns:1fr;
    }
}
@media (max-width: 980px){
    .users-kpi-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .users-filter-form{
        grid-template-columns:1fr 1fr;
    }
}
@media (max-width: 720px){
    .users-kpi-grid,
    .users-filter-form{
        grid-template-columns:1fr;
    }
    .user-name-row{
        flex-direction:column;
    }
    .users-pagination{
        flex-direction:column;
        align-items:flex-start;
    }
}
</style>

<div class="user-accounts-page">
    <section class="app-section-hero">
        <div class="hero-overline">Operations</div>
        <h1 style="margin:0; font-size:34px; line-height:1.02; letter-spacing:-0.04em;">User Accounts</h1>
        <p style="margin:14px 0 0 0; max-width:860px; color:rgba(255,255,255,0.82); font-size:15px; line-height:1.65;">
            Manage enrollment, profile completeness, lifecycle status, and account readiness from one operational workspace.
        </p>
        <div class="hero-meta">
            <span class="hero-chip"><?= cw_users_idx_icon('users') ?> <span>Total Users&nbsp;<?= (int)$totalUsers ?></span></span>
            <span class="hero-chip"><?= cw_users_idx_icon('warning') ?> <span>Pending Activation&nbsp;<?= (int)$pendingActivations ?></span></span>
            <span class="hero-chip"><?= cw_users_idx_icon('spark') ?> <span>Incomplete Profiles&nbsp;<?= (int)$incompleteProfiles ?></span></span>
        </div>
        <div class="page-actions">
            <a class="page-action" href="/admin/users/create.php"><?= cw_users_idx_icon('plus') ?><span>Add User</span></a>
            <a class="page-action page-action--ghost" href="/admin/users/index.php?status=pending_activation"><?= cw_users_idx_icon('mail') ?><span>Pending Activations</span></a>
            <a class="page-action page-action--ghost" href="/admin/users/index.php?completeness=incomplete"><?= cw_users_idx_icon('spark') ?><span>Incomplete Profiles</span></a>
            <a class="page-action page-action--ghost" href="/admin/users/index.php?expiring=soon"><?= cw_users_idx_icon('calendar') ?><span>Expiring Soon</span></a>
            <a class="page-action page-action--ghost" href="/admin/users/index.php?status=retired"><?= cw_users_idx_icon('power') ?><span>Retired Users</span></a>
        </div>
    </section>

    <section class="users-kpi-grid">
        <article class="card user-kpi-card">
            <div class="user-kpi-label"><?= cw_users_idx_icon('users') ?><span>Total Accounts</span></div>
            <div class="user-kpi-value"><?= (int)$totalUsers ?></div>
            <div class="user-kpi-note">All user records currently active in the canonical account root.</div>
        </article>
        <article class="card user-kpi-card">
            <div class="user-kpi-label"><?= cw_users_idx_icon('mail') ?><span>Pending Activation</span></div>
            <div class="user-kpi-value"><?= (int)$pendingActivations ?></div>
            <div class="user-kpi-note">Accounts created but not yet activated by their owner.</div>
        </article>
        <article class="card user-kpi-card">
            <div class="user-kpi-label"><?= cw_users_idx_icon('spark') ?><span>Incomplete Profiles</span></div>
            <div class="user-kpi-value"><?= (int)$incompleteProfiles ?></div>
            <div class="user-kpi-note">Users still missing required personal or contact information.</div>
        </article>
        <article class="card user-kpi-card">
            <div class="user-kpi-label"><?= cw_users_idx_icon('calendar') ?><span>Expiring in 30 Days</span></div>
            <div class="user-kpi-value"><?= (int)$expiringSoon ?></div>
            <div class="user-kpi-note">Accounts nearing the end of their current validity window.</div>
        </article>
        <article class="card user-kpi-card">
            <div class="user-kpi-label"><?= cw_users_idx_icon('power') ?><span>Retired Accounts</span></div>
            <div class="user-kpi-value"><?= (int)$retiredUsers ?></div>
            <div class="user-kpi-note">Archived users retained for historical and compliance continuity.</div>
        </article>
    </section>

    <section class="card users-filter-card">
        <form class="users-filter-form" method="get" action="/admin/users/index.php">
            <div class="users-filter-field">
                <label class="users-filter-label" for="users-filter-q">Search</label>
                <input class="users-filter-input" id="users-filter-q" type="text" name="q" value="<?= h($q) ?>" placeholder="Name, email, or username">
            </div>

            <div class="users-filter-field">
                <label class="users-filter-label" for="users-filter-role">Role</label>
                <select class="users-filter-select" id="users-filter-role" name="role">
                    <option value="">All Roles</option>
                    <option value="admin"<?= $roleFilter === 'admin' ? ' selected' : '' ?>>Admin</option>
                    <option value="supervisor"<?= $roleFilter === 'supervisor' ? ' selected' : '' ?>>Supervisor</option>
                    <option value="student"<?= $roleFilter === 'student' ? ' selected' : '' ?>>Student</option>
                </select>
            </div>

            <div class="users-filter-field">
                <label class="users-filter-label" for="users-filter-status">Status</label>
                <select class="users-filter-select" id="users-filter-status" name="status">
                    <option value="">All Statuses</option>
                    <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                    <option value="pending_activation"<?= $statusFilter === 'pending_activation' ? ' selected' : '' ?>>Pending Activation</option>
                    <option value="locked"<?= $statusFilter === 'locked' ? ' selected' : '' ?>>Locked</option>
                    <option value="retired"<?= $statusFilter === 'retired' ? ' selected' : '' ?>>Retired</option>
                </select>
            </div>

            <div class="users-filter-field">
                <label class="users-filter-label" for="users-filter-completeness">Completeness</label>
                <select class="users-filter-select" id="users-filter-completeness" name="completeness">
                    <option value="">All Profiles</option>
                    <option value="complete"<?= $completenessFilter === 'complete' ? ' selected' : '' ?>>Complete</option>
                    <option value="incomplete"<?= $completenessFilter === 'incomplete' ? ' selected' : '' ?>>Incomplete</option>
                </select>
            </div>

            <div class="users-filter-field">
                <label class="users-filter-label" for="users-filter-expiring">Validity Window</label>
                <select class="users-filter-select" id="users-filter-expiring" name="expiring">
                    <option value="">Any Validity</option>
                    <option value="soon"<?= $expiringFilter === 'soon' ? ' selected' : '' ?>>Expiring Soon</option>
                    <option value="expired"<?= $expiringFilter === 'expired' ? ' selected' : '' ?>>Expired</option>
                </select>
            </div>

            <div class="users-filter-actions">
                <button class="users-btn users-btn--primary" type="submit"><?= cw_users_idx_icon('filter') ?><span>Apply</span></button>
                <a class="users-btn users-btn--secondary" href="/admin/users/index.php"><?= cw_users_idx_icon('rotate') ?><span>Reset</span></a>
            </div>
        </form>
    </section>

    <?php if ($users): ?>
        <section class="users-list">
            <?php foreach ($users as $user): ?>
                <?php
                $role = (string)($user['role'] ?? '');
                $status = (string)($user['status'] ?? '');
                $missingCount = (int)($user['missing_count'] ?? 0);
                $isComplete = (int)($user['is_profile_complete'] ?? 0) === 1;
                $validUntil = (string)($user['account_valid_until'] ?? '');
                $daysUntil = cw_users_idx_days_until($validUntil);
                $expiryLabel = 'No Validity Date';
                $expiryState = 'healthy';

                if ($validUntil !== '') {
                    if ($daysUntil !== null && $daysUntil < 0) {
                        $expiryLabel = 'Expired ' . abs($daysUntil) . ' Day' . (abs($daysUntil) === 1 ? '' : 's') . ' Ago';
                        $expiryState = 'expired';
                    } elseif ($daysUntil !== null && $daysUntil <= 30) {
                        $expiryLabel = 'Expires in ' . $daysUntil . ' Day' . ($daysUntil === 1 ? '' : 's');
                        $expiryState = 'soon';
                    } else {
                        $expiryLabel = 'Valid Until ' . cw_users_idx_human_date($validUntil);
                        $expiryState = 'healthy';
                    }
                }

                $displayName = trim((string)($user['name'] ?? ''));
                if ($displayName === '') {
                    $displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
                }
                if ($displayName === '') {
                    $displayName = (string)($user['email'] ?? 'User');
                }

                $openHref = '/admin/users/edit.php?id=' . (int)$user['id'];
                $activationHref = $openHref . '#account';
                $resetHref = $openHref . '#security';
                $lifecycleHref = $openHref . '#account';
                ?>
                <article class="card user-card">
                    <div class="user-card-top">
                        <div class="user-avatar">
                            <?php if (!empty($user['photo_path'])): ?>
                                <img src="<?= h((string)$user['photo_path']) ?>" alt="<?= h($displayName) ?>">
                            <?php else: ?>
                                <?= h(cw_users_idx_initials($displayName)) ?>
                            <?php endif; ?>
                        </div>

                        <div class="user-card-meta">
                            <div class="user-name-row">
                                <div>
                                    <h2 class="user-name"><?= h($displayName) ?></h2>
                                    <div class="user-identity">
                                        <span><?= h((string)$user['email']) ?></span>
                                        <?php if (!empty($user['username'])): ?><span>• @<?= h((string)$user['username']) ?></span><?php endif; ?>
                                        <?php if (!empty($user['cellphone'])): ?><span>• <?= h((string)$user['cellphone']) ?></span><?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="user-card-badges" style="margin-top:14px;">
                                <span class="ui-badge ui-badge--neutral"><?= cw_users_idx_icon('users') ?><span><?= h(cw_users_idx_role_label($role)) ?></span></span>
                                <span class="<?= h(cw_users_idx_badge_class('status', $status)) ?>"><?= cw_users_idx_icon($status === 'locked' ? 'shield' : ($status === 'pending_activation' ? 'mail' : ($status === 'retired' ? 'power' : 'spark'))) ?><span><?= h(cw_users_idx_status_label($status)) ?></span></span>
                                <span class="<?= h(cw_users_idx_badge_class('completeness', $isComplete ? 'complete' : 'incomplete')) ?>"><?= cw_users_idx_icon($isComplete ? 'spark' : 'warning') ?><span><?= $isComplete ? 'Profile Complete' : ('Missing ' . $missingCount . ' Field' . ($missingCount === 1 ? '' : 's')) ?></span></span>
                                <span class="<?= h(cw_users_idx_badge_class('expiry', $expiryState)) ?>"><?= cw_users_idx_icon('calendar') ?><span><?= h($expiryLabel) ?></span></span>
                            </div>
                        </div>
                    </div>

                    <div class="user-card-actions">
                        <a class="user-card-action user-card-action--primary" href="<?= h($openHref) ?>"><?= cw_users_idx_icon('open') ?><span>Open</span></a>
                        <a class="user-card-action" href="<?= h($activationHref) ?>"><?= cw_users_idx_icon('mail') ?><span>Send Activation</span></a>
                        <a class="user-card-action" href="<?= h($resetHref) ?>"><?= cw_users_idx_icon('key') ?><span>Send Reset</span></a>
                        <?php if ($status === 'retired'): ?>
                            <a class="user-card-action user-card-action--warn" href="<?= h($lifecycleHref) ?>"><?= cw_users_idx_icon('rotate') ?><span>Reactivate</span></a>
                        <?php else: ?>
                            <a class="user-card-action user-card-action--danger" href="<?= h($lifecycleHref) ?>"><?= cw_users_idx_icon('power') ?><span>Retire</span></a>
                        <?php endif; ?>
                    </div>

                    <div class="user-card-footer">
                        <div class="user-card-footer-meta">
                            <strong>Last Login:</strong>
                            <?= h($user['last_login_at'] ? cw_users_idx_human_date((string)$user['last_login_at']) : 'No login recorded') ?>
                        </div>
                        <div class="user-card-footer-meta">
                            <strong>Account ID:</strong>
                            #<?= (int)$user['id'] ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="card users-pagination">
            <div class="users-pagination-meta">
                Showing <?= (int)(($offset + 1)) ?>–<?= (int)min($offset + $perPage, $totalFiltered) ?> of <?= (int)$totalFiltered ?> matching accounts.
            </div>
            <div class="users-pagination-actions">
                <?php if ($page > 1): ?>
                    <?php $prevQuery = $baseQuery; $prevQuery['page'] = $page - 1; ?>
                    <a class="users-btn users-btn--secondary" href="/admin/users/index.php?<?= h(http_build_query($prevQuery)) ?>"><?= cw_users_idx_icon('rotate') ?><span>Previous</span></a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <?php $nextQuery = $baseQuery; $nextQuery['page'] = $page + 1; ?>
                    <a class="users-btn users-btn--primary" href="/admin/users/index.php?<?= h(http_build_query($nextQuery)) ?>"><?= cw_users_idx_icon('open') ?><span>Next</span></a>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="card empty-state empty-state-card">
            <h3 style="margin:0 0 8px 0; font-size:20px; line-height:1.1; letter-spacing:-0.02em; color:var(--text-strong);">No user accounts match the current filters.</h3>
            <p style="margin:0 0 18px 0; max-width:760px; color:var(--text-muted); font-size:14px; line-height:1.65;">
                Adjust the role, status, completeness, or validity filters to widen the operational view, or create a new account to begin onboarding.
            </p>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a class="users-btn users-btn--primary" href="/admin/users/create.php"><?= cw_users_idx_icon('plus') ?><span>Add User</span></a>
                <a class="users-btn users-btn--secondary" href="/admin/users/index.php"><?= cw_users_idx_icon('rotate') ?><span>Reset Filters</span></a>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php
if (function_exists('cw_footer')) {
    cw_footer();
}
?>