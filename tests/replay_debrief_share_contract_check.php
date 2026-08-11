<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ReplayShareService.php';

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/ReplayShareService.php') ?: '';
$migration = file_get_contents($root . '/scripts/sql/2026_07_28_replay_debrief_shares.sql') ?: '';
$deliveryMigration = file_get_contents($root . '/scripts/sql/2026_08_10_replay_share_email_delivery.sql') ?: '';
$adminPage = file_get_contents($root . '/public/admin/master_logbook_intake.php') ?: '';
$publicPage = file_get_contents($root . '/public/replay-debrief.php') ?: '';
$replayPage = file_get_contents($root . '/public/admin/cockpit_recorder_replay.php') ?: '';
$replayApi = file_get_contents($root . '/public/api/recordings/replay.php') ?: '';
$audioApi = file_get_contents($root . '/public/admin/cockpit_recorder_audio.php') ?: '';
$debriefService = file_get_contents($root . '/src/FlightDebriefService.php') ?: '';

$checks = array(
    'opaque token and passcode are stored only as hashes' =>
        str_contains($migration, 'token_hash CHAR(64)')
        && str_contains($migration, 'passcode_hash VARCHAR(255)')
        && !preg_match('/\b(token|passcode)\s+VARCHAR/i', $migration)
        && str_contains($service, "password_hash(\$passcode, PASSWORD_DEFAULT)")
        && str_contains($service, "hash('sha256', trim(\$plainToken))"),
    'recipient shares coexist with selectable 12 24 or 48 hour expiry' =>
        str_contains($service, 'ALLOWED_EXPIRY_HOURS = array(12, 24, 48)')
        && str_contains($service, "modify('+' . \$expiryHours . ' hours')")
        && str_contains($service, 'listForDebrief')
        && str_contains($service, 'revokeShare'),
    'email delivery is recipient specific audited and excludes passcode' =>
        str_contains($deliveryMigration, 'ipca_replay_debrief_share_deliveries')
        && str_contains($deliveryMigration, 'recipient_email VARCHAR(254)')
        && str_contains($deliveryMigration, 'delivery_status')
        && str_contains($service, 'sendLinkEmail')
        && str_contains($service, 'The required passcode is intentionally not included in this email')
        && str_contains($service, 'recipient_email_hash'),
    'passcode abuse is rate limited' =>
        str_contains($service, 'failed_attempt_count')
        && str_contains($service, 'failed_attempt_count + 1 >= 5')
        && str_contains($service, 'INTERVAL 15 MINUTE'),
    'privacy acceptance is recorded server-side with hashed request metadata' =>
        str_contains($migration, 'privacy_accepted_at')
        && str_contains($migration, 'ip_hash CHAR(64)')
        && str_contains($migration, 'user_agent_hash CHAR(64)')
        && str_contains($service, "hash_hmac('sha256'")
        && str_contains($service, 'privacy_notice_version'),
    'mandatory notice contains the requested wording' =>
        str_contains(ReplayShareService::NOTICE_TEXT, 'strictly private and may not be copied, downloaded, distributed, published, shared, or shown to any third party')
        && str_contains(ReplayShareService::NOTICE_TEXT, 'You agree to treat all content as confidential')
        && str_contains(ReplayShareService::NOTICE_TEXT, 'expire automatically at the time shown')
        && str_contains($publicPage, 'accept_privacy'),
    'public authorization is scoped to the shared flight' =>
        str_contains($service, 'recordingBelongsToShare')
        && str_contains($service, 'flight_session_uid')
        && str_contains($replayApi, 'mediaGrant($id)')
        && str_contains($audioApi, 'mediaGrant($id)'),
    'instructor and supervisor roles can load replay samples and audio without share grant' =>
        str_contains(file_get_contents($root . '/src/auth.php') ?: '', 'function cw_user_can_access_flight_replay')
        && str_contains($replayApi, 'cw_user_can_access_flight_replay')
        && str_contains($audioApi, 'cw_user_can_access_flight_replay')
        && str_contains($replayPage, "'instructor', 'chief_instructor'"),
    'public audio supports inline streaming but not public attachment downloads' =>
        str_contains($audioApi, "\$isAdmin && (string)(\$_GET['download']")
        && str_contains($audioApi, "header('Content-Disposition: inline')")
        && str_contains($audioApi, "header('Accept-Ranges: bytes')"),
    'public replay excludes admin navigation and settings controls' =>
        str_contains($replayPage, 'IPCA_PUBLIC_REPLAY')
        && str_contains($replayPage, 'if (!$isPublicReplay)')
        && str_contains($replayPage, 'if (!$isPublicReplay): ?><button class="replay-icon-button replay-settings-button"')
        && str_contains($replayPage, 'if (!$isPublicReplay): ?><a class="replay-icon-button"'),
    'instructor UI supports recipient email expiry and individual revocation' =>
        str_contains($adminPage, 'Create Link &amp; Email Recipient')
        && str_contains($adminPage, 'name="expiry_hours"')
        && str_contains($adminPage, 'name="recipient_email"')
        && str_contains($adminPage, 'name="share_id"')
        && str_contains($adminPage, 'revoke_replay_share')
        && str_contains($adminPage, 'data-copy-input'),
    'privacy flow shows legs and AI debrief before media access' =>
        str_contains($publicPage, "\$stage === 'summary'")
        && str_contains($publicPage, 'Flight Legs')
        && str_contains($debriefService, 'allocated_tacho_duration_ms')
        && str_contains($debriefService, 'fuel_used_usg')
        && str_contains($publicPage, 'Overall Assessment')
        && str_contains($publicPage, 'Main Takeaways')
        && str_contains($publicPage, 'open_replay')
        && str_contains($service, "empty(\$grant['replay_opened'])"),
    'future debriefs use supportive direct instructor voice' =>
        str_contains($debriefService, 'v5-chief-instructor-voice')
        && str_contains($debriefService, 'speaking directly to the student')
        && str_contains($debriefService, 'Chief Flight Instructor')
        && str_contains($debriefService, 'Explain WHY corrections matter')
        && str_contains($debriefService, 'must not sound like an audit'),
);

$failed = array();
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== array()) {
    fwrite(STDERR, 'Failed replay sharing checks: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK: Replay debrief sharing contract checks passed.' . PHP_EOL;
