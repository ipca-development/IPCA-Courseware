<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/tv_screen_pa.php';
require_once __DIR__ . '/../../../src/tv_kiosk_config.php';
require_once __DIR__ . '/../../../src/tv_adsb_status.php';

cw_require_admin();

$user = cw_current_user($pdo);
$uid = (int)($user['id'] ?? 0);

function tv_dt_or_null(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

function tv_admin_dt(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value . ' UTC');
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function tv_label_dt(?string $value): string
{
    if (!$value) {
        return 'Open';
    }
    $ts = strtotime($value . ' UTC');
    return $ts ? date('M j, Y H:i', $ts) : 'Open';
}

function tv_clean_enum(string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim($value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function tv_message_types(): array
{
    return array('standard', 'urgent', 'schedule', 'night', 'aircraft', 'aircraft_board', 'radar');
}

function tv_message_type_label(string $type): string
{
    return match (strtolower(trim($type))) {
        'aircraft' => 'Aircraft (ADS-B track)',
        'aircraft_board' => 'Aircraft board (fleet)',
        'radar' => 'Radar (KTRM scope)',
        default => ucfirst($type),
    };
}

function tv_messages_table_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'tv_screen_messages'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function tv_voice_column_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'voice'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function tv_aircraft_columns_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'aircraft_hex'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function tv_aircraft_type_column_ready(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM tv_screen_messages LIKE 'aircraft_type'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function tv_svg(string $name): string
{
    switch ($name) {
        case 'plus':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>';
        case 'settings':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7Z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 15a7.8 7.8 0 0 0 .1-1 7.8 7.8 0 0 0-.1-1l2-1.5-2-3.5-2.4 1a7.5 7.5 0 0 0-1.7-1L15 4.5h-6l-.3 2.5a7.5 7.5 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a7.8 7.8 0 0 0-.1 1 7.8 7.8 0 0 0 .1 1l-2 1.5 2 3.5 2.4-1a7.5 7.5 0 0 0 1.7 1L9 19.5h6l.3-2.5a7.5 7.5 0 0 0 1.7-1l2.4 1 2-3.5-2-1.5Z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
        case 'display':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v10H4zM8 20h8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        case 'open':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5h5v5M10 14 19 5M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        default:
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
    }
}

function tv_status_badge(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'active' => 'app-badge app-badge-success',
        'draft' => 'app-badge app-badge-warn',
        'inactive' => 'app-badge app-badge-muted',
        'archived' => 'app-badge app-badge-neutral',
        default => 'app-badge app-badge-neutral',
    };
}

function tv_type_badge(string $type): string
{
    $type = strtolower(trim($type));
    return match ($type) {
        'urgent' => 'app-badge app-badge-danger',
        'schedule' => 'app-badge app-badge-warn',
        'night' => 'app-badge app-badge-sky',
        'aircraft' => 'app-badge app-badge-success',
        'aircraft_board' => 'app-badge app-badge-success',
        'radar' => 'app-badge app-badge-sky',
        default => 'app-badge app-badge-accent',
    };
}

function tv_screen_presets(): array
{
    return array(
        'main' => array('label' => 'Main (flip messages / playlist)', 'route' => '/tv/flipboard.php?screen=main'),
        'aircraft' => array('label' => 'Aircraft (fleet board or playlist)', 'route' => '/tv/flipboard.php?screen=aircraft'),
        'radar' => array('label' => 'Radar (scope only or playlist)', 'route' => '/tv/flipboard.php?screen=radar'),
    );
}

$defaults = array(
    'id' => 0,
    'screen_key' => 'main',
    'message_type' => 'standard',
    'title' => '',
    'body' => '',
    'priority' => 10,
    'starts_at' => null,
    'ends_at' => null,
    'display_duration_seconds' => 12,
    'announce_audio_enabled' => 0,
    'voice_text' => '',
    'voice' => tv_pa_default_voice(),
    'audio_url' => '',
    'aircraft_hex' => '',
    'aircraft_label' => '',
    'aircraft_type' => '',
    'aircraft_home_airport' => 'KTRM',
    'status' => 'draft',
);

$settings = tv_kiosk_config();
$fleetAircraftText = tv_kiosk_fleet_aircraft_to_text(is_array($settings['fleet_aircraft'] ?? null) ? $settings['fleet_aircraft'] : array());
$fleetAircraftCount = count(tv_kiosk_normalize_fleet_aircraft(is_array($settings['fleet_aircraft'] ?? null) ? $settings['fleet_aircraft'] : array(), (string)($settings['home_airport'] ?? 'KTRM')));

$notice = '';
$error = '';
$tableReady = tv_messages_table_ready($pdo);
$voiceColumnReady = $tableReady && tv_voice_column_ready($pdo);
$aircraftColumnsReady = $tableReady && tv_aircraft_columns_ready($pdo);
$aircraftTypeColumnReady = $tableReady && tv_aircraft_type_column_ready($pdo);
$paVoices = tv_pa_voices();
$airportOptions = tv_adsb_airports();
$openModal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_settings') {
            $homeAirport = strtoupper(trim((string)($_POST['home_airport'] ?? tv_adsb_default_home_airport()))) ?: tv_adsb_default_home_airport();
            tv_kiosk_config_save(array(
                'screen_key' => preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($_POST['screen_key'] ?? 'main'))) ?: 'main',
                'default_mode' => tv_clean_enum((string)($_POST['default_mode'] ?? ''), ['standard', 'schedule', 'night'], 'standard'),
                'audio_enabled' => isset($_POST['audio_enabled']) ? 1 : 0,
                'night_mode' => isset($_POST['night_mode']) ? 1 : 0,
                'poll_ms' => max(5000, min(10000, (int)($_POST['poll_ms'] ?? 7000))),
                'aircraft_poll_ms' => max(10000, min(60000, (int)($_POST['aircraft_poll_ms'] ?? 15000))),
                'default_pa_voice' => tv_pa_voice_or_default((string)($_POST['default_pa_voice'] ?? '')),
                'gate_label' => trim((string)($_POST['gate_label'] ?? 'SPC Gate')) ?: 'SPC Gate',
                'gate_lat' => (float)($_POST['gate_lat'] ?? tv_adsb_default_gate()['lat']),
                'gate_lon' => (float)($_POST['gate_lon'] ?? tv_adsb_default_gate()['lon']),
                'gate_radius_nm' => max(0.05, min(2.0, (float)($_POST['gate_radius_nm'] ?? tv_adsb_default_gate()['radius_nm']))),
                'assume_parked_off_radar' => isset($_POST['assume_parked_off_radar']) ? 1 : 0,
                'home_airport' => $homeAirport,
                'fleet_aircraft' => tv_kiosk_parse_fleet_aircraft_text((string)($_POST['fleet_aircraft_text'] ?? ''), $homeAirport),
                'kiosk_notes' => trim((string)($_POST['kiosk_notes'] ?? '')),
            ));
            redirect('/admin/tv_screens/index.php?settings=1');
        }

        if (!$tableReady) {
            throw new RuntimeException('TV screen database table is not installed yet. Apply scripts/sql/2026_05_20_tv_screen_messages.sql first.');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid message.');
            }
            $stmt = $pdo->prepare('DELETE FROM tv_screen_messages WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            redirect('/admin/tv_screens/index.php?deleted=1');
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $status = tv_clean_enum((string)($_POST['status'] ?? ''), ['draft', 'active', 'inactive', 'archived'], 'inactive');
            if ($id <= 0) {
                throw new RuntimeException('Invalid message.');
            }
            $stmt = $pdo->prepare('UPDATE tv_screen_messages SET status = ? WHERE id = ? LIMIT 1');
            $stmt->execute([$status, $id]);
            redirect('/admin/tv_screens/index.php?updated=1');
        }

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $screenKey = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($_POST['screen_key'] ?? 'main'))) ?: 'main';
            $type = tv_clean_enum((string)($_POST['message_type'] ?? ''), tv_message_types(), 'standard');
            $status = tv_clean_enum((string)($_POST['status'] ?? ''), ['draft', 'active', 'inactive', 'archived'], 'draft');
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $priority = max(0, min(100, (int)($_POST['priority'] ?? 10)));
            $duration = max(5, min(300, (int)($_POST['display_duration_seconds'] ?? 12)));
            $startsAt = tv_dt_or_null((string)($_POST['starts_at'] ?? ''));
            $endsAt = tv_dt_or_null((string)($_POST['ends_at'] ?? ''));
            $announce = isset($_POST['announce_audio_enabled']) ? 1 : 0;
            $voiceText = trim((string)($_POST['voice_text'] ?? ''));
            $voice = tv_pa_voice_or_default((string)($_POST['voice'] ?? ($settings['default_pa_voice'] ?? '')));
            $audioUrl = trim((string)($_POST['audio_url'] ?? ''));
            $aircraftHex = tv_adsb_normalize_hex((string)($_POST['aircraft_hex'] ?? ''));
            $aircraftLabel = tv_adsb_normalize_label((string)($_POST['aircraft_label'] ?? ''));
            $aircraftType = tv_adsb_normalize_type((string)($_POST['aircraft_type'] ?? ''));
            $aircraftHomeAirport = tv_adsb_normalize_home_airport((string)($_POST['aircraft_home_airport'] ?? ''));

            if ($type === 'aircraft') {
                if ($aircraftHex === '') {
                    throw new RuntimeException('Aircraft hex code is required for ADS-B tracking.');
                }
                if ($aircraftLabel === '') {
                    throw new RuntimeException('Preferred aircraft name is required (for example N153PC).');
                }
                $title = $aircraftLabel;
                $body = $aircraftHex;
            } elseif (in_array($type, array('radar', 'aircraft_board'), true)) {
                if ($title === '') {
                    $title = $type === 'radar' ? 'LIVE RADAR' : 'AIRCRAFT OPS';
                }
                if ($body === '') {
                    $body = '—';
                }
            } elseif ($title === '' || $body === '') {
                throw new RuntimeException('Title and board body are required.');
            }

            if ($id > 0) {
                if ($voiceColumnReady && $aircraftColumnsReady && $aircraftTypeColumnReady) {
                    $stmt = $pdo->prepare("
                        UPDATE tv_screen_messages
                        SET screen_key = ?, message_type = ?, title = ?, body = ?, aircraft_hex = ?, aircraft_label = ?, aircraft_type = ?, aircraft_home_airport = ?, priority = ?,
                            starts_at = ?, ends_at = ?, display_duration_seconds = ?,
                            announce_audio_enabled = ?, voice_text = ?, voice = ?, audio_url = ?, status = ?
                        WHERE id = ? LIMIT 1
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body,
                        $aircraftHex !== '' ? $aircraftHex : null,
                        $aircraftLabel !== '' ? $aircraftLabel : null,
                        $aircraftType !== '' ? $aircraftType : null,
                        $aircraftHomeAirport !== '' ? $aircraftHomeAirport : null,
                        $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $voice, $audioUrl !== '' ? $audioUrl : null,
                        $status, $id,
                    ]);
                } elseif ($voiceColumnReady && $aircraftColumnsReady) {
                    $stmt = $pdo->prepare("
                        UPDATE tv_screen_messages
                        SET screen_key = ?, message_type = ?, title = ?, body = ?, aircraft_hex = ?, aircraft_label = ?, aircraft_home_airport = ?, priority = ?,
                            starts_at = ?, ends_at = ?, display_duration_seconds = ?,
                            announce_audio_enabled = ?, voice_text = ?, voice = ?, audio_url = ?, status = ?
                        WHERE id = ? LIMIT 1
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body,
                        $aircraftHex !== '' ? $aircraftHex : null,
                        $aircraftLabel !== '' ? $aircraftLabel : null,
                        $aircraftHomeAirport !== '' ? $aircraftHomeAirport : null,
                        $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $voice, $audioUrl !== '' ? $audioUrl : null,
                        $status, $id,
                    ]);
                } elseif ($voiceColumnReady) {
                    $stmt = $pdo->prepare("
                        UPDATE tv_screen_messages
                        SET screen_key = ?, message_type = ?, title = ?, body = ?, priority = ?,
                            starts_at = ?, ends_at = ?, display_duration_seconds = ?,
                            announce_audio_enabled = ?, voice_text = ?, voice = ?, audio_url = ?, status = ?
                        WHERE id = ? LIMIT 1
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body, $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $voice, $audioUrl !== '' ? $audioUrl : null,
                        $status, $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tv_screen_messages
                        SET screen_key = ?, message_type = ?, title = ?, body = ?, priority = ?,
                            starts_at = ?, ends_at = ?, display_duration_seconds = ?,
                            announce_audio_enabled = ?, voice_text = ?, audio_url = ?, status = ?
                        WHERE id = ? LIMIT 1
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body, $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $audioUrl !== '' ? $audioUrl : null,
                        $status, $id,
                    ]);
                }
            } else {
                if ($voiceColumnReady && $aircraftColumnsReady && $aircraftTypeColumnReady) {
                    $stmt = $pdo->prepare("
                        INSERT INTO tv_screen_messages (
                            screen_key, message_type, title, body, aircraft_hex, aircraft_label, aircraft_type, aircraft_home_airport,
                            priority, starts_at, ends_at, display_duration_seconds, announce_audio_enabled, voice_text, voice, audio_url,
                            status, created_by
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body,
                        $aircraftHex !== '' ? $aircraftHex : null,
                        $aircraftLabel !== '' ? $aircraftLabel : null,
                        $aircraftType !== '' ? $aircraftType : null,
                        $aircraftHomeAirport !== '' ? $aircraftHomeAirport : null,
                        $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $voice, $audioUrl !== '' ? $audioUrl : null,
                        $status, $uid > 0 ? $uid : null,
                    ]);
                } elseif ($voiceColumnReady && $aircraftColumnsReady) {
                    $stmt = $pdo->prepare("
                        INSERT INTO tv_screen_messages (
                            screen_key, message_type, title, body, aircraft_hex, aircraft_label, aircraft_home_airport,
                            priority, starts_at, ends_at, display_duration_seconds, announce_audio_enabled, voice_text, voice, audio_url,
                            status, created_by
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body,
                        $aircraftHex !== '' ? $aircraftHex : null,
                        $aircraftLabel !== '' ? $aircraftLabel : null,
                        $aircraftHomeAirport !== '' ? $aircraftHomeAirport : null,
                        $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $voice, $audioUrl !== '' ? $audioUrl : null,
                        $status, $uid > 0 ? $uid : null,
                    ]);
                } elseif ($voiceColumnReady) {
                    $stmt = $pdo->prepare("
                        INSERT INTO tv_screen_messages (
                            screen_key, message_type, title, body, priority, starts_at, ends_at,
                            display_duration_seconds, announce_audio_enabled, voice_text, voice, audio_url,
                            status, created_by
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body, $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $voice, $audioUrl !== '' ? $audioUrl : null,
                        $status, $uid > 0 ? $uid : null,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO tv_screen_messages (
                            screen_key, message_type, title, body, priority, starts_at, ends_at,
                            display_duration_seconds, announce_audio_enabled, voice_text, audio_url,
                            status, created_by
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $screenKey, $type, $title, $body, $priority, $startsAt, $endsAt, $duration,
                        $announce, $voiceText !== '' ? $voiceText : null, $audioUrl !== '' ? $audioUrl : null,
                        $status, $uid > 0 ? $uid : null,
                    ]);
                }
            }

            redirect('/admin/tv_screens/index.php?updated=1');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($action === 'save') {
            $openModal = 'message';
        } elseif ($action === 'save_settings') {
            $openModal = 'settings';
        }
    }
}

