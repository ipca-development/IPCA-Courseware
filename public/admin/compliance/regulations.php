<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceRegulatoryLinkEngine.php';
require_once __DIR__ . '/../../../src/resource_library_aim.php';

compliance_require_access($pdo);

$q = trim((string)($_GET['q'] ?? ''));
$corpus = strtolower(trim((string)($_GET['corpus'] ?? 'all')));
if (!in_array($corpus, array('all', 'aim', 'easa'), true)) {
    $corpus = 'all';
}
$findingId = isset($_GET['finding_id']) ? (int)$_GET['finding_id'] : 0;

$findingCtx = null;

/**
 * @return list<array<string,mixed>>
 */
function cmp_reg_search_aim(PDO $pdo, string $needle): array
{
    if (!rl_aim_tables_present($pdo) || $needle === '') {
        return array();
    }
    $like = '%' . cmp_reg_escape_like($needle) . '%';
    $st = $pdo->prepare(
        'SELECT id, paragraph_number, display_title, canonical_url,
                SUBSTRING(COALESCE(body_text, \'\'), 1, 220) AS excerpt
         FROM resource_library_aim_paragraphs
         WHERE citation_status = \'active\'
           AND (display_title LIKE ? ESCAPE \'\\\\\' OR body_text LIKE ? ESCAPE \'\\\\\'
             OR paragraph_number LIKE ? ESCAPE \'\\\\\')
         ORDER BY id DESC
         LIMIT 40'
    );
    $st->execute(array($like, $like, $like));

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cmp_reg_escape_like(string $s): string
{
    return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $s);
}

/**
 * @return list<array<string,mixed>>
 */
function cmp_reg_search_easa(PDO $pdo, string $needle): array
{
    if (!ComplianceRegulatoryLinkEngine::easaStagingPresent($pdo) || $needle === '') {
        return array();
    }
    $like = '%' . cmp_reg_escape_like($needle) . '%';
    $st = $pdo->prepare(
        'SELECT id, batch_id, node_uid, source_erules_id,
                SUBSTRING(COALESCE(title, \'\'), 1, 200) AS title_ex,
                SUBSTRING(COALESCE(plain_text, \'\'), 1, 220) AS excerpt
         FROM easa_erules_import_nodes_staging
         WHERE plain_text LIKE ? ESCAPE \'\\\\\'
            OR title LIKE ? ESCAPE \'\\\\\'
            OR source_erules_id LIKE ? ESCAPE \'\\\\\'
         ORDER BY id DESC
         LIMIT 40'
    );
    $st->execute(array($like, $like, $like));

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($findingId > 0) {
    $st = $pdo->prepare('SELECT id, finding_code, title FROM ipca_compliance_findings WHERE id = ? LIMIT 1');
    $st->execute(array($findingId));
    $findingCtx = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$aimHits = array();
$easaHits = array();
if ($q !== '') {
    if ($corpus === 'all' || $corpus === 'aim') {
        try {
            $aimHits = cmp_reg_search_aim($pdo, $q);
        } catch (Throwable $e) {
            $aimHits = array();
        }
    }
    if ($corpus === 'all' || $corpus === 'easa') {
        try {
            $easaHits = cmp_reg_search_easa($pdo, $q);
        } catch (Throwable $e) {
            $easaHits = array();
        }
    }
}

cw_header('Compliance · Regulations');

$aimOk = rl_aim_tables_present($pdo);
$easaOk = ComplianceRegulatoryLinkEngine::easaStagingPresent($pdo);
?>
<section class="compliance-reg-search" style="padding:12px 0 48px;">
  <style>
    .compliance-reg-search .reg-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 28px;margin-bottom:20px;max-width:1100px;}
    .compliance-reg-search .reg-overline{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:800;margin-bottom:6px;}
    .compliance-reg-search h1{margin:0 0 8px;font-size:24px;font-weight:800;color:#0f172a;}
    .compliance-reg-search .pill{display:inline-block;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;}
    .compliance-reg-search table{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;}
    .compliance-reg-search th,.compliance-reg-search td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:top;}
    .compliance-reg-search th{background:#f8fafc;font-size:12px;color:#475569;}
    .compliance-reg-search .ex{color:#64748b;font-size:12px;line-height:1.4;margin-top:4px;}
    .compliance-reg-search input[type=text],.compliance-reg-search select{padding:8px 10px;border-radius:8px;border:1px solid #cbd5e1;}
    .compliance-reg-search button.attach{background:#1e3c72;color:#fff;border:0;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer;font-size:12px;}
  </style>

  <div class="reg-card">
    <div class="reg-overline">Phase 3c — Regulatory bridge</div>
    <h1>Regulation search</h1>
    <p style="color:#334155;line-height:1.55;margin:0 0 16px;max-width:820px;">
      Search indexed FAA AIM paragraphs and EASA Easy Access (staging) nodes, then attach a citation to a finding.
      Verifier hooks in <code>resource_library_source_verify.php</code> remain the authority for edition freshness — this page only creates <code>ipca_compliance_finding_regulatory_links</code> rows.
    </p>
    <p style="margin:0 0 12px;font-size:13px;color:#64748b;">
      Libraries:
      <span class="pill"><?= $aimOk ? 'AIM table OK' : 'AIM table missing' ?></span>
      <span class="pill" style="margin-left:6px;"><?= $easaOk ? 'EASA staging OK' : 'EASA staging missing' ?></span>
    </p>

    <?php if ($findingCtx): ?>
      <p style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:12px;padding:12px 16px;margin:0 0 16px;color:#065f46;font-size:14px;">
        Attaching to <strong><?= h((string)$findingCtx['finding_code']) ?></strong>
        — <?= h((string)$findingCtx['title']) ?>
      </p>
    <?php else: ?>
      <p style="background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:12px 16px;margin:0 0 16px;color:#92400e;font-size:14px;">
        Open a finding and use <strong>Search regulations & attach</strong>, or add <code>?finding_id=…</code> to this URL to enable attach buttons.
      </p>
    <?php endif; ?>

    <form method="get" action="/admin/compliance/regulations.php" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:8px;">
      <?php if ($findingId > 0): ?>
        <input type="hidden" name="finding_id" value="<?= (int)$findingId ?>">
      <?php endif; ?>
      <label style="margin:0;">
        <span style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Query</span>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="e.g. FCL, parallel approaches, fuel…" style="min-width:280px;">
      </label>
      <label style="margin:0;">
        <span style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Corpus</span>
        <select name="corpus">
          <option value="all" <?= $corpus === 'all' ? 'selected' : '' ?>>All available</option>
          <option value="aim" <?= $corpus === 'aim' ? 'selected' : '' ?>>FAA AIM only</option>
          <option value="easa" <?= $corpus === 'easa' ? 'selected' : '' ?>>EASA eRules only</option>
        </select>
      </label>
      <button type="submit" class="attach" style="background:#0f172a;">Search</button>
    </form>

    <?php if ($q !== '' && !$aimOk && !$easaOk): ?>
      <p class="queue-status is-danger" style="margin-top:12px;">No regulatory tables available — apply resource library SQL migrations.</p>
    <?php endif; ?>
  </div>

  <?php if ($q !== '' && ($corpus === 'all' || $corpus === 'aim') && $aimOk && $aimHits !== array()): ?>
    <div class="reg-card">
      <h2 style="margin:0 0 6px;font-size:18px;">FAA AIM (<?= count($aimHits) ?>)</h2>
      <table>
        <thead><tr><th>Paragraph</th><th>Title / excerpt</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($aimHits as $row):
              $id = (int)$row['id'];
              $pn = trim((string)($row['paragraph_number'] ?? ''));
              $dt = trim((string)($row['display_title'] ?? ''));
              $lab = $pn !== '' ? ('AIM ' . $pn) : ($dt !== '' ? $dt : ('Paragraph #' . $id));
              $canon = trim((string)($row['canonical_url'] ?? ''));
              ?>
            <tr>
              <td style="font-family:ui-monospace,monospace;font-size:12px;"><?= h($pn !== '' ? $pn : ('#' . $id)) ?></td>
              <td>
                <div><strong><?= h($dt !== '' ? $dt : $lab) ?></strong></div>
                <?php if (!empty($row['excerpt'])): ?>
                  <div class="ex"><?= h((string)$row['excerpt']) ?>…</div>
                <?php endif; ?>
                <?php if ($canon !== ''): ?>
                  <div class="ex"><a href="<?= h($canon) ?>" target="_blank" rel="noopener">Open canonical URL</a></div>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <?php if ($findingCtx): ?>
                  <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$findingId ?>" style="display:inline;">
                    <input type="hidden" name="action" value="attach_regulation_link">
                    <input type="hidden" name="finding_id" value="<?= (int)$findingId ?>">
                    <input type="hidden" name="source_kind" value="<?= h(ComplianceRegulatoryLinkEngine::KIND_AIM) ?>">
                    <input type="hidden" name="source_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="citation_label" value="<?= h($lab) ?>">
                    <input type="hidden" name="citation_url" value="<?= h($canon) ?>">
                    <input type="hidden" name="link_type" value="PRIMARY">
                    <input type="hidden" name="confidence" value="MANUAL">
                    <button type="submit" class="attach">Attach</button>
                  </form>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($q !== '' && ($corpus === 'all' || $corpus === 'easa') && $easaOk && $easaHits !== array()): ?>
    <div class="reg-card">
      <h2 style="margin:0 0 6px;font-size:18px;">EASA eRules staging (<?= count($easaHits) ?>)</h2>
      <table>
        <thead><tr><th>Id / ERules</th><th>Title / excerpt</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($easaHits as $row):
              $bid = (int)$row['batch_id'];
              $nodeUidStr = trim((string)($row['node_uid'] ?? ''));
              $sid = $bid . ':' . $nodeUidStr;
              $er = trim((string)($row['source_erules_id'] ?? ''));
              $ti = trim((string)($row['title_ex'] ?? ''));
              $lab = $er !== '' ? $er : ($ti !== '' ? $ti : $sid);
              ?>
            <tr>
              <td style="font-size:12px;word-break:break-all;">
                <div><code>batch <?= (int)$bid ?></code></div>
                <?php if ($er !== ''): ?>
                  <div><?= h($er) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <div><strong><?= h($ti !== '' ? $ti : 'Node') ?></strong></div>
                <?php if (!empty($row['excerpt'])): ?>
                  <div class="ex"><?= h((string)$row['excerpt']) ?>…</div>
                <?php endif; ?>
              </td>
              <td style="text-align:right;">
                <?php if ($findingCtx && $nodeUidStr !== ''): ?>
                  <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$findingId ?>" style="display:inline;">
                    <input type="hidden" name="action" value="attach_regulation_link">
                    <input type="hidden" name="finding_id" value="<?= (int)$findingId ?>">
                    <input type="hidden" name="source_kind" value="<?= h(ComplianceRegulatoryLinkEngine::KIND_EASA) ?>">
                    <input type="hidden" name="source_id" value="<?= h($sid) ?>">
                    <input type="hidden" name="citation_label" value="<?= h(function_exists('mb_substr') ? mb_substr($lab, 0, 255) : substr($lab, 0, 255)) ?>">
                    <input type="hidden" name="link_type" value="PRIMARY">
                    <input type="hidden" name="confidence" value="MANUAL">
                    <button type="submit" class="attach">Attach</button>
                  </form>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($q !== '' && $aimHits === array() && $easaHits === array() && ($aimOk || $easaOk)): ?>
    <div class="reg-card">
      <p style="margin:0;color:#64748b;">No matches — shorten or broaden the query.</p>
    </div>
  <?php endif; ?>

  <div class="reg-card">
    <h2 style="margin:0 0 8px;font-size:18px;">Attach external https URL</h2>
    <p style="color:#64748b;font-size:14px;margin:0 0 12px;">For citations outside AIM/EASA (e.g. BCAA portal PDF).</p>
    <?php if ($findingCtx): ?>
      <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$findingId ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <input type="hidden" name="action" value="attach_regulation_link">
        <input type="hidden" name="finding_id" value="<?= (int)$findingId ?>">
        <input type="hidden" name="source_kind" value="<?= h(ComplianceRegulatoryLinkEngine::KIND_EXTERNAL) ?>">
        <label style="margin:0;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">https URL *</span>
          <input type="url" name="external_url" required placeholder="https://…" style="min-width:320px;">
        </label>
        <label style="margin:0;">
          <span style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;">Label (optional)</span>
          <input type="text" name="citation_label" placeholder="e.g. BCAA letter 2024-…" style="min-width:240px;">
        </label>
        <input type="hidden" name="link_type" value="SUPPORTING">
        <input type="hidden" name="confidence" value="MANUAL">
        <button type="submit" class="attach">Attach URL</button>
      </form>
    <?php else: ?>
      <p style="margin:0;color:#64748b;font-size:14px;">Select a finding first.</p>
    <?php endif; ?>
  </div>
</section>
<?php
cw_footer();
