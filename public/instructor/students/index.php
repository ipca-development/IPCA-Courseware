<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

cw_require_login();

$currentUser = cw_current_user($pdo);
$currentRole = strtolower(trim((string)($currentUser['role'] ?? '')));

if (!in_array($currentRole, array('instructor', 'supervisor', 'chief_instructor'), true)) {
    http_response_code(403);
    exit('Forbidden');
}

$q = trim((string)($_GET['q'] ?? ''));
$cohortId = (int)($_GET['cohort_id'] ?? 0);

$cohortRows = array();
$cohortStmt = $pdo->query("
    SELECT
        c.id,
        c.name,
        c.start_date
    FROM cohorts c
    ORDER BY
        c.start_date DESC,
        c.name ASC,
        c.id ASC
");
if ($cohortStmt) {
    $cohortRows = $cohortStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($cohortRows)) {
        $cohortRows = array();
    }
}

$sql = "
    SELECT
        u.id,
        u.uuid,
        u.name,
        u.first_name,
        u.last_name,
        u.email,
        u.photo_path,
        u.status,
        COALESCE(req.missing_count, 0) AS missing_count
    FROM users u
    LEFT JOIN user_profile_requirements_status req
        ON req.user_id = u.id
";

$params = array();

if ($cohortId > 0) {
    $sql .= "
        INNER JOIN cohort_students cs_filter
            ON cs_filter.user_id = u.id
           AND cs_filter.cohort_id = :cohort_id
           AND cs_filter.status = 'active'
           AND cs_filter.exited_at IS NULL
    ";
    $params[':cohort_id'] = $cohortId;
}

$sql .= "
    WHERE u.role = 'student'
";

if ($q !== '') {
    $sql .= "
        AND (
            u.name LIKE :q
            OR u.first_name LIKE :q
            OR u.last_name LIKE :q
            OR u.email LIKE :q
        )
    ";
    $params[':q'] = '%' . $q . '%';
}

$sql .= "
    GROUP BY
        u.id,
        u.uuid,
        u.name,
        u.first_name,
        u.last_name,
        u.email,
        u.photo_path,
        u.status,
        req.missing_count
    ORDER BY
        u.last_name ASC,
        u.first_name ASC,
        u.name ASC,
        u.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!is_array($rows)) {
    $rows = array();
}

$studentIds = array();
foreach ($rows as $row) {
    $studentId = (int)($row['id'] ?? 0);
    if ($studentId > 0) {
        $studentIds[] = $studentId;
    }
}
$studentIds = array_values(array_unique($studentIds));

$cohortsByUserId = array();

if ($studentIds) {
    $membershipSql = "
        SELECT
            cs.user_id,
            cs.cohort_id,
            c.name AS cohort_name
        FROM cohort_students cs
        INNER JOIN cohorts c
            ON c.id = cs.cohort_id
        WHERE cs.status = 'active'
          AND cs.exited_at IS NULL
          AND cs.user_id IN (" . implode(',', array_fill(0, count($studentIds), '?')) . ")
    ";

    $membershipParams = $studentIds;

    if ($cohortId > 0) {
        $membershipSql .= " AND cs.cohort_id = ? ";
        $membershipParams[] = $cohortId;
    }

    $membershipSql .= "
        ORDER BY
            c.start_date DESC,
            c.name ASC,
            c.id ASC
    ";

    $membershipStmt = $pdo->prepare($membershipSql);
    $membershipStmt->execute($membershipParams);
    $membershipRows = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($membershipRows)) {
        $membershipRows = array();
    }

    foreach ($membershipRows as $membershipRow) {
        $userId = (int)($membershipRow['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (!isset($cohortsByUserId[$userId]) || !is_array($cohortsByUserId[$userId])) {
            $cohortsByUserId[$userId] = array();
        }

        $cohortName = trim((string)($membershipRow['cohort_name'] ?? ''));
        if ($cohortName === '') {
            continue;
        }

        $cohortsByUserId[$userId][] = $cohortName;
    }

    foreach ($cohortsByUserId as $userId => $cohortNames) {
        $cohortsByUserId[$userId] = array_values(array_unique($cohortNames));
    }
}

if (!function_exists('isp_status_label')) {
    function isp_status_label(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'active' => 'Active',
            'pending_activation' => 'Pending Activation',
            'locked' => 'Locked',
            'retired' => 'Retired',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}

if (!function_exists('isp_status_class')) {
    function isp_status_class(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'active' => 'app-badge app-badge-success',
            'pending_activation' => 'app-badge app-badge-warn',
            'locked' => 'app-badge app-badge-danger',
            'retired' => 'app-badge app-badge-muted',
            default => 'app-badge app-badge-neutral',
        };
    }
}

cw_header('Students');
?>