if (isset($_GET['updated'])) {
    $notice = 'TV screen message saved.';
}
if (isset($_GET['deleted'])) {
    $notice = 'TV screen message deleted.';
}
if (isset($_GET['settings'])) {
    $notice = 'Screen settings saved.';
}

$form = $defaults;
if ($error !== '' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $form = array_merge($defaults, $_POST);
    $form['id'] = (int)($_POST['id'] ?? 0);
    $form['announce_audio_enabled'] = isset($_POST['announce_audio_enabled']) ? 1 : 0;
    $form['voice'] = tv_pa_voice_or_default((string)($_POST['voice'] ?? ''));
    $form['aircraft_hex'] = tv_adsb_normalize_hex((string)($_POST['aircraft_hex'] ?? ''));
    $form['aircraft_label'] = tv_adsb_normalize_label((string)($_POST['aircraft_label'] ?? ''));
    $form['aircraft_type'] = tv_adsb_normalize_type((string)($_POST['aircraft_type'] ?? ''));
    $form['aircraft_home_airport'] = tv_adsb_normalize_home_airport((string)($_POST['aircraft_home_airport'] ?? ''));
}

$messages = array();
if ($tableReady) {
    try {
        $stmt = $pdo->query("
            SELECT m.*, u.name AS creator_name
            FROM tv_screen_messages m
            LEFT JOIN users u ON u.id = m.created_by
            ORDER BY
              CASE WHEN m.status = 'active' THEN 0 WHEN m.status = 'draft' THEN 1 ELSE 2 END,
              CASE WHEN m.message_type = 'urgent' OR m.priority >= 90 THEN 0 ELSE 1 END,
              m.priority DESC, m.updated_at DESC, m.id DESC
        ");
        $messages = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : ('Unable to load TV messages: ' . $e->getMessage());
    }
}

$activeCount = 0;
foreach ($messages as $m) {
    if (($m['status'] ?? '') === 'active') {
        $activeCount += 1;
    }
}

$previewUrl = '/tv/flipboard.php?screen=' . rawurlencode((string)$settings['screen_key']);
if ((int)$settings['night_mode'] === 1) {
    $previewUrl .= '&mode=night';
}

cw_header('TV Flip Board');
?>
<style>
.tv-page{display:block;max-width:100%;overflow-x:hidden}
.tv-page .app-section-hero{margin-bottom:20px}
.tv-hero-head{display:flex;align-items:flex-start;justify-content:space-between;gap:24px}
.tv-hero-copy{min-width:0}
.tv-hero-title{margin:0;font-size:34px;line-height:1.02;letter-spacing:-0.04em;font-weight:760;color:#fff}
.tv-hero-text{max-width:820px;margin:14px 0 0;color:rgba(255,255,255,.82);font-size:15px;line-height:1.65}
.tv-hero-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end}
.tv-action{height:40px;display:inline-flex;align-items:center;gap:10px;padding:0 16px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.08);color:#fff;text-decoration:none;font-size:13px;font-weight:650;cursor:pointer}
.tv-action:hover{background:rgba(255,255,255,.13)}
.tv-action svg{width:16px;height:16px;flex:0 0 16px}
.tv-hero-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:22px}
.tv-stat-chip{min-height:88px;padding:16px 18px;border-radius:18px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.09)}
.tv-stat-label{color:rgba(255,255,255,.68);font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:680}
.tv-stat-value{margin-top:10px;color:#fff;font-size:31px;line-height:1;font-weight:760}
.tv-alert{margin-bottom:16px;padding:12px 14px;border-radius:14px;font-weight:700}
.tv-alert.ok{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
.tv-alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.tv-list-head-card{padding:18px 20px;margin:0 0 14px}
.tv-list-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.tv-list-title{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:730;color:var(--text-strong)}
.tv-list-count{display:inline-flex;align-items:center;min-height:34px;padding:0 14px;border-radius:999px;background:#f3f6fb;border:1px solid rgba(15,23,42,.08);color:#71809a;font-size:13px;font-weight:700}
.tv-card-list{display:grid;grid-template-columns:1fr;gap:16px;max-width:100%}
.tv-msg-card{padding:22px;max-width:100%;overflow:hidden}
.tv-msg-inner{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,360px);gap:18px;align-items:start}
.tv-msg-main{min-width:0}
.tv-msg-title{margin:0 0 8px;font-size:22px;font-weight:760;letter-spacing:-0.03em;color:var(--text-strong);word-break:break-word}
.tv-msg-body{margin:0;color:var(--text-muted);font-size:14px;line-height:1.55;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
.tv-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px;margin-top:14px}
.tv-meta-label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:#8a97ab;font-weight:700}
.tv-meta-value{margin-top:5px;font-size:14px;font-weight:630;color:var(--text-strong);overflow-wrap:anywhere}
.tv-badge-row{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}
.tv-card-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:14px}
.tv-empty{padding:34px 28px}
.tv-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1200;display:none;align-items:center;justify-content:center;padding:20px}
.tv-modal-backdrop.is-open{display:flex}
.tv-modal{width:min(720px,100%);max-height:calc(100vh - 40px);overflow:auto;background:#fff;border-radius:22px;border:1px solid rgba(15,23,42,.08);box-shadow:0 24px 60px rgba(15,23,42,.18)}
.tv-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px;border-bottom:1px solid rgba(15,23,42,.07)}
.tv-modal-title{margin:0;font-size:22px;font-weight:760;color:var(--text-strong)}
.tv-modal-sub{margin-top:6px;color:var(--text-muted);font-size:14px;line-height:1.5}
.tv-modal-body{padding:20px 22px 22px}
.tv-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.tv-field{display:flex;flex-direction:column;gap:7px;min-width:0}
.tv-field.span-2{grid-column:1/-1}
.tv-field-label{font-size:12px;font-weight:670;color:var(--text-muted)}
.tv-field-hint{margin:0;font-size:12px;line-height:1.5;color:#8a97ab}
.tv-check-row{display:flex;align-items:center;gap:10px;min-height:44px}
.tv-check-row input{width:auto}
.tv-pa-preview-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.tv-modal-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:16px;border-top:1px solid rgba(15,23,42,.07)}
@media(max-width:1100px){.tv-hero-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.tv-msg-inner{grid-template-columns:1fr}.tv-badge-row,.tv-card-actions{justify-content:flex-start}}
@media(max-width:700px){.tv-hero-head{flex-direction:column}.tv-hero-actions{justify-content:flex-start}.tv-form-grid{grid-template-columns:1fr}.tv-field.span-2{grid-column:auto}.tv-meta-grid{grid-template-columns:1fr}}
</style>

