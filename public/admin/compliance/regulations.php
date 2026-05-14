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

require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';

$aimOk = rl_aim_tables_present($pdo);
$easaOk = ComplianceRegulatoryLinkEngine::easaStagingPresent($pdo);

$regDescription = 'Search indexed FAA AIM paragraphs and EASA Easy Access (staging) nodes, then attach a citation to a finding. Verifier hooks remain the authority for edition freshness — this page only creates regulatory link rows.';

compliance_page_open(array(
    'overline' => 'Compliance · Regulatory bridge',
    'title' => 'Regulation search',
    'description' => $regDescription,
    'back' => $findingId > 0 ? array(
        'href' => '/admin/compliance/findings.php?id=' . (int)$findingId,
        'label' => 'Back to finding',
        'code' => (string)($findingCtx['finding_code'] ?? ('#' . $findingId)),
    ) : null,
    'stats' => array(
        array('label' => 'FAA AIM',  'value' => $aimOk ? 'OK' : 'Missing',  'tone' => $aimOk ? 'ok' : 'crit'),
        array('label' => 'EASA',     'value' => $easaOk ? 'OK' : 'Missing', 'tone' => $easaOk ? 'ok' : 'crit'),
        array('label' => 'Hits',     'value' => count($aimHits) + count($easaHits)),
    ),
));
?>
<section class="compliance-reg-search">
  <style>
    .compliance-reg-search .ex{color:var(--text-muted);font-size:12px;line-height:1.4;margin-top:4px;}
  </style>

  <section class="cmp-card">
    <?php if ($findingCtx): ?>
      <p class="cmp-flash cmp-flash-ok" style="margin:0 0 16px;">
        Attaching to <strong><?= h((string)$findingCtx['finding_code']) ?></strong> — <?= h((string)$findingCtx['title']) ?>
      </p>
    <?php else: ?>
      <p class="cmp-flash cmp-flash-warn" style="margin:0 0 16px;">
        Open a finding and use <strong>Search regulations & attach</strong>, or add <code>?finding_id=…</code> to this URL to enable attach buttons.
      </p>
    <?php endif; ?>

    <form method="get" action="/admin/compliance/regulations.php" class="cmp-toolbar">
      <?php if ($findingId > 0): ?>
        <input type="hidden" name="finding_id" value="<?= (int)$findingId ?>">
      <?php endif; ?>
      <label class="cmp-field" style="min-width:280px;">
        <span class="cmp-field-label">Query</span>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="e.g. FCL, parallel approaches, fuel…">
      </label>
      <label class="cmp-field" style="min-width:180px;">
        <span class="cmp-field-label">Corpus</span>
        <select name="corpus">
          <option value="all" <?= $corpus === 'all' ? 'selected' : '' ?>>All available</option>
          <option value="aim" <?= $corpus === 'aim' ? 'selected' : '' ?>>FAA AIM only</option>
          <option value="easa" <?= $corpus === 'easa' ? 'selected' : '' ?>>EASA eRules only</option>
        </select>
      </label>
      <button type="submit">Search</button>
    </form>

    <?php if ($q !== '' && !$aimOk && !$easaOk): ?>
      <p class="cmp-flash cmp-flash-danger" style="margin-top:12px;">No regulatory tables available — apply resource library SQL migrations.</p>
    <?php endif; ?>
  </section>

  <?php if ($q !== '' && ($corpus === 'all' || $corpus === 'aim') && $aimOk && $aimHits !== array()): ?>
    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title"><?= compliance_ui_icon('document') ?><span>FAA AIM</span></div>
        <div class="cmp-count-pill"><?= count($aimHits) ?></div>
      </div>
      <div class="compliance-table-wrap">
      <table class="compliance-table">
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
              <td class="cmp-mono"><?= h($pn !== '' ? $pn : ('#' . $id)) ?></td>
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
                    <button type="submit" style="height:32px;min-height:32px;padding:0 12px;font-size:12px;">Attach</button>
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
    </section>
  <?php endif; ?>

  <?php if ($q !== '' && ($corpus === 'all' || $corpus === 'easa') && $easaOk && $easaHits !== array()): ?>
    <section class="cmp-card">
      <div class="cmp-list-head" style="margin-bottom:14px;">
        <div class="cmp-list-title"><?= compliance_ui_icon('document') ?><span>EASA eRules staging</span></div>
        <div class="cmp-count-pill"><?= count($easaHits) ?></div>
      </div>
      <div class="compliance-table-wrap">
      <table class="compliance-table">
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
                    <button type="submit" style="height:32px;min-height:32px;padding:0 12px;font-size:12px;">Attach</button>
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
    </section>
  <?php endif; ?>

  <?php if ($q !== '' && $aimHits === array() && $easaHits === array() && ($aimOk || $easaOk)): ?>
    <section class="cmp-card">
      <p style="margin:0;color:var(--text-muted);">No matches — shorten or broaden the query.</p>
    </section>
  <?php endif; ?>

  <section class="cmp-card">
    <h2 style="margin:0 0 8px;">Attach external https URL</h2>
    <p style="color:var(--text-muted);font-size:14px;margin:0 0 12px;">For citations outside AIM/EASA (e.g. BCAA portal PDF).</p>
    <?php if ($findingCtx): ?>
      <form method="post" action="/admin/compliance/findings.php?id=<?= (int)$findingId ?>" class="cmp-toolbar">
        <input type="hidden" name="action" value="attach_regulation_link">
        <input type="hidden" name="finding_id" value="<?= (int)$findingId ?>">
        <input type="hidden" name="source_kind" value="<?= h(ComplianceRegulatoryLinkEngine::KIND_EXTERNAL) ?>">
        <label class="cmp-field" style="min-width:320px;">
          <span class="cmp-field-label">https URL *</span>
          <input type="url" name="external_url" required placeholder="https://…">
        </label>
        <label class="cmp-field" style="min-width:240px;">
          <span class="cmp-field-label">Label (optional)</span>
          <input type="text" name="citation_label" placeholder="e.g. BCAA letter 2024-…">
        </label>
        <input type="hidden" name="link_type" value="SUPPORTING">
        <input type="hidden" name="confidence" value="MANUAL">
        <button type="submit">Attach URL</button>
      </form>
    <?php else: ?>
      <p style="margin:0;color:var(--text-muted);font-size:14px;">Select a finding first.</p>
    <?php endif; ?>
  </section>
</section>
<?php
compliance_page_close();
cw_footer();
