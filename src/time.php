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

    $utc = trim($utc);
    try {
        if (preg_match('/\.\d+$/', $utc)) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $utc, new DateTimeZone('UTC'));
            if ($parsed instanceof DateTimeImmutable) {
                return $parsed->setTimezone(new DateTimeZone($tz));
            }
        }
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

function cw_dt_cohort_tz(?string $utc, PDO $pdo, ?int $cohortId = null, ?int $userId = null): string
{
    $tz = cw_effective_cohort_timezone($pdo, $cohortId, $userId);
    $dt = cw_dt_obj($utc, $tz);
    if (!$dt) return '—';

    return $dt->format('D M j, Y') . ' – ' . $dt->format('H:i T');
}

/* ---------------------------------------------------------
   LOGBOOK LOCAL TIME (UTC storage, local display)
--------------------------------------------------------- */
function cw_logbook_time(?string $utc, string $timezone): string
{
    $dt = cw_dt_obj($utc, $timezone);
    return $dt === null ? '—' : $dt->format('H:i');
}

function cw_logbook_datetime(?string $utc, string $timezone): string
{
    $dt = cw_dt_obj($utc, $timezone);
    return $dt === null ? '—' : $dt->format('M j, Y H:i:s') . ' LT';
}

function cw_logbook_date(?string $utc, string $timezone): ?string
{
    $dt = cw_dt_obj($utc, $timezone);
    return $dt === null ? null : $dt->format('Y-m-d');
}

function cw_local_input_to_utc(string $localInput, string $timezone): ?string
{
    $localInput = trim($localInput);
    if ($localInput === '') {
        return null;
    }
    $timezone = cw_time_valid_timezone($timezone) ?? 'UTC';
    $formats = array('Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i');
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $localInput, new DateTimeZone($timezone));
        if ($dt instanceof DateTimeImmutable) {
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function cw_airport_timezone(PDO $pdo, string $icao): ?string
{
    static $cache = array();
    $icao = strtoupper(trim($icao));
    if ($icao === '') {
        return null;
    }
    if (array_key_exists($icao, $cache)) {
        return $cache[$icao];
    }

    $known = array(
        'KTRM' => 'America/Los_Angeles',
        'KPSP' => 'America/Los_Angeles',
        'KHMT' => 'America/Los_Angeles',
        'KUDD' => 'America/Los_Angeles',
        'KBNG' => 'America/Los_Angeles',
        'KRAL' => 'America/Los_Angeles',
        'KSBD' => 'America/Los_Angeles',
        'KBLH' => 'America/Los_Angeles',
        'KCRQ' => 'America/Los_Angeles',
        'KMYF' => 'America/Los_Angeles',
        'KSAN' => 'America/Los_Angeles',
        'KONT' => 'America/Los_Angeles',
        'KLAX' => 'America/Los_Angeles',
        'EBAW' => 'Europe/Brussels',
        'EBBR' => 'Europe/Brussels',
        'EBLG' => 'Europe/Brussels',
        'EBOS' => 'Europe/Brussels',
        'EBKT' => 'Europe/Brussels',
    );
    if (isset($known[$icao])) {
        return $cache[$icao] = $known[$icao];
    }

    try {
        $stmt = $pdo->prepare('SELECT region, country FROM ipca_airports WHERE icao_identifier = ? LIMIT 1');
        $stmt->execute(array($icao));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $timezone = cw_region_timezone((string)($row['region'] ?? ''), (string)($row['country'] ?? ''));
            if ($timezone !== null) {
                return $cache[$icao] = $timezone;
            }
        }
    } catch (Throwable $e) {
    }

    return $cache[$icao] = null;
}

function cw_region_timezone(string $region, string $country): ?string
{
    $country = strtolower(trim($country));
    $region = strtolower(trim($region));
    if (in_array($country, array('united states', 'usa', 'us', 'united states of america'), true)) {
        if (str_contains($region, 'california') || in_array($region, array('ca', 'calif'), true)) {
            return 'America/Los_Angeles';
        }
        if (str_contains($region, 'arizona') || $region === 'az') {
            return 'America/Phoenix';
        }
    }
    if ($country === 'belgium') {
        return 'Europe/Brussels';
    }
    return null;
}

function cw_logbook_display_timezone(PDO $pdo, ?int $aircraftId = null, ?string $departureAirport = null, ?string $arrivalAirport = null): string
{
    $aircraftTimezone = cw_aircraft_operational_timezone($pdo, $aircraftId);
    if ($aircraftTimezone !== 'UTC') {
        return $aircraftTimezone;
    }
    foreach (array($departureAirport, $arrivalAirport) as $icao) {
        $airportTimezone = cw_airport_timezone($pdo, (string)$icao);
        if ($airportTimezone !== null) {
            return $airportTimezone;
        }
    }
    return 'UTC';
}

function cw_aircraft_operational_timezone(PDO $pdo, ?int $aircraftId = null): string
{
    static $cache = array();
    $key = 'id:' . (string)($aircraftId ?? 0);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if ($aircraftId === null || $aircraftId <= 0) {
        return $cache[$key] = 'UTC';
    }
    try {
        require_once __DIR__ . '/AircraftOperationalConfigService.php';
        $config = (new AircraftOperationalConfigService($pdo))->configForAircraft($aircraftId);
        $timezone = cw_time_valid_timezone((string)($config['timezone_identifier'] ?? 'UTC'));
        return $cache[$key] = ($timezone ?? 'UTC');
    } catch (Throwable $e) {
        return $cache[$key] = 'UTC';
    }
}

function cw_aircraft_operational_timezone_by_registration(PDO $pdo, string $registration): string
{
    static $cache = array();
    $registration = strtoupper(trim($registration));
    if ($registration === '') {
        return 'UTC';
    }
    if (isset($cache[$registration])) {
        return $cache[$registration];
    }
    try {
        $stmt = $pdo->prepare('SELECT id FROM ipca_aircraft_devices WHERE UPPER(registration) = ? ORDER BY active DESC, id DESC LIMIT 1');
        $stmt->execute(array($registration));
        $aircraftId = (int)$stmt->fetchColumn();
        return $cache[$registration] = cw_aircraft_operational_timezone($pdo, $aircraftId > 0 ? $aircraftId : null);
    } catch (Throwable $e) {
        return $cache[$registration] = 'UTC';
    }
}