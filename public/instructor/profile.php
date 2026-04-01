<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/user_profile_access_helpers.php';

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

if (!function_exists('ip_active_tab')) {
    function ip_active_tab(?string $tab): string
    {
        $tab = strtolower(trim((string)$tab));
        $tabs = ups_self_service_tabs();

        return isset($tabs[$tab]) ? $tab : 'personal';
    }
}

if (!function_exists('ip_profile_url')) {
    function ip_profile_url(string $tab, array $extra = array()): string
    {
        $params = array_merge(array(
            'tab' => $tab,
        ), $extra);

        return '/instructor/profile.php?' . http_build_query($params);
    }
}

if (!function_exists('ip_flash_redirect')) {
    function ip_flash_redirect(string $tab, string $type, string $message): void
    {
        header('Location: ' . ip_profile_url($tab, array(
            'flash_type' => $type,
            'flash_message' => $message,
        )));
        exit;
    }
}

if (!function_exists('ip_svg')) {
    function ip_svg(string $name): string
    {
        switch ($name) {
            case 'users':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19v-1.2a3.8 3.8 0 0 0-3.8-3.8H8.8A3.8 3.8 0 0 0 5 17.8V19M15.5 8.5a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm-8 0a2.5 2.5 0 1 1 0-5a2.5 2.5 0 0 1 0 5Zm12 10.5v-1a3 3 0 0 0-2.2-2.9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'archive':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M6 7l1 11h10l1-11M10 11h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 4h14v3H5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
            case 'warning':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l8 14H4L12 4Zm0 5.2v4.8m0 3h.01" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'camera':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6l1.2-2h5.6L16 6h2a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2Zm4 10a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'lock':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'save':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11l3 3v13H5V4Zm3 0v5h8V4M9 20v-6h6v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'activity':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l2-5l4 10l2-5h6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            case 'profile':
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0-4-4a4 4 0 0 0 4 4Zm-7 8a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            default:
                return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
        }
    }
}

if (!function_exists('ip_photo_max_bytes')) {
    function ip_photo_max_bytes(): int
    {
        return 5 * 1024 * 1024;
    }
}

if (!function_exists('ip_handle_photo_upload')) {
    function ip_handle_photo_upload(int $userId): ?string
    {
        if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
            return null;
        }

        if ((int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $uploadError = (int)($_FILES['photo']['error'] ?? 0);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Photo upload failed. Please try again with a JPG, PNG, or WEBP image.');
        }

        $size = (int)($_FILES['photo']['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded photo is empty.');
        }

        if ($size > ip_photo_max_bytes()) {
            throw new RuntimeException('Photo is too large. Maximum allowed size is 5 MB.');
        }

        $tmpPath = (string)($_FILES['photo']['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid upload payload.');
        }

        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $mime = (string)($imageInfo['mime'] ?? '');
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };

        if ($ext === '') {
            throw new RuntimeException('Only JPG, PNG, or WEBP photos are allowed.');
        }

        $targetDirFs = dirname(__DIR__, 2) . '/public/uploads/user_photos';
        if (!is_dir($targetDirFs) && !mkdir($targetDirFs, 0775, true) && !is_dir($targetDirFs)) {
            throw new RuntimeException('Unable to create photo upload directory.');
        }

        $fileName = 'user_' . $userId . '_' . time() . '.' . $ext;
        $targetFs = $targetDirFs . '/' . $fileName;
        $targetWeb = '/uploads/user_photos/' . $fileName;

        if (!move_uploaded_file($tmpPath, $targetFs)) {
            throw new RuntimeException('Unable to store uploaded photo.');
        }

        return $targetWeb;
    }
}

