<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';

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

$editingId = max(0, (int)($_GET['edit'] ?? 0));
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
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
            $status = tv_clean_enum((string)($_POST['status'] ?? ''), ['draft','active','inactive','archived'], 'inactive');
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
            $type = tv_clean_enum((string)($_POST['message_type'] ?? ''), ['standard','urgent','schedule','night'], 'standard');
            $status = tv_clean_enum((string)($_POST['status'] ?? ''), ['draft','active','inactive','archived'], 'draft');
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $priority = max(0, min(100, (int)($_POST['priority'] ?? 10)));
            $duration = max(5, min(120, (int)($_POST['display_duration_seconds'] ?? 12)));
            $startsAt = tv_dt_or_null((string)($_POST['starts_at'] ?? ''));
            $endsAt = tv_dt_or_null((string)($_POST['ends_at'] ?? ''));
            $announce = isset($_POST['announce_audio_enabled']) ? 1 : 0;
            $voiceText = trim((string)($_POST['voice_text'] ?? ''));
            $audioUrl = trim((string)($_POST['audio_url'] ?? ''));

            if ($title === '' || $body === '') {
                throw new RuntimeException('Title and board body are required.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE tv_screen_messages
                    SET screen_key = ?,
                        message_type = ?,
                        title = ?,
                        body = ?,
                        priority = ?,
                        starts_at = ?,
                        ends_at = ?,
                        display_duration_seconds = ?,
                        announce_audio_enabled = ?,
                        voice_text = ?,
                        audio_url = ?,
                        status = ?
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([
                    $screenKey,
                    $type,
                    $title,
                    $body,
                    $priority,
                    $startsAt,
                    $endsAt,
                    $duration,
                    $announce,
                    $voiceText !== '' ? $voiceText : null,
                    $audioUrl !== '' ? $audioUrl : null,
                    $status,
                    $id,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO tv_screen_messages (
                        screen_key,
                        message_type,
                        title,
                        body,
                        priority,
                        starts_at,
                        ends_at,
                        display_duration_seconds,
                        announce_audio_enabled,
                        voice_text,
                        audio_url,
                        status,
                        created_by
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $screenKey,
                    $type,
                    $title,
                    $body,
                    $priority,
                    $startsAt,
                    $endsAt,
                    $duration,
                    $announce,
                    $voiceText !== '' ? $voiceText : null,
                    $audioUrl !== '' ? $audioUrl : null,
                    $status,
                    $uid > 0 ? $uid : null,
                ]);
            }

            redirect('/admin/tv_screens/index.php?updated=1');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['updated'])) {
    $notice = 'TV screen message saved.';
}
if (isset($_GET['deleted'])) {
    $notice = 'TV screen message deleted.';
}

