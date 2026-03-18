<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/auth.php';

cw_require_admin();

$currentUser = cw_current_user($pdo);
$currentPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/admin/users/index.php');

/**
 * ------------------------------------------------------------
 * Filters
 * ------------------------------------------------------------
 */
$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = strtolower(trim((string)($_GET['role'] ?? '')));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$completenessFilter = strtolower(trim((string)($_GET['completeness'] ?? '')));
$validityFilter = strtolower(trim((string)($_GET['validity'] ?? '')));
$securityFilter = strtolower(trim((string)($_GET['security'] ?? '')));

/**
 * ------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------
 */
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cw_users_build_qs(array $overrides = array()): string
{
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }

    $query = http_build_query($params);
    return $query !== '' ? ('?' . $query) : '';
}

function cw_users_human_date(?string $date): string
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

function cw_users_human_datetime(?string $dateTime): string
{
    if (!$dateTime) {
        return '—';
    }

    $ts = strtotime($dateTime);
    if (!$ts) {
        return '—';
    }

    return date('M j, Y · H:i', $ts);
}

function cw_users_role_label(string $role): string
{
    $role = strtolower(trim($role));
    return match ($role) {
        'admin' => 'Admin',
        'supervisor' => 'Supervisor',
        'instructor' => 'Instructor',
        'chief_instructor' => 'Chief Instructor',
        'student' => 'Student',
        default => ucfirst($role),
    };
}

function cw_users_status_label(string $status): string
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

function cw_users_status_class(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'active' => 'ua-badge ua-badge--ok',
        'pending_activation' => 'ua-badge ua-badge--warn',
        'locked' => 'ua-badge ua-badge--danger',
        'retired' => 'ua-badge ua-badge--muted',
        default => 'ua-badge ua-badge--neutral',
    };
}

function cw_users_role_class(string $role): string
{
    $role = strtolower(trim($role));
    return match ($role) {
        'admin' => 'ua-badge ua-badge--accent',
        'supervisor', 'instructor', 'chief_instructor' => 'ua-badge ua-badge--sky',
        'student' => 'ua-badge ua-badge--neutral',
        default => 'ua-badge ua-badge--neutral',
    };
}

function cw_users_completeness_class(int $missingCount): string
{
    return $missingCount > 0
        ? 'ua-badge ua-badge--warn'
        : 'ua-badge ua-badge--ok';
}

function cw_users_validity_class(?string $validUntil): string
{
    if (!$validUntil) {
        return 'ua-badge ua-badge--neutral';
    }

    $today = strtotime(date('Y-m-d'));
    $target = strtotime($validUntil);
    if (!$target) {
        return 'ua-badge ua-badge--neutral';
    }

    $days = (int)floor(($target - $today) / 86400);

    if ($days < 0) {
        return 'ua-badge ua-badge--danger';
    }
    if ($days <= 30) {
        return 'ua-badge ua-badge--warn';
    }

    return 'ua-badge ua-badge--ok';
}

function cw_users_validity_label(?string $validUntil): string
{
    if (!$validUntil) {
        return 'No validity set';
    }

    $today = strtotime(date('Y-m-d'));
    $target = strtotime($validUntil);
    if (!$target) {
        return 'No validity set';
    }

    $days = (int)floor(($target - $today) / 86400);

    if ($days < 0) {
        return 'Expired';
    }
    if ($days === 0) {
        return 'Expires Today';
    }
    if ($days <= 30) {
        return 'Expires in ' . $days . ' day' . ($days === 1 ? '' : 's');
    }

    return 'Valid';
}

function cw_users_security_badges(array $row): array
{
    $badges = array();

    if ((int)($row['must_change_password'] ?? 0) === 1) {
        $badges[] = array(
            'class' => 'ua-badge ua-badge--warn',
            'label' => 'Password Update Required',
        );
    }

    if (strtolower((string)($row['status'] ?? '')) === 'locked') {
        $badges[] = array(
            'class' => 'ua-badge ua-badge--danger',
            'label' => 'Locked',
        );
    }

    if (strtolower((string)($row['status'] ?? '')) === 'pending_activation') {
        $badges[] = array(
            'class' => 'ua-badge ua-badge--warn',
            'label' => 'Activation Pending',
        );
    }

    return $badges;
}

