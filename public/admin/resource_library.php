<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';

cw_require_admin();

/**
 * @return array{ok: bool, rows: list<array<string, mixed>>, error?: string}
 */
function rl_fetch_editions(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT id, title, revision_code, revision_date, status, thumbnail_path, work_code, sort_order, created_at, updated_at
            FROM resource_library_editions
            ORDER BY FIELD(status, 'live', 'draft', 'archived'), sort_order ASC, title ASC, id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['ok' => true, 'rows' => $rows];
    } catch (Throwable $e) {
        return ['ok' => false, 'rows' => [], 'error' => $e->getMessage()];
    }
}

function rl_table_missing_message(string $dbError): string
{
    if (stripos($dbError, "doesn't exist") !== false || stripos($dbError, 'Unknown table') !== false) {
        return 'The resource library table is not installed yet. Apply the migration '
            . '<code>scripts/sql/resource_library_editions.sql</code> to this database, then reload.';
    }

    return h($dbError);
}

function rl_thumb_src(?string $path): string
{
    $p = trim((string)$path);
    if ($p === '') {
        return '/assets/icons/documents.svg';
    }
    if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
        return $p;
    }
    if ($p[0] !== '/') {
        return '/' . $p;
    }

    return $p;
}

function rl_status_label(string $status): string
{
    return match ($status) {
        'live' => 'Live',
        'draft' => 'Draft',
        'archived' => 'Archived',
        default => $status,
    };
}

function rl_status_class(string $status): string
{
    return match ($status) {
        'live' => 'rl-status rl-status-live',
        'draft' => 'rl-status rl-status-draft',
        'archived' => 'rl-status rl-status-archived',
        default => 'rl-status',
    };
}

$result = rl_fetch_editions($pdo);
$rows = $result['rows'];
$tableError = (!$result['ok']) ? ($result['error'] ?? 'Unknown error') : '';

cw_header('Resource Library');
?>
<style>
  .rl-wrap { max-width: 1100px; }
  .rl-intro {
    color: #64748b;
    font-size: 14px;
    line-height: 1.5;
    margin: 0 0 20px;
  }
  .rl-alert {
    border: 1px solid #f59e0b;
    background: #fffbeb;
    color: #92400e;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 14px;
  }
  .rl-alert code { font-size: 13px; background: rgba(255,255,255,.7); padding: 2px 6px; border-radius: 6px; }
  .rl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 18px;
  }
  .rl-card {
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .rl-card-thumb {
    aspect-ratio: 4 / 3;
    background: linear-gradient(145deg, #f1f5f9, #e2e8f0);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .rl-card-thumb img {
    max-width: 100%;
    max-height: 140px;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
  }
  .rl-card-body { padding: 14px 16px 16px; flex: 1; display: flex; flex-direction: column; gap: 8px; }
  .rl-card-title {
    font-size: 16px;
    font-weight: 600;
    color: #0f172a;
    line-height: 1.35;
    margin: 0;
  }
  .rl-meta { font-size: 13px; color: #475569; display: flex; flex-direction: column; gap: 4px; }
  .rl-meta dt { float: left; clear: left; width: 7.5rem; color: #64748b; font-weight: 500; }
  .rl-meta dd { margin: 0 0 0 7.5rem; color: #0f172a; }
  .rl-status {
    display: inline-flex;
    align-items: center;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 4px 10px;
    border-radius: 999px;
    width: fit-content;
    margin-top: 4px;
  }
  .rl-status-live { background: #dcfce7; color: #166534; }
  .rl-status-draft { background: #f1f5f9; color: #475569; }
  .rl-status-archived { background: #fee2e2; color: #991b1b; }
  .rl-empty {
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    padding: 28px 20px;
    text-align: center;
    color: #64748b;
    font-size: 14px;
    background: #f8fafc;
  }
</style>

<div class="rl-wrap">
  <p class="rl-intro">
    Controlled reference editions available to the platform (handbooks, manuals). Uploads and JSON imports will be added here;
    for now this list reflects what is stored in the database.
  </p>

  <?php if (!$result['ok']): ?>
    <div class="rl-alert"><?= rl_table_missing_message($tableError) ?></div>
  <?php elseif ($rows === []): ?>
    <div class="rl-empty">No editions yet. After running <code>scripts/sql/resource_library_editions.sql</code>, add rows in
      <code>resource_library_editions</code> or extend this UI with upload tools.</div>
  <?php else: ?>
    <div class="rl-grid">
      <?php foreach ($rows as $row): ?>
        <?php
          $title = (string)($row['title'] ?? '');
          $revCode = (string)($row['revision_code'] ?? '');
          $revDate = (string)($row['revision_date'] ?? '');
          $status = (string)($row['status'] ?? 'draft');
          $thumb = rl_thumb_src(isset($row['thumbnail_path']) ? (string)$row['thumbnail_path'] : '');
          $ts = $revDate !== '' ? strtotime($revDate . 'T12:00:00') : false;
          $revDisplay = ($ts !== false) ? date('F j, Y', $ts) : '—';
        ?>
        <article class="rl-card">
          <div class="rl-card-thumb">
            <img src="<?= h($thumb) ?>" alt="" loading="lazy" width="200" height="150">
          </div>
          <div class="rl-card-body">
            <h2 class="rl-card-title"><?= h($title) ?></h2>
            <dl class="rl-meta">
              <dt>Version</dt>
              <dd><?= h($revCode) ?></dd>
              <dt>Revision date</dt>
              <dd><?= h($revDisplay) ?></dd>
              <dt>Status</dt>
              <dd><span class="<?= h(rl_status_class($status)) ?>"><?= h(rl_status_label($status)) ?></span></dd>
            </dl>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php cw_footer(); ?>