<style>
.instructor-students-page{
    display:block;
}
.instructor-students-page .app-section-hero{
    margin-bottom:20px;
}
.isp-hero-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:24px;
}
.isp-hero-copy{
    min-width:0;
}
.isp-hero-title{
    margin:0;
    font-size:34px;
    line-height:1.02;
    letter-spacing:-0.04em;
    font-weight:760;
    color:#fff;
}
.isp-hero-text{
    max-width:820px;
    margin:14px 0 0 0;
    color:rgba(255,255,255,0.82);
    font-size:15px;
    line-height:1.65;
}
.isp-toolbar-card{
    padding:18px;
}
.isp-toolbar-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:14px;
}
.isp-toolbar-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:17px;
    font-weight:720;
    color:var(--text-strong);
    letter-spacing:-0.02em;
}
.isp-toolbar-meta{
    color:var(--text-muted);
    font-size:13px;
    font-weight:560;
}
.isp-search-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(220px,260px) auto auto;
    gap:12px;
    align-items:end;
}
.isp-field{
    display:flex;
    flex-direction:column;
    gap:7px;
}
.isp-field label{
    font-size:12px;
    font-weight:670;
    letter-spacing:.02em;
    color:var(--text-muted);
}
.isp-input,
.isp-select{
    width:100%;
    height:44px;
    border-radius:14px;
    box-sizing:border-box;
    padding:0 14px;
}
.isp-list{
    display:grid;
    gap:16px;
    margin-top:18px;
}
.isp-card{
    padding:20px;
}
.isp-card-inner{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:18px;
    align-items:center;
}
.isp-main{
    display:flex;
    gap:16px;
    min-width:0;
}
.isp-avatar{
    width:68px;
    height:68px;
    border-radius:18px;
    overflow:hidden;
    flex:0 0 68px;
    background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);
    border:1px solid rgba(15,23,42,0.07);
    display:flex;
    align-items:center;
    justify-content:center;
}
.isp-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}
.isp-avatar-fallback{
    width:28px;
    height:28px;
    color:#7b8aa0;
    display:flex;
    align-items:center;
    justify-content:center;
}
.isp-copy{
    min-width:0;
}
.isp-name{
    margin:0;
    font-size:22px;
    line-height:1.08;
    letter-spacing:-0.03em;
    font-weight:760;
    color:var(--text-strong);
}
.isp-email{
    margin-top:8px;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.5;
    word-break:break-word;
}
.isp-meta{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-top:12px;
}
.isp-cohorts{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:12px;
}
.isp-cohort-badge{
    display:inline-flex;
    align-items:center;
    min-height:30px;
    padding:0 12px;
    border-radius:999px;
    background:rgba(37,99,235,0.08);
    border:1px solid rgba(37,99,235,0.14);
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
    line-height:1.2;
}
.isp-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.isp-empty{
    padding:34px 28px;
}
.isp-empty-title{
    margin:0;
    font-size:18px;
    font-weight:740;
    letter-spacing:-0.02em;
    color:var(--text-strong);
}
.isp-empty-text{
    margin:8px 0 0 0;
    color:var(--text-muted);
    font-size:14px;
    line-height:1.65;
}
.isp-live-empty{
    display:none;
}
.isp-live-empty.is-visible{
    display:block;
}
@media (max-width:900px){
    .isp-hero-head{
        flex-direction:column;
        align-items:flex-start;
    }
    .isp-search-row{
        grid-template-columns:1fr;
    }
    .isp-card-inner{
        grid-template-columns:1fr;
    }
    .isp-actions{
        justify-content:flex-start;
    }
}
</style>