function cw_users_svg(string $name): string
{
    switch ($name) {
        case 'search':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15a7.5 7.5 0 0 1 0 15Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'plus':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'open':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M10 14L19 5M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'mail':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-9Zm0 .5l8 5.5l8-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'shield':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.4-2.7 8.4-7 10c-4.3-1.6-7-5.6-7-10V6l7-3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'archive':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M6 7l1 11h10l1-11M10 11h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 4h14v3H5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';

        case 'users':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1.2a3.8 3.8 0 0 0-3.8-3.8H8.8A3.8 3.8 0 0 0 5 17.8V19M15.5 8.5a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm-8 0a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm12 10.5v-1a3 3 0 0 0-2.2-2.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'check':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'warning':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l8 14H4L12 4Zm0 5.2v4.8m0 3h.01" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'clock':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-18 0a9 9 0 0 1 18 0Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        case 'filter':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        default:
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
    }
}

/**
 * ------------------------------------------------------------
 * Summary counts
 * ------------------------------------------------------------
 */
function cw_users_scalar(PDO $pdo, string $sql, array $params = array()): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return ($value !== false && $value !== null) ? (int)$value : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

$stats = array(
    'total' => cw_users_scalar($pdo, "SELECT COUNT(*) FROM users"),
    'pending' => cw_users_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'pending_activation'"),
    'incomplete' => cw_users_scalar($pdo, "SELECT COUNT(*) FROM user_profile_requirements_status WHERE missing_count > 0"),
    'expiring_soon' => cw_users_scalar($pdo, "SELECT COUNT(*) FROM users WHERE account_valid_until IS NOT NULL AND account_valid_until >= CURDATE() AND account_valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"),
    'retired' => cw_users_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'retired'"),
    'must_change_password' => cw_users_scalar($pdo, "SELECT COUNT(*) FROM users WHERE must_change_password = 1"),
);

/**
 * ------------------------------------------------------------
 * Card list query
 * ------------------------------------------------------------
 */
$where = array();
$params = array();

if ($q !== '') {
    $where[] = "(u.name LIKE :q OR u.email LIKE :q OR u.username LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($roleFilter !== '' && in_array($roleFilter, array('admin', 'student', 'supervisor', 'instructor', 'chief_instructor'), true)) {
    $where[] = "u.role = :role";
    $params[':role'] = $roleFilter;
}

if ($statusFilter !== '' && in_array($statusFilter, array('active', 'pending_activation', 'locked', 'retired'), true)) {
    $where[] = "u.status = :status";
    $params[':status'] = $statusFilter;
}

if ($completenessFilter === 'complete') {
    $where[] = "COALESCE(req.missing_count, 0) = 0";
} elseif ($completenessFilter === 'incomplete') {
    $where[] = "COALESCE(req.missing_count, 0) > 0";
}

if ($validityFilter === 'expiring_soon') {
    $where[] = "u.account_valid_until IS NOT NULL AND u.account_valid_until >= CURDATE() AND u.account_valid_until <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($validityFilter === 'expired') {
    $where[] = "u.account_valid_until IS NOT NULL AND u.account_valid_until < CURDATE()";
} elseif ($validityFilter === 'unset') {
    $where[] = "u.account_valid_until IS NULL";
}

if ($securityFilter === 'password_update') {
    $where[] = "u.must_change_password = 1";
} elseif ($securityFilter === 'locked') {
    $where[] = "u.status = 'locked'";
}

$sql = "
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
        u.must_change_password,
        u.last_login_at,
        COALESCE(req.missing_count, 0) AS missing_count,
        req.is_profile_complete,
        req.last_evaluated_at
    FROM users u
    LEFT JOIN user_profile_requirements_status req
        ON req.user_id = u.id
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    ORDER BY
        CASE u.status
            WHEN 'pending_activation' THEN 0
            WHEN 'active' THEN 1
            WHEN 'locked' THEN 2
            WHEN 'retired' THEN 3
            ELSE 4
        END,
        CASE
            WHEN COALESCE(req.missing_count, 0) > 0 THEN 0
            ELSE 1
        END,
        u.name ASC,
        u.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows)) {
    $rows = array();
}

cw_header('User Accounts');
?>

<style>
.user-accounts-page{
    display:block;
}

.user-accounts-page .app-section-hero{
    margin-bottom:20px;
}

.ua-hero-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:24px;
}

.ua-hero-copy{
    min-width:0;
}