$editRow = null;
if ($editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tv_screen_messages WHERE id = ? LIMIT 1');
    $stmt->execute([$editingId]);
    $editRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmt = $pdo->query("
    SELECT
        m.*,
        u.name AS creator_name
    FROM tv_screen_messages m
    LEFT JOIN users u ON u.id = m.created_by
    ORDER BY
      CASE WHEN m.status = 'active' THEN 0 WHEN m.status = 'draft' THEN 1 ELSE 2 END ASC,
      CASE WHEN m.message_type = 'urgent' OR m.priority >= 90 THEN 0 ELSE 1 END ASC,
      m.priority DESC,
      m.updated_at DESC,
      m.id DESC
");
$messages = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

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
    'audio_url' => '',
    'status' => 'draft',
);
$form = array_merge($defaults, is_array($editRow) ? $editRow : array());

cw_header('TV Flip Board Screens');
?>
<style>
.tv-admin{display:grid;grid-template-columns:minmax(340px,440px) minmax(0,1fr);gap:24px;align-items:start}
.tv-hero{
  margin-bottom:20px;
  padding:24px 26px;
  border-radius:24px;
  color:#fff;
  background:
    radial-gradient(circle at 12% 0%, rgba(229,173,57,.24), transparent 30%),
    linear-gradient(135deg,#101827,#172033 58%,#0b1220);
  box-shadow:0 18px 46px rgba(15,23,42,.18), inset 0 1px 0 rgba(255,255,255,.08);
}
.tv-hero-kicker{font-size:12px;font-weight:900;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.74)}
.tv-hero h2{margin:8px 0 8px;font-size:30px;line-height:1.05;color:#fff}
.tv-hero p{margin:0;max-width:980px;line-height:1.55;color:rgba(255,255,255,.86)}
.tv-panel{
  background:#fff;
  border:1px solid rgba(15,23,42,.08);
  border-radius:22px;
  box-shadow:0 12px 30px rgba(15,23,42,.06);
  overflow:hidden;
}
.tv-panel-head{padding:18px 20px;border-bottom:1px solid rgba(15,23,42,.07)}
.tv-panel-title{margin:0;font-size:18px;color:#102845}
.tv-panel-body{padding:18px 20px}
.tv-form{display:flex;flex-direction:column;gap:14px}
.tv-form label{display:flex;flex-direction:column;gap:7px;color:#334155;font-size:13px;font-weight:800}
.tv-form input,.tv-form select,.tv-form textarea{
  width:100%;
  border:1px solid rgba(15,23,42,.13);
  border-radius:12px;
  padding:10px 12px;
  font:inherit;
}
.tv-form textarea{min-height:132px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;line-height:1.45}
.tv-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.tv-check{flex-direction:row!important;align-items:center}
.tv-check input{width:auto}
.tv-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.tv-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:0;
  border-radius:999px;
  padding:10px 14px;
  font-weight:900;
  text-decoration:none;
  cursor:pointer;
  background:#0f2e52;
  color:#fff;
}
.tv-btn.secondary{background:#eef2f7;color:#102845}
.tv-btn.danger{background:#991b1b}
.tv-btn.amber{background:#b7791f}
.tv-alert{border-radius:14px;padding:12px 14px;margin-bottom:14px;font-weight:800}
.tv-alert.ok{background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
.tv-alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.tv-table-wrap{overflow:auto}
.tv-table{width:100%;border-collapse:separate;border-spacing:0;min-width:980px}
.tv-table th{
  background:#f8fafc;
  color:#334155;
  font-size:12px;
  letter-spacing:.08em;
  text-transform:uppercase;
  text-align:left;
  padding:13px 14px;
  border-bottom:1px solid rgba(15,23,42,.08);
}
.tv-table td{padding:14px;border-bottom:1px solid rgba(15,23,42,.06);vertical-align:top;color:#1e293b}
.tv-message-title{font-weight:900;color:#0f172a;margin-bottom:4px}
.tv-message-body{color:#64748b;font-size:13px;line-height:1.35;max-width:360px;white-space:pre-line}
.tv-pill{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900;text-transform:uppercase}
.tv-pill.active{background:#dcfce7;color:#166534}
.tv-pill.draft{background:#eef2ff;color:#3730a3}
.tv-pill.inactive,.tv-pill.archived{background:#f1f5f9;color:#475569}
.tv-pill.urgent{background:#fee2e2;color:#991b1b}
.tv-pill.schedule{background:#fef3c7;color:#92400e}
.tv-preview-link{font-size:13px;color:#1d4ed8;font-weight:900;text-decoration:none}
@media(max-width:1100px){.tv-admin{grid-template-columns:1fr}.tv-grid-2{grid-template-columns:1fr}}
</style>

<div class="tv-hero">
  <div class="tv-hero-kicker">Airport Operations Display System</div>
  <h2>Physical split-flap screen control</h2>
  <p>Create operational messages, urgent overrides, schedule rows, and airport PA announcements for Chrome kiosk screens running on Mac Mini displays.</p>
</div>

<?php if ($notice !== ''): ?><div class="tv-alert ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="tv-alert err"><?= h($error) ?></div><?php endif; ?>

<div class="tv-admin">
  <section class="tv-panel">
    <div class="tv-panel-head">
      <h3 class="tv-panel-title"><?= ((int)$form['id'] > 0) ? 'Edit Board Message' : 'Create Board Message' ?></h3>
    </div>
    <div class="tv-panel-body">
      <form class="tv-form" method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h((string)$form['id']) ?>">

        <div class="tv-grid-2">
          <label>Screen
            <input name="screen_key" value="<?= h((string)$form['screen_key']) ?>" maxlength="64" required>
          </label>
          <label>Mode
            <select name="message_type">
              <?php foreach (['standard','urgent','schedule','night'] as $type): ?>
                <option value="<?= h($type) ?>" <?= $form['message_type'] === $type ? 'selected' : '' ?>><?= h(strtoupper($type)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label>Board Title
          <input name="title" value="<?= h((string)$form['title']) ?>" maxlength="160" required>
        </label>

        <label>Board Body
          <textarea name="body" required><?= h((string)$form['body']) ?></textarea>
        </label>

        <div class="tv-grid-2">
          <label>Priority
            <input type="number" name="priority" min="0" max="100" value="<?= h((string)$form['priority']) ?>">
          </label>
          <label>Display Seconds
            <input type="number" name="display_duration_seconds" min="5" max="120" value="<?= h((string)$form['display_duration_seconds']) ?>">
          </label>
        </div>

        <div class="tv-grid-2">
          <label>Starts At
            <input type="datetime-local" name="starts_at" value="<?= h(tv_admin_dt($form['starts_at'] ?? null)) ?>">
          </label>
          <label>Ends At
            <input type="datetime-local" name="ends_at" value="<?= h(tv_admin_dt($form['ends_at'] ?? null)) ?>">
          </label>
        </div>

        <div class="tv-grid-2">
          <label>Status
            <select name="status">
              <?php foreach (['draft','active','inactive','archived'] as $status): ?>
                <option value="<?= h($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= h(strtoupper($status)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Audio URL
            <input name="audio_url" value="<?= h((string)$form['audio_url']) ?>" maxlength="512" placeholder="/tv/assets/audio/announcements/ops.mp3">
          </label>
        </div>

        <label class="tv-check">
          <input type="checkbox" name="announce_audio_enabled" value="1" <?= ((int)$form['announce_audio_enabled'] === 1) ? 'checked' : '' ?>>
          Enable chime and PA announcement
        </label>

        <label>Voice Text
          <textarea name="voice_text" placeholder="Professional airport PA text for pre-generated MP3 or future TTS generation."><?= h((string)$form['voice_text']) ?></textarea>
        </label>

        <div class="tv-actions">
          <button class="tv-btn" type="submit">Save Message</button>
          <?php if ((int)$form['id'] > 0): ?>
            <a class="tv-btn secondary" href="/admin/tv_screens/index.php">Create New</a>
          <?php endif; ?>
          <a class="tv-preview-link" href="/tv/flipboard.php?screen=<?= h((string)$form['screen_key']) ?>" target="_blank" rel="noopener">Open kiosk preview</a>
        </div>
      </form>
    </div>
  </section>

  <section class="tv-panel">
    <div class="tv-panel-head">
      <h3 class="tv-panel-title">Messages</h3>
    </div>
    <div class="tv-table-wrap">
      <table class="tv-table">
        <thead>
          <tr>
            <th>Message</th>
            <th>Screen</th>
            <th>Type</th>
            <th>Status</th>
            <th>Schedule</th>
            <th>Audio</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($messages as $row): ?>
            <tr>
              <td>
                <div class="tv-message-title"><?= h((string)$row['title']) ?></div>
                <div class="tv-message-body"><?= h((string)$row['body']) ?></div>
              </td>
              <td><?= h((string)$row['screen_key']) ?></td>
              <td><span class="tv-pill <?= h((string)$row['message_type']) ?>"><?= h((string)$row['message_type']) ?></span><br>Priority <?= h((string)$row['priority']) ?></td>
              <td><span class="tv-pill <?= h((string)$row['status']) ?>"><?= h((string)$row['status']) ?></span></td>
              <td><?= h(tv_label_dt($row['starts_at'] ?? null)) ?><br>to <?= h(tv_label_dt($row['ends_at'] ?? null)) ?></td>
              <td><?= ((int)$row['announce_audio_enabled'] === 1) ? 'PA enabled' : 'Board only' ?></td>
              <td>
                <div class="tv-actions">
                  <a class="tv-btn secondary" href="/admin/tv_screens/index.php?edit=<?= h((string)$row['id']) ?>">Edit</a>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= h((string)$row['id']) ?>">
                    <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active' ?>">
                    <button class="tv-btn amber" type="submit"><?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                  </form>
                  <form method="post" onsubmit="return confirm('Delete this TV screen message?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= h((string)$row['id']) ?>">
                    <button class="tv-btn danger" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($messages) === 0): ?>
            <tr><td colspan="7">No TV screen messages yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php
cw_footer();