<div class="instructor-students-page">
    <section class="app-section-hero">
        <div class="hero-overline">Instructor · Students</div>

        <div class="isp-hero-head">
            <div class="isp-hero-copy">
                <h2 class="isp-hero-title">Students</h2>
                <p class="isp-hero-text">
                    Review student profiles, open training-relevant details, and access the instructor-facing student workspace.
                </p>
            </div>
        </div>
    </section>

    <section class="card isp-toolbar-card">
        <div class="isp-toolbar-head">
            <div class="isp-toolbar-title">
                <span>Student Search</span>
            </div>
            <div class="isp-toolbar-meta">
                <span id="isp-result-count"><?php echo count($rows); ?></span> result<?php echo count($rows) === 1 ? '' : 's'; ?>
            </div>
        </div>

        <form method="get" action="/instructor/students/index.php" id="isp-search-form">
            <div class="isp-search-row">
                <div class="isp-field">
                    <label for="q">Search</label>
                    <input
                        class="app-input isp-input"
                        id="q"
                        type="text"
                        name="q"
                        value="<?php echo h($q); ?>"
                        placeholder="First name, last name, full name or email"
                        autocomplete="off">
                </div>

                <div class="isp-field">
                    <label for="cohort_id">Cohort</label>
                    <select class="app-select isp-select" id="cohort_id" name="cohort_id">
                        <option value="0">All cohorts</option>
                        <?php foreach ($cohortRows as $cohortRow): ?>
                            <?php $thisCohortId = (int)($cohortRow['id'] ?? 0); ?>
                            <option value="<?php echo $thisCohortId; ?>"<?php echo $cohortId === $thisCohortId ? ' selected' : ''; ?>>
                                <?php echo h((string)($cohortRow['name'] ?? ('Cohort #' . $thisCohortId))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button class="app-btn app-btn-primary" type="submit">Search</button>
                <a class="app-btn app-btn-secondary" href="/instructor/students/index.php">Clear</a>
            </div>
        </form>
    </section>

    <?php if (!$rows): ?>
        <section class="card isp-empty">
            <h3 class="isp-empty-title">No students found</h3>
            <p class="isp-empty-text">
                Adjust the search or cohort filter, or add student accounts in the admin user system first.
            </p>
        </section>
    <?php else: ?>
        <section class="card isp-empty isp-live-empty" id="isp-live-empty">
            <h3 class="isp-empty-title">No students found</h3>
            <p class="isp-empty-text">
                No matching students were found for the current search.
            </p>
        </section>

        <div class="isp-list" id="isp-student-list">
            <?php foreach ($rows as $row): ?>
                <?php
                $studentId = (int)($row['id'] ?? 0);

                $displayName = trim((string)($row['name'] ?? ''));
                if ($displayName === '') {
                    $displayName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                }
                if ($displayName === '') {
                    $displayName = 'Student #' . $studentId;
                }

                $firstName = trim((string)($row['first_name'] ?? ''));
                $lastName = trim((string)($row['last_name'] ?? ''));
                $email = trim((string)($row['email'] ?? ''));
                $photoPath = trim((string)($row['photo_path'] ?? ''));
                $missingCount = (int)($row['missing_count'] ?? 0);
                $cohortNames = isset($cohortsByUserId[$studentId]) && is_array($cohortsByUserId[$studentId])
                    ? $cohortsByUserId[$studentId]
                    : array();

                $searchBlob = strtolower(trim(
                    $displayName . ' ' .
                    $firstName . ' ' .
                    $lastName . ' ' .
                    $email . ' ' .
                    implode(' ', $cohortNames)
                ));
                ?>
                <section class="card isp-card" data-search="<?php echo h($searchBlob); ?>">
                    <div class="isp-card-inner">
                        <div class="isp-main">
                            <div class="isp-avatar">
                                <?php if ($photoPath !== ''): ?>
                                    <img src="<?php echo h($photoPath); ?>" alt="<?php echo h($displayName); ?>">
                                <?php else: ?>
                                    <span class="isp-avatar-fallback">👤</span>
                                <?php endif; ?>
                            </div>

                            <div class="isp-copy">
                                <h3 class="isp-name"><?php echo h($displayName); ?></h3>
                                <div class="isp-email"><?php echo h($email !== '' ? $email : '—'); ?></div>

                                <div class="isp-meta">
                                    <span class="<?php echo isp_status_class((string)($row['status'] ?? '')); ?>">
                                        <?php echo h(isp_status_label((string)($row['status'] ?? ''))); ?>
                                    </span>

                                    <span class="<?php echo $missingCount > 0 ? 'app-badge app-badge-warn' : 'app-badge app-badge-success'; ?>">
                                        <?php echo $missingCount > 0 ? ('Missing ' . $missingCount) : 'Profile Complete'; ?>
                                    </span>
                                </div>

                                <?php if ($cohortNames): ?>
                                    <div class="isp-cohorts">
                                        <?php foreach ($cohortNames as $cohortName): ?>
                                            <span class="isp-cohort-badge"><?php echo h((string)$cohortName); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="isp-actions">
                            <a class="app-btn app-btn-primary" href="/instructor/students/view.php?id=<?php echo $studentId; ?>">
                                Open Student
                            </a>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('q');
    var cards = document.querySelectorAll('#isp-student-list .isp-card');
    var resultCount = document.getElementById('isp-result-count');
    var emptyState = document.getElementById('isp-live-empty');

    if (!input || !cards.length || !resultCount) {
        return;
    }

    function normalize(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function applyFilter() {
        var needle = normalize(input.value);
        var visible = 0;
        var i;
        var card;
        var haystack;

        for (i = 0; i < cards.length; i++) {
            card = cards[i];
            haystack = normalize(card.getAttribute('data-search'));

            if (needle === '' || haystack.indexOf(needle) !== -1) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }
        }

        resultCount.textContent = String(visible);

        if (emptyState) {
            if (visible === 0) {
                emptyState.classList.add('is-visible');
            } else {
                emptyState.classList.remove('is-visible');
            }
        }
    }

    input.addEventListener('input', applyFilter);
    applyFilter();
})();
</script>

<?php cw_footer(); ?>