.ua-hero-title{
    margin:0;
    font-size:34px;
    line-height:1.02;
    letter-spacing:-0.04em;
    font-weight:760;
    color:#fff;
}

.ua-hero-text{
    max-width:820px;
    margin:14px 0 0 0;
    color:rgba(255,255,255,0.82);
    font-size:15px;
    line-height:1.65;
}

.ua-hero-actions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    justify-content:flex-end;
}

.ua-action{
    height:40px;
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding:0 16px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,0.12);
    background:rgba(255,255,255,0.08);
    color:#fff;
    text-decoration:none;
    font-size:13px;
    font-weight:650;
    letter-spacing:.01em;
    transition:background .16s ease, transform .16s ease, border-color .16s ease;
}

.ua-action:hover{
    background:rgba(255,255,255,0.13);
    transform:translateY(-1px);
}

.ua-action svg{
    width:16px;
    height:16px;
    flex:0 0 16px;
}

.ua-hero-stats{
    display:grid;
    grid-template-columns:repeat(6, minmax(0, 1fr));
    gap:14px;
    margin-top:22px;
}

.ua-stat-chip{
    min-height:88px;
    padding:16px 18px;
    border-radius:18px;
    background:rgba(255,255,255,0.08);
    border:1px solid rgba(255,255,255,0.09);
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.035);
}

.ua-stat-label{
    color:rgba(255,255,255,0.68);
    font-size:11px;
    line-height:1.15;
    letter-spacing:.12em;
    text-transform:uppercase;
    font-weight:680;
}

.ua-stat-value{
    margin-top:10px;
    color:#fff;
    font-size:31px;
    line-height:1;
    font-weight:760;
    letter-spacing:-0.04em;
}

.ua-toolbar-card{
    padding:18px;
}

.ua-toolbar-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:14px;
}

.ua-toolbar-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:17px;
    font-weight:720;
    color:var(--text-strong);
    letter-spacing:-0.02em;
}

.ua-toolbar-title-icon{
    width:18px;
    height:18px;
    color:var(--text-muted);
}

.ua-toolbar-meta{
    color:var(--text-muted);
    font-size:13px;
    font-weight:560;
}

.ua-filters{
    display:grid;
    grid-template-columns:2fr repeat(5, minmax(0, 1fr));
    gap:12px;
}

.ua-field{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.ua-field-label{
    font-size:12px;
    font-weight:670;
    letter-spacing:.02em;
    color:var(--text-muted);
}

.ua-input-wrap{
    position:relative;
}

.ua-input-icon{
    position:absolute;
    left:12px;
    top:50%;
    transform:translateY(-50%);
    width:16px;
    height:16px;
    color:#8a97ab;
    pointer-events:none;
}

.ua-input,
.ua-select{
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
}

.ua-input{
    padding:0 14px 0 40px;
}

.ua-select{
    padding:0 14px;
}

.ua-input:focus,
.ua-select:focus{
    border-color:rgba(82, 133, 212, 0.45);
    box-shadow:0 0 0 4px rgba(110,174,252,0.12);
}

.ua-filter-actions{
    display:flex;
    align-items:flex-end;
    gap:10px;
}

.ua-filter-btn{
    height:44px;
    padding:0 16px;
    border:none;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    background:linear-gradient(180deg, #17345d 0%, #102440 100%);
    color:#fff;
    text-decoration:none;
    font-size:14px;
    font-weight:680;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(16,36,64,0.14);
}

.ua-filter-btn:hover{
    transform:translateY(-1px);
}

.ua-filter-btn--ghost{
    background:#fff;
    color:var(--text-strong);
    border:1px solid rgba(15,23,42,0.08);
    box-shadow:none;
}

.ua-filter-btn svg{
    width:16px;
    height:16px;
    flex:0 0 16px;
}

.ua-list-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin:24px 0 14px 0;
}

.ua-list-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:18px;
    font-weight:730;
    letter-spacing:-0.02em;
    color:var(--text-strong);
}

.ua-list-title-icon{
    width:18px;
    height:18px;
    color:var(--text-muted);
}

.ua-list-count{
    color:var(--text-muted);
    font-size:13px;
    font-weight:600;
}

.ua-card-list{
    display:grid;
    grid-template-columns:1fr;
    gap:16px;
}

.ua-user-card{
    padding:22px;
}

.ua-user-card-inner{
    display:grid;
    grid-template-columns:minmax(0, 1.7fr) minmax(340px, 1fr);
    gap:18px;
    align-items:flex-start;
}