if (!function_exists('ip_update_personal_profile')) {
    function ip_update_personal_profile(PDO $pdo, int $userId): void
    {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            throw new RuntimeException('First name and last name are required.');
        }

        $secondaryEmail = trim((string)($_POST['secondary_email'] ?? ''));
        if ($secondaryEmail !== '' && !filter_var($secondaryEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Secondary email address is invalid.');
        }

        $photoPath = ip_handle_photo_upload($userId);
        $displayName = trim($firstName . ' ' . $lastName);

        $pdo->beginTransaction();

        try {
            $updateUser = $pdo->prepare("
                UPDATE users
                SET
                    first_name = :first_name,
                    last_name = :last_name,
                    name = :name,
                    photo_path = COALESCE(:photo_path, photo_path),
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $updateUser->execute(array(
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':name' => $displayName,
                ':photo_path' => $photoPath,
                ':id' => $userId,
            ));

            $upsertProfile = $pdo->prepare("
                INSERT INTO user_profiles (
                    user_id,
                    street_address,
                    street_number,
                    zip_code,
                    city,
                    state_region,
                    country_code,
                    cellphone,
                    secondary_email,
                    date_of_birth,
                    place_of_birth,
                    nationality,
                    id_passport_number,
                    gender,
                    weight,
                    height_cm,
                    hair_color,
                    eye_color,
                    marital_status,
                    created_at,
                    updated_at
                ) VALUES (
                    :user_id,
                    :street_address,
                    :street_number,
                    :zip_code,
                    :city,
                    :state_region,
                    :country_code,
                    :cellphone,
                    :secondary_email,
                    :date_of_birth,
                    :place_of_birth,
                    :nationality,
                    :id_passport_number,
                    :gender,
                    :weight,
                    :height_cm,
                    :hair_color,
                    :eye_color,
                    :marital_status,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    street_address = VALUES(street_address),
                    street_number = VALUES(street_number),
                    zip_code = VALUES(zip_code),
                    city = VALUES(city),
                    state_region = VALUES(state_region),
                    country_code = VALUES(country_code),
                    cellphone = VALUES(cellphone),
                    secondary_email = VALUES(secondary_email),
                    date_of_birth = VALUES(date_of_birth),
                    place_of_birth = VALUES(place_of_birth),
                    nationality = VALUES(nationality),
                    id_passport_number = VALUES(id_passport_number),
                    gender = VALUES(gender),
                    weight = VALUES(weight),
                    height_cm = VALUES(height_cm),
                    hair_color = VALUES(hair_color),
                    eye_color = VALUES(eye_color),
                    marital_status = VALUES(marital_status),
                    updated_at = NOW()
            ");
            $upsertProfile->execute(array(
                ':user_id' => $userId,
                ':street_address' => trim((string)($_POST['street_address'] ?? '')) ?: null,
                ':street_number' => trim((string)($_POST['street_number'] ?? '')) ?: null,
                ':zip_code' => trim((string)($_POST['zip_code'] ?? '')) ?: null,
                ':city' => trim((string)($_POST['city'] ?? '')) ?: null,
                ':state_region' => trim((string)($_POST['state_region'] ?? '')) ?: null,
                ':country_code' => strtoupper(trim((string)($_POST['country_code'] ?? ''))) ?: null,
                ':cellphone' => trim((string)($_POST['cellphone'] ?? '')) ?: null,
                ':secondary_email' => $secondaryEmail !== '' ? $secondaryEmail : null,
                ':date_of_birth' => ups_normalize_date((string)($_POST['date_of_birth'] ?? '')),
                ':place_of_birth' => trim((string)($_POST['place_of_birth'] ?? '')) ?: null,
                ':nationality' => trim((string)($_POST['nationality'] ?? '')) ?: null,
                ':id_passport_number' => trim((string)($_POST['id_passport_number'] ?? '')) ?: null,
                ':gender' => trim((string)($_POST['gender'] ?? '')) ?: null,
                ':weight' => trim((string)($_POST['weight_kg'] ?? '')) ?: null,
                ':height_cm' => ups_normalize_decimal((string)($_POST['height_cm'] ?? '')),
                ':hair_color' => trim((string)($_POST['hair_color'] ?? '')) ?: null,
                ':eye_color' => trim((string)($_POST['eye_color'] ?? '')) ?: null,
                ':marital_status' => trim((string)($_POST['marital_status'] ?? '')) ?: null,
            ));

            ups_recalculate_profile_requirements_status($pdo, $userId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('ip_upsert_emergency_contact_row')) {
    function ip_upsert_emergency_contact_row(PDO $pdo, int $userId, int $sortOrder, ?string $contactName, ?string $relationship, ?string $phone): void
    {
        $contactName = trim((string)$contactName);
        $relationship = trim((string)$relationship);
        $phone = trim((string)$phone);

        $allEmpty = ($contactName === '' && $relationship === '' && $phone === '');

        $existingStmt = $pdo->prepare("
            SELECT id
            FROM user_emergency_contacts
            WHERE user_id = :user_id
              AND sort_order = :sort_order
            ORDER BY id ASC
            LIMIT 1
        ");
        $existingStmt->execute(array(
            ':user_id' => $userId,
            ':sort_order' => $sortOrder,
        ));
        $existingId = (int)$existingStmt->fetchColumn();

        if ($allEmpty) {
            if ($existingId > 0) {
                $deleteStmt = $pdo->prepare("
                    DELETE FROM user_emergency_contacts
                    WHERE id = :id
                    LIMIT 1
                ");
                $deleteStmt->execute(array(
                    ':id' => $existingId,
                ));
            }
            return;
        }

        if ($existingId > 0) {
            $updateStmt = $pdo->prepare("
                UPDATE user_emergency_contacts
                SET
                    contact_name = :contact_name,
                    relationship = :relationship,
                    phone = :phone,
                    sort_order = :sort_order,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $updateStmt->execute(array(
                ':contact_name' => $contactName !== '' ? $contactName : null,
                ':relationship' => $relationship !== '' ? $relationship : null,
                ':phone' => $phone !== '' ? $phone : null,
                ':sort_order' => $sortOrder,
                ':id' => $existingId,
            ));
            return;
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO user_emergency_contacts (
                user_id,
                contact_name,
                relationship,
                phone,
                sort_order,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :contact_name,
                :relationship,
                :phone,
                :sort_order,
                NOW(),
                NOW()
            )
        ");
        $insertStmt->execute(array(
            ':user_id' => $userId,
            ':contact_name' => $contactName !== '' ? $contactName : null,
            ':relationship' => $relationship !== '' ? $relationship : null,
            ':phone' => $phone !== '' ? $phone : null,
            ':sort_order' => $sortOrder,
        ));
    }
}

if (!function_exists('ip_update_emergency_contacts')) {
    function ip_update_emergency_contacts(PDO $pdo, int $userId): void
    {
        $requiredContactCount = max(0, ups_policy_int($pdo, 'user_required_emergency_contact_count', 2));
        $workspace = ups_load_self_service_workspace($pdo, $userId);
        $contactsAll = is_array($workspace['emergency_contacts'] ?? null) ? $workspace['emergency_contacts'] : array();
        $maxExistingSort = 0;

        foreach ($contactsAll as $row) {
            $sortOrder = (int)($row['sort_order'] ?? 0);
            if ($sortOrder > $maxExistingSort) {
                $maxExistingSort = $sortOrder;
            }
        }

        $renderCount = max(2, $requiredContactCount, $maxExistingSort);

        $pdo->beginTransaction();

        try {
            for ($i = 1; $i <= $renderCount; $i++) {
                ip_upsert_emergency_contact_row(
                    $pdo,
                    $userId,
                    $i,
                    (string)($_POST['contact_name_' . $i] ?? ''),
                    (string)($_POST['relationship_' . $i] ?? ''),
                    (string)($_POST['phone_' . $i] ?? '')
                );
            }

            ups_recalculate_profile_requirements_status($pdo, $userId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('ip_update_password')) {
    function ip_update_password(PDO $pdo, int $userId): void
    {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new RuntimeException('All password fields are required.');
        }

        if (!ups_verify_current_password($pdo, $userId, $currentPassword)) {
            throw new RuntimeException('Current password is incorrect.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('New password and confirmation do not match.');
        }

        if (strlen($newPassword) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }

        if ($newPassword === $currentPassword) {
            throw new RuntimeException('New password must be different from your current password.');
        }

        ups_update_password($pdo, $userId, $newPassword);
    }
}

$activeTab = ip_active_tab((string)($_GET['tab'] ?? 'personal'));
$flashType = strtolower(trim((string)($_GET['flash_type'] ?? '')));
$flashMessage = trim((string)($_GET['flash_message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = strtolower(trim((string)($_POST['form_section'] ?? '')));
    $postedTab = ip_active_tab((string)($_POST['tab'] ?? $activeTab));

    try {
        switch ($section) {
            case 'personal':
                ip_update_personal_profile($pdo, $currentUserId);
                ip_flash_redirect('personal', 'success', 'Personal details updated.');
                break;

            case 'emergency':
                ip_update_emergency_contacts($pdo, $currentUserId);
                ip_flash_redirect('emergency', 'success', 'Emergency contacts updated.');
                break;

            case 'password':
                ip_update_password($pdo, $currentUserId);
                ip_flash_redirect('password', 'success', 'Password updated successfully.');
                break;

            default:
                ip_flash_redirect($postedTab, 'error', 'Unknown form action.');
        }
    } catch (Throwable $e) {
        ip_flash_redirect($postedTab, 'error', $e->getMessage());
    }
}

ups_recalculate_profile_requirements_status($pdo, $currentUserId);

$workspace = ups_load_self_service_workspace($pdo, $currentUserId);
if (!$workspace) {
    http_response_code(404);
    exit('Profile not found.');
}

$user = $workspace['user'];
if (!isset($user['weight_kg'])) {
    $user['weight_kg'] = isset($user['weight']) ? (string)$user['weight'] : '';
}

$displayName = (string)$workspace['display_name'];
$missingFields = is_array($workspace['missing_fields'] ?? null) ? $workspace['missing_fields'] : array();
$missingCount = (int)($user['missing_count'] ?? 0);

$tabs = ups_self_service_tabs();

$countryOptions = array(
    '' => 'Select country',
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

$genderOptions = array(
    '' => 'Select gender',
    'Male' => 'Male',
    'Female' => 'Female',
    'Non-Binary' => 'Non-Binary',
    'Prefer Not to Say' => 'Prefer Not to Say',
    'Other' => 'Other',
);

$maritalStatusOptions = array(
    '' => 'Select marital status',
    'Single' => 'Single',
    'Married' => 'Married',
    'Separated' => 'Separated',
    'Divorced' => 'Divorced',
    'Widowed' => 'Widowed',
    'Domestic Partnership' => 'Domestic Partnership',
    'Civil Union' => 'Civil Union',
    'Prefer Not to Say' => 'Prefer Not to Say',
);

$hairColorOptions = array(
    '' => 'Select hair color',
    'Black' => 'Black',
    'Brown' => 'Brown',
    'Blonde' => 'Blonde',
    'Red' => 'Red',
    'Gray' => 'Gray',
    'White' => 'White',
    'Bald' => 'Bald / Shaved',
    'Other' => 'Other',
);

$eyeColorOptions = array(
    '' => 'Select eye color',
    'Brown' => 'Brown',
    'Blue' => 'Blue',
    'Hazel' => 'Hazel',
    'Green' => 'Green',
    'Gray' => 'Gray',
    'Amber' => 'Amber',
    'Other' => 'Other',
);

$relationshipOptions = array(
    '' => 'Select relationship',
    'Mother' => 'Mother',
    'Father' => 'Father',
    'Spouse' => 'Spouse',
    'Partner' => 'Partner',
    'Wife' => 'Wife',
    'Husband' => 'Husband',
    'Son' => 'Son',
    'Daughter' => 'Daughter',
    'Brother' => 'Brother',
    'Sister' => 'Sister',
    'Grandmother' => 'Grandmother',
    'Grandfather' => 'Grandfather',
    'Aunt' => 'Aunt',
    'Uncle' => 'Uncle',
    'Guardian' => 'Guardian',
    'Friend' => 'Friend',
    'Relative' => 'Relative',
    'Other' => 'Other',
);

$contactsAll = is_array($workspace['emergency_contacts'] ?? null) ? $workspace['emergency_contacts'] : array();
$contactsBySort = array();
$maxExistingSort = 0;

foreach ($contactsAll as $contactRow) {
    $sortOrder = (int)($contactRow['sort_order'] ?? 0);
    if ($sortOrder > 0) {
        $contactsBySort[$sortOrder] = $contactRow;
        if ($sortOrder > $maxExistingSort) {
            $maxExistingSort = $sortOrder;
        }
    }
}

$requiredContactCount = max(0, ups_policy_int($pdo, 'user_required_emergency_contact_count', 2));
$contactRenderCount = max(2, $requiredContactCount, $maxExistingSort);

cw_header('My Profile');
?>

<style>
.instructor-profile-page{display:block}
.instructor-profile-page .app-section-hero{margin-bottom:20px}
.ip-back-link{display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;color:rgba(255,255,255,0.86);text-decoration:none;font-size:13px;font-weight:650}
.ip-back-link:hover{color:#fff}
.ip-back-link svg{width:15px;height:15px;flex:0 0 15px}
.ip-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px}
.ip-identity{display:flex;gap:18px;min-width:0}
.ip-avatar{width:84px;height:84px;border-radius:24px;overflow:hidden;flex:0 0 84px;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45)}
.ip-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.ip-avatar-fallback{width:34px;height:34px;color:#7b8aa0}
.ip-title{margin:0;font-size:34px;line-height:1.02;letter-spacing:-0.04em;font-weight:760;color:#fff}
.ip-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.ip-flash{padding:14px 16px;border-radius:16px;margin-bottom:18px;font-size:14px;font-weight:620}
.ip-flash--success{background:rgba(32,135,90,0.09);color:#1f7a54;border:1px solid rgba(32,135,90,0.14)}
.ip-flash--error{background:rgba(185,54,54,0.09);color:#ac2f2f;border:1px solid rgba(185,54,54,0.14)}
.ip-tabs-card{padding:10px}
.ip-tabs{display:flex;gap:8px;flex-wrap:wrap}
.ip-tab{min-height:42px;padding:0 14px}
.ip-grid{display:grid;grid-template-columns:1.2fr 0.8fr;gap:18px;margin-top:18px;align-items:start}
.ip-stack{display:grid;gap:18px;align-content:start}
.ip-card{padding:22px}
.ip-card-title{display:flex;align-items:center;gap:10px;margin:0 0 16px 0;font-size:18px;font-weight:740;letter-spacing:-0.02em;color:var(--text-strong)}
.ip-card-title svg{width:18px;height:18px;color:var(--text-muted)}
.ip-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.ip-field{display:flex;flex-direction:column;gap:7px;min-width:0}
.ip-field label{font-size:12px;font-weight:670;letter-spacing:.02em;color:var(--text-muted)}
.ip-field--full{grid-column:1 / -1}
.ip-input,.ip-select{width:100%;max-width:100%;height:44px;border-radius:14px;box-sizing:border-box;padding:0 14px}
.ip-readonly{background:#f7f9fc;color:#6b778d}
.ip-file-input{padding-top:10px;height:auto;min-height:44px}
.ip-photo-block{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.ip-photo-preview{width:72px;height:72px;border-radius:20px;overflow:hidden;background:linear-gradient(180deg,#e8eef7 0%,#dfe7f2 100%);border:1px solid rgba(15,23,42,0.07);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 1px 0 rgba(255,255,255,0.45);flex:0 0 72px}
.ip-photo-preview img{width:100%;height:100%;object-fit:cover;display:block}
.ip-photo-preview .fallback{width:28px;height:28px;color:#7b8aa0}
.ip-inline-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.ip-actions-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.ip-btn{min-height:42px;padding:0 16px;border-radius:12px}
.ip-btn svg{width:15px;height:15px;flex:0 0 15px}
.ip-help{color:var(--text-muted);font-size:13px;line-height:1.6}
.ip-note{color:var(--text-muted);font-size:13px;line-height:1.6}
.ip-list{display:grid;gap:10px}
.ip-list-item{padding:14px;border:1px solid rgba(15,23,42,0.06);border-radius:14px;background:#fbfcfe}
.ip-list-title{font-size:14px;font-weight:700;color:var(--text-strong)}
.ip-list-meta{margin-top:6px;color:var(--text-muted);font-size:13px;line-height:1.55}
.ip-collapse-content[hidden]{display:none !important}
.ip-missing-pill-button{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 12px;border-radius:999px;border:none;cursor:pointer;font-size:12px;font-weight:700;transition:transform .16s ease, box-shadow .16s ease}
.ip-missing-pill-button:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(15,23,42,0.10)}
.ip-missing-pill-static{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 12px;border-radius:999px;font-size:12px;font-weight:700}
.ip-list-item-link{display:block;text-decoration:none;transition:background .16s ease,border-color .16s ease,transform .16s ease}
.ip-list-item-link:hover{background:#f8fafc;border-color:rgba(15,23,42,0.12);transform:translateY(-1px);text-decoration:none}
.ip-banner{padding:18px;border-radius:18px;border:1px solid rgba(196,118,11,0.14);background:rgba(196,118,11,0.06);margin-bottom:18px}
.ip-banner-title{margin:0;font-size:16px;font-weight:760;letter-spacing:-0.02em;color:#8f5a07}
.ip-banner-text{margin:10px 0 0 0;color:#8f5a07;font-size:13px;line-height:1.65}
.ip-contact-block{padding:16px 18px 18px 18px;border:1px solid rgba(15,23,42,0.06);border-radius:16px;background:#fbfcfe}
.ip-contact-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.ip-contact-title{font-size:15px;font-weight:730;letter-spacing:-0.02em;color:var(--text-strong)}
.ip-contact-chip{display:inline-flex;align-items:center;padding:0 12px;height:30px;border-radius:999px;background:rgba(32,84,176,0.08);border:1px solid rgba(32,84,176,0.12);color:#2557b3;font-size:12px;font-weight:700}
@media (max-width:1200px){.ip-grid{grid-template-columns:1fr}.ip-header{flex-direction:column;align-items:flex-start}}
@media (max-width:900px){.ip-form-grid,.ip-inline-grid{grid-template-columns:1fr}}
@media (max-width:820px){.ip-title{font-size:28px}}
</style>

<div class="instructor-profile-page">
    <?php if ($flashMessage !== ''): ?>
        <div class="ip-flash <?php echo $flashType === 'success' ? 'ip-flash--success' : 'ip-flash--error'; ?>">
            <?php echo h($flashMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($missingCount > 0): ?>
        <div class="ip-banner">
            <h3 class="ip-banner-title">Your profile still needs attention</h3>
            <p class="ip-banner-text">
                You still have <?php echo (int)$missingCount; ?> required field<?php echo $missingCount === 1 ? '' : 's'; ?> to complete.
            </p>
        </div>
    <?php endif; ?>

    <section class="app-section-hero">
        <a class="ip-back-link" href="/instructor/index.php">
            <?php echo ip_svg('archive'); ?>
            <span>Back to Instructor Area</span>
        </a>

        <div class="hero-overline">Instructor · Self-Service Profile</div>

        <div class="ip-header">
            <div class="ip-identity">
                <div class="ip-avatar">
                    <?php if (!empty($user['photo_path'])): ?>
                        <img src="<?php echo h((string)$user['photo_path']); ?>" alt="<?php echo h($displayName); ?>">
                    <?php else: ?>
                        <span class="ip-avatar-fallback"><?php echo ip_svg('users'); ?></span>
                    <?php endif; ?>
                </div>

                <div style="min-width:0;">
                    <h2 class="ip-title"><?php echo h($displayName); ?></h2>

                    <div class="ip-meta">
                        <span class="<?php echo $missingCount > 0 ? 'app-badge app-badge-warn' : 'app-badge app-badge-success'; ?>">
                            <?php echo $missingCount > 0 ? ('Missing ' . $missingCount) : 'Profile Complete'; ?>
                        </span>

                        <span class="app-badge app-badge-neutral">
                            <?php echo h((string)($user['email'] ?? '—')); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="card ip-tabs-card">
        <nav class="ip-tabs" aria-label="Profile sections">
            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <a class="app-tab-pill ip-tab<?php echo $activeTab === $tabKey ? ' is-active' : ''; ?>" href="<?php echo h(ip_profile_url((string)$tabKey)); ?>">
                    <?php
                    if ($tabKey === 'personal') {
                        echo ip_svg('profile');
                    } elseif ($tabKey === 'emergency') {
                        echo ip_svg('warning');
                    } else {
                        echo ip_svg('lock');
                    }
                    ?>
                    <span><?php echo h((string)$tabLabel); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </section>

    <div class="ip-grid">
        <div class="ip-stack">
            <?php if ($activeTab === 'personal'): ?>
                <section class="card ip-card">
                    <h3 class="ip-card-title"><?php echo ip_svg('profile'); ?><span>Personal Details</span></h3>

                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="form_section" value="personal">
                        <input type="hidden" name="tab" value="personal">

                        <div class="ip-form-grid">
                            <div class="ip-field">
                                <label for="first_name">First Name</label>
                                <input class="app-input ip-input" id="first_name" type="text" name="first_name" value="<?php echo h((string)($user['first_name'] ?? '')); ?>" required>
                            </div>

                            <div class="ip-field">
                                <label for="last_name">Last Name</label>
                                <input class="app-input ip-input" id="last_name" type="text" name="last_name" value="<?php echo h((string)($user['last_name'] ?? '')); ?>" required>
                            </div>

                            <div class="ip-field ip-field--full">
                                <label for="email">Primary E-mail / Login Identity</label>
                                <input class="app-input ip-input ip-readonly" id="email" type="email" value="<?php echo h((string)($user['email'] ?? '')); ?>" readonly>
                                <div class="ip-help">Your login e-mail is controlled by administration and cannot be changed here.</div>
                            </div>

                            <div class="ip-field ip-field--full">
                                <label for="photo">Photo</label>
                                <div class="ip-photo-block">
                                    <div class="ip-photo-preview">
                                        <?php if (!empty($user['photo_path'])): ?>
                                            <img src="<?php echo h((string)$user['photo_path']); ?>" alt="<?php echo h($displayName); ?>">
                                        <?php else: ?>
                                            <span class="fallback"><?php echo ip_svg('camera'); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <div style="min-width:260px;flex:1 1 auto;">
                                        <input class="app-input ip-input ip-file-input" id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                                        <div class="ip-help" style="margin-top:8px;">Use JPG, PNG, or WEBP. Maximum 5 MB.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="ip-field ip-field--full">
                                <label for="street_address">Street Address</label>
                                <input class="app-input ip-input" id="street_address" type="text" name="street_address" value="<?php echo h((string)($user['street_address'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="street_number">Street Number</label>
                                <input class="app-input ip-input" id="street_number" type="text" name="street_number" value="<?php echo h((string)($user['street_number'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="zip_code">Zip Code</label>
                                <input class="app-input ip-input" id="zip_code" type="text" name="zip_code" value="<?php echo h((string)($user['zip_code'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="city">City</label>
                                <input class="app-input ip-input" id="city" type="text" name="city" value="<?php echo h((string)($user['city'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="state_region">State / Region</label>
                                <input class="app-input ip-input" id="state_region" type="text" name="state_region" value="<?php echo h((string)($user['state_region'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="country_code">Country</label>
                                <select class="app-select ip-select" id="country_code" name="country_code">
                                    <?php foreach ($countryOptions as $countryCode => $countryLabel): ?>
                                        <option value="<?php echo h($countryCode); ?>"<?php echo strtoupper((string)($user['country_code'] ?? '')) === $countryCode ? ' selected' : ''; ?>>
                                            <?php echo h($countryLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ip-field">
                                <label for="cellphone">Cellphone</label>
                                <input class="app-input ip-input" id="cellphone" type="text" name="cellphone" value="<?php echo h((string)($user['cellphone'] ?? '')); ?>">
                            </div>

                            <div class="ip-field ip-field--full">
                                <label for="secondary_email">Secondary Email</label>
                                <input class="app-input ip-input" id="secondary_email" type="email" name="secondary_email" value="<?php echo h((string)($user['secondary_email'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="date_of_birth">Date of Birth</label>
                                <input class="app-input ip-input" id="date_of_birth" type="date" name="date_of_birth" value="<?php echo h((string)($user['date_of_birth'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="place_of_birth">Place of Birth</label>
                                <input class="app-input ip-input" id="place_of_birth" type="text" name="place_of_birth" value="<?php echo h((string)($user['place_of_birth'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="nationality">Nationality</label>
                                <input class="app-input ip-input" id="nationality" type="text" name="nationality" value="<?php echo h((string)($user['nationality'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="id_passport_number">ID / Passport Number</label>
                                <input class="app-input ip-input" id="id_passport_number" type="text" name="id_passport_number" value="<?php echo h((string)($user['id_passport_number'] ?? '')); ?>">
                            </div>

                            <div class="ip-field">
                                <label for="gender">Gender</label>
                                <select class="app-select ip-select" id="gender" name="gender">
                                    <?php foreach ($genderOptions as $optionValue => $optionLabel): ?>
                                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['gender'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                                            <?php echo h($optionLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ip-field">
                                <label for="marital_status">Marital Status</label>
                                <select class="app-select ip-select" id="marital_status" name="marital_status">
                                    <?php foreach ($maritalStatusOptions as $optionValue => $optionLabel): ?>
                                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['marital_status'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                                            <?php echo h($optionLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ip-field">
                                <label for="hair_color">Hair Color</label>
                                <select class="app-select ip-select" id="hair_color" name="hair_color">
                                    <?php foreach ($hairColorOptions as $optionValue => $optionLabel): ?>
                                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['hair_color'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                                            <?php echo h($optionLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ip-field">
                                <label for="eye_color">Eye Color</label>
                                <select class="app-select ip-select" id="eye_color" name="eye_color">
                                    <?php foreach ($eyeColorOptions as $optionValue => $optionLabel): ?>
                                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($user['eye_color'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                                            <?php echo h($optionLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ip-field ip-field--full">
                                <div class="ip-inline-grid">
                                    <div class="ip-field">
                                        <label for="weight_kg">Weight (kg)</label>
                                        <input class="app-input ip-input" id="weight_kg" type="number" step="0.1" min="0" name="weight_kg" value="<?php echo h((string)($user['weight_kg'] ?? '')); ?>">
                                    </div>

                                    <div class="ip-field">
                                        <label for="weight_lb_display">Weight (lb)</label>
                                        <input class="app-input ip-input ip-readonly" id="weight_lb_display" type="text" value="" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="ip-field ip-field--full">
                                <div class="ip-inline-grid">
                                    <div class="ip-field">
                                        <label for="height_cm">Height (cm)</label>
                                        <input class="app-input ip-input" id="height_cm" type="number" step="0.1" min="0" name="height_cm" value="<?php echo h((string)($user['height_cm'] ?? '')); ?>">
                                    </div>

                                    <div class="ip-field">
                                        <label for="height_in_display">Height (in)</label>
                                        <input class="app-input ip-input ip-readonly" id="height_in_display" type="text" value="" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ip-actions-row">
                            <button class="app-btn app-btn-primary ip-btn" type="submit">
                                <?php echo ip_svg('save'); ?>
                                <span>Save Personal Details</span>
                            </button>
                        </div>
                    </form>
                </section>
            <?php elseif ($activeTab === 'emergency'): ?>
                <section class="card ip-card">
                    <h3 class="ip-card-title"><?php echo ip_svg('warning'); ?><span>Emergency Contacts</span></h3>

                    <form method="post">
                        <input type="hidden" name="form_section" value="emergency">
                        <input type="hidden" name="tab" value="emergency">

                        <div class="ip-form-grid">
                            <?php for ($i = 1; $i <= $contactRenderCount; $i++): ?>
                                <?php
                                $contact = isset($contactsBySort[$i]) && is_array($contactsBySort[$i])
                                    ? $contactsBySort[$i]
                                    : ups_empty_emergency_contact($i);
                                ?>
                                <div class="ip-field ip-field--full">
                                    <div class="ip-contact-block">
                                        <div class="ip-contact-head">
                                            <div class="ip-contact-title">Emergency Contact <?php echo (int)$i; ?></div>
                                            <div class="ip-contact-chip"><?php echo $i === 1 ? 'Primary' : 'Contact ' . (int)$i; ?></div>
                                        </div>

                                        <div class="ip-form-grid">
                                            <div class="ip-field ip-field--full">
                                                <label for="contact_name_<?php echo (int)$i; ?>">Emergency Contact <?php echo (int)$i; ?> Name</label>
                                                <input
                                                    class="app-input ip-input"
                                                    id="contact_name_<?php echo (int)$i; ?>"
                                                    type="text"
                                                    name="contact_name_<?php echo (int)$i; ?>"
                                                    value="<?php echo h((string)($contact['contact_name'] ?? '')); ?>"
                                                    placeholder="Full name">
                                            </div>

                                            <div class="ip-field">
                                                <label for="relationship_<?php echo (int)$i; ?>">Relationship</label>
                                                <select class="app-select ip-select" id="relationship_<?php echo (int)$i; ?>" name="relationship_<?php echo (int)$i; ?>">
                                                    <?php foreach ($relationshipOptions as $optionValue => $optionLabel): ?>
                                                        <option value="<?php echo h($optionValue); ?>"<?php echo (string)($contact['relationship'] ?? '') === $optionValue ? ' selected' : ''; ?>>
                                                            <?php echo h($optionLabel); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="ip-field">
                                                <label for="phone_<?php echo (int)$i; ?>">Phone</label>
                                                <input
                                                    class="app-input ip-input"
                                                    id="phone_<?php echo (int)$i; ?>"
                                                    type="text"
                                                    name="phone_<?php echo (int)$i; ?>"
                                                    value="<?php echo h((string)($contact['phone'] ?? '')); ?>"
                                                    placeholder="+1 ...">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <div class="ip-actions-row">
                            <button class="app-btn app-btn-primary ip-btn" type="submit">
                                <?php echo ip_svg('save'); ?>
                                <span>Save Emergency Contacts</span>
                            </button>
                        </div>
                    </form>
                </section>
            <?php else: ?>
                <section class="card ip-card">
                    <h3 class="ip-card-title"><?php echo ip_svg('lock'); ?><span>Password</span></h3>

                    <form method="post">
                        <input type="hidden" name="form_section" value="password">
                        <input type="hidden" name="tab" value="password">

                        <div class="ip-form-grid">
                            <div class="ip-field ip-field--full">
                                <label for="current_password">Current Password</label>
                                <input class="app-input ip-input" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                            </div>

                            <div class="ip-field">
                                <label for="new_password">New Password</label>
                                <input class="app-input ip-input" id="new_password" type="password" name="new_password" autocomplete="new-password" required>
                            </div>

                            <div class="ip-field">
                                <label for="confirm_password">Confirm New Password</label>
                                <input class="app-input ip-input" id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" required>
                            </div>
                        </div>

                        <div class="ip-actions-row">
                            <button class="app-btn app-btn-primary ip-btn" type="submit">
                                <?php echo ip_svg('save'); ?>
                                <span>Update Password</span>
                            </button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        </div>

        <aside class="ip-stack">
            <section class="card ip-card">
                <h3 class="ip-card-title">
                    <?php echo ip_svg('activity'); ?>
                    <span>Profile Readiness</span>
                </h3>

                <div class="ip-list">
                    <div class="ip-list-item">
                        <div class="ip-list-title">Login Email</div>
                        <div class="ip-list-meta"><?php echo h((string)($user['email'] ?? '—')); ?></div>
                    </div>

                    <div class="ip-list-item">
                        <div class="ip-list-title">Last Evaluation</div>
                        <div class="ip-list-meta"><?php echo h(cw_dt((string)($user['last_evaluated_at'] ?? ''), $pdo, $currentUserId)); ?></div>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <?php if ($missingCount > 0): ?>
                        <button
                            type="button"
                            class="app-badge app-badge-warn ip-missing-pill-button"
                            aria-expanded="false"
                            aria-controls="ip-missing-data-content"
                            onclick="return ipToggleMissingItems(this, 'ip-missing-data-content');">
                            <?php echo 'Missing ' . $missingCount . ' field' . ($missingCount === 1 ? '' : 's'); ?>
                        </button>
                    <?php else: ?>
                        <span class="app-badge app-badge-success ip-missing-pill-static">
                            Profile Complete
                        </span>
                    <?php endif; ?>
                </div>

                <div id="ip-missing-data-content" class="ip-collapse-content" hidden style="margin-top:16px; display:none;">
                    <?php if ($missingFields): ?>
                        <div class="ip-list">
                            <?php foreach ($missingFields as $item): ?>
                                <a class="ip-list-item ip-list-item-link" href="<?php echo h(ups_missing_field_url((array)$item, '/instructor/profile.php')); ?>">
                                    <div class="ip-list-title"><?php echo h((string)($item['label'] ?? 'Missing Field')); ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="ip-note">No missing data items.</div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<script>
function ipToggleMissingItems(trigger, targetId) {
    var target = document.getElementById(targetId);
    var isOpen;

    if (!target) {
        return false;
    }

    isOpen = !target.hasAttribute('hidden') && target.style.display !== 'none';

    if (isOpen) {
        target.setAttribute('hidden', 'hidden');
        target.style.display = 'none';
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    } else {
        target.removeAttribute('hidden');
        target.style.display = 'block';
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');
        }
    }

    return false;
}

(function () {
    var panel = document.getElementById('ip-missing-data-content');
    var weightKg = document.getElementById('weight_kg');
    var weightLb = document.getElementById('weight_lb_display');
    var heightCm = document.getElementById('height_cm');
    var heightIn = document.getElementById('height_in_display');

    if (panel && panel.hasAttribute('hidden')) {
        panel.style.display = 'none';
    }

    function formatOneDecimal(value) {
        return Math.round(value * 10) / 10;
    }

    function syncWeight() {
        var kg;
        if (!weightKg || !weightLb) {
            return;
        }

        kg = parseFloat(weightKg.value);
        if (isNaN(kg) || kg <= 0) {
            weightLb.value = '';
            return;
        }

        weightLb.value = formatOneDecimal(kg * 2.20462262) + ' lb';
    }

    function syncHeight() {
        var cm;
        if (!heightCm || !heightIn) {
            return;
        }

        cm = parseFloat(heightCm.value);
        if (isNaN(cm) || cm <= 0) {
            heightIn.value = '';
            return;
        }

        heightIn.value = formatOneDecimal(cm / 2.54) + ' in';
    }

    if (weightKg) {
        weightKg.addEventListener('input', syncWeight);
        syncWeight();
    }

    if (heightCm) {
        heightCm.addEventListener('input', syncHeight);
        syncHeight();
    }

    if (window.location.hash) {
        setTimeout(function () {
            var target = document.getElementById(window.location.hash.substring(1));
            if (target) {
                try {
                    target.scrollIntoView({behavior: 'smooth', block: 'center'});
                } catch (e) {
                    target.scrollIntoView(true);
                }
                if (typeof target.focus === 'function') {
                    try {
                        target.focus({preventScroll: true});
                    } catch (e) {
                        target.focus();
                    }
                }
            }
        }, 120);
    }
})();
</script>

<?php cw_footer(); ?>