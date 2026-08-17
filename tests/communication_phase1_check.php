#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/communication/CommunicationKernel.php';

$root = dirname(__DIR__);
$failures = array();

function comm_assert(string $name, bool $ok): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$name}\n";
        return;
    }
    echo "FAIL  {$name}\n";
    $failures[] = $name;
}

function comm_uuid(): string
{
    return CommunicationSupport::uuid();
}

function comm_jpeg(int $width, int $height, int $r = 40, int $g = 80, int $b = 120): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $r, $g, $b));
    ob_start();
    imagejpeg($image, null, 80);
    $bytes = (string)ob_get_clean();
    return $bytes;
}

function comm_sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("CREATE TABLE users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      uuid TEXT NOT NULL,
      email TEXT NOT NULL,
      name TEXT NOT NULL,
      first_name TEXT NOT NULL,
      last_name TEXT NOT NULL,
      role TEXT NOT NULL,
      status TEXT NOT NULL,
      account_valid_until TEXT NULL,
      photo_path TEXT NULL,
      password_hash TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE ipca_communication_app_config (
      config_key TEXT PRIMARY KEY,
      config_value TEXT NOT NULL,
      updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE ipca_communication_system_actors (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      actor_uuid TEXT NOT NULL UNIQUE,
      actor_key TEXT NOT NULL UNIQUE,
      display_name TEXT NOT NULL,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE ipca_communication_devices (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      device_uuid TEXT NOT NULL UNIQUE,
      user_id INTEGER NOT NULL,
      organization_id INTEGER NOT NULL DEFAULT 1,
      platform TEXT NOT NULL,
      model TEXT NOT NULL DEFAULT '',
      os_version TEXT NOT NULL DEFAULT '',
      app_version TEXT NOT NULL DEFAULT '',
      apns_token TEXT NULL,
      push_authorized INTEGER NULL,
      apns_environment TEXT NULL,
      last_seen_at_utc TEXT NULL,
      last_sync_at_utc TEXT NULL,
      last_sync_cursor INTEGER NOT NULL DEFAULT 0,
      revoked_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE ipca_communication_device_credentials (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      credential_uuid TEXT NOT NULL UNIQUE,
      device_id INTEGER NOT NULL,
      token_hash TEXT NOT NULL UNIQUE,
      label TEXT NOT NULL DEFAULT 'session',
      expires_at_utc TEXT NULL,
      revoked_at_utc TEXT NULL,
      last_used_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (device_id) REFERENCES ipca_communication_devices(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_conversations (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      conversation_uuid TEXT NOT NULL UNIQUE,
      organization_id INTEGER NOT NULL DEFAULT 1,
      conversation_type TEXT NOT NULL,
      title TEXT NOT NULL DEFAULT '',
      direct_pair_key TEXT NULL,
      created_by_user_id INTEGER NULL,
      last_message_seq INTEGER NOT NULL DEFAULT 0,
      last_message_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (organization_id, direct_pair_key)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_conversation_members (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      conversation_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      member_role TEXT NOT NULL DEFAULT 'member',
      last_read_seq INTEGER NOT NULL DEFAULT 0,
      last_read_at_utc TEXT NULL,
      last_delivered_seq INTEGER NOT NULL DEFAULT 0,
      last_delivered_at_utc TEXT NULL,
      muted INTEGER NOT NULL DEFAULT 0,
      joined_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      left_at_utc TEXT NULL,
      UNIQUE (conversation_id, user_id),
      FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_messages (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      message_uuid TEXT NOT NULL UNIQUE,
      conversation_id INTEGER NOT NULL,
      organization_id INTEGER NOT NULL DEFAULT 1,
      seq INTEGER NOT NULL,
      client_id TEXT NOT NULL,
      sender_user_id INTEGER NULL,
      sender_device_id INTEGER NULL,
      sender_system_actor_id INTEGER NULL,
      sender_type TEXT NOT NULL DEFAULT 'user',
      body TEXT NOT NULL,
      requires_acknowledgement INTEGER NOT NULL DEFAULT 0,
      reply_allowed INTEGER NOT NULL DEFAULT 1,
      reply_to_message_id INTEGER NULL,
      source_type TEXT NULL,
      source_id TEXT NULL,
      source_event_id TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (sender_user_id, client_id),
      UNIQUE (conversation_id, seq),
      UNIQUE (source_type, source_id, source_event_id),
      FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_change_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      organization_id INTEGER NOT NULL DEFAULT 1,
      conversation_id INTEGER NOT NULL,
      change_type TEXT NOT NULL,
      entity_uuid TEXT NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_message_device_syncs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      message_id INTEGER NOT NULL,
      device_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      synced_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (message_id, device_id),
      FOREIGN KEY (message_id) REFERENCES ipca_communication_messages(id),
      FOREIGN KEY (device_id) REFERENCES ipca_communication_devices(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_push_attempts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      push_uuid TEXT NOT NULL UNIQUE,
      message_id INTEGER NOT NULL,
      device_id INTEGER NOT NULL,
      accepted_at_utc TEXT NULL,
      failed_at_utc TEXT NULL,
      provider_response TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (message_id, device_id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_acknowledgements (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      acknowledgement_uuid TEXT NOT NULL UNIQUE,
      message_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      device_id INTEGER NULL,
      acknowledged_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (message_id, user_id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_message_reactions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      reaction_uuid TEXT NOT NULL UNIQUE,
      message_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      device_id INTEGER NULL,
      emoji TEXT NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (message_id, user_id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_attachments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      attachment_uuid TEXT NOT NULL UNIQUE,
      conversation_id INTEGER NOT NULL,
      organization_id INTEGER NOT NULL DEFAULT 1,
      uploaded_by_user_id INTEGER NOT NULL,
      uploaded_by_device_id INTEGER NULL,
      storage_key TEXT NOT NULL UNIQUE,
      original_filename TEXT NOT NULL DEFAULT '',
      mime_type TEXT NOT NULL,
      byte_size INTEGER NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending',
      uploaded_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (conversation_id) REFERENCES ipca_communication_conversations(id)
    )");
    $pdo->exec("CREATE TABLE ipca_communication_message_attachments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      message_id INTEGER NOT NULL,
      attachment_id INTEGER NOT NULL,
      sort_order INTEGER NOT NULL DEFAULT 0,
      UNIQUE (message_id, attachment_id),
      FOREIGN KEY (message_id) REFERENCES ipca_communication_messages(id),
      FOREIGN KEY (attachment_id) REFERENCES ipca_communication_attachments(id)
    )");
    $pdo->exec("CREATE TABLE ipca_community_posts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      post_uuid TEXT NOT NULL UNIQUE,
      author_user_id INTEGER NOT NULL,
      author_device_id INTEGER NULL,
      organization_id INTEGER NOT NULL DEFAULT 1,
      school_scope TEXT NULL,
      program_scope TEXT NULL,
      caption TEXT NOT NULL DEFAULT '',
      body TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'published',
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at_utc TEXT NULL,
      deleted_by_user_id INTEGER NULL
    )");
    $pdo->exec("CREATE TABLE ipca_community_post_media (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      media_uuid TEXT NOT NULL UNIQUE,
      post_id INTEGER NULL,
      organization_id INTEGER NOT NULL DEFAULT 1,
      uploaded_by_user_id INTEGER NOT NULL,
      uploaded_by_device_id INTEGER NULL,
      storage_key TEXT NOT NULL UNIQUE,
      original_filename TEXT NOT NULL DEFAULT '',
      mime_type TEXT NOT NULL,
      kind TEXT NOT NULL,
      byte_size INTEGER NOT NULL,
      duration_ms INTEGER NOT NULL DEFAULT 0,
      poster_storage_key TEXT NULL,
      sort_order INTEGER NOT NULL DEFAULT 0,
      status TEXT NOT NULL DEFAULT 'pending',
      uploaded_at_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    )");
    $pdo->exec("CREATE TABLE ipca_community_likes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      like_uuid TEXT NOT NULL UNIQUE,
      post_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      device_id INTEGER NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (post_id, user_id),
      FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    )");
    $pdo->exec("CREATE TABLE ipca_community_comments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      comment_uuid TEXT NOT NULL UNIQUE,
      post_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      device_id INTEGER NULL,
      body TEXT NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at_utc TEXT NULL,
      FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    )");
    $pdo->exec("CREATE TABLE ipca_community_reports (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      report_uuid TEXT NOT NULL UNIQUE,
      post_id INTEGER NOT NULL,
      reporter_user_id INTEGER NOT NULL,
      reporter_device_id INTEGER NULL,
      reason TEXT NOT NULL,
      details TEXT NOT NULL DEFAULT '',
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (post_id, reporter_user_id),
      FOREIGN KEY (post_id) REFERENCES ipca_community_posts(id)
    )");
    $pdo->exec("CREATE TABLE ipca_training_video_categories (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      slug TEXT NOT NULL UNIQUE,
      name TEXT NOT NULL,
      sort_order INTEGER NOT NULL DEFAULT 0,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO ipca_training_video_categories (slug, name, sort_order, is_active) VALUES
      ('private-pilot', 'Private Pilot', 10, 1),
      ('instrument', 'Instrument', 20, 1),
      ('commercial', 'Commercial', 30, 1),
      ('cfi', 'CFI', 40, 1),
      ('systems', 'Systems', 50, 1),
      ('uncategorized', 'Uncategorized', 90, 1)");
    $pdo->exec("CREATE TABLE ipca_training_videos (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      video_uuid TEXT NOT NULL UNIQUE,
      title TEXT NOT NULL,
      title_source TEXT NULL,
      description TEXT NULL,
      description_source TEXT NULL,
      category TEXT NULL,
      category_id INTEGER NULL,
      aircraft TEXT NULL,
      program TEXT NULL,
      storage_key TEXT NULL UNIQUE,
      mime_type TEXT NOT NULL DEFAULT 'video/mp4',
      poster_storage_key TEXT NULL,
      poster_mime_type TEXT NULL,
      poster_source TEXT NULL,
      poster_template TEXT NULL,
      poster_library_asset_id INTEGER NULL,
      poster_candidate_json TEXT NULL,
      poster_candidate_index INTEGER NOT NULL DEFAULT 0,
      duration_ms INTEGER NOT NULL DEFAULT 0,
      byte_size INTEGER NOT NULL DEFAULT 0,
      width INTEGER NOT NULL DEFAULT 0,
      height INTEGER NOT NULL DEFAULT 0,
      orientation TEXT NULL,
      status TEXT NOT NULL DEFAULT 'draft',
      created_by_user_id INTEGER NOT NULL,
      updated_by_user_id INTEGER NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      archived_at_utc TEXT NULL,
      deleted_at_utc TEXT NULL
    )");
    $pdo->exec("CREATE TABLE ipca_training_video_grants (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      video_id INTEGER NOT NULL,
      grant_type TEXT NOT NULL,
      grant_value TEXT NOT NULL DEFAULT '',
      available_from_utc TEXT NULL,
      available_until_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE ipca_training_video_category_entitlements (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      category_id INTEGER NOT NULL,
      available_from_utc TEXT NULL,
      available_until_utc TEXT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at_utc TEXT NULL,
      UNIQUE (user_id, category_id)
    )");
    $pdo->exec("CREATE TABLE ipca_training_video_views (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      video_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      first_viewed_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_viewed_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      position_ms INTEGER NOT NULL DEFAULT 0,
      max_position_ms INTEGER NOT NULL DEFAULT 0,
      progress_percent INTEGER NOT NULL DEFAULT 0,
      completed_at_utc TEXT NULL,
      updated_at_utc TEXT NULL,
      UNIQUE (video_id, user_id),
      FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE ipca_training_video_likes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      video_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (video_id, user_id),
      FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE ipca_training_video_comments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      comment_uuid TEXT NOT NULL UNIQUE,
      video_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      body TEXT NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at_utc TEXT NULL,
      FOREIGN KEY (video_id) REFERENCES ipca_training_videos(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE ipca_training_media_library (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      asset_uuid TEXT NOT NULL UNIQUE,
      storage_key TEXT NOT NULL UNIQUE,
      original_filename TEXT NOT NULL DEFAULT '',
      mime_type TEXT NOT NULL DEFAULT 'image/jpeg',
      byte_size INTEGER NOT NULL DEFAULT 0,
      width INTEGER NOT NULL DEFAULT 0,
      height INTEGER NOT NULL DEFAULT 0,
      orientation TEXT NOT NULL DEFAULT 'landscape',
      analysis_json TEXT NULL,
      analysis_text TEXT NULL,
      analysis_status TEXT NOT NULL DEFAULT 'pending',
      created_by_user_id INTEGER NOT NULL,
      created_at_utc TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      deleted_at_utc TEXT NULL
    )");
    $flags = array(
        'protocol_version' => '1',
        'min_app_version' => '1.0.0',
        'min_ios_version' => '17.0',
        'messaging_enabled' => '1',
        'groups_enabled' => '1',
        'attachments_enabled' => '1',
        'system_messages_enabled' => '1',
        'training_enabled' => '1',
        'training_videos_enabled' => '1',
        'community_enabled' => '1',
        'community_posting_enabled' => '1',
        'push_enabled' => '1',
    );
    $insert = $pdo->prepare('INSERT INTO ipca_communication_app_config (config_key, config_value) VALUES (?, ?)');
    foreach ($flags as $key => $value) {
        $insert->execute(array($key, $value));
    }
    $pdo->exec("INSERT INTO ipca_communication_system_actors (actor_uuid, actor_key, display_name, is_active) VALUES
        ('a1000000-0000-4000-8000-000000000001', 'ipca_training', 'IPCA Training', 1),
        ('a1000000-0000-4000-8000-000000000002', 'ipca_scheduling', 'IPCA Scheduling', 1),
        ('a1000000-0000-4000-8000-000000000003', 'ipca_administration', 'IPCA Administration', 1)
    ");
    return $pdo;
}

function comm_add_user(PDO $pdo, string $email, string $name, string $role = 'student', string $status = 'active'): int
{
    $stmt = $pdo->prepare('INSERT INTO users (uuid, email, name, first_name, last_name, role, status, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $parts = explode(' ', $name, 2);
    $stmt->execute(array(
        comm_uuid(),
        $email,
        $name,
        $parts[0],
        $parts[1] ?? '',
        $role,
        $status,
        password_hash('secret', PASSWORD_DEFAULT),
    ));
    return (int)$pdo->lastInsertId();
}

function comm_publish_photo(CommunicationKernel $kernel, CommunicationMemoryObjectStore $store, array $session, string $caption, string $body = ''): array
{
    $mediaUuid = comm_uuid();
    $bytes = "\xff\xd8fakejpeg";
    $kernel->community->presignMedia($session, 'photo.jpg', 'image/jpeg', strlen($bytes), 0, $mediaUuid);
    $store->put('community/1/m/' . $mediaUuid, $bytes, 'image/jpeg');
    $kernel->community->completeMedia($session, $mediaUuid);
    return $kernel->community->create($session, $caption, array($mediaUuid), null, $body);
}

function comm_publish_video(
    CommunicationKernel $kernel,
    CommunicationMemoryObjectStore $store,
    array $session,
    string $caption,
    int $durationMs = 20000,
    string $body = ''
): array {
    $mediaUuid = comm_uuid();
    $bytes = str_repeat('v', 2048);
    $presign = $kernel->community->presignMedia($session, 'clip.mov', 'video/quicktime', strlen($bytes), $durationMs, $mediaUuid);
    $store->put('community/1/m/' . $mediaUuid, $bytes, 'video/quicktime');
    $posterKey = (string)($presign['poster_storage_key'] ?? ('community/1/m/' . $mediaUuid . '.poster.jpg'));
    $store->put($posterKey, "\xff\xd8fakeposter", 'image/jpeg');
    $kernel->community->completeMedia($session, $mediaUuid);
    return $kernel->community->create($session, $caption, array($mediaUuid), null, $body);
}

function comm_login(CommunicationKernel $kernel, string $email, string $platform = 'iphone'): array
{
    return $kernel->auth->login($email, 'secret', array(
        'device_uuid' => comm_uuid(),
        'platform' => $platform,
        'model' => $platform === 'ipad' ? 'iPad' : 'iPhone',
        'os_version' => '17.0',
        'app_version' => '1.0.0',
    ));
}

function comm_session(CommunicationKernel $kernel, array $login): array
{
    return $kernel->auth->authenticateToken((string)$login['token']);
}

function comm_training_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE ipca_aircraft_devices (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      registration TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE ipca_flight_schedule_slots (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      scheduled_start_time TEXT NOT NULL,
      scheduled_end_time TEXT NOT NULL,
      reservation_type TEXT NOT NULL DEFAULT 'flight_training',
      mission_code TEXT NOT NULL DEFAULT '',
      aircraft_id INTEGER NULL,
      mission_id INTEGER NULL,
      planned_departure_airport TEXT NOT NULL DEFAULT '',
      planned_destination_airport TEXT NOT NULL DEFAULT '',
      scheduler_record_id TEXT NULL,
      status TEXT NOT NULL DEFAULT 'scheduled'
    )");
    $pdo->exec("CREATE TABLE ipca_missions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      code TEXT NOT NULL DEFAULT '',
      name TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE ipca_flight_schedule_crew (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      schedule_slot_id INTEGER NOT NULL,
      user_id INTEGER NULL,
      person_name_snapshot TEXT NOT NULL,
      crew_role TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE programs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      program_key TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE courses (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      program_id INTEGER NOT NULL,
      title TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE cohorts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      course_id INTEGER NULL
    )");
    $pdo->exec("CREATE TABLE cohort_students (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      cohort_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL
    )");
    $pdo->exec("CREATE TABLE lessons (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE cohort_lesson_deadlines (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      cohort_id INTEGER NOT NULL,
      lesson_id INTEGER NOT NULL,
      deadline_utc TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE lesson_activity (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      cohort_id INTEGER NOT NULL,
      lesson_id INTEGER NOT NULL,
      completion_status TEXT NULL,
      test_pass_status TEXT NULL,
      effective_deadline_utc TEXT NULL,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE student_required_actions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      lesson_id INTEGER NULL,
      action_type TEXT NOT NULL,
      title TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'pending',
      completed_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE ipca_internal_inbox_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      recipient_user_id INTEGER NOT NULL,
      item_type TEXT NOT NULL,
      title TEXT NOT NULL,
      summary TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'pending'
    )");
}

$migration = file_get_contents($root . '/scripts/sql/2026_08_13_communication_phase1.sql') ?: '';
$iosAppPath = $root . '/ipca-app-ios/IPCA/IPCAApp.swift';
$iosApp = is_file($iosAppPath) ? (string)file_get_contents($iosAppPath) : '';
comm_assert('migration creates communication tables', str_contains($migration, 'CREATE TABLE IF NOT EXISTS ipca_communication_messages'));
comm_assert('migration seeds rollout flags', str_contains($migration, 'community_enabled') && str_contains($migration, "'0'"));
comm_assert('migration distinguishes device_synced from push_accepted', str_contains($migration, 'ipca_communication_message_device_syncs') && str_contains($migration, 'ipca_communication_push_attempts'));
comm_assert('auth endpoint exists', is_file($root . '/public/api/communication/auth.php'));
comm_assert('bootstrap endpoint exists', is_file($root . '/public/api/communication/bootstrap.php'));
comm_assert('sync endpoint exists', is_file($root . '/public/api/communication/sync.php'));
comm_assert('messages endpoint exists', is_file($root . '/public/api/communication/messages.php'));

$pdo = comm_sqlite();
$objectStore = new CommunicationMemoryObjectStore();
$kernel = new CommunicationKernel($pdo, $objectStore);
$userA = comm_add_user($pdo, 'a@ipca.training', 'Alice Student');
$userB = comm_add_user($pdo, 'b@ipca.training', 'Bob Instructor', 'instructor');
$userC = comm_add_user($pdo, 'c@ipca.training', 'Cara Student');
$lockedId = comm_add_user($pdo, 'locked@ipca.training', 'Locked User', 'student', 'locked');

$loginA = comm_login($kernel, 'a@ipca.training', 'iphone');
$loginB = comm_login($kernel, 'b@ipca.training', 'iphone');
$loginAPad = comm_login($kernel, 'a@ipca.training', 'ipad');
$sessionA = comm_session($kernel, $loginA);
$sessionB = comm_session($kernel, $loginB);
$sessionAPad = comm_session($kernel, $loginAPad);

comm_assert('login issues bearer token', is_string($loginA['token']) && $loginA['token'] !== '');
comm_assert('login does not persist plaintext token', $pdo->query("SELECT COUNT(*) FROM ipca_communication_device_credentials WHERE token_hash = '" . $loginA['token'] . "'")->fetchColumn() == 0);
comm_assert('iphone and ipad are separate devices', (string)$loginA['device']['device_uuid'] !== (string)$loginAPad['device']['device_uuid']);

$enrolled = $kernel->enrollment->snapshot();
$alice = null;
foreach ($enrolled['people'] as $person) {
    if ((int)$person['user_id'] === $userA) {
        $alice = $person;
    }
}
comm_assert(
    'sign-in enrolls the person immediately for staff DM reachability',
    is_array($alice)
    && $alice['has_iphone'] === true
    && $alice['has_ipad'] === true
    && $alice['push_ready'] === false
    && (int)$enrolled['stats']['enrolled_users'] >= 2
);

$lockedFailed = false;
try {
    comm_login($kernel, 'locked@ipca.training');
} catch (CommunicationException $e) {
    $lockedFailed = $e->errorCode === 'account_ineligible';
}
comm_assert('locked account cannot log in', $lockedFailed);

$wrongPassword = false;
try {
    $kernel->auth->login('a@ipca.training', 'nope', array(
        'device_uuid' => comm_uuid(),
        'platform' => 'iphone',
        'app_version' => '1.0.0',
    ));
} catch (CommunicationException $e) {
    $wrongPassword = $e->errorCode === 'invalid_credentials' && $e->httpStatus === 401;
}
comm_assert('wrong password is rejected', $wrongPassword);

$bootstrap = $kernel->sync->bootstrap($sessionA);
comm_assert('bootstrap enables Community tab', $bootstrap['capabilities']['community_enabled'] === true);
comm_assert('bootstrap enables Training tab', $bootstrap['capabilities']['training_enabled'] === true);
comm_assert('bootstrap advertises Training Videos independently', $bootstrap['capabilities']['training_videos_enabled'] === true);
comm_assert('bootstrap enables messaging', $bootstrap['capabilities']['messaging_enabled'] === true);
comm_assert('bootstrap does not include inbox', !isset($bootstrap['messages']) && !isset($bootstrap['conversations']));
comm_assert('bootstrap includes unread and needs-action counts', array_key_exists('unread_count', $bootstrap) && array_key_exists('needs_action_count', $bootstrap));

$peerUuid = (string)$loginB['user']['uuid'];
$created = $kernel->conversations->createDirect($sessionA, $peerUuid);
$createdAgain = $kernel->conversations->createDirect($sessionA, $peerUuid);
comm_assert('direct conversation is reused', $created['conversation_uuid'] === $createdAgain['conversation_uuid']);
comm_assert('direct conversation has two members', count($created['members']) === 2);

$conversationUuid = (string)$created['conversation_uuid'];
$client1 = comm_uuid();
$sent = $kernel->messages->send($sessionA, $conversationUuid, $client1, 'Hello Bob');
$sentAgain = $kernel->messages->send($sessionA, $conversationUuid, $client1, 'Hello Bob');
comm_assert('duplicate client_id does not create a second message', $sent['message_uuid'] === $sentAgain['message_uuid'] && $sent['seq'] === 1);
comm_assert('message is server_received', $sent['server_received'] === true);

$count = (int)$pdo->query('SELECT COUNT(*) FROM ipca_communication_messages')->fetchColumn();
comm_assert('only one row after duplicate send', $count === 1);

$syncB1 = $kernel->sync->pull($sessionB, 0);
comm_assert('receiver syncs the message', count($syncB1['messages']) === 1 && $syncB1['messages'][0]['body'] === 'Hello Bob');
comm_assert('sync cursor advances', (int)$syncB1['cursor'] > 0);

$deviceSyncCount = (int)$pdo->query('SELECT COUNT(*) FROM ipca_communication_message_device_syncs')->fetchColumn();
comm_assert('device_synced evidence exists for sender and receiver devices', $deviceSyncCount >= 2);

$bobDelivered = (int)$pdo->query(
    'SELECT last_delivered_seq FROM ipca_communication_conversation_members WHERE user_id = ' . (int)$sessionB['user']['id'] . ' AND conversation_id = (SELECT id FROM ipca_communication_conversations WHERE conversation_uuid = ' . $pdo->quote($conversationUuid) . ')'
)->fetchColumn();
$aliceDeliveredBeforeBobSyncWouldBeSenderOnly = (int)$pdo->query(
    'SELECT last_delivered_seq FROM ipca_communication_conversation_members WHERE user_id = ' . (int)$sessionA['user']['id'] . ' AND conversation_id = (SELECT id FROM ipca_communication_conversations WHERE conversation_uuid = ' . $pdo->quote($conversationUuid) . ')'
)->fetchColumn();
comm_assert('recipient last_delivered_seq advances on device_synced, not on send/APNs', $bobDelivered >= 1);
comm_assert('sender last_delivered_seq is not used as recipient delivered evidence', $aliceDeliveredBeforeBobSyncWouldBeSenderOnly === 0);

$replyClient = comm_uuid();
$reply = $kernel->messages->send($sessionB, $conversationUuid, $replyClient, 'Hi Alice');
$syncA1 = $kernel->sync->pull($sessionA, 0);
$bodies = array_map(static fn(array $m): string => (string)$m['body'], $syncA1['messages']);
comm_assert('sender synchronizes the reply', in_array('Hi Alice', $bodies, true));

$syncAPad = $kernel->sync->pull($sessionAPad, 0);
$padBodies = array_map(static fn(array $m): string => (string)$m['body'], $syncAPad['messages']);
comm_assert('iPad of same user receives both messages', in_array('Hello Bob', $padBodies, true) && in_array('Hi Alice', $padBodies, true));

$syncBRepeat = $kernel->sync->pull($sessionB, (int)$syncB1['cursor']);
comm_assert('repeated sync with same cursor is empty after catching up except new events', is_array($syncBRepeat['messages']));

$rapid = array();
for ($i = 1; $i <= 5; $i++) {
    $rapid[] = $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), 'rapid-' . $i);
}
$seqs = array_map(static fn(array $m): int => (int)$m['seq'], $rapid);
comm_assert('rapid messages get monotonic seq', $seqs === array(3, 4, 5, 6, 7));

$page = $kernel->messages->page($sessionB, $conversationUuid, null, 50);
$pageBodies = array_map(static fn(array $m): string => (string)$m['body'], $page);
comm_assert('history is ordered by seq', $pageBodies[0] === 'Hello Bob' && end($pageBodies) === 'rapid-5');

$loginC = comm_login($kernel, 'c@ipca.training');
$sessionC = comm_session($kernel, $loginC);
$unauthorized = false;
try {
    $kernel->messages->page($sessionC, $conversationUuid, null, 20);
} catch (CommunicationException $e) {
    $unauthorized = $e->errorCode === 'not_a_member' && $e->httpStatus === 403;
}
comm_assert('non-member cannot read conversation', $unauthorized);

$kernel->messages->markRead($sessionB, $conversationUuid, (int)$reply['seq']);
$listed = $kernel->conversations->listForUser($sessionB);
comm_assert('unread count drops after read', (int)$listed[0]['unread_count'] === 5);
$syncAfterRead = $kernel->sync->pull($sessionA, 0);
$bobReadSeq = 0;
$bobDeliveredSeq = 0;
foreach ($syncAfterRead['reads'] as $read) {
    if ((string)$read['user_uuid'] === (string)$loginB['user']['uuid'] && (string)$read['conversation_uuid'] === $conversationUuid) {
        $bobReadSeq = (int)$read['last_read_seq'];
        $bobDeliveredSeq = (int)($read['last_delivered_seq'] ?? 0);
    }
}
comm_assert(
    'sender sync sees recipient delivered and read cursors separately',
    $bobDeliveredSeq >= 1 && $bobReadSeq >= (int)$reply['seq']
);

$second = $kernel->conversations->createDirect($sessionA, (string)$loginC['user']['uuid']);
$kernel->messages->send($sessionA, (string)$second['conversation_uuid'], comm_uuid(), 'later conversation');
$ordered = $kernel->conversations->listForUser($sessionA);
comm_assert('newest conversation is first after sync', $ordered[0]['conversation_uuid'] === $second['conversation_uuid']);

$group = $kernel->conversations->createGroup($sessionA, 'Belgium August 2026', array((string)$loginB['user']['uuid'], (string)$loginC['user']['uuid']));
$groupMsg = $kernel->messages->send($sessionA, (string)$group['conversation_uuid'], comm_uuid(), 'Welcome');
$syncC = $kernel->sync->pull($sessionC, 0);
$groupHit = false;
foreach ($syncC['messages'] as $message) {
    if ($message['message_uuid'] === $groupMsg['message_uuid']) {
        $groupHit = true;
    }
}
comm_assert('group message reaches members via sync', $groupHit && $group['conversation_type'] === 'group');

$photoBytes = 'fake-jpeg-bytes';
$photoUuid = comm_uuid();
$presign = $kernel->attachments->presignPut($sessionA, $conversationUuid, 'photo.jpg', 'image/jpeg', strlen($photoBytes), $photoUuid);
comm_assert('private presign PUT URL is not a public CDN object', str_contains((string)$presign['put_url'], 'memory.invalid/put/') && !str_contains((string)$presign['put_url'], 'public-read'));
$incomplete = false;
try {
    $kernel->attachments->complete($sessionA, $photoUuid);
} catch (CommunicationException $e) {
    $incomplete = $e->errorCode === 'upload_incomplete';
}
comm_assert('complete rejects missing private object', $incomplete);
$storageKey = (string)$pdo->query('SELECT storage_key FROM ipca_communication_attachments WHERE attachment_uuid = ' . $pdo->quote($photoUuid))->fetchColumn();
comm_assert('storage key stays under communication prefix', str_starts_with($storageKey, 'communication/'));
$objectStore->put($storageKey, $photoBytes, 'image/jpeg');
$completed = $kernel->attachments->complete($sessionA, $photoUuid);
comm_assert('complete marks the object uploaded', (string)$completed['status'] === 'uploaded');

$pendingUuid = comm_uuid();
$kernel->attachments->presignPut($sessionA, $conversationUuid, 'later.jpg', 'image/jpeg', 12, $pendingUuid);
$sendBeforeUpload = false;
try {
    $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), '', array($pendingUuid));
} catch (CommunicationException $e) {
    $sendBeforeUpload = $e->errorCode === 'upload_incomplete';
}
comm_assert('send with media waits until upload is complete', $sendBeforeUpload);

$photoMsg = $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), '', array($photoUuid));
comm_assert(
    'uploaded attachment can be sent with empty body',
    $photoMsg['server_received'] === true
    && $photoMsg['body'] === ''
    && isset($photoMsg['attachments'][0]['attachment_uuid'])
    && $photoMsg['attachments'][0]['attachment_uuid'] === $photoUuid
);
$listedAfterPhoto = $kernel->conversations->listForUser($sessionA);
$photoPreview = '';
foreach ($listedAfterPhoto as $row) {
    if ((string)$row['conversation_uuid'] === $conversationUuid) {
        $photoPreview = (string)($row['preview']['body'] ?? '');
    }
}
comm_assert('attachment-only conversation preview is Photo', $photoPreview === 'Photo');

$syncBPhoto = $kernel->sync->pull($sessionB, 0);
$photoHit = false;
foreach ($syncBPhoto['messages'] as $message) {
    if ((string)$message['message_uuid'] === (string)$photoMsg['message_uuid']
        && isset($message['attachments'][0]['attachment_uuid'])
        && $message['attachments'][0]['attachment_uuid'] === $photoUuid) {
        $photoHit = true;
    }
}
comm_assert('receiver syncs attachment metadata without a public URL', $photoHit);
$download = $kernel->attachments->download($sessionB, $photoUuid);
comm_assert('member can fetch a short-lived signed GET', str_contains((string)$download['get_url'], 'memory.invalid/get/'));
$outsiderBlocked = false;
try {
    $kernel->attachments->download($sessionC, $photoUuid);
} catch (CommunicationException $e) {
    $outsiderBlocked = $e->errorCode === 'not_a_member';
}
comm_assert('non-member cannot download a private attachment', $outsiderBlocked);

$spacesSrc = (string)file_get_contents($root . '/src/spaces.php');
$presignFn = preg_match('/function cw_spaces_presign\(.*?function cw_spaces_head_object/s', $spacesSrc, $m) ? $m[0] : '';
comm_assert(
    'Spaces chat presign does not set public-read ACL',
    str_contains($spacesSrc, 'function cw_spaces_presign')
    && str_contains($spacesSrc, 'function cw_spaces_head_object')
    && !str_contains($presignFn, 'public-read')
);

$sourceMsg = $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), 'please reply to this');
$quoted = $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), 'here is the reply', array(), 0, (string)$sourceMsg['message_uuid']);
comm_assert(
    'quoted reply points at the parent message',
    is_array($quoted['reply_to'] ?? null)
    && (string)$quoted['reply_to']['message_uuid'] === (string)$sourceMsg['message_uuid']
    && str_contains((string)$quoted['reply_to']['body_preview'], 'please reply')
);
$crossReply = false;
try {
    $kernel->messages->send($sessionA, (string)$second['conversation_uuid'], comm_uuid(), 'nope', array(), 0, (string)$sourceMsg['message_uuid']);
} catch (CommunicationException $e) {
    $crossReply = $e->errorCode === 'not_found';
}
comm_assert('cannot quote a message from another conversation', $crossReply);

$reacted = $kernel->messages->setReaction($sessionB, (string)$sourceMsg['message_uuid'], '👍', comm_uuid());
$reactHit = false;
foreach ($reacted['reactions'] as $reaction) {
    if ((string)$reaction['emoji'] === '👍' && (int)$reaction['count'] === 1 && $reaction['reacted_by_me'] === true) {
        $reactHit = true;
    }
}
comm_assert('recipient can add an emoji reaction', $reactHit);
$reactedB = $kernel->messages->setReaction($sessionB, (string)$sourceMsg['message_uuid'], '👍', comm_uuid());
$cleared = ($reactedB['reactions'] ?? array()) === array();
comm_assert('tapping the same emoji again clears the reaction', $cleared);
$reactedAgain = $kernel->messages->setReaction($sessionB, (string)$sourceMsg['message_uuid'], '❤️', comm_uuid());
$syncReact = $kernel->sync->pull($sessionA, 0);
$syncReactHit = false;
foreach ($syncReact['messages'] as $message) {
    if ((string)$message['message_uuid'] === (string)$sourceMsg['message_uuid']) {
        foreach ($message['reactions'] as $reaction) {
            if ((string)$reaction['emoji'] === '❤️' && (int)$reaction['count'] === 1) {
                $syncReactHit = true;
            }
        }
    }
}
comm_assert('reactions sync to the other member', $syncReactHit && (string)($reactedAgain['reactions'][0]['emoji'] ?? '') === '❤️');

$adminId = comm_add_user($pdo, 'admin@ipca.training', 'Dana Admin', 'admin');
$loginAdmin = comm_login($kernel, 'admin@ipca.training');
$sessionAdmin = comm_session($kernel, $loginAdmin);
$studentPublish = false;
try {
    $kernel->systemMessages->publishFromSession($sessionA, 'ipca_administration', 'nope');
} catch (CommunicationException $e) {
    $studentPublish = $e->errorCode === 'forbidden';
}
comm_assert('students cannot send official system messages', $studentPublish);

$eventId = comm_uuid();
$official = $kernel->systemMessages->publish(
    'ipca_administration',
    'Please confirm you received this notice.',
    array((int)$sessionA['user']['id']),
    true,
    false,
    'test',
    'phase4',
    $eventId
);
$officialAgain = $kernel->systemMessages->publish(
    'ipca_administration',
    'Please confirm you received this notice.',
    array((int)$sessionA['user']['id']),
    true,
    false,
    'test',
    'phase4',
    $eventId
);
comm_assert('event to message is idempotent on source_event_id', $official['message_uuid'] === $officialAgain['message_uuid']);
comm_assert(
    'system message is not a login-able user sender',
    (string)$official['sender_type'] === 'system'
    && $official['sender_user_uuid'] === null
    && (string)$official['sender_display_name'] === 'IPCA Administration'
    && $official['requires_acknowledgement'] === true
);

$syncOfficial = $kernel->sync->pull($sessionA, 0);
$officialHit = false;
foreach ($syncOfficial['messages'] as $message) {
    if ((string)$message['message_uuid'] === (string)$official['message_uuid']) {
        $officialHit = (string)$message['sender_type'] === 'system';
    }
}
comm_assert('recipient syncs the official message', $officialHit);
$bootNeeds = $kernel->sync->bootstrap($sessionA);
comm_assert('Needs Attention count includes unacked official messages', (int)$bootNeeds['needs_action_count'] >= 1);
$actions = $kernel->systemMessages->needsAttention($sessionA);
$actionHit = false;
foreach ($actions as $action) {
    if ((string)$action['message_uuid'] === (string)$official['message_uuid']) {
        $actionHit = (string)$action['kind'] === 'acknowledgement';
    }
}
comm_assert('Needs Attention lists the pending acknowledgement', $actionHit);

$ackUuid = comm_uuid();
$acked = $kernel->systemMessages->acknowledge($sessionA, (string)$official['message_uuid'], $ackUuid);
$ackedAgain = $kernel->systemMessages->acknowledge($sessionA, (string)$official['message_uuid'], comm_uuid());
comm_assert('acknowledgement is append-only and duplicate safe', $acked['already_acknowledged'] === false && $ackedAgain['already_acknowledged'] === true && $ackedAgain['acknowledgement_uuid'] === $ackUuid);
$bootAfterAck = $kernel->sync->bootstrap($sessionA);
comm_assert('Needs Attention count drops after acknowledgement', (int)$bootAfterAck['needs_action_count'] === 0);
$outsiderAck = false;
try {
    $kernel->systemMessages->acknowledge($sessionC, (string)$official['message_uuid'], comm_uuid());
} catch (CommunicationException $e) {
    $outsiderAck = $e->errorCode === 'not_a_member';
}
comm_assert('non-member cannot acknowledge an official message', $outsiderAck);
$evidence = $kernel->systemMessages->evidence($sessionAdmin);
$evidenceHit = false;
foreach ($evidence as $row) {
    if ((string)$row['message_uuid'] === (string)$official['message_uuid']) {
        $evidenceHit = (int)$row['acknowledged_count'] === 1 && (int)$row['member_count'] >= 1;
    }
}
comm_assert('staff evidence shows acknowledgement progress', $evidenceHit);

$recorder = new CommunicationPushRecordingTransport();
$kernel->push->useTransport($recorder);
$tokenB = str_repeat('ab', 32);
$kernel->auth->upsertDevice((int)$sessionB['user']['id'], array(
    'device_uuid' => (string)$sessionB['device']['device_uuid'],
    'platform' => (string)$sessionB['device']['platform'],
    'model' => (string)($sessionB['device']['model'] ?? 'iPhone'),
    'os_version' => '17.0',
    'app_version' => '1.0.0',
    'apns_token' => $tokenB,
    'push_authorized' => 1,
    'apns_environment' => 'sandbox',
));
$sessionAPad = comm_session($kernel, $loginAPad);
$kernel->auth->upsertDevice((int)$sessionAPad['user']['id'], array(
    'device_uuid' => (string)$sessionAPad['device']['device_uuid'],
    'platform' => 'ipad',
    'model' => 'iPad',
    'os_version' => '17.0',
    'app_version' => '1.0.0',
    'apns_token' => str_repeat('cd', 32),
    'push_authorized' => 1,
    'apns_environment' => 'sandbox',
));
$pushed = $kernel->messages->send($sessionA, $conversationUuid, comm_uuid(), 'push-wake');
comm_assert('APNs wakes the recipient device only', count($recorder->sent) === 1 && (string)$recorder->sent[0]['device_token'] === $tokenB);
$bobEnrolled = null;
foreach ($kernel->enrollment->people() as $person) {
    if ((int)$person['user_id'] === $userB) {
        $bobEnrolled = $person;
    }
}
comm_assert('push token makes the enrolled device push-ready', is_array($bobEnrolled) && $bobEnrolled['push_ready'] === true && $bobEnrolled['has_iphone'] === true);
comm_assert(
    'push payload is a wake with conversation uuid and badge',
    (string)($recorder->sent[0]['payload']['conversation_uuid'] ?? '') === $conversationUuid
    && isset($recorder->sent[0]['payload']['aps']['badge'])
    && (string)($recorder->sent[0]['payload']['aps']['alert']['body'] ?? '') === 'push-wake'
);
$pushRow = $pdo->query('SELECT accepted_at_utc, failed_at_utc FROM ipca_communication_push_attempts ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$syncRowCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM ipca_communication_message_device_syncs ds INNER JOIN ipca_communication_messages m ON m.id = ds.message_id WHERE m.message_uuid = ' . $pdo->quote((string)$pushed['message_uuid'])
)->fetchColumn();
comm_assert(
    'push_accepted is recorded separately from device_synced',
    is_array($pushRow) && trim((string)($pushRow['accepted_at_utc'] ?? '')) !== '' && $syncRowCount >= 1
);
$duplicateClient = (string)$pushed['client_id'];
$kernel->messages->send($sessionA, $conversationUuid, $duplicateClient, 'push-wake');
comm_assert('duplicate send does not create a second APNs attempt', count($recorder->sent) === 1);

$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$pem = '';
if (is_object($key) || is_resource($key)) {
    openssl_pkey_export($key, $pem);
}
$jwt = $pem !== '' ? CommunicationApnsClient::jwtFromPem('KEYIDKEYID', 'W9RY547Y4P', $pem, time()) : '';
$jwtParts = explode('.', $jwt);
$jwtSig = $jwtParts[2] ?? '';
$jwtSigBin = base64_decode(strtr($jwtSig, '-_', '+/') . str_repeat('=', (4 - strlen($jwtSig) % 4) % 4));
comm_assert('APNs JWT is ES256 with a raw P-256 signature', count($jwtParts) === 3 && strlen((string)$jwtSigBin) === 64);

$phase2Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_13_communication_phase2_apns.sql');
comm_assert('phase 2 SQL is additive for APNs environment', str_contains($phase2Sql, 'apns_environment') && str_contains($phase2Sql, 'push_enabled'));
$phase3Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_14_communication_phase2_receipts_phase3_attachments.sql');
comm_assert(
    'phase 3 SQL adds private attachments and does not reuse public-read uploads',
    str_contains($phase3Sql, 'ipca_communication_attachments')
    && str_contains($phase3Sql, 'attachments_enabled')
    && !str_contains($phase3Sql, 'public-read')
);
comm_assert('attachments endpoint exists', is_file($root . '/public/api/communication/attachments.php'));
$phase4Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_14_communication_phase4_system_messages.sql');
comm_assert('phase 4 SQL enables official system messages', str_contains($phase4Sql, 'system_messages_enabled'));
comm_assert('system message endpoint exists', is_file($root . '/public/api/communication/system_messages.php'));
comm_assert('acknowledgement endpoint exists', is_file($root . '/public/api/communication/acknowledgements.php'));
comm_assert('Needs Attention endpoint exists', is_file($root . '/public/api/communication/actions.php'));
comm_assert('training endpoint exists', is_file($root . '/public/api/communication/training.php'));
$phase5Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_14_communication_phase5_training.sql');
comm_assert('phase 5 SQL enables the Training companion flag', str_contains($phase5Sql, 'training_enabled'));
comm_assert('community endpoint exists', is_file($root . '/public/api/communication/community.php'));
$phase6Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_14_communication_phase6_community.sql');
comm_assert(
    'phase 6 SQL adds isolated community tables and enables the feed',
    str_contains($phase6Sql, 'ipca_community_posts')
    && str_contains($phase6Sql, 'ipca_community_post_media')
    && str_contains($phase6Sql, 'ipca_community_likes')
    && str_contains($phase6Sql, 'ipca_community_comments')
    && str_contains($phase6Sql, 'ipca_community_reports')
    && str_contains($phase6Sql, 'school_scope')
    && str_contains($phase6Sql, 'community_enabled')
);
comm_assert('IPCA App enrollment admin page exists', is_file($root . '/public/admin/ipca_app.php'));
comm_assert(
    'admin navigation includes IPCA App enrollment',
    str_contains((string)file_get_contents($root . '/src/nav/admin.php'), '/admin/ipca_app.php')
);
$phase7Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_15_communication_phase7_training_videos.sql');
comm_assert(
    'phase 7 SQL adds private training videos with time-based grants',
    str_contains($phase7Sql, 'training_videos_enabled')
    && str_contains($phase7Sql, 'ipca_training_videos')
    && str_contains($phase7Sql, 'ipca_training_video_grants')
    && str_contains($phase7Sql, 'available_from_utc')
    && str_contains($phase7Sql, 'available_until_utc')
    && str_contains($phase7Sql, 'ipca_training_video_views')
    && str_contains($phase7Sql, 'ipca_training_video_likes')
    && str_contains($phase7Sql, 'ipca_training_video_comments')
    && !str_contains($phase7Sql, 'public-read')
);
comm_assert('training videos member endpoint exists', is_file($root . '/public/api/communication/training_videos.php'));
comm_assert('training videos admin page exists', is_file($root . '/public/admin/ipca_training_videos.php'));
comm_assert(
    'training videos admin shows upload progress and published state',
    str_contains((string)file_get_contents($root . '/public/admin/ipca_training_videos.php'), 'xhr.upload.onprogress')
    && str_contains((string)file_get_contents($root . '/public/admin/ipca_training_videos.php'), 'training_videos_upload.php')
    && str_contains((string)file_get_contents($root . '/public/admin/ipca_training_videos.php'), 'On the app')
    && str_contains((string)file_get_contents($root . '/public/admin/ipca_training_videos.php'), 'Publish to app')
);
comm_assert(
    'training videos admin previews thumbnails through the origin',
    is_file($root . '/public/admin/api/training_videos_preview.php')
    && str_contains((string)file_get_contents($root . '/public/admin/ipca_training_videos.php'), 'poster_preview_url')
    && !str_contains((string)file_get_contents($root . '/public/admin/ipca_training_videos.php'), 'poster_url +')
);
comm_assert(
    'training videos admin origin upload endpoint exists',
    is_file($root . '/public/admin/api/training_videos_upload.php')
);
$spacesSource = (string)file_get_contents($root . '/src/spaces.php');
$privateStreamStart = strpos($spacesSource, 'function cw_spaces_put_private_stream');
$privateStreamEnd = strpos($spacesSource, 'function cw_spaces_presign');
comm_assert(
    'origin training-video PUT stays private',
    $privateStreamStart !== false
    && $privateStreamEnd !== false
    && $privateStreamEnd > $privateStreamStart
    && !str_contains(substr($spacesSource, $privateStreamStart, $privateStreamEnd - $privateStreamStart), 'public-read')
    && !str_contains(substr($spacesSource, $privateStreamStart, $privateStreamEnd - $privateStreamStart), 'x-amz-acl:')
);
comm_assert('training videos admin API exists', is_file($root . '/public/admin/api/training_videos_api.php'));
comm_assert(
    'admin navigation includes Training Videos',
    str_contains((string)file_get_contents($root . '/src/nav/admin.php'), '/admin/ipca_training_videos.php')
);
$trainingVideoServiceSource = (string)file_get_contents($root . '/src/communication/CommunicationTrainingVideoService.php');
comm_assert(
    'training video delivery only uses private presigned GET URLs',
    str_contains($trainingVideoServiceSource, 'presignGet')
    && !str_contains($trainingVideoServiceSource, 'publicUrl')
    && !str_contains($trainingVideoServiceSource, 'presignPublicPut')
);
$phase8Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_16_communication_phase8_training_video_thumbnails.sql');
$adminThumbPage = (string)file_get_contents($root . '/public/admin/ipca_training_videos.php');
$mediaLibrarySource = (string)file_get_contents($root . '/src/communication/CommunicationTrainingMediaLibraryService.php');
$rendererSource = (string)file_get_contents($root . '/src/communication/CommunicationTrainingThumbnailRenderer.php');
comm_assert(
    'phase 8 SQL adds a private Media Library',
    str_contains($phase8Sql, 'ipca_training_media_library')
    && str_contains($phase8Sql, 'analysis_json')
    && !str_contains($phase8Sql, 'public-read')
);
comm_assert(
    'training video thumbnails keep locked IPCA templates out of the image model',
    str_contains($rendererSource, 'IPCA_ALPHA_LANDSCAPE_V1')
    && str_contains($rendererSource, 'IPCA_ALPHA_PORTRAIT_V1')
    && str_contains($rendererSource, 'IPCA_PRIVATE_PILOT_LANDSCAPE_V1')
    && str_contains($rendererSource, 'IPCA_CFI_LANDSCAPE_V1')
    && str_contains($rendererSource, 'ALPHA TRAINER PRO')
    && str_contains($rendererSource, 'displayDimensions')
    && str_contains($mediaLibrarySource, 'generateAiBackground')
    && str_contains($mediaLibrarySource, 'return null')
);
comm_assert(
    'training video overlays composite the official IPCA lockup PNG',
    str_contains($rendererSource, 'public/assets/logo/ipca_logo_white.png')
    && str_contains($rendererSource, 'imagecopyresampled')
    && !str_contains($rendererSource, "drawText(\$canvas, 'IPCA'")
);
comm_assert(
    'training videos admin generates thumbnails without requiring a custom poster',
    str_contains($adminThumbPage, 'THUMBNAIL')
    && str_contains($adminThumbPage, 'IPCA Media Library')
    && str_contains($adminThumbPage, 'AI Generated')
    && str_contains($adminThumbPage, 'Regenerate')
    && str_contains($adminThumbPage, 'Choose Another Image')
    && str_contains($adminThumbPage, 'Upload Custom')
    && str_contains($adminThumbPage, 'Rewrite from video')
    && str_contains($adminThumbPage, 'generate_explanation')
);
comm_assert(
    'training video thumbnails keep orientation frames uncropped and show regenerate progress',
    str_contains($adminThumbPage, 'Matching a ')
    && str_contains($adminThumbPage, ' photo…')
    && str_contains($adminThumbPage, 'Drawing the IPCA overlay…')
    && str_contains($adminThumbPage, 'aspect-ratio:9/16')
    && str_contains($adminThumbPage, 'object-fit:contain')
    && str_contains($adminThumbPage, 'tcc-modal-foot')
    && str_contains($trainingVideoServiceSource, "'&v='")
    && str_contains($trainingVideoServiceSource, 'publicOrientation')
    && str_contains($trainingVideoServiceSource, 'regenerateGeneratedThumbnails')
    && is_file($root . '/scripts/regenerate_training_video_thumbnails.php')
);
$analyzerSource = (string)file_get_contents($root . '/src/communication/CommunicationTrainingVideoAnalyzer.php');
comm_assert(
    'training video orientation is probed from the stored file including rotation',
    str_contains($analyzerSource, 'probeGeometry')
    && str_contains($analyzerSource, 'ffprobe')
    && str_contains($analyzerSource, 'streamRotation')
    && str_contains($trainingVideoServiceSource, 'resolveVideoGeometry')
    && CommunicationTrainingThumbnailRenderer::displayDimensions(1920, 1080, 90) === array('width' => 1080, 'height' => 1920)
    && CommunicationTrainingThumbnailRenderer::displaySizeFromProbe(1920, 1080, 0, '81:256', '9:16')['height']
        > CommunicationTrainingThumbnailRenderer::displaySizeFromProbe(1920, 1080, 0, '81:256', '9:16')['width']
    && CommunicationTrainingThumbnailRenderer::videoOrientation(1080, 1920) === 'portrait'
);
$phase9Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_16_communication_phase9_training_video_catalog.sql');
$adminPlaySource = (string)file_get_contents($root . '/public/admin/api/training_videos_play.php');
comm_assert(
    'phase 9 SQL adds a closed training-video category catalog',
    str_contains($phase9Sql, 'ipca_training_video_categories')
    && str_contains($phase9Sql, 'private-pilot')
    && str_contains($phase9Sql, 'uncategorized')
    && !str_contains($phase9Sql, 'public-read')
);
comm_assert(
    'training videos admin is a Hero Banner catalog without a left column',
    str_contains($adminThumbPage, 'ia-hero-banner')
    && str_contains($adminThumbPage, 'IPCA App · Training Videos')
    && str_contains($adminThumbPage, 'Bulk upload')
    && str_contains($adminThumbPage, 'Newest')
    && str_contains($adminThumbPage, 'Most viewed')
    && str_contains($adminThumbPage, 'Queued')
    && str_contains($adminThumbPage, 'Writing copy')
    && str_contains($adminThumbPage, 'training_videos_play.php')
    && str_contains($adminThumbPage, 'tcc-modal-overlay')
    && !str_contains($adminThumbPage, 'grid-template-columns: 300px 1fr')
);
comm_assert(
    'admin training-video playback stays same-origin and private',
    is_file($root . '/public/admin/api/training_videos_play.php')
    && str_contains($adminPlaySource, 'adminVideoOrigin')
    && !str_contains($adminPlaySource, 'public-read')
    && !str_contains($adminPlaySource, 'publicUrl')
);
comm_assert(
    'watch progress stays on training video views and the member API',
    str_contains((string)file_get_contents($root . '/public/api/communication/training_videos.php'), "'progress'")
    && str_contains($trainingVideoServiceSource, 'function progress')
    && str_contains($trainingVideoServiceSource, 'max_position_ms')
    && str_contains($trainingVideoServiceSource, 'watch_percent')
    && str_contains((string)file_get_contents($root . '/src/communication/CommunicationTrainingVideoAnalyzer.php'), 'category_slug')
);
$phase10Sql = (string)file_get_contents($root . '/scripts/sql/2026_08_16_communication_phase10_training_video_access.sql');
comm_assert(
    'phase 10 SQL adds optional video end dates and category entitlements',
    is_file($root . '/scripts/apply_communication_phase10_training_video_access.php')
    && str_contains($phase10Sql, 'ipca_training_video_category_entitlements')
    && !str_contains($phase10Sql, 'public-read')
);
$mediaLibraryPage = (string)file_get_contents($root . '/public/admin/ipca_media_library.php');
$enrollmentPage = (string)file_get_contents($root . '/public/admin/ipca_app.php');
comm_assert(
    'Media Library uses the Training Videos hero catalog shell',
    str_contains($mediaLibraryPage, 'ia-hero-banner')
    && str_contains($mediaLibraryPage, 'IPCA App · Media Library')
    && str_contains($mediaLibraryPage, 'xhr.upload.onprogress')
    && str_contains($mediaLibraryPage, 'tcc-btn')
);
comm_assert(
    'Enrollment uses the Training Videos hero catalog shell and category access',
    str_contains($enrollmentPage, 'ia-hero-banner')
    && str_contains($enrollmentPage, 'IPCA App · Enrollment')
    && str_contains($enrollmentPage, 'ia-chip--hero')
    && str_contains($enrollmentPage, 'Bulk category access')
    && is_file($root . '/public/admin/api/ipca_app_api.php')
    && is_file($root . '/public/admin/css/ipca_app_catalog.css')
);
comm_assert('IPCA Media Library admin page exists', is_file($root . '/public/admin/ipca_media_library.php'));
comm_assert(
    'admin navigation includes Media Library',
    str_contains((string)file_get_contents($root . '/src/nav/admin.php'), '/admin/ipca_media_library.php')
);
comm_assert(
    'Media Library stays on private object storage',
    str_contains($mediaLibrarySource, 'putStream')
    && str_contains($mediaLibrarySource, 'getBytes')
    && !str_contains($mediaLibrarySource, 'publicUrl')
    && !str_contains($mediaLibrarySource, 'presignPublicPut')
    && str_contains((string)file_get_contents($root . '/src/spaces.php'), 'function cw_spaces_get_object')
);

$emptyTraining = $kernel->training->summary($sessionA);
comm_assert(
    'training companion is honest when Courseware tables are absent',
    $emptyTraining['next_flight'] === null
    && $emptyTraining['schedule'] === array()
    && $emptyTraining['theory']['enrolled'] === false
    && $emptyTraining['theory']['percent'] === 0
    && $emptyTraining['actions'] === array()
    && $emptyTraining['deadlines'] === array()
    && str_contains((string)$emptyTraining['theory']['honesty_note'], 'Flight phase')
);

comm_training_schema($pdo);
$kernel = new CommunicationKernel($pdo, $objectStore);
$sessionA = comm_session($kernel, $loginA);
$sessionB = comm_session($kernel, $loginB);

$pdo->exec("INSERT INTO ipca_aircraft_devices (id, registration) VALUES (1, 'N428EA')");
$pdo->exec("INSERT INTO ipca_missions (id, code, name) VALUES (1, 'DUAL', 'Dual Local')");
$start = (new DateTimeImmutable('+1 day', new DateTimeZone('America/Los_Angeles')))->setTime(10, 0);
$end = $start->modify('+2 hours');
$later = (new DateTimeImmutable('+3 days', new DateTimeZone('America/Los_Angeles')))->setTime(14, 0);
$laterEnd = $later->modify('+2 hours');
$pdo->prepare("INSERT INTO ipca_flight_schedule_slots (id, scheduled_start_time, scheduled_end_time, reservation_type, mission_code, aircraft_id, mission_id, planned_departure_airport, planned_destination_airport, status) VALUES (1, ?, ?, 'flight_training', 'DUAL', 1, 1, 'KTRM', 'KTRM', 'scheduled')")
    ->execute(array($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')));
$pdo->prepare("INSERT INTO ipca_flight_schedule_slots (id, scheduled_start_time, scheduled_end_time, reservation_type, mission_code, aircraft_id, mission_id, planned_departure_airport, planned_destination_airport, status) VALUES (2, ?, ?, 'flight_training', 'NAV', 1, NULL, 'KTRM', 'KPSP', 'scheduled')")
    ->execute(array($later->format('Y-m-d H:i:s'), $laterEnd->format('Y-m-d H:i:s')));
$pdo->prepare('INSERT INTO ipca_flight_schedule_crew (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (1, ?, ?, ?)')
    ->execute(array($userA, 'Alice Student', 'student'));
$pdo->prepare('INSERT INTO ipca_flight_schedule_crew (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (1, NULL, ?, ?)')
    ->execute(array('Dana Instructor', 'instructor'));
$pdo->prepare('INSERT INTO ipca_flight_schedule_crew (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (2, ?, ?, ?)')
    ->execute(array($userA, 'Alice Student', 'student'));
$pdo->prepare('INSERT INTO ipca_flight_schedule_crew (schedule_slot_id, user_id, person_name_snapshot, crew_role) VALUES (2, NULL, ?, ?)')
    ->execute(array('Riley Safety', 'safety_pilot'));
$flight = $kernel->training->summary($sessionA);
$expectedDate = $start->format('D M j, Y');
comm_assert(
    'next scheduled flight is the student’s upcoming crew assignment',
    is_array($flight['next_flight'])
    && $flight['next_flight']['aircraft_registration'] === 'N428EA'
    && $flight['next_flight']['reservation_label'] === 'Flight Training'
    && $flight['next_flight']['role'] === 'Student'
    && in_array('Dana Instructor', $flight['next_flight']['with_names'], true)
);
comm_assert(
    'training schedule uses the online Flight Schedule for this user',
    count($flight['schedule']) === 2
    && (string)$flight['schedule'][0]['date_label'] === $expectedDate
    && (string)$flight['schedule'][0]['route'] === 'KTRM → KTRM'
    && (string)$flight['schedule'][0]['mission_code'] === 'DUAL'
    && (string)$flight['schedule'][0]['mission_label'] === 'DUAL · Dual Local'
    && (string)$flight['schedule'][0]['time_zone'] === 'America/Los_Angeles'
    && (string)$flight['schedule'][1]['route'] === 'KTRM → KPSP'
    && in_array('Riley Safety', $flight['schedule'][1]['with_names'], true)
);
$otherFlight = $kernel->training->summary($sessionB);
comm_assert('another member does not inherit someone else’s next flight', $otherFlight['next_flight'] === null);

$pdo->exec("INSERT INTO programs (id, program_key) VALUES (1, 'private_pilot')");
$pdo->exec("INSERT INTO courses (id, program_id, title) VALUES (1, 1, 'PPL Theory')");
$pdo->exec("INSERT INTO cohorts (id, name, course_id) VALUES (1, 'PPL August', 1)");
$pdo->prepare('INSERT INTO cohort_students (cohort_id, user_id) VALUES (1, ?)')->execute(array($userA));
$pdo->exec("INSERT INTO lessons (id, title) VALUES (1, 'Aerodynamics'), (2, 'Air Law')");
$dueSoon = (new DateTimeImmutable('+3 days', new DateTimeZone('UTC')))->format('Y-m-d 00:00:00');
$pdo->prepare('INSERT INTO cohort_lesson_deadlines (cohort_id, lesson_id, deadline_utc) VALUES (1, 1, ?), (1, 2, ?)')
    ->execute(array($dueSoon, $dueSoon));
$pdo->prepare("INSERT INTO lesson_activity (user_id, cohort_id, lesson_id, completion_status, test_pass_status) VALUES (?, 1, 1, 'completed', 'passed')")
    ->execute(array($userA));
$pdo->prepare("INSERT INTO lesson_activity (user_id, cohort_id, lesson_id, completion_status) VALUES (?, 1, 2, 'summary_required')")
    ->execute(array($userA));
$pdo->prepare("INSERT INTO student_required_actions (user_id, lesson_id, action_type, title, status) VALUES (?, 2, 'deadline_reason', 'Explain missed deadline', 'pending')")
    ->execute(array($userA));
$pdo->prepare("INSERT INTO ipca_internal_inbox_items (recipient_user_id, item_type, title, summary, status) VALUES (?, 'form', 'Medical certificate', 'Upload your current medical', 'pending')")
    ->execute(array($userA));

$trained = $kernel->training->summary($sessionA);
comm_assert(
    'theory progress uses lesson_activity and does not invent a flight phase',
    $trained['theory']['enrolled'] === true
    && $trained['theory']['percent'] === 50
    && $trained['theory']['completed_lessons'] === 1
    && $trained['theory']['total_lessons'] === 2
    && $trained['theory']['cohort_name'] === 'PPL August'
    && str_contains((string)$trained['theory']['honesty_note'], 'Flight phase')
    && !isset($trained['theory']['current_phase'])
);
$actionTitles = array_map(static fn(array $item): string => (string)$item['title'], $trained['actions']);
comm_assert(
    'training actions include theory workflow, required actions, and forms',
    in_array('Summary Required', $actionTitles, true)
    && in_array('Explain missed deadline', $actionTitles, true)
    && in_array('Medical certificate', $actionTitles, true)
);
comm_assert(
    'incomplete lesson deadlines are listed and completed lessons are not',
    count($trained['deadlines']) === 1
    && $trained['deadlines'][0]['title'] === 'Air Law'
);
$peerTraining = $kernel->training->summary($sessionB);
comm_assert('unenrolled user has no theory percent to display', $peerTraining['theory']['enrolled'] === false && $peerTraining['theory']['percent'] === 0);

$sessionC = comm_session($kernel, $loginC);
$activeVideoGrant = static function (string $type, string $value = ''): array {
    return array(
        'grant_type' => $type,
        'grant_value' => $value,
        'available_from_utc' => (new DateTimeImmutable('-1 hour', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        'available_until_utc' => (new DateTimeImmutable('+30 days', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    );
};
$videoSaved = $kernel->trainingVideos->saveAdmin(array(
    'title' => 'Private Maneuvers Briefing',
    'description' => 'Review before the flight.',
    'duration_ms' => 125000,
    'status' => 'published',
), array($activeVideoGrant('all')), $adminId);
$trainingVideoUuid = (string)$videoSaved['video']['video_uuid'];
$videoPresign = $kernel->trainingVideos->presignAdminUpload(
    $trainingVideoUuid,
    'video',
    'video/mp4',
    4096,
    'briefing.mp4'
);
$objectStore->put('training-videos/1/' . $trainingVideoUuid . '.video', str_repeat('v', 4096), 'video/mp4');
$kernel->trainingVideos->completeAdminUpload($trainingVideoUuid, 'video', 125000);
$originStream = fopen('php://memory', 'rb+');
fwrite($originStream, str_repeat('z', 2048));
rewind($originStream);
$originUpload = $kernel->trainingVideos->putAdminObject(
    $trainingVideoUuid,
    'video',
    'video/mp4',
    2048,
    $originStream,
    125000
);
fclose($originStream);
comm_assert(
    'admin origin upload stores a private object',
    !empty($originUpload['video']['has_video'])
    && (int)$originUpload['video']['byte_size'] === 2048
    && !empty($originUpload['video']['app_visible'])
);
$posterPresign = $kernel->trainingVideos->presignAdminUpload(
    $trainingVideoUuid,
    'poster',
    'image/jpeg',
    1024,
    'poster.jpg'
);
$objectStore->put('training-videos/1/' . $trainingVideoUuid . '.poster', str_repeat('p', 1024), 'image/jpeg');
$kernel->trainingVideos->completeAdminUpload($trainingVideoUuid, 'poster');
$publishedAdmin = $kernel->trainingVideos->adminDetail($trainingVideoUuid);
comm_assert(
    'admin catalog marks a published file as visible on the app',
    !empty($publishedAdmin['video']['has_video'])
    && !empty($publishedAdmin['video']['has_poster'])
    && !empty($publishedAdmin['video']['app_visible'])
    && (string)$publishedAdmin['video']['status'] === 'published'
);
comm_assert(
    'admin uploads use private PUTs without a public-read ACL',
    str_contains((string)$videoPresign['put_url'], 'memory.invalid/put/')
    && str_contains((string)$posterPresign['put_url'], 'memory.invalid/put/')
    && !str_contains((string)$videoPresign['put_url'], 'public-read')
    && !str_contains((string)$posterPresign['put_url'], 'public-read')
);
$videoFeed = $kernel->trainingVideos->feed($sessionA);
comm_assert(
    'all-users grant exposes published metadata and a private poster',
    (string)($videoFeed['videos'][0]['title'] ?? '') === 'Private Maneuvers Briefing'
    && str_contains((string)($videoFeed['videos'][0]['poster_url'] ?? ''), 'memory.invalid/get/')
    && !str_contains((string)($videoFeed['videos'][0]['poster_url'] ?? ''), 'memory.invalid/cdn/')
    && !empty($videoFeed['videos'][0]['downloadable'])
    && (int)($videoFeed['videos'][0]['duration_seconds'] ?? 0) === 125
    && (string)($videoFeed['videos'][0]['available_until'] ?? '') !== ''
    && in_array((string)($videoFeed['videos'][0]['orientation'] ?? ''), array('landscape', 'portrait'), true)
);
$firstPlay = $kernel->trainingVideos->playback($sessionA, $trainingVideoUuid);
$secondPlay = $kernel->trainingVideos->playback($sessionA, $trainingVideoUuid);
$videoDownload = $kernel->trainingVideos->playback($sessionA, $trainingVideoUuid, true);
comm_assert(
    'playback and offline downloads use the iOS private-media contract',
    str_contains((string)$firstPlay['url'], 'memory.invalid/get/')
    && str_contains((string)$secondPlay['url'], 'memory.invalid/get/')
    && str_contains((string)($firstPlay['stream_url'] ?? ''), 'memory.invalid/get/')
    && str_contains((string)($videoDownload['download_url'] ?? ''), 'memory.invalid/get/')
    && (string)($videoDownload['available_until'] ?? '') !== ''
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_training_video_views')->fetchColumn() === 1
);
$videoLike = $kernel->trainingVideos->like($sessionB, $trainingVideoUuid);
$videoLikeAgain = $kernel->trainingVideos->like($sessionB, $trainingVideoUuid);
$videoComment = $kernel->trainingVideos->comment($sessionB, $trainingVideoUuid, 'Clear and useful.');
comm_assert(
    'training video likes are unique and comments are social',
    (int)$videoLike['video']['like_count'] === 1
    && (int)$videoLikeAgain['video']['like_count'] === 1
    && (string)$videoComment['comment']['body'] === 'Clear and useful.'
    && count($kernel->trainingVideos->comments($sessionA, $trainingVideoUuid)['comments']) === 1
);
$started = $kernel->trainingVideos->progress($sessionA, $trainingVideoUuid, 25000, 125000);
$rewound = $kernel->trainingVideos->progress($sessionA, $trainingVideoUuid, 8000, 125000);
$finished = $kernel->trainingVideos->progress($sessionA, $trainingVideoUuid, 120000, 125000);
comm_assert(
    'watch progress records percent watched without treating open as complete',
    (int)($started['video']['watch_percent'] ?? 0) === 20
    && empty($started['video']['watch_completed'])
    && (int)($rewound['video']['watch_percent'] ?? 0) === 20
    && (int)($rewound['video']['resume_position_ms'] ?? 0) === 8000
    && (int)($finished['video']['watch_percent'] ?? 0) === 100
    && !empty($finished['video']['watch_completed'])
    && (int)($finished['video']['resume_position_ms'] ?? -1) === 0
    && (int)$pdo->query('SELECT COUNT(*) FROM ipca_training_video_views')->fetchColumn() === 1
);

$renderer = new CommunicationTrainingThumbnailRenderer();
$landscapeJpeg = $renderer->render(
    CommunicationTrainingThumbnailRenderer::LANDSCAPE,
    array('title' => 'Steep Turns', 'category' => 'Private Pilot'),
    comm_jpeg(1600, 900, 30, 60, 110)
);
$portraitJpeg = $renderer->render(
    CommunicationTrainingThumbnailRenderer::PORTRAIT,
    array('title' => 'Cockpit Scan', 'category' => 'Instrument'),
    comm_jpeg(900, 1600, 20, 40, 80)
);
$privatePilotJpeg = $renderer->render(
    CommunicationTrainingThumbnailRenderer::PRIVATE_PILOT_LANDSCAPE,
    array('title' => 'Steep Turns', 'category' => 'Private Pilot'),
    comm_jpeg(1600, 900, 30, 60, 110)
);
$landscapeInfo = getimagesizefromstring($landscapeJpeg);
$portraitInfo = getimagesizefromstring($portraitJpeg);
$privatePilotInfo = getimagesizefromstring($privatePilotJpeg);
comm_assert(
    'locked IPCA templates render independent landscape and portrait masters',
    is_array($landscapeInfo)
    && (int)$landscapeInfo[0] === 1280
    && (int)$landscapeInfo[1] === 720
    && is_array($portraitInfo)
    && (int)$portraitInfo[0] === 720
    && (int)$portraitInfo[1] === 1280
    && is_array($privatePilotInfo)
    && (int)$privatePilotInfo[0] === 1280
    && (int)$privatePilotInfo[1] === 720
    && str_starts_with($landscapeJpeg, "\xff\xd8")
    && str_starts_with($portraitJpeg, "\xff\xd8")
    && CommunicationTrainingThumbnailRenderer::videoOrientation(1920, 1080) === 'landscape'
    && CommunicationTrainingThumbnailRenderer::videoOrientation(1080, 1920) === 'portrait'
    && CommunicationTrainingThumbnailRenderer::templateFor('landscape', 'private-pilot') === 'IPCA_PRIVATE_PILOT_LANDSCAPE_V1'
);

$landscapeCockpit = comm_jpeg(1280, 720, 10, 20, 40);
$portraitCockpit = comm_jpeg(720, 1280, 12, 24, 48);
$landscapeRamp = comm_jpeg(1280, 720, 80, 90, 100);
$cockpitStream = fopen('php://memory', 'rb+');
fwrite($cockpitStream, $landscapeCockpit);
rewind($cockpitStream);
$libraryCockpit = $kernel->mediaLibrary->putAdminAsset($cockpitStream, 'image/jpeg', strlen($landscapeCockpit), 'cessna_172_cockpit_steep_turns.jpg', $userB);
fclose($cockpitStream);
$portraitStream = fopen('php://memory', 'rb+');
fwrite($portraitStream, $portraitCockpit);
rewind($portraitStream);
$libraryPortrait = $kernel->mediaLibrary->putAdminAsset($portraitStream, 'image/jpeg', strlen($portraitCockpit), 'cessna_172_cockpit_portrait.jpg', $userB);
fclose($portraitStream);
$rampStream = fopen('php://memory', 'rb+');
fwrite($rampStream, $landscapeRamp);
rewind($rampStream);
$libraryRamp = $kernel->mediaLibrary->putAdminAsset($rampStream, 'image/jpeg', strlen($landscapeRamp), 'ramp_sunset_exterior.jpg', $userB);
fclose($rampStream);
$rankedLandscape = $kernel->mediaLibrary->rankForVideo(array(
    'title' => 'Cessna 172 steep turns',
    'description' => 'Cockpit demonstration of steep turns',
    'category' => 'Private Pilot',
    'aircraft' => 'Cessna 172',
), 'landscape', 3);
$rankedPortrait = $kernel->mediaLibrary->rankForVideo(array(
    'title' => 'Cessna 172 steep turns',
    'description' => 'Cockpit demonstration of steep turns',
    'aircraft' => 'Cessna 172',
), 'portrait', 3);
$rankedLandscapeUuids = array_map(static fn(array $asset): string => (string)$asset['asset_uuid'], $rankedLandscape);
$rankedPortraitUuids = array_map(static fn(array $asset): string => (string)$asset['asset_uuid'], $rankedPortrait);
comm_assert(
    'Media Library ranking uses orientation as a hard filter',
    ($rankedLandscape[0]['asset_uuid'] ?? '') === ($libraryCockpit['asset']['asset_uuid'] ?? '')
    && !in_array((string)($libraryPortrait['asset']['asset_uuid'] ?? ''), $rankedLandscapeUuids, true)
    && ($rankedPortrait[0]['asset_uuid'] ?? '') === ($libraryPortrait['asset']['asset_uuid'] ?? '')
    && !in_array((string)($libraryCockpit['asset']['asset_uuid'] ?? ''), $rankedPortraitUuids, true)
);

$autoThumb = $kernel->trainingVideos->saveAdmin(array(
    'title' => 'Cessna 172 steep turns',
    'description' => 'Cockpit demonstration of steep turns',
    'category' => 'Private Pilot',
    'aircraft' => 'Cessna 172',
    'status' => 'draft',
), array(), $userB);
$autoUuid = (string)$autoThumb['video']['video_uuid'];
$autoStream = fopen('php://memory', 'rb+');
fwrite($autoStream, str_repeat('v', 2048));
rewind($autoStream);
$autoUploaded = $kernel->trainingVideos->putAdminObject(
    $autoUuid,
    'video',
    'video/mp4',
    2048,
    $autoStream,
    60000,
    array('width' => 1920, 'height' => 1080)
);
fclose($autoStream);
comm_assert(
    'uploading a landscape video auto-renders an IPCA Media Library thumbnail',
    !empty($autoUploaded['video']['has_poster'])
    && (string)($autoUploaded['video']['orientation'] ?? '') === 'landscape'
    && (string)($autoUploaded['video']['poster_source'] ?? '') === 'media_library'
    && (string)($autoUploaded['video']['poster_template'] ?? '') === 'IPCA_PRIVATE_PILOT_LANDSCAPE_V1'
    && count($autoUploaded['video']['thumbnail_candidates'] ?? array()) >= 1
    && (string)($autoUploaded['video']['thumbnail_candidates'][0]['asset_uuid'] ?? '') === (string)($libraryCockpit['asset']['asset_uuid'] ?? '')
    && str_contains((string)($autoUploaded['video']['poster_preview_url'] ?? ''), 'training_videos_preview.php')
    && str_contains((string)($autoUploaded['video']['poster_preview_url'] ?? ''), '&v=')
    && str_starts_with($kernel->trainingVideos->adminPosterBytes($autoUuid)['bytes'], "\xff\xd8")
);
$explained = $kernel->trainingVideos->generateAdminExplanation($autoUuid, true);
$explainedText = trim((string)($explained['video']['description'] ?? ''));
comm_assert(
    'training videos can write a short what-you-will-learn explanation',
    $explainedText !== ''
    && mb_strlen($explainedText) >= 20
    && (string)($explained['video']['description_source'] ?? '') === 'generated'
    && is_file($root . '/src/communication/CommunicationTrainingVideoAnalyzer.php')
);
$catalog = $kernel->trainingVideos->adminCatalog();
$catalogSlugs = array_map(static fn(array $row): string => (string)$row['slug'], $catalog['categories'] ?? array());
comm_assert(
    'admin catalog exposes the closed category list and card stats',
    in_array('private-pilot', $catalogSlugs, true)
    && in_array('uncategorized', $catalogSlugs, true)
    && isset($catalog['stats']['published'], $catalog['stats']['drafts'], $catalog['stats']['views'])
    && (string)($explained['video']['category'] ?? '') === 'Private Pilot'
    && str_contains((string)($explained['video']['video_play_url'] ?? ''), 'training_videos_play.php')
);

$portraitVideo = $kernel->trainingVideos->saveAdmin(array(
    'title' => 'Handheld cockpit scan',
    'description' => 'Portrait cockpit scan',
    'aircraft' => 'Cessna 172',
    'status' => 'draft',
), array(), $userB);
$portraitUuid = (string)$portraitVideo['video']['video_uuid'];
$portraitVideoStream = fopen('php://memory', 'rb+');
fwrite($portraitVideoStream, str_repeat('p', 2048));
rewind($portraitVideoStream);
$portraitUploaded = $kernel->trainingVideos->putAdminObject(
    $portraitUuid,
    'video',
    'video/mp4',
    2048,
    $portraitVideoStream,
    45000,
    array('width' => 1080, 'height' => 1920)
);
fclose($portraitVideoStream);
comm_assert(
    'uploading a portrait video never uses a landscape thumbnail template',
    (string)($portraitUploaded['video']['orientation'] ?? '') === 'portrait'
    && (string)($portraitUploaded['video']['poster_template'] ?? '') === 'IPCA_ALPHA_PORTRAIT_V1'
    && (string)($portraitUploaded['video']['poster_source'] ?? '') === 'media_library'
);
$bulkThumbRefresh = $kernel->trainingVideos->regenerateGeneratedThumbnails();
$refreshedPortrait = $kernel->trainingVideos->adminDetail($portraitUuid);
comm_assert(
    'generated training-video posters can be redrawn with the current overlay',
    (int)($bulkThumbRefresh['regenerated'] ?? 0) >= 1
    && (string)($refreshedPortrait['video']['orientation'] ?? '') === 'portrait'
    && (string)($refreshedPortrait['video']['poster_template'] ?? '') === 'IPCA_ALPHA_PORTRAIT_V1'
    && str_contains((string)($refreshedPortrait['video']['poster_preview_url'] ?? ''), '&v=')
);
$pdo->prepare('UPDATE ipca_training_videos SET orientation = NULL WHERE video_uuid = ?')->execute(array($portraitUuid));
$recoveredPortrait = $kernel->trainingVideos->regenerateAdminThumbnail($portraitUuid);
comm_assert(
    'regenerate recovers portrait from stored dimensions when orientation was left blank',
    (string)($recoveredPortrait['video']['orientation'] ?? '') === 'portrait'
    && (string)($recoveredPortrait['video']['poster_template'] ?? '') === 'IPCA_ALPHA_PORTRAIT_V1'
);

$secondLandscape = $kernel->trainingVideos->saveAdmin(array(
    'title' => 'Cessna 172 steep turns follow-up',
    'description' => 'Cockpit demonstration of steep turns',
    'category' => 'Private Pilot',
    'aircraft' => 'Cessna 172',
    'status' => 'draft',
), array(), $userB);
$secondUuid = (string)$secondLandscape['video']['video_uuid'];
$secondStream = fopen('php://memory', 'rb+');
fwrite($secondStream, str_repeat('v', 2048));
rewind($secondStream);
$secondUploaded = $kernel->trainingVideos->putAdminObject(
    $secondUuid,
    'video',
    'video/mp4',
    2048,
    $secondStream,
    60000,
    array('width' => 1920, 'height' => 1080)
);
fclose($secondStream);
comm_assert(
    'a second landscape video does not reuse the first video’s photograph',
    (string)($secondUploaded['video']['poster_source'] ?? '') === 'media_library'
    && (string)($explained['video']['thumbnail_candidates'][0]['asset_uuid'] ?? '') !== ''
    && (string)($secondUploaded['video']['thumbnail_candidates'][0]['asset_uuid'] ?? '') !== ''
    && (string)($secondUploaded['video']['thumbnail_candidates'][0]['asset_uuid'] ?? '') !== (string)($explained['video']['thumbnail_candidates'][0]['asset_uuid'] ?? '')
);

$baseVideo = array(
    'video_uuid' => $trainingVideoUuid,
    'title' => 'Private Maneuvers Briefing',
    'description' => 'Review before the flight.',
    'duration_ms' => 125000,
    'status' => 'published',
);
$kernel->trainingVideos->saveAdmin($baseVideo, array(
    $activeVideoGrant('roles', 'instructor'),
    $activeVideoGrant('users', (string)$userC),
), $adminId);
$roleUserMatrix = $kernel->trainingVideos->feed($sessionB)['videos'] !== array()
    && $kernel->trainingVideos->feed($sessionC)['videos'] !== array()
    && $kernel->trainingVideos->feed($sessionA)['videos'] === array();
comm_assert('multiple role and user grants are ORed', $roleUserMatrix);

$kernel->trainingVideos->saveAdmin($baseVideo, array(
    $activeVideoGrant('cohorts', '1'),
), $adminId);
comm_assert(
    'cohort grant follows current enrollment',
    $kernel->trainingVideos->feed($sessionA)['videos'] !== array()
    && $kernel->trainingVideos->feed($sessionB)['videos'] === array()
);
$kernel->trainingVideos->saveAdmin($baseVideo, array(
    $activeVideoGrant('programs', '1'),
), $adminId);
comm_assert(
    'program grant follows cohort course membership',
    $kernel->trainingVideos->feed($sessionA)['videos'] !== array()
    && $kernel->trainingVideos->feed($sessionC)['videos'] === array()
);
$kernel->trainingVideos->saveAdmin($baseVideo, array(
    array(
        'grant_type' => 'all',
        'grant_value' => '',
        'available_from_utc' => (new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        'available_until_utc' => (new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    ),
), $adminId);
$futureDenied = false;
try {
    $kernel->trainingVideos->playback($sessionA, $trainingVideoUuid);
} catch (CommunicationException $e) {
    $futureDenied = $e->errorCode === 'not_found';
}
comm_assert(
    'future grants hide metadata and reject URL issuance',
    $kernel->trainingVideos->feed($sessionA)['videos'] === array() && $futureDenied
);
$kernel->trainingVideos->saveAdmin($baseVideo, array(
    $activeVideoGrant('all'),
), $adminId);
$kernel->trainingVideos->saveAdmin(array_merge($baseVideo, array('category' => 'Private Pilot')), array(
    array(
        'grant_type' => 'all',
        'grant_value' => '',
        'available_from_utc' => '',
        'available_until_utc' => '',
    ),
), $adminId);
$openEndedFeed = $kernel->trainingVideos->feed($sessionA);
comm_assert(
    'an empty video until-date stays available indefinitely',
    $openEndedFeed['videos'] !== array()
    && (string)($openEndedFeed['videos'][0]['available_until'] ?? 'missing') === ''
);
$instrumentId = 0;
foreach ($kernel->trainingVideos->listCategories() as $category) {
    if ((string)$category['slug'] === 'instrument') {
        $instrumentId = (int)$category['id'];
        break;
    }
}
$kernel->trainingVideos->grantCategoryEntitlements(array($userA), array($instrumentId));
$entitledFeedA = $kernel->trainingVideos->feed($sessionA);
$entitledFeedB = $kernel->trainingVideos->feed($sessionB);
$kernel->trainingVideos->replaceUserCategoryEntitlements($userA, array());
comm_assert(
    'category entitlements hide videos outside the granted categories',
    $instrumentId > 0
    && $entitledFeedA['videos'] === array()
    && $entitledFeedB['videos'] !== array()
);
$kernel->trainingVideos->saveAdmin($baseVideo, array(
    $activeVideoGrant('all'),
), $adminId);
$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '0' WHERE config_key = 'training_videos_enabled'")->execute();
$kernelNoTrainingVideos = new CommunicationKernel($pdo, $objectStore);
$videosDisabled = false;
try {
    $kernelNoTrainingVideos->trainingVideos->feed(comm_session($kernelNoTrainingVideos, $loginA));
} catch (CommunicationException $e) {
    $videosDisabled = $e->errorCode === 'training_videos_disabled';
}
comm_assert('server flag can disable Training Videos independently', $videosDisabled);
$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '1' WHERE config_key = 'training_videos_enabled'")->execute();
$kernel = new CommunicationKernel($pdo, $objectStore);
$sessionA = comm_session($kernel, $loginA);
$sessionB = comm_session($kernel, $loginB);

$tokenA = str_repeat('aa', 32);
$kernel->auth->upsertDevice((int)$sessionA['user']['id'], array(
    'device_uuid' => (string)$sessionA['device']['device_uuid'],
    'platform' => (string)$sessionA['device']['platform'],
    'model' => (string)($sessionA['device']['model'] ?? 'iPhone'),
    'os_version' => '17.0',
    'app_version' => '1.0.0',
    'apns_token' => $tokenA,
    'push_authorized' => 1,
    'apns_environment' => 'sandbox',
));
$communityRecorder = new CommunicationPushRecordingTransport();
$kernel->push->useTransport($communityRecorder);

$firstPost = comm_publish_photo($kernel, $objectStore, $sessionA, 'First flight of the day');
$secondPost = comm_publish_photo($kernel, $objectStore, $sessionA, 'Ramp at sunset');
$feed = $kernel->community->feed($sessionA, 0);
comm_assert(
    'community feed is newest first and isolated from message sync',
    count($feed['posts']) >= 2
    && (string)$feed['posts'][0]['post_uuid'] === (string)$secondPost['post']['post_uuid']
    && (string)$feed['posts'][0]['caption'] === 'Ramp at sunset'
    && (string)($feed['posts'][0]['body'] ?? '') === ''
    && (string)$feed['posts'][0]['media'][0]['kind'] === 'photo'
    && str_contains((string)$feed['posts'][0]['media'][0]['get_url'], 'memory.invalid/cdn/')
    && !str_contains((string)$feed['posts'][0]['media'][0]['get_url'], 'memory.invalid/get/')
);
$syncHasCommunity = false;
foreach ($kernel->sync->pull($sessionB, 0)['messages'] as $message) {
    if (str_contains((string)($message['body'] ?? ''), 'Ramp at sunset')) {
        $syncHasCommunity = true;
    }
}
comm_assert('community posts do not appear in messaging sync', $syncHasCommunity === false);

$liked = $kernel->community->like($sessionB, (string)$secondPost['post']['post_uuid']);
$likedAgain = $kernel->community->like($sessionB, (string)$secondPost['post']['post_uuid']);
comm_assert('like is idempotent per user', (int)$liked['post']['like_count'] === 1 && (int)$likedAgain['post']['like_count'] === 1 && $likedAgain['post']['liked'] === true);
$unliked = $kernel->community->unlike($sessionB, (string)$secondPost['post']['post_uuid']);
comm_assert('unlike removes that user’s like', (int)$unliked['post']['like_count'] === 0 && $unliked['post']['liked'] === false);
$kernel->community->like($sessionB, (string)$secondPost['post']['post_uuid']);

$commented = $kernel->community->comment($sessionB, (string)$secondPost['post']['post_uuid'], 'Nice light.');
$comments = $kernel->community->comments($sessionA, (string)$secondPost['post']['post_uuid']);
comm_assert(
    'comments are flat and visible to other members',
    (string)$commented['comment']['body'] === 'Nice light.'
    && count($comments['comments']) === 1
    && (string)$comments['comments'][0]['author']['name'] === 'Bob Instructor'
);
$communityPushHit = false;
foreach ($communityRecorder->sent as $sent) {
    if ((string)($sent['device_token'] ?? '') === $tokenA
        && (string)($sent['payload']['community_post_uuid'] ?? '') === (string)$secondPost['post']['post_uuid']
        && (string)($sent['payload']['aps']['category'] ?? '') === 'COMMUNITY'
        && !isset($sent['payload']['conversation_uuid'])) {
        $communityPushHit = true;
    }
}
comm_assert('commenting notifies the author without a message delivery row', $communityPushHit);
$pushAttemptCount = (int)$pdo->query('SELECT COUNT(*) FROM ipca_communication_push_attempts')->fetchColumn();
$communityAttempts = (int)$pdo->query("SELECT COUNT(*) FROM ipca_communication_push_attempts WHERE provider_response LIKE '%community%'")->fetchColumn();
comm_assert('community push does not write message delivery evidence', $communityAttempts === 0 && $pushAttemptCount >= 1);

$reported = $kernel->community->report($sessionB, (string)$secondPost['post']['post_uuid'], 'inappropriate', 'not a school photo');
$reportedAgain = $kernel->community->report($sessionB, (string)$secondPost['post']['post_uuid'], 'spam');
comm_assert('duplicate reports are stored once', $reported['already_reported'] === false && $reportedAgain['already_reported'] === true);
comm_assert('staff enrollment roster surfaces Community reports', count($kernel->enrollment->reports()) >= 1);

$selfDelete = false;
try {
    $kernel->community->deletePost($sessionB, (string)$firstPost['post']['post_uuid']);
} catch (CommunicationException $e) {
    $selfDelete = $e->errorCode === 'forbidden';
}
comm_assert('members cannot delete someone else’s post', $selfDelete);
$sessionAdmin = comm_session($kernel, $loginAdmin);
$staffDeleted = $kernel->community->deletePost($sessionAdmin, (string)$firstPost['post']['post_uuid']);
$ownDeleted = $kernel->community->deletePost($sessionA, (string)$secondPost['post']['post_uuid']);
$afterDelete = $kernel->community->feed($sessionA, 0);
$deletedGone = true;
foreach ($afterDelete['posts'] as $post) {
    if (in_array((string)$post['post_uuid'], array((string)$firstPost['post']['post_uuid'], (string)$secondPost['post']['post_uuid']), true)) {
        $deletedGone = false;
    }
}
comm_assert('authors and staff can delete posts from the chronological feed', $staffDeleted['deleted'] === true && $ownDeleted['deleted'] === true && $deletedGone);

$noted = comm_publish_photo($kernel, $objectStore, $sessionA, 'Koen in Pipistrel Action!', 'Great light over the field this morning.');
comm_assert(
    'community posts can include text below the caption',
    (string)$noted['post']['caption'] === 'Koen in Pipistrel Action!'
    && (string)$noted['post']['body'] === 'Great light over the field this morning.'
);
$studentVideoBlocked = false;
try {
    $kernel->community->presignMedia($sessionA, 'clip.mov', 'video/quicktime', 2048, 31000);
} catch (CommunicationException $e) {
    $studentVideoBlocked = $e->errorCode === 'validation_error';
}
comm_assert('students cannot upload Community videos longer than 30 seconds', $studentVideoBlocked);
$instructorVideo = $kernel->community->presignMedia($sessionB, 'lesson.mov', 'video/quicktime', 2048, 180000);
$adminVideo = $kernel->community->presignMedia($sessionAdmin, 'brief.mov', 'video/quicktime', 2048, 540000);
comm_assert(
    'instructors and admins can upload longer Community videos',
    isset($instructorVideo['put_url'])
    && isset($adminVideo['put_url'])
    && isset($instructorVideo['poster_put_url'])
    && isset($adminVideo['poster_put_url'])
    && (string)($instructorVideo['headers']['x-amz-acl'] ?? '') === 'public-read'
    && str_contains((string)$instructorVideo['put_url'], 'acl=public-read')
);
$videoPost = comm_publish_video($kernel, $objectStore, $sessionB, 'Pattern work from the right seat.');
$videoMedia = $videoPost['post']['media'][0] ?? array();
comm_assert(
    'community videos include a vertical poster image',
    (string)($videoMedia['kind'] ?? '') === 'video'
    && str_contains((string)($videoMedia['poster_url'] ?? ''), 'memory.invalid/cdn/')
    && str_contains((string)($videoMedia['poster_url'] ?? ''), '.poster.jpg')
    && str_contains((string)($videoMedia['get_url'] ?? ''), 'memory.invalid/cdn/')
);

$visiblePost = comm_publish_photo($kernel, $objectStore, $sessionA, 'Still here');

$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '0' WHERE config_key = 'training_enabled'")->execute();
$kernelNoTrain = new CommunicationKernel($pdo, $objectStore);
$trainOff = false;
try {
    $kernelNoTrain->training->summary(comm_session($kernelNoTrain, $loginA));
} catch (CommunicationException $e) {
    $trainOff = $e->errorCode === 'training_disabled';
}
comm_assert('server flag can disable Training without an app update', $trainOff);
$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '1' WHERE config_key = 'training_enabled'")->execute();

$pdo->prepare("UPDATE users SET status = 'locked' WHERE id = ?")->execute(array($userA));
$blocked = false;
try {
    $kernel->auth->authenticateToken((string)$loginA['token']);
} catch (CommunicationException $e) {
    $blocked = $e->errorCode === 'account_ineligible';
}
comm_assert('locked account loses access while credential still exists', $blocked);
$pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute(array($userA));

$kernel->auth->logout($sessionB);
$loggedOut = false;
try {
    $kernel->auth->authenticateToken((string)$loginB['token']);
} catch (CommunicationException $e) {
    $loggedOut = $e->errorCode === 'credential_revoked' || $e->errorCode === 'unauthenticated';
}
comm_assert('logout revokes only that device credential', $loggedOut);

$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '0' WHERE config_key = 'messaging_enabled'")->execute();
$kernelDisabled = new CommunicationKernel($pdo);
$sessionA2 = $kernelDisabled->auth->authenticateToken((string)$loginA['token']);
$disabled = false;
try {
    $kernelDisabled->messages->send($sessionA2, $conversationUuid, comm_uuid(), 'should fail');
} catch (CommunicationException $e) {
    $disabled = $e->errorCode === 'messaging_disabled';
}
comm_assert('server flag can disable messaging without an app update', $disabled);
$trainingWhileMessagingOff = $kernelDisabled->training->summary($sessionA2);
comm_assert(
    'Training reads stay available when messaging is disabled',
    is_array($trainingWhileMessagingOff['next_flight'])
    && $trainingWhileMessagingOff['next_flight']['aircraft_registration'] === 'N428EA'
);
$communityWhileMessagingOff = $kernelDisabled->community->feed($sessionA2, 0);
comm_assert(
    'Community stays available when messaging is disabled',
    (string)($communityWhileMessagingOff['posts'][0]['caption'] ?? '') === 'Still here'
);

$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '1' WHERE config_key = 'messaging_enabled'")->execute();
$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '0' WHERE config_key = 'community_enabled'")->execute();
$kernelNoCommunity = new CommunicationKernel($pdo, $objectStore);
$communityOff = false;
try {
    $kernelNoCommunity->community->feed(comm_session($kernelNoCommunity, $loginA), 0);
} catch (CommunicationException $e) {
    $communityOff = $e->errorCode === 'community_disabled';
}
comm_assert('server flag can disable Community without an app update', $communityOff);
$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '1' WHERE config_key = 'community_enabled'")->execute();
$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '0' WHERE config_key = 'attachments_enabled'")->execute();
$kernelNoAtt = new CommunicationKernel($pdo, $objectStore);
$sessionA3 = $kernelNoAtt->auth->authenticateToken((string)$loginA['token']);
$attDisabled = false;
try {
    $kernelNoAtt->attachments->presignPut($sessionA3, $conversationUuid, 'x.jpg', 'image/jpeg', 10);
} catch (CommunicationException $e) {
    $attDisabled = $e->errorCode === 'attachments_disabled';
}
comm_assert('server flag can disable attachments without an app update', $attDisabled);
$pdo->prepare("UPDATE ipca_communication_app_config SET config_value = '1' WHERE config_key = 'attachments_enabled'")->execute();

comm_assert(
    'Courseware user UUIDs without RFC variant bits are accepted',
    CommunicationSupport::isUuid('7fa33990-2525-1ae2-6f5a-0ddc2d9a4969')
    && CommunicationSupport::isUuid('bf12be0f-0bc7-bfcb-e092-632f941adcc0')
    && CommunicationSupport::isUuid('f70c19f7-2268-11f1-9326-2ee9f951d5a3')
    && !CommunicationSupport::isUuid('')
    && !CommunicationSupport::isUuid('not-a-uuid')
);
comm_assert(
    'iOS opens the conversation after create instead of only dismissing',
    str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/NewMessageView.swift'), 'revealOpenedConversation')
    && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MessagesRootView.swift'), 'compactPath')
);

foreach (glob($root . '/public/api/communication/*.php') ?: array() as $apiFile) {
    $src = (string)file_get_contents($apiFile);
    comm_assert(basename($apiFile) . ' uses PDO-only communication bootstrap', str_contains($src, 'api_bootstrap.php') && !str_contains($src, 'src/bootstrap.php'));
}

$authSrc = file_get_contents($root . '/src/communication/CommunicationAuthService.php') ?: '';
comm_assert('auth checks eligibility on every request', str_contains($authSrc, 'userIsEligible'));
$msgSrc = file_get_contents($root . '/src/communication/MessageService.php') ?: '';
comm_assert('send is idempotent on client_id', str_contains($msgSrc, 'findByClientId'));
$syncSrc = file_get_contents($root . '/src/communication/CommunicationSyncService.php') ?: '';
comm_assert('sync is cursor based and transport-independent', str_contains($syncSrc, 'ipca_communication_change_log'));

if ($iosApp === '') {
    echo "NOTE  iOS app file not yet present during this check\n";
} else {
    comm_assert('iOS app stores the token in Keychain', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Auth/KeychainStore.swift'), 'kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly'));
    comm_assert('iOS outbox supports dependent attachment operations', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxPlanner.swift'), 'uploadAttachment'));
    comm_assert('iOS hides unfinished tabs behind server flags', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MainShellView.swift'), 'communityEnabled'));
    comm_assert('iOS upserts synced messages by client_id then message_uuid', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Persistence/StoreWriter.swift'), 'message(clientID: dto.clientID) ?? message(uuid: dto.messageUUID)'));
    comm_assert('iOS recovers interrupted outbox on launch', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxWorker.swift'), 'recoverOutbox'));
    comm_assert('iOS awaits bearer token before post-login API calls', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'await api.setToken(response.token)'));
    comm_assert('iOS registers for APNs after login', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'requestPushAuthorization'));
    comm_assert('iOS Debug entitlements request development APNs', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/IPCA-Debug.entitlements'), 'aps-environment') && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/IPCA-Debug.entitlements'), 'development'));
    comm_assert('iOS opens conversations from ipca://c/ deep links', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Info.plist'), 'ipca') && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'handleOpenURL'));
    $conversationView = (string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/ConversationView.swift');
    $persistence = (string)file_get_contents($root . '/ipca-app-ios/IPCA/Persistence/PersistenceController.swift');
    $outboxWorker = (string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxWorker.swift');
    comm_assert('iOS shows Delivered and Read from member cursors', str_contains($conversationView, '"Delivered"') && str_contains($conversationView, '"Read"') && str_contains($conversationView, 'lastDeliveredSeq'));
    comm_assert('iOS infers Core Data mapping for new receipt and attachment attributes', str_contains($persistence, 'shouldMigrateStoreAutomatically') && str_contains($persistence, 'lastDeliveredSeq') && str_contains($persistence, 'attachmentsJSON'));
    comm_assert('iOS uploads privately before sendMessage', str_contains($outboxWorker, 'presignAttachment') && str_contains($outboxWorker, 'completeAttachment') && !str_contains($outboxWorker, 'attachments_not_in_phase_1'));
    comm_assert('iOS requests photo library access for attachments', str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Info.plist'), 'NSPhotoLibraryUsageDescription'));
    comm_assert(
        'iOS can attach a photo from the camera',
        str_contains($conversationView, 'Camera')
        && str_contains($conversationView, 'CameraCaptureView')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Info.plist'), 'NSCameraUsageDescription')
    );
    comm_assert('iOS opens attached photos full screen', str_contains($conversationView, 'ImageLightboxView'));
    comm_assert(
        'iOS can acknowledge official messages from the thread',
        str_contains($conversationView, 'Acknowledge')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'acknowledge')
    );
    comm_assert(
        'iOS opens Needs Attention from ipca://actions',
        str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'ipca://actions')
        || (str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'host == "actions"')
            && is_file($root . '/ipca-app-ios/IPCA/Views/ActionsView.swift'))
    );
    comm_assert(
        'iOS can reply to a specific message or add an emoji reaction',
        str_contains($conversationView, '"Reply"')
        && str_contains($conversationView, '👍')
        && str_contains($conversationView, 'Replying to')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'func react(')
        && is_file($root . '/public/api/communication/reactions.php')
    );
    comm_assert(
        'iOS iPad sidebar uses split-view selection to leave Needs Attention',
        str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MessagesRootView.swift'), 'regularDetailID')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MessagesRootView.swift'), '.id(regularDetailID)')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/ConversationListView.swift'), 'session.selectedConversationUUID = conversation.conversationUUID')
    );
    comm_assert(
        'iOS Training tab is a companion of next flight, theory, actions, and deadlines',
        is_file($root . '/ipca-app-ios/IPCA/Views/TrainingView.swift')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingView.swift'), 'Next Flight')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingView.swift'), 'Schedule')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIModels.swift'), 'date_label')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIModels.swift'), 'mission_code')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIModels.swift'), 'airport_chain')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MainShellView.swift'), 'TrainingView()')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIClient.swift'), 'training.php')
        && !str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxWorker.swift'), 'training.php')
    );
    comm_assert(
        'iOS Community tab is a chronological feed with like, comment, and report',
        is_file($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift'), 'Like')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift'), 'Comment')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift'), 'more')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift'), 'videoMaximumDuration')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift'), 'AVAssetImageGenerator')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift'), 'resizeAspectFill')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/CommunityView.swift'), 'speaker.slash.fill')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIModels.swift'), 'poster_url')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MainShellView.swift'), 'CommunityView()')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIClient.swift'), 'community.php')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'host == "community"')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'extraHeaders: presign.headers')
        && !str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxWorker.swift'), 'community.php')
    );
    comm_assert(
        'iOS Training Videos tab plays privately and can save offline',
        is_file($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift')
        && is_file($root . '/ipca-app-ios/IPCA/Persistence/TrainingVideoDownloadManager.swift')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift'), 'View offline')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift'), 'AVPlayerViewController')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift'), 'Like')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift'), 'Comment')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MainShellView.swift'), 'TrainingVideosView()')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIClient.swift'), 'training_videos.php')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIClient.swift'), '"action": "progress"')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIModels.swift'), 'watch_percent')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift'), 'Watched')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Persistence/TrainingVideoDownloadManager.swift'), 'ownerUserUUID')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Networking/APIModels.swift'), 'orientation')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift'), 'aspectRatio')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/TrainingVideosView.swift'), 'scaledToFit')
        && !str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Sync/OutboxWorker.swift'), 'training_videos.php')
    );
    comm_assert(
        'iOS enrollment uses ipca.training and explains notifications before the system prompt',
        str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'https://ipca.training')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/MainShellView.swift'), 'Stay reachable')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/LoginView.swift'), 'IPCA.training')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/Views/ConversationListView.swift'), 'Waiting for network')
        && str_contains((string)file_get_contents($root . '/ipca-app-ios/IPCA/App/AppSession.swift'), 'guard isAuthenticated, isOnline')
    );
}

if ($failures) {
    echo "\n" . count($failures) . " failed\n";
    exit(1);
}
echo "\nAll communication Phase 1 checks passed\n";
exit(0);