.ua-user-main{
    display:flex;
    gap:16px;
    min-width:0;
}

.ua-avatar{
    width:72px;
    height:72px;
    border-radius:20px;
    overflow:hidden;
    flex:0 0 72px;
    background:linear-gradient(180deg, #e8eef7 0%, #dfe7f2 100%);
    border:1px solid rgba(15,23,42,0.07);
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.45);
}

.ua-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.ua-avatar-fallback{
    width:30px;
    height:30px;
    color:#7b8aa0;
}

.ua-main-copy{
    min-width:0;
}

.ua-name-row{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:10px;
}

.ua-name{
    margin:0;
    font-size:24px;
    line-height:1.08;
    letter-spacing:-0.03em;
    font-weight:760;
    color:var(--text-strong);
}

.ua-meta-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:10px 16px;
    margin-top:14px;
}

.ua-meta-block{
    min-width:0;
}

.ua-meta-label{
    font-size:11px;
    line-height:1.15;
    text-transform:uppercase;
    letter-spacing:.12em;
    color:#8a97ab;
    font-weight:700;
}

.ua-meta-value{
    margin-top:6px;
    color:var(--text-strong);
    font-size:14px;
    font-weight:630;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.ua-card-side{
    display:flex;
    flex-direction:column;
    gap:12px;
}

.ua-badge-grid{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    justify-content:flex-end;
}

.ua-badge{
    min-height:34px;
    padding:0 13px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    border:1px solid rgba(15,23,42,0.08);
    background:#f8fafc;
    color:#324155;
    font-size:12px;
    font-weight:700;
    letter-spacing:.02em;
    white-space:nowrap;
}

.ua-badge--ok{
    background:rgba(32, 135, 90, 0.10);
    color:#1f7a54;
    border-color:rgba(32, 135, 90, 0.18);
}

.ua-badge--warn{
    background:rgba(196, 118, 11, 0.10);
    color:#a66508;
    border-color:rgba(196, 118, 11, 0.18);
}

.ua-badge--danger{
    background:rgba(185, 54, 54, 0.10);
    color:#ac2f2f;
    border-color:rgba(185, 54, 54, 0.18);
}

.ua-badge--muted{
    background:rgba(15, 23, 42, 0.06);
    color:#637287;
    border-color:rgba(15, 23, 42, 0.08);
}

.ua-badge--accent{
    background:rgba(32, 84, 176, 0.10);
    color:#2557b3;
    border-color:rgba(32, 84, 176, 0.18);
}

.ua-badge--sky{
    background:rgba(48, 124, 183, 0.10);
    color:#246ea9;
    border-color:rgba(48, 124, 183, 0.18);
}

.ua-badge--neutral{
    background:rgba(86, 112, 153, 0.10);
    color:#405a82;
    border-color:rgba(86, 112, 153, 0.16);
}

.ua-card-actions{
    display:flex;
    justify-content:flex-end;
    flex-wrap:wrap;
    gap:10px;
}

.ua-card-action{
    min-height:40px;
    padding:0 14px;
    display:inline-flex;
    align-items:center;
    gap:9px;
    border-radius:12px;
    text-decoration:none;
    color:var(--text-strong);
    font-size:13px;
    font-weight:680;
    border:1px solid rgba(15,23,42,0.08);
    background:#fff;
    transition:transform .16s ease, border-color .16s ease, background .16s ease;
}

.ua-card-action:hover{
    transform:translateY(-1px);
    border-color:rgba(16,36,64,0.16);
    background:#f9fbfe;
}

.ua-card-action--primary{
    background:linear-gradient(180deg, #17345d 0%, #102440 100%);
    color:#fff;
    border-color:transparent;
    box-shadow:0 10px 22px rgba(16,36,64,0.13);
}

.ua-card-action svg{
    width:15px;
    height:15px;
    flex:0 0 15px;
}

.ua-card-foot{
    margin-top:16px;
    padding-top:16px;
    border-top:1px solid rgba(15,23,42,0.06);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.ua-foot-note{
    color:var(--text-muted);
    font-size:13px;
    font-weight:560;
}

.ua-empty{
    padding:34px 28px;
}

.ua-empty-inner{
    display:flex;
    align-items:flex-start;
    gap:14px;
}

.ua-empty-icon{
    width:22px;
    height:22px;
    color:#8a97ab;
    flex:0 0 22px;
}

.ua-empty-title{
    margin:0;
    font-size:18px;
    font-weight:740;
    letter-spacing:-0.02em;
    color:var(--text-strong);
}

.ua-empty-text{
    margin:8px 0 0 0;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.65;
    max-width:700px;
}

@media (max-width: 1300px){
    .ua-hero-stats{
        grid-template-columns:repeat(3, minmax(0, 1fr));
    }

    .ua-filters{
        grid-template-columns:repeat(3, minmax(0, 1fr));
    }

    .ua-user-card-inner{
        grid-template-columns:1fr;
    }

    .ua-badge-grid,
    .ua-card-actions{
        justify-content:flex-start;
    }
}

@media (max-width: 900px){
    .ua-hero-head{
        flex-direction:column;
        align-items:flex-start;
    }

    .ua-hero-actions{
        justify-content:flex-start;
    }

    .ua-hero-stats{
        grid-template-columns:repeat(2, minmax(0, 1fr));
    }

    .ua-filters{
        grid-template-columns:1fr;
    }

    .ua-filter-actions{
        align-items:stretch;
    }

    .ua-filter-btn,
    .ua-filter-btn--ghost{
        flex:1 1 auto;
        justify-content:center;
    }

    .ua-meta-grid{
        grid-template-columns:1fr;
    }
}

@media (max-width: 640px){
    .ua-hero-stats{
        grid-template-columns:1fr;
    }

    .ua-name{
        font-size:21px;
    }

    .ua-avatar{
        width:62px;
        height:62px;
        flex-basis:62px;
    }
}
</style>

<div class="user-accounts-page">
    <section class="app-section-hero">
        <div class="hero-overline">Operations</div>

        <div class="ua-hero-head">
            <div class="ua-hero-copy">
                <h2 class="ua-hero-title">User Accounts</h2>
                <p class="ua-hero-text">
                    Manage enrollment readiness, account status, profile completeness, and operational account visibility from one premium control surface.
                </p>
            </div>

            <div class="ua-hero-actions">
                <a class="ua-action" href="/admin/users/create.php">
                    <?php echo cw_users_svg('plus'); ?>
                    <span>Add User</span>
                </a>

                <a class="ua-action" href="/admin/users/index.php?status=pending_activation">
                    <?php echo cw_users_svg('mail'); ?>
                    <span>Pending Activations</span>
                </a>

                <a class="ua-action" href="/admin/users/index.php?completeness=incomplete">
                    <?php echo cw_users_svg('warning'); ?>
                    <span>Incomplete Profiles</span>
                </a>
            </div>
        </div>

        <div class="ua-hero-stats">
            <div class="ua-stat-chip">
                <div class="ua-stat-label">Total Accounts</div>
                <div class="ua-stat-value"><?php echo (int)$stats['total']; ?></div>
            </div>

            <div class="ua-stat-chip">
                <div class="ua-stat-label">Pending Activation</div>
                <div class="ua-stat-value"><?php echo (int)$stats['pending']; ?></div>
            </div>

            <div class="ua-stat-chip">
                <div class="ua-stat-label">Incomplete Profiles</div>
                <div class="ua-stat-value"><?php echo (int)$stats['incomplete']; ?></div>
            </div>

            <div class="ua-stat-chip">
                <div class="ua-stat-label">Expiring Soon</div>
                <div class="ua-stat-value"><?php echo (int)$stats['expiring_soon']; ?></div>
            </div>

            <div class="ua-stat-chip">
                <div class="ua-stat-label">Retired</div>
                <div class="ua-stat-value"><?php echo (int)$stats['retired']; ?></div>
            </div>

            <div class="ua-stat-chip">
                <div class="ua-stat-label">Password Updates</div>
                <div class="ua-stat-value"><?php echo (int)$stats['must_change_password']; ?></div>
            </div>
        </div>
    </section>

    <section class="card ua-toolbar-card">
        <div class="ua-toolbar-head">
            <div class="ua-toolbar-title">
                <span class="ua-toolbar-title-icon"><?php echo cw_users_svg('filter'); ?></span>
                <span>Filter and search</span>
            </div>
            <div class="ua-toolbar-meta">Refine the roster by role, lifecycle status, completeness, validity, and readiness.</div>
        </div>

        <form method="get" action="/admin/users/index.php">
            <div class="ua-filters">
                <div class="ua-field">
                    <label class="ua-field-label" for="ua-q">Search</label>
                    <div class="ua-input-wrap">
                        <span class="ua-input-icon"><?php echo cw_users_svg('search'); ?></span>
                        <input
                            id="ua-q"
                            class="ua-input"
                            type="text"
                            name="q"
                            value="<?php echo h($q); ?>"
                            placeholder="Name, email, or username">
                    </div>
                </div>

                <div class="ua-field">
                    <label class="ua-field-label" for="ua-role">Role</label>
                    <select class="ua-select" id="ua-role" name="role">
                        <option value="">All roles</option>
                        <option value="admin"<?php echo $roleFilter === 'admin' ? ' selected' : ''; ?>>Admin</option>
                        <option value="supervisor"<?php echo $roleFilter === 'supervisor' ? ' selected' : ''; ?>>Supervisor</option>
                        <option value="student"<?php echo $roleFilter === 'student' ? ' selected' : ''; ?>>Student</option>
                    </select>
                </div>

                <div class="ua-field">
                    <label class="ua-field-label" for="ua-status">Status</label>
                    <select class="ua-select" id="ua-status" name="status">
                        <option value="">All statuses</option>
                        <option value="active"<?php echo $statusFilter === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="pending_activation"<?php echo $statusFilter === 'pending_activation' ? ' selected' : ''; ?>>Pending Activation</option>
                        <option value="locked"<?php echo $statusFilter === 'locked' ? ' selected' : ''; ?>>Locked</option>
                        <option value="retired"<?php echo $statusFilter === 'retired' ? ' selected' : ''; ?>>Retired</option>
                    </select>
                </div>

                <div class="ua-field">
                    <label class="ua-field-label" for="ua-completeness">Completeness</label>
                    <select class="ua-select" id="ua-completeness" name="completeness">
                        <option value="">All profiles</option>
                        <option value="complete"<?php echo $completenessFilter === 'complete' ? ' selected' : ''; ?>>Complete</option>
                        <option value="incomplete"<?php echo $completenessFilter === 'incomplete' ? ' selected' : ''; ?>>Incomplete</option>
                    </select>
                </div>

                <div class="ua-field">
                    <label class="ua-field-label" for="ua-validity">Validity</label>
                    <select class="ua-select" id="ua-validity" name="validity">
                        <option value="">Any validity</option>
                        <option value="expiring_soon"<?php echo $validityFilter === 'expiring_soon' ? ' selected' : ''; ?>>Expiring Soon</option>
                        <option value="expired"<?php echo $validityFilter === 'expired' ? ' selected' : ''; ?>>Expired</option>
                        <option value="unset"<?php echo $validityFilter === 'unset' ? ' selected' : ''; ?>>Not Set</option>
                    </select>
                </div>

                <div class="ua-field">
                    <label class="ua-field-label" for="ua-security">Security</label>
                    <select class="ua-select" id="ua-security" name="security">
                        <option value="">Any security state</option>
                        <option value="password_update"<?php echo $securityFilter === 'password_update' ? ' selected' : ''; ?>>Password Update Required</option>
                        <option value="locked"<?php echo $securityFilter === 'locked' ? ' selected' : ''; ?>>Locked</option>
                    </select>
                </div>
            </div>

            <div class="ua-filter-actions" style="margin-top:14px;">
                <button class="ua-filter-btn" type="submit">
                    <?php echo cw_users_svg('search'); ?>
                    <span>Apply Filters</span>
                </button>

                <a class="ua-filter-btn ua-filter-btn--ghost" href="/admin/users/index.php">
                    <?php echo cw_users_svg('check'); ?>
                    <span>Clear</span>
                </a>
            </div>
        </form>
    </section>

    <div class="ua-list-head">
        <div class="ua-list-title">
            <span class="ua-list-title-icon"><?php echo cw_users_svg('users'); ?></span>
            <span>User roster</span>
        </div>
        <div class="ua-list-count"><?php echo count($rows); ?> result<?php echo count($rows) === 1 ? '' : 's'; ?></div>
    </div>

    <?php if (!$rows): ?>
        <section class="card ua-empty">
            <div class="ua-empty-inner">
                <div class="ua-empty-icon"><?php echo cw_users_svg('warning'); ?></div>
                <div>
                    <h3 class="ua-empty-title">No user accounts matched the current filters</h3>
                    <p class="ua-empty-text">
                        Adjust the filters, clear the search, or add a new account to begin managing enrollment and readiness from this workspace.
                    </p>
                </div>
            </div>
        </section>
    <?php else: ?>
        <div class="ua-card-list">
            <?php foreach ($rows as $row): ?>
                <?php
                    $userId = (int)$row['id'];
                    $displayName = trim((string)$row['name']) !== '' ? (string)$row['name'] : trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                    if ($displayName === '') {
                        $displayName = 'User #' . $userId;
                    }

                    $photoPath = trim((string)($row['photo_path'] ?? ''));
                    $missingCount = (int)($row['missing_count'] ?? 0);
                    $securityBadges = cw_users_security_badges($row);
                    $validityLabel = cw_users_validity_label((string)($row['account_valid_until'] ?? ''));
                ?>
                <section class="card ua-user-card">
                    <div class="ua-user-card-inner">
                        <div class="ua-user-main">
                            <div class="ua-avatar">
                                <?php if ($photoPath !== ''): ?>
                                    <img src="<?php echo h($photoPath); ?>" alt="<?php echo h($displayName); ?>">
                                <?php else: ?>
                                    <span class="ua-avatar-fallback"><?php echo cw_users_svg('users'); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="ua-main-copy">
                                <div class="ua-name-row">
                                    <h3 class="ua-name"><?php echo h($displayName); ?></h3>
                                </div>

                                <div class="ua-meta-grid">
                                    <div class="ua-meta-block">
                                        <div class="ua-meta-label">Email</div>
                                        <div class="ua-meta-value"><?php echo h((string)$row['email']); ?></div>
                                    </div>

                                    <div class="ua-meta-block">
                                        <div class="ua-meta-label">Username</div>
                                        <div class="ua-meta-value"><?php echo h((string)($row['username'] !== null ? $row['username'] : '—')); ?></div>
                                    </div>

                                    <div class="ua-meta-block">
                                        <div class="ua-meta-label">Last Login</div>
                                        <div class="ua-meta-value"><?php echo h(cw_users_human_datetime((string)($row['last_login_at'] ?? ''))); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ua-card-side">
                            <div class="ua-badge-grid">
                                <span class="<?php echo cw_users_role_class((string)$row['role']); ?>">
                                    <?php echo h(cw_users_role_label((string)$row['role'])); ?>
                                </span>

                                <span class="<?php echo cw_users_status_class((string)$row['status']); ?>">
                                    <?php echo h(cw_users_status_label((string)$row['status'])); ?>
                                </span>

                                <span class="<?php echo cw_users_completeness_class($missingCount); ?>">
                                    <?php echo $missingCount > 0 ? ('Missing ' . $missingCount) : 'Profile Complete'; ?>
                                </span>

                                <span class="<?php echo cw_users_validity_class((string)($row['account_valid_until'] ?? '')); ?>">
                                    <?php echo h($validityLabel); ?>
                                </span>

                                <?php foreach ($securityBadges as $badge): ?>
                                    <span class="<?php echo h((string)$badge['class']); ?>">
                                        <?php echo h((string)$badge['label']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <div class="ua-card-actions">
                                <a class="ua-card-action ua-card-action--primary" href="/admin/users/edit.php?id=<?php echo $userId; ?>">
                                    <?php echo cw_users_svg('open'); ?>
                                    <span>Open Workspace</span>
                                </a>

                                <a class="ua-card-action" href="/admin/users/edit.php?id=<?php echo $userId; ?>#account">
                                    <?php echo cw_users_svg('mail'); ?>
                                    <span>Activation</span>
                                </a>

                                <a class="ua-card-action" href="/admin/users/edit.php?id=<?php echo $userId; ?>#security">
                                    <?php echo cw_users_svg('shield'); ?>
                                    <span>Security</span>
                                </a>

                                <a class="ua-card-action" href="/admin/users/edit.php?id=<?php echo $userId; ?>#status">
                                    <?php echo cw_users_svg('archive'); ?>
                                    <span>Status</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="ua-card-foot">
                        <div class="ua-foot-note">
                            Account valid until: <strong><?php echo h(cw_users_human_date((string)($row['account_valid_until'] ?? ''))); ?></strong>
                        </div>

                        <div class="ua-foot-note">
                            Completeness last evaluated: <strong><?php echo h(cw_users_human_datetime((string)($row['last_evaluated_at'] ?? ''))); ?></strong>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>