<?php
declare(strict_types=1);

function cw_progress_test_client_ip(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $value = trim((string)$_SERVER[$key]);
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $value);
                $value = trim((string)$parts[0]);
            }
            if ($value !== '') return $value;
        }
    }
    return '';
}

function cw_progress_test_ip_in_cidr(string $ip, string $cidr): bool
{
    if ($ip === '' || $cidr === '') return false;
    if (strpos($cidr, '/') === false) return $ip === $cidr;

    [$subnet, $mask] = explode('/', $cidr, 2);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    $mask = (int)$mask;

    if ($ipLong === false || $subnetLong === false || $mask < 0 || $mask > 32) return false;
    $maskLong = $mask === 0 ? 0 : (-1 << (32 - $mask));
    return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
}

function cw_progress_test_ip_matches_cidrs(string $ip, string $cidrs): bool
{
    $cidrs = trim($cidrs);
    if ($ip === '' || $cidrs === '') return false;
    $parts = preg_split('/[\s,;]+/', $cidrs);
    if (!is_array($parts)) return false;
    foreach ($parts as $rule) {
        $rule = trim((string)$rule);
        if ($rule !== '' && cw_progress_test_ip_in_cidr($ip, $rule)) return true;
    }
    return false;
}

function cw_progress_test_load_access_policy(PDO $pdo, int $userId, int $cohortId): ?array
{
    $st = $pdo->prepare("
        SELECT *
        FROM progress_test_access_policy
        WHERE
            (scope_type = 'user' AND scope_id = :user_id)
            OR (scope_type = 'cohort' AND scope_id = :cohort_id)
            OR (scope_type = 'global' AND scope_id IS NULL)
        ORDER BY
            CASE scope_type
                WHEN 'user' THEN 1
                WHEN 'cohort' THEN 2
                WHEN 'global' THEN 3
                ELSE 9
            END
        LIMIT 1
    ");
    $st->execute([':user_id' => $userId, ':cohort_id' => $cohortId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function cw_progress_test_access_session_key(int $cohortId): string
{
    return 'progress_test_access_ok_' . $cohortId;
}

function cw_progress_test_access_state(PDO $pdo, int $userId, int $cohortId): array
{
    $policy = cw_progress_test_load_access_policy($pdo, $userId, $cohortId);
    if (!$policy) return ['allowed' => true, 'policy' => null, 'mode' => 'any', 'ip_allowed' => true, 'pin_verified' => true];

    $mode = (string)($policy['mode'] ?? 'any');
    $allowedCidrs = (string)($policy['allowed_cidrs'] ?? '');
    $pinHash = (string)($policy['pin_hash'] ?? '');
    $clientIp = cw_progress_test_client_ip();
    $ipAllowed = $allowedCidrs !== '' && cw_progress_test_ip_matches_cidrs($clientIp, $allowedCidrs);
    $pinVerified = !empty($_SESSION[cw_progress_test_access_session_key($cohortId)]);

    $allowed = true;
    if ($mode === 'school_ip') {
        $allowed = $ipAllowed || ($pinHash !== '' && $pinVerified);
    } elseif ($mode === 'pin') {
        $allowed = $pinVerified;
    }

    return [
        'allowed' => $allowed,
        'policy' => $policy,
        'mode' => $mode,
        'ip_allowed' => $ipAllowed,
        'pin_verified' => $pinVerified,
        'client_ip' => $clientIp,
    ];
}

function cw_progress_test_verify_submitted_pin(PDO $pdo, int $userId, int $cohortId, string $pin): bool
{
    $policy = cw_progress_test_load_access_policy($pdo, $userId, $cohortId);
    $pinHash = is_array($policy) ? (string)($policy['pin_hash'] ?? '') : '';
    if ($pinHash === '' || trim($pin) === '' || !password_verify(trim($pin), $pinHash)) return false;
    $_SESSION[cw_progress_test_access_session_key($cohortId)] = 1;
    return true;
}

function cw_progress_test_load_global_policy(PDO $pdo): ?array
{
    $st = $pdo->query("
        SELECT *
        FROM progress_test_access_policy
        WHERE scope_type = 'global' AND scope_id IS NULL
        LIMIT 1
    ");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    return $row ?: null;
}

function cw_progress_test_save_global_allowed_cidrs(PDO $pdo, string $allowedCidrs, int $adminUserId): array
{
    $allowedCidrs = str_replace(["\r\n", "\r", "\n", ';'], ',', $allowedCidrs);
    $allowedCidrs = trim(preg_replace('/\s+/', ' ', $allowedCidrs) ?? '');
    $row = cw_progress_test_load_global_policy($pdo);
    if ($row) {
        $up = $pdo->prepare("
            UPDATE progress_test_access_policy
            SET allowed_cidrs = ?, mode = 'school_ip'
            WHERE id = ?
        ");
        $up->execute([$allowedCidrs, (int)$row['id']]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO progress_test_access_policy
              (scope_type, scope_id, mode, allowed_cidrs, pin_hash)
            VALUES ('global', NULL, 'school_ip', ?, '')
        ");
        $ins->execute([$allowedCidrs]);
    }
    return ['ok' => true, 'allowed_cidrs' => $allowedCidrs, 'admin_user_id' => $adminUserId];
}

function cw_progress_test_v4_access_allowed(PDO $pdo, int $studentUserId, int $cohortId, int $lessonId = 0): bool
{
    $access = cw_progress_test_access_state($pdo, $studentUserId, $cohortId);
    if (!empty($access['allowed'])) {
        return true;
    }

    if ($lessonId <= 0) {
        $st = $pdo->prepare("
            SELECT lesson_id
            FROM progress_tests_v2
            WHERE user_id = ? AND cohort_id = ?
              AND status IN ('preparing','ready','in_progress','processing')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$studentUserId, $cohortId]);
        $lessonId = (int)$st->fetchColumn();
    }

    if ($lessonId <= 0) {
        return false;
    }

    require_once __DIR__ . '/courseware_progression_v2.php';
    $engine = new CoursewareProgressionV2($pdo);
    $pageAccess = $engine->resolveProgressTestPageAccess($studentUserId, $cohortId, $lessonId);

    return !empty($pageAccess['allowed']);
}
