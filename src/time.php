<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IPCA Time / Timezone Helpers
|--------------------------------------------------------------------------
| Storage = UTC (SSOT)
| UI = Localized time
|--------------------------------------------------------------------------
*/

if (!defined('CW_TIME_SYSTEM_POLICY_KEY')) {
    define('CW_TIME_SYSTEM_POLICY_KEY', 'system.default_timezone');
}

/* ---------------------------------------------------------
   VALIDATE TIMEZONE
--------------------------------------------------------- */
function cw_time_valid_timezone(?string $timezone): ?string
{
    $timezone = trim((string)$timezone);
    if ($timezone === '') return null;

    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        return null;
    }
}

/* ---------------------------------------------------------
   SYSTEM DEFAULT TIMEZONE
--------------------------------------------------------- */
function cw_system_timezone(PDO $pdo): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $stmt = $pdo->prepare("
            SELECT value
            FROM system_policy_values
            WHERE policy_key = :key
            LIMIT 1
        ");
        $stmt->execute([':key' => CW_TIME_SYSTEM_POLICY_KEY]);

        $tz = cw_time_valid_timezone($stmt->fetchColumn());

        if ($tz !== null) {
            $cached = $tz;
            return $tz;
        }

    } catch (Throwable $e) {}

    return $cached = 'UTC';
}

/* ---------------------------------------------------------
   USER TIMEZONE
--------------------------------------------------------- */
function cw_user_timezone(PDO $pdo, ?int $userId = null): ?string
{
    if ($userId === null && isset($_SESSION['cw_user_id'])) {
        $userId = (int)$_SESSION['cw_user_id'];
    }

    if (!$userId) return null;

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(u.timezone, up.timezone) AS tz
            FROM users u
            LEFT JOIN user_profiles up ON up.user_id = u.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);

        return cw_time_valid_timezone($stmt->fetchColumn());

    } catch (Throwable $e) {
        return null;
    }
}

/* ---------------------------------------------------------
   COHORT TIMEZONE
--------------------------------------------------------- */
function cw_cohort_timezone(PDO $pdo, ?int $cohortId = null): ?string
{
    if (!$cohortId) return null;

    try {
        $stmt = $pdo->prepare("
            SELECT timezone
            FROM cohorts
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $cohortId]);

        return cw_time_valid_timezone($stmt->fetchColumn());

    } catch (Throwable $e) {
        return null;
    }
}

/* ---------------------------------------------------------
   EFFECTIVE TIMEZONE
--------------------------------------------------------- */
function cw_effective_timezone(PDO $pdo, ?int $userId = null): string
{
    $userTz = cw_user_timezone($pdo, $userId);
    if ($userTz !== null) return $userTz;

    return cw_system_timezone($pdo);
}

/* ---------------------------------------------------------
   EFFECTIVE COHORT TIMEZONE
--------------------------------------------------------- */
function cw_effective_cohort_timezone(PDO $pdo, ?int $cohortId = null, ?int $userId = null): string
{
    $cohortTz = cw_cohort_timezone($pdo, $cohortId);
    if ($cohortTz !== null) return $cohortTz;

    return cw_effective_timezone($pdo, $userId);
}

/* ---------------------------------------------------------
   UTC → LOCAL DATETIME OBJECT
--------------------------------------------------------- */
function cw_dt_obj(?string $utc, string $tz): ?DateTimeImmutable
{
    if (!$utc || $utc === '0000-00-00 00:00:00') return null;

    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($tz));
    } catch (Throwable $e) {
        return null;
    }
}

/* ---------------------------------------------------------
   DATE ONLY
--------------------------------------------------------- */
function cw_date_only(?string $date): string
{
    if (!$date || $date === '0000-00-00') return '—';

    try {
        return (new DateTimeImmutable($date))->format('D M j, Y');
    } catch (Throwable $e) {
        return '—';
    }
}

/* ---------------------------------------------------------
   USER-FACING DATETIME
--------------------------------------------------------- */
function cw_dt(?string $utc, PDO $pdo, ?int $userId = null): string
{
    $tz = cw_effective_timezone($pdo, $userId);
    $dt = cw_dt_obj($utc, $tz);
    if (!$dt) return '—';

    return $dt->format('D M j, Y') . ' – ' . $dt->format('H:i') . ' LT';
}

/* ---------------------------------------------------------
   DATETIME WITH TZ LABEL
--------------------------------------------------------- */
function cw_dt_tz(?string $utc, PDO $pdo, ?int $userId = null): string
{
    $tz = cw_effective_timezone($pdo, $userId);
    $dt = cw_dt_obj($utc, $tz);
    if (!$dt) return '—';

    return $dt->format('D M j, Y') . ' – ' . $dt->format('H:i T');
}

/* ---------------------------------------------------------
   ADMIN/AUDIT FORMAT
--------------------------------------------------------- */
function cw_dt_admin(?string $utc, PDO $pdo, ?int $userId = null): string
{
    $tz = cw_effective_timezone($pdo, $userId);
    $local = cw_dt_obj($utc, $tz);
    $utcDt = cw_dt_obj($utc, 'UTC');

    if (!$local || !$utcDt) return '—';

    return $local->format('D M j, Y') . ' – ' . $local->format('H:i T')
        . ' (' . $utcDt->format('H:i') . ' UTC)';
}

/* ---------------------------------------------------------
   COHORT-AWARE DATETIME
--------------------------------------------------------- */
function cw_dt_cohort(?string $utc, PDO $pdo, ?int $cohortId = null, ?int $userId = null): string
{
    $tz = cw_effective_cohort_timezone($pdo, $cohortId, $userId);
    $dt = cw_dt_obj($utc, $tz);
    if (!$dt) return '—';

    return $dt->format('D M j, Y') . ' – ' . $dt->format('H:i') . ' LT';
}

/* ---------------------------------------------------------
   COHORT WITH TZ LABEL
--------------------------------------------------------- */
function cw_dt_cohort_tz(?string $utc, PDO $pdo, ?int $cohortId = null, ?int $userId = null): string
{
    $tz = cw_effective_cohort_timezone($pdo, $cohortId, $userId);
    $dt = cw_dt_obj($utc, $tz);
    if (!$dt) return '—';

    return $dt->format('D M j, Y') . ' – ' . $dt->format('H:i T');
}