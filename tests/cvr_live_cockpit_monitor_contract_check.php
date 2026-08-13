<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/scripts/sql/2026_08_13_cvr_live_cockpit_monitoring.sql') ?: '';
$service = file_get_contents($root . '/src/CvrLiveCockpitMonitorService.php') ?: '';
$adminApi = file_get_contents($root . '/public/admin/api/live_cockpit_monitor.php') ?: '';
$audioApi = file_get_contents($root . '/public/admin/api/live_cockpit_monitor_audio.php') ?: '';
$deviceLeaseApi = file_get_contents($root . '/public/api/cvr/live_cockpit_monitor_lease.php') ?: '';
$deviceChunkApi = file_get_contents($root . '/public/api/cvr/live_cockpit_monitor_chunk.php') ?: '';
$cleanup = file_get_contents($root . '/scripts/cleanup_cvr_live_cockpit_monitor.php') ?: '';
$schedule = file_get_contents($root . '/public/admin/schedule.php') ?: '';
$scheduleJs = file_get_contents($root . '/public/admin/assets/flight_schedule.js') ?: '';
$audioManager = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/AudioRecorderManager.swift') ?: '';
$captureFanout = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/LiveAudioCaptureFanout.swift') ?: '';
$monitorStore = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/LiveCockpitMonitorStore.swift') ?: '';
$apiClient = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift') ?: '';
$statusView = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/StatusDashboardView.swift') ?: '';
$operationalViews = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift') ?: '';

$checks = array(
    'migration stores aggregate broadcasts, independent listeners, and ephemeral chunks' =>
        str_contains($migration, 'ipca_cvr_monitor_broadcasts')
        && str_contains($migration, 'ipca_cvr_monitor_listener_leases')
        && str_contains($migration, 'ipca_cvr_monitor_chunks')
        && str_contains($migration, 'UNIQUE KEY uk_cvr_monitor_chunk_sequence'),
    'staff APIs require schedule-editor authorization and CSRF' =>
        str_contains($adminApi, 'cw_require_flight_schedule_editor()')
        && str_contains($adminApi, "flight_schedule_csrf")
        && str_contains($adminApi, "hash_equals"),
    'device APIs use device authentication on every poll and upload' =>
        str_contains($deviceLeaseApi, 'DeviceAuthService')
        && str_contains($deviceLeaseApi, 'requireDevice()')
        && str_contains($deviceChunkApi, 'DeviceAuthService')
        && str_contains($deviceChunkApi, 'requireDevice()'),
    'lease expiry and multiple listeners drive one aggregate broadcast' =>
        str_contains($service, 'LEASE_SECONDS = 15')
        && str_contains($service, 'activeListenerCount')
        && str_contains($service, 'no_active_listeners')
        && str_contains($service, 'heartbeat_expired'),
    'dispatch, Operational Session, and device ownership gate all chunks' =>
        str_contains($service, 'operational_session_uuid')
        && str_contains($service, "b.device_id = ?")
        && str_contains($service, 'uploaded_by_device_id')
        && str_contains($service, 'activeListenerCount'),
    'chunk uploads are idempotent and immutable' =>
        str_contains($service, 'already_present')
        && str_contains($service, 'sequence already contains different audio')
        && str_contains($migration, 'uk_cvr_monitor_chunk_sequence'),
    'audio serving is no-store and path confined' =>
        str_contains($audioApi, 'Cache-Control: no-store')
        && str_contains($audioApi, 'X-Content-Type-Options: nosniff')
        && str_contains($service, "str_starts_with(\$path, \$root . DIRECTORY_SEPARATOR)"),
    'listener start, reconnect, and stop are audited' =>
        str_contains($service, 'cvr.live_monitor.listener_started')
        && str_contains($service, 'cvr.live_monitor.listener_reconnected')
        && str_contains($service, 'cvr.live_monitor.listener_stopped')
        && str_contains($service, 'AuditEventService'),
    'ephemeral chunks expire and have a cleanup command' =>
        str_contains($service, 'CHUNK_TTL_SECONDS = 120')
        && str_contains($service, 'cleanupExpiredChunks')
        && str_contains($cleanup, 'cleanupExpiredChunks'),
    'single input tap isolates evidence from a bounded lossy monitor branch' =>
        substr_count($captureFanout, 'installTap(onBus: 0') === 1
        && str_contains($captureFanout, 'evidenceQueue')
        && str_contains($captureFanout, 'pendingMonitorBuffers < 8')
        && str_contains($captureFanout, 'Priority B is intentionally fail-open'),
    'capture refactor is hardware gated with immediate legacy fallback' =>
        str_contains($audioManager, 'configureEngineCaptureAllowed')
        && str_contains($audioManager, 'engineCaptureAllowed')
        && str_contains($audioManager, 'Legacy recorder fallback')
        && str_contains($service, 'CW_CVR_LIVE_MONITOR_DEVICE_ALLOWLIST'),
    'monitor failures cannot call primary recorder lifecycle controls' =>
        !preg_match('/recorder\\?*\\.?(stop|record)\\s*\\(/', $monitorStore)
        && !str_contains($monitorStore, 'rotateSegment')
        && str_contains($monitorStore, 'Intentionally no durable retry'),
    'device polling enforces recording, session, auth, network, and lease conditions' =>
        str_contains($monitorStore, 'audio.isRecording')
        && str_contains($monitorStore, 'currentOperationalSessionUUID')
        && str_contains($monitorStore, 'deviceCredential')
        && str_contains($monitorStore, 'network.isSatisfied')
        && str_contains($monitorStore, 'Date().timeIntervalSince(lastValidLeaseAt) >= 15'),
    'AAC chunks are low bandwidth, short, and single-flight uploaded' =>
        str_contains($captureFanout, 'bitRate: 24_000')
        && str_contains($captureFanout, 'monitorWriter.duration >= 4')
        && str_contains($monitorStore, 'uploadInFlight')
        && str_contains($apiClient, 'uploadLiveCockpitMonitorChunk'),
    'crew receives a persistent live broadcast notice without controls' =>
        str_contains($statusView, 'LIVE AUDIO STREAMING')
        && str_contains($operationalViews, 'LIVE AUDIO STREAMING')
        && !str_contains($statusView, 'Stop Broadcast')
        && !str_contains($operationalViews, 'Stop Broadcast'),
    'scheduler player exposes required states and heartbeat reconnect' =>
        str_contains($schedule, 'id="flightLiveAudioStart"')
        && str_contains($scheduleJs, "'Waiting for recorder'")
        && str_contains($scheduleJs, "'Buffering'")
        && str_contains($scheduleJs, "'Live'")
        && str_contains($scheduleJs, "'Reconnecting…'")
        && str_contains($scheduleJs, "'Ended'")
        && str_contains($scheduleJs, 'sendLiveMonitorHeartbeat')
        && str_contains($scheduleJs, 'stopLiveMonitor({ keepalive: true })'),
);

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== array()) {
    fwrite(STDERR, "cvr_live_cockpit_monitor_contract_check FAILED:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo 'cvr_live_cockpit_monitor_contract_check OK (' . count($checks) . " checks)\n";