<div class="tv-page">
  <section class="app-section-hero">
    <div class="hero-overline">Operations</div>
    <div class="tv-hero-head">
      <div class="tv-hero-copy">
        <h2 class="tv-hero-title">TV Flip Board</h2>
        <p class="tv-hero-text">
          Manage airport-style split-flap messages for Mac Mini kiosk displays. Control operational announcements, urgent overrides, schedules, and PA audio from one surface.
        </p>
      </div>
      <div class="tv-hero-actions">
        <button type="button" class="tv-action" id="tvOpenSettings" data-modal="settings">
          <?= tv_svg('settings') ?><span>Settings</span>
        </button>
        <button type="button" class="tv-action" id="tvOpenCreate">
          <?= tv_svg('plus') ?><span>Add New Message</span>
        </button>
        <a class="tv-action" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">
          <?= tv_svg('open') ?><span>Open Kiosk Preview</span>
        </a>
      </div>
    </div>
    <div class="tv-hero-stats">
      <div class="tv-stat-chip"><div class="tv-stat-label">Total Messages</div><div class="tv-stat-value"><?= count($messages) ?></div></div>
      <div class="tv-stat-chip"><div class="tv-stat-label">Active</div><div class="tv-stat-value"><?= $activeCount ?></div></div>
      <div class="tv-stat-chip"><div class="tv-stat-label">Screen Key</div><div class="tv-stat-value" style="font-size:22px;letter-spacing:.04em"><?= h(strtoupper((string)$settings['screen_key'])) ?></div></div>
      <div class="tv-stat-chip"><div class="tv-stat-label">Fleet Aircraft</div><div class="tv-stat-value"><?= $fleetAircraftCount ?></div></div>
      <div class="tv-stat-chip"><div class="tv-stat-label">Poll Interval</div><div class="tv-stat-value" style="font-size:22px"><?= (int)$settings['poll_ms'] / 1000 ?>s</div></div>
    </div>
  </section>

  <?php if ($notice !== ''): ?><div class="tv-alert ok"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error !== '' && $openModal === ''): ?><div class="tv-alert err"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$tableReady): ?>
    <div class="tv-alert err">Apply <code>scripts/sql/2026_05_20_tv_screen_messages.sql</code> to enable TV screen messages.</div>
  <?php elseif (!$voiceColumnReady): ?>
    <div class="tv-alert err">Apply <code>scripts/sql/2026_05_30_tv_screen_pa_voice.sql</code> to enable OpenAI PA voice selection.</div>
  <?php endif; ?>
  <?php if ($tableReady): ?>
    <div class="tv-alert ok">ADS-B aircraft boards use RapidAPI (<code>CW_ADSBEXCHANGE_API_KEY</code> in PHP-FPM). Radar merges fleet ADS-B with live area traffic. Weather uses Tempest station (<code>CW_TEMPEST_ACCESS_TOKEN</code>, <code>CW_TEMPEST_STATION_ID</code>) from tempo_asos. Apply <code>scripts/sql/2026_05_31_tv_screen_aircraft_type.sql</code>, <code>scripts/sql/2026_06_07_tv_screen_aircraft_fields.sql</code>, and <code>scripts/sql/2026_06_08_tv_screen_aircraft_type_col.sql</code>.</div>
  <?php endif; ?>

  <section class="card tv-list-head-card">
    <div class="tv-list-head">
      <div class="tv-list-title"><?= tv_svg('display') ?><span>Board messages</span></div>
      <div class="tv-list-count"><?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?></div>
    </div>
  </section>

  <?php if (count($messages) === 0): ?>
    <section class="card tv-empty">
      <h3 style="margin:0 0 8px;font-size:18px;font-weight:740">No messages yet</h3>
      <p style="margin:0;color:var(--text-muted);line-height:1.6">Add your first operational board message to feed the kiosk display.</p>
    </section>
  <?php else: ?>
    <div class="tv-card-list">
      <?php foreach ($messages as $row): ?>
        <?php
          $rowJson = htmlspecialchars(json_encode(array(
            'id' => (int)$row['id'],
            'screen_key' => (string)$row['screen_key'],
            'message_type' => (string)$row['message_type'],
            'title' => (string)$row['title'],
            'body' => (string)$row['body'],
            'priority' => (int)$row['priority'],
            'starts_at' => tv_admin_dt($row['starts_at'] ?? null),
            'ends_at' => tv_admin_dt($row['ends_at'] ?? null),
            'display_duration_seconds' => (int)$row['display_duration_seconds'],
            'announce_audio_enabled' => (int)$row['announce_audio_enabled'],
            'voice_text' => (string)($row['voice_text'] ?? ''),
            'voice' => tv_pa_voice_or_default((string)($row['voice'] ?? '')),
            'audio_url' => (string)($row['audio_url'] ?? ''),
            'aircraft_hex' => strtolower(trim((string)($row['aircraft_hex'] ?? $row['body'] ?? ''))),
            'aircraft_label' => (string)($row['aircraft_label'] ?? $row['title'] ?? ''),
            'aircraft_type' => strtoupper(trim((string)($row['aircraft_type'] ?? ''))),
            'aircraft_home_airport' => strtoupper(trim((string)($row['aircraft_home_airport'] ?? 'KTRM'))),
            'status' => (string)$row['status'],
          )), ENT_QUOTES, 'UTF-8');
        ?>
        <section class="card tv-msg-card">
          <div class="tv-msg-inner">
            <div class="tv-msg-main">
              <h3 class="tv-msg-title"><?= h((string)$row['title']) ?></h3>
              <p class="tv-msg-body"><?= h((string)$row['body']) ?></p>
              <div class="tv-meta-grid">
                <div><div class="tv-meta-label">Screen</div><div class="tv-meta-value"><?= h((string)$row['screen_key']) ?></div></div>
                <div><div class="tv-meta-label">Schedule</div><div class="tv-meta-value"><?= h(tv_label_dt($row['starts_at'] ?? null)) ?> → <?= h(tv_label_dt($row['ends_at'] ?? null)) ?></div></div>
                <div><div class="tv-meta-label">Display</div><div class="tv-meta-value"><?= (int)$row['display_duration_seconds'] ?> seconds</div></div>
                <div><div class="tv-meta-label">Priority</div><div class="tv-meta-value"><?= (int)$row['priority'] ?></div></div>
                <?php if ((int)$row['announce_audio_enabled'] === 1): ?>
                <div><div class="tv-meta-label">PA Voice</div><div class="tv-meta-value"><?= h(tv_pa_voice_or_default((string)($row['voice'] ?? ''))) ?></div></div>
                <?php endif; ?>
                <?php if ((string)($row['message_type'] ?? '') === 'aircraft'): ?>
                <div><div class="tv-meta-label">Hex</div><div class="tv-meta-value"><?= h(strtolower((string)($row['aircraft_hex'] ?? $row['body'] ?? ''))) ?></div></div>
                <div><div class="tv-meta-label">Label</div><div class="tv-meta-value"><?= h((string)($row['aircraft_label'] ?? $row['title'] ?? '')) ?></div></div>
                <?php if ((string)($row['aircraft_type'] ?? '') !== ''): ?>
                <div><div class="tv-meta-label">Type</div><div class="tv-meta-value"><?= h(strtoupper((string)$row['aircraft_type'])) ?></div></div>
                <?php endif; ?>
                <div><div class="tv-meta-label">Home Base</div><div class="tv-meta-value"><?= h(strtoupper((string)($row['aircraft_home_airport'] ?? 'KTRM'))) ?></div></div>
                <?php endif; ?>
              </div>
            </div>
            <div>
              <div class="tv-badge-row">
                <span class="<?= h(tv_status_badge((string)$row['status'])) ?>"><?= h((string)$row['status']) ?></span>
                <span class="<?= h(tv_type_badge((string)$row['message_type'])) ?>"><?= h((string)$row['message_type']) ?></span>
                <span class="app-badge app-badge-neutral"><?= ((int)$row['announce_audio_enabled'] === 1) ? 'PA enabled' : 'Board only' ?></span>
              </div>
              <div class="tv-card-actions">
                <button type="button" class="app-btn app-btn-secondary tv-edit-btn" data-message="<?= $rowJson ?>">Edit</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active' ?>">
                  <button type="submit" class="app-btn app-btn-secondary"><?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this message?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button type="submit" class="app-btn app-btn-danger">Delete</button>
                </form>
              </div>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="tv-modal-backdrop<?= $openModal === 'settings' ? ' is-open' : '' ?>" id="tvSettingsModal" aria-hidden="<?= $openModal === 'settings' ? 'false' : 'true' ?>">
  <div class="tv-modal" role="dialog" aria-modal="true" aria-labelledby="tvSettingsTitle">
    <div class="tv-modal-head">
      <div>
        <h3 class="tv-modal-title" id="tvSettingsTitle">Screen settings</h3>
        <p class="tv-modal-sub">Kiosk defaults for polling, ADS-B gate tracking, audio, and display mode.</p>
      </div>
      <button type="button" class="app-btn app-btn-secondary" data-close-modal>Close</button>
    </div>
    <form method="post">
      <div class="tv-modal-body">
        <input type="hidden" name="action" value="save_settings">
        <div class="tv-form-grid">
          <div class="tv-field">
            <label class="tv-field-label" for="set_screen_key">Default screen key</label>
            <select class="app-select" id="set_screen_key" name="screen_key" required>
              <?php foreach (tv_screen_presets() as $presetKey => $preset): ?>
                <option value="<?= h($presetKey) ?>" <?= (string)$settings['screen_key'] === $presetKey ? 'selected' : '' ?>><?= h($preset['label']) ?></option>
              <?php endforeach; ?>
              <?php if (!array_key_exists((string)$settings['screen_key'], tv_screen_presets())): ?>
                <option value="<?= h((string)$settings['screen_key']) ?>" selected><?= h((string)$settings['screen_key']) ?> (custom)</option>
              <?php endif; ?>
            </select>
            <p class="tv-field-hint">Radar kiosk: <code>/tv/flipboard.php?screen=radar</code>. Playlist: set the same screen key on multiple active messages.</p>
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_default_mode">Default mode</label>
            <select class="app-select" id="set_default_mode" name="default_mode">
              <?php foreach (['standard', 'schedule', 'night'] as $mode): ?>
                <option value="<?= h($mode) ?>" <?= $settings['default_mode'] === $mode ? 'selected' : '' ?>><?= h(ucfirst($mode)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_poll_ms">Message poll interval (ms)</label>
            <input class="app-input" type="number" id="set_poll_ms" name="poll_ms" min="5000" max="10000" step="500" value="<?= (int)$settings['poll_ms'] ?>">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_aircraft_poll_ms">Aircraft poll interval (ms)</label>
            <input class="app-input" type="number" id="set_aircraft_poll_ms" name="aircraft_poll_ms" min="10000" max="60000" step="1000" value="<?= (int)($settings['aircraft_poll_ms'] ?? 15000) ?>">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_gate_label">Gate label</label>
            <input class="app-input" id="set_gate_label" name="gate_label" value="<?= h((string)($settings['gate_label'] ?? 'SPC Gate')) ?>" maxlength="80">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_home_airport">Home airport ICAO</label>
            <input class="app-input" id="set_home_airport" name="home_airport" value="<?= h((string)($settings['home_airport'] ?? 'KTRM')) ?>" maxlength="4">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_gate_lat">Gate latitude</label>
            <input class="app-input" type="number" step="0.000001" id="set_gate_lat" name="gate_lat" value="<?= h((string)($settings['gate_lat'] ?? '33.6267')) ?>">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_gate_lon">Gate longitude</label>
            <input class="app-input" type="number" step="0.000001" id="set_gate_lon" name="gate_lon" value="<?= h((string)($settings['gate_lon'] ?? '-116.1600')) ?>">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_gate_radius_nm">Gate radius (NM)</label>
            <input class="app-input" type="number" step="0.01" min="0.05" max="2" id="set_gate_radius_nm" name="gate_radius_nm" value="<?= h((string)($settings['gate_radius_nm'] ?? '0.18')) ?>">
          </div>
          <div class="tv-field tv-check-row">
            <input type="checkbox" id="set_assume_parked_off_radar" name="assume_parked_off_radar" value="1" <?= ((int)($settings['assume_parked_off_radar'] ?? 1) === 1) ? 'checked' : '' ?>>
            <label class="tv-field-label" for="set_assume_parked_off_radar">Assume parked at SPC when aircraft is off ADS-B</label>
          </div>
          <div class="tv-field tv-check-row">
            <input type="checkbox" id="set_audio_enabled" name="audio_enabled" value="1" <?= ((int)$settings['audio_enabled'] === 1) ? 'checked' : '' ?>>
            <label class="tv-field-label" for="set_audio_enabled">Enable mechanical audio on kiosk</label>
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="set_default_pa_voice">Default OpenAI PA voice</label>
            <select class="app-input" id="set_default_pa_voice" name="default_pa_voice">
              <?php foreach ($paVoices as $voiceKey => $voiceLabel): ?>
                <option value="<?= h($voiceKey) ?>" <?= tv_pa_voice_or_default((string)$settings['default_pa_voice']) === $voiceKey ? 'selected' : '' ?>><?= h($voiceLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="tv-field tv-check-row">
            <input type="checkbox" id="set_night_mode" name="night_mode" value="1" <?= ((int)$settings['night_mode'] === 1) ? 'checked' : '' ?>>
            <label class="tv-field-label" for="set_night_mode">Open preview in night mode</label>
          </div>
          <div class="tv-field span-2">
            <label class="tv-field-label" for="set_fleet_aircraft_text">Fleet aircraft</label>
            <textarea class="app-textarea" id="set_fleet_aircraft_text" name="fleet_aircraft_text" rows="8" placeholder="N153PC,a4b605,ALPHA&#10;N397EA,abc123,C172"><?= h($fleetAircraftText) ?></textarea>
            <p class="tv-field-hint">One aircraft per line: <code>TAIL,HEX</code> or <code>TAIL,HEX,TYPE</code>. Used by the fleet board, radar, and PA announcements. Lines starting with <code>#</code> are ignored.</p>
          </div>
          <div class="tv-field span-2">
            <label class="tv-field-label" for="set_kiosk_notes">Kiosk notes</label>
            <textarea class="app-textarea" id="set_kiosk_notes" name="kiosk_notes" rows="3"><?= h((string)$settings['kiosk_notes']) ?></textarea>
          </div>
        </div>
        <?php if ($error !== '' && $openModal === 'settings'): ?><div class="tv-alert err" style="margin-top:14px"><?= h($error) ?></div><?php endif; ?>
        <div class="tv-modal-actions">
          <button type="button" class="app-btn app-btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="app-btn app-btn-primary">Save settings</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="tv-modal-backdrop<?= $openModal === 'message' ? ' is-open' : '' ?>" id="tvMessageModal" aria-hidden="<?= $openModal === 'message' ? 'false' : 'true' ?>">
  <div class="tv-modal" role="dialog" aria-modal="true" aria-labelledby="tvMessageTitle">
    <div class="tv-modal-head">
      <div>
        <h3 class="tv-modal-title" id="tvMessageTitle">Add board message</h3>
        <p class="tv-modal-sub">Text wraps by whole words on the physical board.</p>
      </div>
      <button type="button" class="app-btn app-btn-secondary" data-close-modal>Close</button>
    </div>
    <form method="post" id="tvMessageForm">
      <div class="tv-modal-body">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="msg_id" value="<?= h((string)$form['id']) ?>">
        <div class="tv-form-grid">
          <div class="tv-field">
            <label class="tv-field-label" for="msg_screen_key">Screen</label>
            <input class="app-input" id="msg_screen_key" name="screen_key" value="<?= h((string)$form['screen_key']) ?>" maxlength="64" list="tv_screen_key_presets" required>
            <datalist id="tv_screen_key_presets">
              <?php foreach (tv_screen_presets() as $presetKey => $preset): ?>
                <option value="<?= h($presetKey) ?>"><?= h($preset['label']) ?></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="msg_message_type">Mode</label>
            <select class="app-select" id="msg_message_type" name="message_type">
              <?php foreach (tv_message_types() as $type): ?>
                <option value="<?= h($type) ?>" <?= $form['message_type'] === $type ? 'selected' : '' ?>><?= h(tv_message_type_label($type)) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="tv-field-hint" id="msg_playlist_hint">Playlist: add multiple active messages on the same screen key (standard, radar, aircraft board, etc.). Fleet aircraft are configured in <strong>Screen settings</strong>, not here.</p>
          </div>
          <div class="tv-field span-2" id="msg_title_field">
            <label class="tv-field-label" for="msg_title" id="msg_title_label">Title</label>
            <input class="app-input" id="msg_title" name="title" value="<?= h((string)$form['title']) ?>" maxlength="160" required>
          </div>
          <div class="tv-field span-2" id="msg_body_field">
            <label class="tv-field-label" for="msg_body" id="msg_body_label">Board body</label>
            <textarea class="app-textarea" id="msg_body" name="body" rows="5" required><?= h((string)$form['body']) ?></textarea>
          </div>
          <div class="tv-field" id="msg_aircraft_hex_field">
            <label class="tv-field-label" for="msg_aircraft_hex">ADS-B hex code</label>
            <input class="app-input" id="msg_aircraft_hex" name="aircraft_hex" value="<?= h((string)$form['aircraft_hex']) ?>" maxlength="6" placeholder="a4b605" pattern="[a-fA-F0-9]{6}">
          </div>
          <div class="tv-field" id="msg_aircraft_label_field">
            <label class="tv-field-label" for="msg_aircraft_label">Preferred name</label>
            <input class="app-input" id="msg_aircraft_label" name="aircraft_label" value="<?= h((string)$form['aircraft_label']) ?>" maxlength="16" placeholder="N153PC">
          </div>
          <div class="tv-field" id="msg_aircraft_type_field">
            <label class="tv-field-label" for="msg_aircraft_type">Aircraft type</label>
            <input class="app-input" id="msg_aircraft_type" name="aircraft_type" value="<?= h((string)$form['aircraft_type']) ?>" maxlength="24" placeholder="ALPHA">
            <p class="tv-field-hint">Shown in the TYPE column (for example Alpha, C172SP).</p>
          </div>
          <div class="tv-field span-2" id="msg_aircraft_home_field">
            <label class="tv-field-label" for="msg_aircraft_home_airport">Home base (ICAO)</label>
            <select class="app-select" id="msg_aircraft_home_airport" name="aircraft_home_airport">
              <?php foreach ($airportOptions as $icao => $airport): ?>
                <option value="<?= h($icao) ?>" <?= strtoupper((string)$form['aircraft_home_airport']) === $icao ? 'selected' : '' ?>><?= h($icao . ' — ' . $airport['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="tv-field-hint" id="msg_aircraft_hint">Track by hex via RapidAPI ADS-B Exchange. Enable PA announcements below for automatic calls on takeoff, airborne, taxi-in, and landing events.</p>
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="msg_priority">Priority</label>
            <input class="app-input" type="number" id="msg_priority" name="priority" min="0" max="100" value="<?= h((string)$form['priority']) ?>">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="msg_display_duration_seconds">Display seconds</label>
            <input class="app-input" type="number" id="msg_display_duration_seconds" name="display_duration_seconds" min="5" max="300" value="<?= h((string)$form['display_duration_seconds']) ?>">
            <p class="tv-field-hint" id="msg_duration_hint">Seconds this playlist slot stays on screen (5–300).</p>
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="msg_starts_at">Starts at (UTC)</label>
            <input class="app-input" type="datetime-local" id="msg_starts_at" name="starts_at" value="<?= h(tv_admin_dt($form['starts_at'] ?? null)) ?>">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="msg_ends_at">Ends at (UTC)</label>
            <input class="app-input" type="datetime-local" id="msg_ends_at" name="ends_at" value="<?= h(tv_admin_dt($form['ends_at'] ?? null)) ?>">
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="msg_status">Status</label>
            <select class="app-select" id="msg_status" name="status">
              <?php foreach (['draft', 'active', 'inactive', 'archived'] as $status): ?>
                <option value="<?= h($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="tv-field">
            <label class="tv-field-label" for="msg_audio_url">Audio URL</label>
            <input class="app-input" id="msg_audio_url" name="audio_url" value="<?= h((string)$form['audio_url']) ?>" maxlength="512">
          </div>
          <div class="tv-field tv-check-row span-2">
            <input type="checkbox" id="msg_announce_audio_enabled" name="announce_audio_enabled" value="1" <?= ((int)$form['announce_audio_enabled'] === 1) ? 'checked' : '' ?>>
            <label class="tv-field-label" for="msg_announce_audio_enabled">Enable chime and OpenAI PA announcement</label>
          </div>
          <?php if ($voiceColumnReady): ?>
          <div class="tv-field span-2">
            <label class="tv-field-label" for="msg_voice">OpenAI PA voice</label>
            <select class="app-input" id="msg_voice" name="voice">
              <?php foreach ($paVoices as $voiceKey => $voiceLabel): ?>
                <option value="<?= h($voiceKey) ?>" <?= tv_pa_voice_or_default((string)$form['voice']) === $voiceKey ? 'selected' : '' ?>><?= h($voiceLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="tv-field span-2">
            <label class="tv-field-label" for="msg_voice_text">PA announcement script</label>
            <textarea class="app-textarea" id="msg_voice_text" name="voice_text" rows="3" placeholder="Attention please. All students report to dispatch for briefing."><?= h((string)$form['voice_text']) ?></textarea>
            <p class="tv-field-hint">Spoken after the airport chime using OpenAI TTS with the selected PA voice. Leave Audio URL empty unless you have a custom MP3.</p>
          </div>
          <div class="tv-field span-2 tv-pa-preview-row">
            <button type="button" class="app-btn app-btn-secondary" id="tvPreviewPaVoice">Preview OpenAI PA voice</button>
            <span class="tv-field-hint" id="tvPreviewPaStatus">Uses the script above, or a terminal check phrase if empty.</span>
          </div>
        </div>
        <?php if ($error !== '' && $openModal === 'message'): ?><div class="tv-alert err" style="margin-top:14px"><?= h($error) ?></div><?php endif; ?>
        <div class="tv-modal-actions">
          <button type="button" class="app-btn app-btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="app-btn app-btn-primary">Save message</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var settingsModal = document.getElementById('tvSettingsModal');
  var messageModal = document.getElementById('tvMessageModal');
  var messageTitle = document.getElementById('tvMessageTitle');

  function openModal(el) {
    if (!el) return;
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
  }
  function closeModal(el) {
    if (!el) return;
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
  }
  function closeAll() {
    closeModal(settingsModal);
    closeModal(messageModal);
  }

  document.getElementById('tvOpenSettings')?.addEventListener('click', function () {
    openModal(settingsModal);
  });
  function syncAircraftFormHints() {
    var typeEl = document.getElementById('msg_message_type');
    var type = typeEl ? typeEl.value : 'standard';
    var isAircraft = type === 'aircraft';
    var isPlaylistSlot = type === 'radar' || type === 'aircraft_board';
    var titleField = document.getElementById('msg_title_field');
    var bodyField = document.getElementById('msg_body_field');
    var hexField = document.getElementById('msg_aircraft_hex_field');
    var labelField = document.getElementById('msg_aircraft_label_field');
    var typeField = document.getElementById('msg_aircraft_type_field');
    var homeField = document.getElementById('msg_aircraft_home_field');
    var titleInput = document.getElementById('msg_title');
    var bodyInput = document.getElementById('msg_body');
    var hexInput = document.getElementById('msg_aircraft_hex');
    var labelInput = document.getElementById('msg_aircraft_label');
    var titleLabel = document.getElementById('msg_title_label');
    var bodyLabel = document.getElementById('msg_body_label');
    var durationHint = document.getElementById('msg_duration_hint');

    if (titleField) titleField.style.display = isAircraft ? 'none' : '';
    if (bodyField) bodyField.style.display = (isAircraft || isPlaylistSlot) ? 'none' : '';
    if (hexField) hexField.style.display = isAircraft ? '' : 'none';
    if (labelField) labelField.style.display = isAircraft ? '' : 'none';
    if (typeField) typeField.style.display = isAircraft ? '' : 'none';
    if (homeField) homeField.style.display = isAircraft ? '' : 'none';

    if (titleInput) titleInput.required = !isAircraft;
    if (bodyInput) bodyInput.required = !isAircraft && !isPlaylistSlot;
    if (hexInput) hexInput.required = !!isAircraft;
    if (labelInput) labelInput.required = !!isAircraft;

    if (titleLabel) {
      titleLabel.textContent = type === 'radar'
        ? 'Slot label (status bar)'
        : (type === 'aircraft_board' ? 'Slot label (status bar)' : 'Title');
    }
    if (bodyLabel) bodyLabel.textContent = 'Board body';
    if (durationHint) {
      durationHint.textContent = isPlaylistSlot
        ? 'Seconds this view stays on screen before the next playlist slot.'
        : 'Seconds this message stays on screen (5–300).';
    }

    var announceInput = document.getElementById('msg_announce_audio_enabled');
    if (announceInput && isAircraft && document.getElementById('msg_id').value === '0') {
      announceInput.checked = true;
    }
  }

  document.getElementById('msg_message_type')?.addEventListener('change', syncAircraftFormHints);
  syncAircraftFormHints();

  document.getElementById('tvOpenCreate')?.addEventListener('click', function () {
    document.getElementById('tvMessageForm').reset();
    document.getElementById('msg_id').value = '0';
    var defaultVoice = <?= json_encode(tv_pa_voice_or_default((string)$settings['default_pa_voice'])) ?>;
    var voiceSelect = document.getElementById('msg_voice');
    if (voiceSelect) voiceSelect.value = defaultVoice;
    messageTitle.textContent = 'Add board message';
    openModal(messageModal);
  });

  document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
    btn.addEventListener('click', closeAll);
  });
  [settingsModal, messageModal].forEach(function (backdrop) {
    backdrop?.addEventListener('click', function (e) {
      if (e.target === backdrop) closeAll();
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
  });

  document.querySelectorAll('.tv-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var data = {};
      try { data = JSON.parse(btn.getAttribute('data-message') || '{}'); } catch (e) {}
      document.getElementById('msg_id').value = String(data.id || 0);
      document.getElementById('msg_screen_key').value = data.screen_key || 'main';
      document.getElementById('msg_message_type').value = data.message_type || 'standard';
      document.getElementById('msg_title').value = data.title || '';
      document.getElementById('msg_body').value = data.body || '';
      document.getElementById('msg_priority').value = String(data.priority ?? 10);
      document.getElementById('msg_display_duration_seconds').value = String(data.display_duration_seconds ?? 12);
      document.getElementById('msg_starts_at').value = data.starts_at || '';
      document.getElementById('msg_ends_at').value = data.ends_at || '';
      document.getElementById('msg_status').value = data.status || 'draft';
      document.getElementById('msg_audio_url').value = data.audio_url || '';
      document.getElementById('msg_voice_text').value = data.voice_text || '';
      document.getElementById('msg_aircraft_hex').value = data.aircraft_hex || '';
      document.getElementById('msg_aircraft_label').value = data.aircraft_label || '';
      document.getElementById('msg_aircraft_type').value = data.aircraft_type || '';
      document.getElementById('msg_aircraft_home_airport').value = data.aircraft_home_airport || 'KTRM';
      var voiceSelect = document.getElementById('msg_voice');
      if (voiceSelect) voiceSelect.value = data.voice || voiceSelect.value;
      document.getElementById('msg_announce_audio_enabled').checked = Number(data.announce_audio_enabled) === 1;
      messageTitle.textContent = 'Edit board message';
      syncAircraftFormHints();
      openModal(messageModal);
    });
  });

  var previewBtn = document.getElementById('tvPreviewPaVoice');
  var previewStatus = document.getElementById('tvPreviewPaStatus');
  var previewAudio = null;
  previewBtn?.addEventListener('click', function () {
    var voiceEl = document.getElementById('msg_voice');
    var typeEl = document.getElementById('msg_message_type');
    var textEl = document.getElementById('msg_voice_text');
    var voice = voiceEl ? voiceEl.value : 'marin';
    var messageType = typeEl ? typeEl.value : 'standard';
    var text = textEl ? textEl.value.trim() : '';
    var url = '/admin/tv_screens/preview_pa.php?voice=' + encodeURIComponent(voice)
      + '&message_type=' + encodeURIComponent(messageType);
    if (text !== '') url += '&text=' + encodeURIComponent(text);
    if (previewStatus) previewStatus.textContent = 'Generating OpenAI PA preview…';
    if (previewAudio) {
      previewAudio.pause();
      previewAudio = null;
    }
    previewAudio = new Audio(url);
    previewAudio.onplaying = function () {
      if (previewStatus) previewStatus.textContent = 'Playing OpenAI PA preview.';
    };
    previewAudio.onended = function () {
      if (previewStatus) previewStatus.textContent = 'Preview complete.';
    };
    previewAudio.onerror = function () {
      if (previewStatus) previewStatus.textContent = 'Preview failed. Check OpenAI API key and voice migration.';
    };
    previewAudio.play().catch(function () {
      if (previewStatus) previewStatus.textContent = 'Click preview again if the browser blocked playback.';
    });
  });
});
</script>
<?php
cw_footer();
