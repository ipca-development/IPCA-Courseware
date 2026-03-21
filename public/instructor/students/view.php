<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/user_profile_access_helpers.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

cw_require_login();

$currentUser = cw_current_user($pdo);
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentRole = strtolower(trim((string)($currentUser['role'] ?? '')));

$allowedRoles = array('instructor', 'supervisor', 'chief_instructor');

if ($currentUserId <= 0 || !in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$studentId = (int)($_GET['id'] ?? 0);
if ($studentId <= 0) {
    http_response_code(400);
    exit('Missing student id.');
}

$workspace = ups_load_instructor_student_workspace($pdo, $studentId, $currentUserId);
if (!$workspace) {
    echo '<pre style="color:red;">WORKSPACE FAILED</pre>';
    var_dump($studentId, $currentUserId);
    exit;
}

$user = is_array($workspace['user'] ?? null) ? $workspace['user'] : array();
$displayName = (string)($workspace['display_name'] ?? ('Student #' . $studentId));
$emergencyContacts = is_array($workspace['emergency_contacts'] ?? null) ? $workspace['emergency_contacts'] : array();

if (!function_exists('isv_svg')) {
    function isv_svg(string $name): string
    {
        switch ($name) {
            case 'users':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1.2a3.8 3.8 0 0 0-3.8-3.8H8.8A3.8 3.8 0 0 0 5 17.8V19M15.5 8.5a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm-8 0a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm12 10.5v-1a3 3 0 0 0-2.2-2.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'archive':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M6 7l1 11h10l1-11M10 11h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 4h14v3H5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
            case 'warning':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l8 14H4L12 4Zm0 5.2v4.8m0 3h.01" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'profile':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0-4-4a4 4 0 0 0 4 4Zm-7 8a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'mail':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-9Zm0 .5l8 5.5l8-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'phone':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25a11.2 11.2 0 0 0 3.5.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.3 21 3 13.7 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1a11.2 11.2 0 0 0 .56 3.5a1 1 0 0 1-.25 1l-2.2 2.3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'id':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 10h5M8 14h8M17 10h.01" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            default:
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
        }
    }
}

if (!function_exists('isv_value')) {
    function isv_value(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return '—';
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : '—';
    }
}

if (!function_exists('isv_country_label')) {
    function isv_country_label(?string $countryCode): string
    {
        $map = array(
            'US' => 'United States',
            'CA' => 'Canada',
            'MX' => 'Mexico',
            'BE' => 'Belgium',
            'NL' => 'Netherlands',
            'DE' => 'Germany',
            'FR' => 'France',
            'ES' => 'Spain',
            'IT' => 'Italy',
            'PT' => 'Portugal',
            'GB' => 'United Kingdom',
            'IE' => 'Ireland',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'LU' => 'Luxembourg',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'HR' => 'Croatia',
            'GR' => 'Greece',
            'TR' => 'Turkey',
            'UA' => 'Ukraine',
            'IS' => 'Iceland',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'ZA' => 'South Africa',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'QA' => 'Qatar',
            'KW' => 'Kuwait',
            'IL' => 'Israel',
            'EG' => 'Egypt',
            'MA' => 'Morocco',
            'IN' => 'India',
            'PK' => 'Pakistan',
            'BD' => 'Bangladesh',
            'LK' => 'Sri Lanka',
            'NP' => 'Nepal',
            'TH' => 'Thailand',
            'VN' => 'Vietnam',
            'MY' => 'Malaysia',
            'SG' => 'Singapore',
            'ID' => 'Indonesia',
            'PH' => 'Philippines',
            'CN' => 'China',
            'HK' => 'Hong Kong',
            'TW' => 'Taiwan',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'BR' => 'Brazil',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Peru',
            'VE' => 'Venezuela',
        );

        $countryCode = strtoupper(trim((string)$countryCode));
        if ($countryCode === '') {
            return '—';
        }

        return $map[$countryCode] ?? $countryCode;
    }
}

if (!function_exists('isv_can_show')) {
    function isv_can_show(array $row, string $key): bool
    {
        return array_key_exists($key, $row);
    }
}

$identityBlocks = array(
    array('label' => 'First Name', 'key' => 'first_name'),
    array('label' => 'Last Name', 'key' => 'last_name'),
    array('label' => 'E-mail', 'key' => 'email', 'icon' => 'mail'),
    array('label' => 'Cellphone', 'key' => 'cellphone', 'icon' => 'phone'),
    array('label' => 'Date of Birth', 'key' => 'date_of_birth', 'type' => 'date'),
    array('label' => 'Nationality', 'key' => 'nationality'),
);

$profileBlocks = array(
    array('label' => 'Place of Birth', 'key' => 'place_of_birth'),
    array('label' => 'ID / Passport Number', 'key' => 'id_passport_number', 'icon' => 'id'),
    array('label' => 'Gender', 'key' => 'gender'),
    array('label' => 'Marital Status', 'key' => 'marital_status'),
    array('label' => 'Hair Color', 'key' => 'hair_color'),
    array('label' => 'Eye Color', 'key' => 'eye_color'),
);

$addressParts = array();
if (isv_can_show($user, 'street_address') && trim((string)($user['street_address'] ?? '')) !== '') {
    $addressParts[] = trim((string)$user['street_address']);
}
if (isv_can_show($user, 'street_number') && trim((string)($user['street_number'] ?? '')) !== '') {
    $addressParts[] = trim((string)$user['street_number']);
}
if (isv_can_show($user, 'zip_code') && trim((string)($user['zip_code'] ?? '')) !== '') {
    $addressParts[] = trim((string)$user['zip_code']);
}
if (isv_can_show($user, 'city') && trim((string)($user['city'] ?? '')) !== '') {
    $addressParts[] = trim((string)$user['city']);
}
if (isv_can_show($user, 'state_region') && trim((string)($user['state_region'] ?? '')) !== '') {
    $addressParts[] = trim((string)$user['state_region']);
}
if (isv_can_show($user, 'country_code')) {
    $country = isv_country_label((string)($user['country_code'] ?? ''));
    if ($country !== '—') {
        $addressParts[] = $country;
    }
}
$fullAddress = $addressParts ? implode(', ', $addressParts) : '—';

cw_header('Student View');
?>

<style>
.instructor-student-view-page{display:block}
.instructor-student-view-page .app-section-hero{margin-bottom:20px}
.isv-back-link{display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;color:rgba(255,255,255,0.86);text-decoration:none;font-size:13px;font-weight:650}
.isv-back-link:hover{color:#fff}
.isv-back-link svg{width:15px;height:15px;flex:0 0 15px}
.isv-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px}
.isv-identity{display:flex;gap:18px;min-width:0}
.isv-avatar{width:84px;height:84px;border-radius:24px;overflow:hidden;flex:0 0 84px;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.isv-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.isv-avatar-fallback{width:34px;height:34px;color:#7b8aa0}
.isv-title{margin:0;font-size:34px;line-height:1.02;letter-spacing:-0.04em;font-weight:760;color:#fff}
.isv-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.isv-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px;align-items:start}
.isv-stack{display:grid;gap:18px;align-content:start}
.isv-card{padding:22px}
.isv-card-title{display:flex;align-items:center;gap:10px;margin:0 0 16px 0;font-size:18px;font-weight:740;letter-spacing:-0.02em;color:var(--text-strong)}
.isv-card-title svg{width:18px;height:18px;color:var(--text-muted)}
.isv-list{display:grid;gap:10px}
.isv-list-item{padding:14px;border:1px solid rgba(15,23,42,0.06);border-radius:14px;background:#fbfcfe}
.isv-list-title{font-size:14px;font-weight:700;color:var(--text-strong);display:flex;align-items:center;gap:8px}
.isv-list-title svg{width:15px;height:15px;color:var(--text-muted)}
.isv-list-meta{margin-top:6px;color:var(--text-muted);font-size:13px;line-height:1.55;word-break:break-word}
.isv-note{color:var(--text-muted);font-size:13px;line-height:1.6}
@media (max-width:1200px){
    .isv-grid{grid-template-columns:1fr}
    .isv-header{flex-direction:column;align-items:flex-start}
}
@media (max-width:820px){
    .isv-title{font-size:28px}
}
</style>

<div class="instructor-student-view-page">
    <section class="app-section-hero">
        <a class="isv-back-link" href="/instructor/index.php">
            <?php echo isv_svg('archive'); ?>
            <span>Back to Instructor Area</span>
        </a>

        <div class="hero-overline">Instructor · Student Record</div>

        <div class="isv-header">
            <div class="isv-identity">
                <div class="isv-avatar">
                    <?php if (!empty($user['photo_path'])): ?>
                        <img src="<?php echo h((string)$user['photo_path']); ?>" alt="<?php echo h($displayName); ?>">
                    <?php else: ?>
                        <span class="isv-avatar-fallback"><?php echo isv_svg('users'); ?></span>
                    <?php endif; ?>
                </div>

                <div style="min-width:0;">
                    <h2 class="isv-title"><?php echo h($displayName); ?></h2>

                    <div class="isv-meta">
                        <span class="app-badge app-badge-neutral">Student</span>
                        <?php if (isv_can_show($user, 'email')): ?>
                            <span class="app-badge app-badge-neutral"><?php echo h(isv_value($user, 'email')); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="isv-grid">
        <div class="isv-stack">
            <section class="card isv-card">
                <h3 class="isv-card-title">
                    <?php echo isv_svg('profile'); ?>
                    <span>Identity and Contact</span>
                </h3>

                <div class="isv-list">
                    <?php foreach ($identityBlocks as $block): ?>
                        <?php if (!isv_can_show($user, (string)$block['key'])) continue; ?>
                        <div class="isv-list-item">
                            <div class="isv-list-title">
                                <?php if (!empty($block['icon'])): ?>
                                    <?php echo isv_svg((string)$block['icon']); ?>
                                <?php endif; ?>
                                <span><?php echo h((string)$block['label']); ?></span>
                            </div>
                            <div class="isv-list-meta">
                                <?php
                                if (($block['type'] ?? '') === 'date') {
                                    echo h(cw_date_only((string)($user[(string)$block['key']] ?? '')));
                                } else {
                                    echo h(isv_value($user, (string)$block['key']));
                                }
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($fullAddress !== '—'): ?>
                        <div class="isv-list-item">
                            <div class="isv-list-title">Address</div>
                            <div class="isv-list-meta"><?php echo h($fullAddress); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php
            $hasProfileExtra = false;
            foreach ($profileBlocks as $block) {
                if (isv_can_show($user, (string)$block['key'])) {
                    $hasProfileExtra = true;
                    break;
                }
            }
            ?>
            <?php if ($hasProfileExtra): ?>
                <section class="card isv-card">
                    <h3 class="isv-card-title">
                        <?php echo isv_svg('id'); ?>
                        <span>Operational Details</span>
                    </h3>

                    <div class="isv-list">
                        <?php foreach ($profileBlocks as $block): ?>
                            <?php if (!isv_can_show($user, (string)$block['key'])) continue; ?>
                            <div class="isv-list-item">
                                <div class="isv-list-title">
                                    <?php if (!empty($block['icon'])): ?>
                                        <?php echo isv_svg((string)$block['icon']); ?>
                                    <?php endif; ?>
                                    <span><?php echo h((string)$block['label']); ?></span>
                                </div>
                                <div class="isv-list-meta"><?php echo h(isv_value($user, (string)$block['key'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside class="isv-stack">
            <section class="card isv-card">
                <h3 class="isv-card-title">
                    <?php echo isv_svg('warning'); ?>
                    <span>Emergency Contacts</span>
                </h3>

                <?php if ($emergencyContacts): ?>
                    <div class="isv-list">
                        <?php foreach ($emergencyContacts as $contact): ?>
                            <?php
                            $sortOrder = (int)($contact['sort_order'] ?? 0);
                            $contactName = trim((string)($contact['contact_name'] ?? ''));
                            $relationship = trim((string)($contact['relationship'] ?? ''));
                            $phone = trim((string)($contact['phone'] ?? ''));
                            ?>
                            <div class="isv-list-item">
                                <div class="isv-list-title">
                                    <?php echo isv_svg('warning'); ?>
                                    <span>Emergency Contact <?php echo $sortOrder > 0 ? (int)$sortOrder : ''; ?></span>
                                </div>
                                <div class="isv-list-meta">
                                    <?php echo h($contactName !== '' ? $contactName : '—'); ?><br>
                                    Relationship: <?php echo h($relationship !== '' ? $relationship : '—'); ?><br>
                                    Phone: <?php echo h($phone !== '' ? $phone : '—'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="isv-note">No emergency contacts available.</div>
                <?php endif; ?>
            </section>

            <section class="card isv-card">
                <h3 class="isv-card-title">
                    <?php echo isv_svg('users'); ?>
                    <span>Visibility Note</span>
                </h3>

                <div class="isv-note">
                    This page is read-only and only displays student information that is operationally visible to instructors under the active policy configuration.
                </div>
            </section>
        </aside>
    </div>
</div>

<?php cw_footer(); ?>