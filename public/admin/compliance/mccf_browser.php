<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/layout.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceAccess.php';
require_once __DIR__ . '/../../../src/compliance/ComplianceUi.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfBrowserService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfRegulationLinkService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfBcaaViewService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfIntegrityService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfRegulationReviewService.php';
require_once __DIR__ . '/../../../src/publishing/ControlledPublishingMccfIntegrityJobService.php';

$user = compliance_require_access($pdo);
$svc = new ControlledPublishingMccfBrowserService($pdo);
$regSvc = new ControlledPublishingMccfRegulationLinkService($pdo);
$bcaaSvc = new ControlledPublishingMccfBcaaViewService($pdo);
$integritySvc = new ControlledPublishingMccfIntegrityService($pdo);
$reviewSvc = new ControlledPublishingMccfRegulationReviewService($pdo);
$integrityJobSvc = new ControlledPublishingMccfIntegrityJobService($pdo);
$integrityJobsAvailable = ControlledPublishingMccfIntegrityJobService::tablesPresent($pdo);
$regLinksAvailable = ControlledPublishingMccfRegulationLinkService::regulationLinksTablePresent($pdo);
$easaChangesPending = $reviewSvc->hasPendingEasaChanges();
$easaMonitorChanges = $easaChangesPending ? $reviewSvc->listPendingEasaMonitorChanges() : array();

$layout = strtolower(trim((string)($_GET['layout'] ?? 'bcaa')));
if (!in_array($layout, array('bcaa', 'coverage', 'pairs'), true)) {
    $layout = 'bcaa';
}

$sourceSets = $svc->listMccfSourceSets();
$sourceSetId = $svc->resolveSourceSetId(
    (int)($_GET['source_set_id'] ?? 0),
    trim((string)($_GET['source_set'] ?? ''))
);
$sourceSet = $svc->sourceSetById($sourceSetId);
$integrityJobStatus = ($sourceSetId > 0 && $integrityJobsAvailable)
    ? $integrityJobSvc->statusForSourceSet($sourceSetId)
    : array('ok' => true, 'run' => null);

$part = trim((string)($_GET['part'] ?? ''));
$coverage = trim((string)($_GET['coverage'] ?? 'all'));
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$requirementId = (int)($_GET['req'] ?? 0);

$parts = $sourceSetId > 0 ? $svc->listParts($sourceSetId) : array();
$summary = $sourceSetId > 0 ? $svc->coverageSummary($sourceSetId) : array('total' => 0, 'linked' => 0, 'unlinked' => 0, 'by_part' => array());
$search = $sourceSetId > 0
    ? $svc->searchRequirements($sourceSetId, array(
        'part' => $part,
        'coverage' => $coverage,
        'q' => $q,
    ), $page, 50)
    : array('rows' => array(), 'total' => 0, 'page' => 1, 'per_page' => 50);
$detail = $requirementId > 0 ? $svc->getRequirement($requirementId) : null;
$detailRegLinks = ($requirementId > 0 && $regLinksAvailable)
    ? $regSvc->listLinksForRequirement($requirementId)
    : array();
$detailRegParsed = is_array($detail)
    ? ControlledPublishingMccfRegulationLinkService::parseRegulationRef((string)($detail['regulation_ref'] ?? ''))
    : array();

$bcaaSections = ($sourceSetId > 0 && in_array($layout, array('bcaa', 'pairs'), true))
    ? $bcaaSvc->listPartSections($sourceSetId, array(
        'part' => $part,
        'coverage' => $coverage,
        'q' => $q,
    ))
    : array();

$manualCode = 'OM';
if (is_array($sourceSet) && str_contains(strtoupper((string)($sourceSet['source_set_key'] ?? '')), 'OMM')) {
    $manualCode = 'OMM';
} elseif ($bcaaSections !== array()) {
    foreach ($bcaaSections as $section) {
        foreach ($section['rows'] ?? array() as $row) {
            $mc = strtoupper(trim((string)($row['manual_code'] ?? '')));
            if ($mc !== '') {
                $manualCode = $mc;
                break 2;
            }
        }
    }
}
$manualRefColumn = strtoupper($manualCode) . ' REF.';
$manualDisplayTitle = ControlledPublishingMccfBcaaViewService::manualDisplayTitle($manualCode);

$detailIntegrity = null;
if (is_array($detail)) {
    $detailExcerptsForScore = (new ControlledPublishingMccfLinkedManualService($pdo))
        ->linkedSectionsForRequirement($requirementId);

    $detailManualCode = strtoupper(trim((string)($detail['manual_code'] ?? 'OM')));
    $detailVersionLabel = $detailManualCode === 'OMM' ? '4.0' : '6.0';
    $detailBookVersionId = 0;
    try {
        $bvStmt = $pdo->prepare("
            SELECT bv.id
            FROM ipca_publishing_book_versions bv
            INNER JOIN ipca_publishing_books b ON b.id = bv.book_id
            WHERE b.book_key = :book_key
              AND bv.version_label = :version_label
            ORDER BY bv.id DESC
            LIMIT 1
        ");
        $bvStmt->execute(array(
            ':book_key' => $detailManualCode,
            ':version_label' => $detailVersionLabel,
        ));
        $detailBookVersionId = (int)$bvStmt->fetchColumn();
    } catch (Throwable) {
        $detailBookVersionId = 0;
    }

    $detailIntegrity = $integritySvc->scoreRequirement(
        $detail,
        $detailExcerptsForScore,
        $detailRegLinks,
        $detailBookVersionId
    );
}

$totalPages = $search['per_page'] > 0
    ? (int)max(1, ceil($search['total'] / $search['per_page']))
    : 1;

function mccf_browser_query(array $overrides = array()): string
{
    $params = array_merge(array(
        'source_set_id' => (int)($_GET['source_set_id'] ?? 0),
        'part' => trim((string)($_GET['part'] ?? '')),
        'coverage' => trim((string)($_GET['coverage'] ?? 'all')),
        'q' => trim((string)($_GET['q'] ?? '')),
        'page' => max(1, (int)($_GET['page'] ?? 1)),
        'req' => (int)($_GET['req'] ?? 0),
        'layout' => strtolower(trim((string)($_GET['layout'] ?? 'bcaa'))),
    ), $overrides);
    if ((int)$params['source_set_id'] <= 0) {
        unset($params['source_set_id']);
    }
    if ($params['part'] === '') {
        unset($params['part']);
    }
    if ($params['coverage'] === '' || $params['coverage'] === 'all') {
        unset($params['coverage']);
    }
    if ($params['q'] === '') {
        unset($params['q']);
    }
    if ((int)$params['page'] <= 1) {
        unset($params['page']);
    }
    if ((int)$params['req'] <= 0) {
        unset($params['req']);
    }
    $query = http_build_query($params);
    return $query !== '' ? ('?' . $query) : '';
}

function mccf_integrity_bar_html(array $integrity, bool $compact = false): string
{
    $score = max(0, min(100, (int)($integrity['score'] ?? 0)));
    $tone = (string)($integrity['tone'] ?? 'muted');
    $label = (string)($integrity['label'] ?? '');
    $barCls = match ($tone) {
        'ok' => 'ok',
        'warn' => 'warn',
        'bad' => 'danger',
        default => 'muted',
    };
    $title = h($label !== '' ? ($label . ' — ' . $score . '%') : ($score . '%'));

    if ($compact) {
        return '<div class="mccf-integrity-row" title="' . $title . '">'
            . '<div class="mccf-integrity-bar"><span class="' . h($barCls) . '" style="width:' . $score . '%;"></span></div>'
            . '<div class="mccf-integrity-value">' . $score . '%</div>'
            . '</div>';
    }

    return '<div class="mccf-integrity-row" title="' . $title . '">'
        . '<div class="mccf-integrity-bar"><span class="' . h($barCls) . '" style="width:' . $score . '%;"></span></div>'
        . '<div class="mccf-integrity-value">' . $score . '%</div>'
        . '<div class="mccf-integrity-label">' . h($label) . '</div>'
        . '</div>';
}

function mccf_applicable_pill_html(array $row): string
{
    $raw = trim((string)($row['applicable'] ?? ''));
    $norm = strtoupper($raw);
    if ($norm === '' || $norm === 'YES' || $norm === 'Y') {
        return '<span class="cmp-pill cmp-pill-ok mccf-pill-sm">Yes</span>';
    }
    if (in_array($norm, array('NO', 'N', 'NA', 'N/A', 'NOT APPLICABLE'), true)) {
        return '<span class="cmp-pill cmp-pill-muted mccf-pill-sm">No</span>';
    }

    return '<span class="cmp-pill cmp-pill-muted mccf-pill-sm">' . h($raw) . '</span>';
}

function mccf_regulation_cell_html(array $row, int $requirementId): string
{
    $ref = trim((string)($row['regulation_ref'] ?? ''));
    if ($ref === '' || $requirementId <= 0) {
        return '—';
    }

    $tokens = array();
    foreach ($row['regulation_links'] ?? array() as $regLink) {
        if (!is_array($regLink)) {
            continue;
        }
        $token = trim((string)($regLink['rule_token'] ?? ''));
        if ($token !== '') {
            $tokens[$token] = true;
        }
    }
    if ($tokens === array()) {
        foreach (ControlledPublishingMccfRegulationLinkService::parseRegulationRef($ref) as $parsedRow) {
            $token = trim((string)($parsedRow['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if ($tokens === array()) {
        return '<button type="button" class="mccf-ref-btn" data-mccf-action="regulation" data-req="'
            . $requirementId . '" data-rule="' . h($ref) . '">' . h($ref) . '</button>';
    }

    $html = '';
    foreach (array_keys($tokens) as $token) {
        $html .= '<div class="mccf-reg-token-line"><button type="button" class="mccf-ref-btn" data-mccf-action="regulation" data-req="'
            . $requirementId . '" data-rule="' . h($token) . '">' . h($token) . '</button></div>';
    }

    return $html;
}

function mccf_row_class(array $row): string
{
    $classes = array();
    if (empty($row['bcaa_show_group'])) {
        $classes[] = 'mccf-bcaa-continuation';
    }
    $state = is_array($row['row_state'] ?? null) ? $row['row_state'] : array();
    if (!empty($state['is_not_required'])) {
        $classes[] = 'mccf-row--na';
    } elseif (!empty($state['missing_book_ref'])) {
        $classes[] = 'mccf-row--missing-ref';
    }

    return implode(' ', $classes);
}

$titleSuffix = is_array($sourceSet) ? (string)($sourceSet['source_set_key'] ?? '') : '';

cw_header('Compliance · MCCF Browser');

compliance_page_open(array(
    'overline' => 'Compliance · Controlled publishing',
    'title' => 'MCCF Requirements Browser',
    'description' => 'BCAA-format MCCF checklist view with manual coverage and automatic links to applicable EASA regulation sources in Resource Library.',
    'back' => array(
        'href' => '/admin/compliance/controlled_books.php',
        'label' => 'Controlled Books',
    ),
    'stats' => array(
        array('label' => 'Source set', 'value' => $titleSuffix !== '' ? $titleSuffix : '—'),
        array('label' => 'Requirements', 'value' => (int)($summary['total'] ?? 0)),
        array(
            'label' => 'Linked',
            'value' => (int)($summary['linked'] ?? 0),
            'tone' => 'ok',
        ),
        array(
            'label' => 'Unlinked',
            'value' => (int)($summary['unlinked'] ?? 0),
            'tone' => ((int)($summary['unlinked'] ?? 0) > 0) ? 'warn' : 'ok',
        ),
    ),
    'actions' => array(
        array(
            'label' => 'EASA Resource Library',
            'href' => '/admin/resource_library.php?tab=easa',
            'variant' => 'secondary',
        ),
        array(
            'label' => 'Canonical Sources',
            'href' => '/admin/compliance/canonical_sources.php',
            'variant' => 'secondary',
        ),
    ),
));

?>
<link rel="stylesheet" href="/assets/controlled_book_editor.css">
<style>
  .mccf-layout { display: grid; gap: 16px; }
  .mccf-filters { display: grid; gap: 12px; }
  .mccf-filters-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1.4fr auto; gap: 10px; align-items: end; }
  @media (max-width: 960px) { .mccf-filters-row { grid-template-columns: 1fr; } }
  .mccf-filters label { display: grid; gap: 6px; font-size: 13px; font-weight: 600; color: #334155; }
  .mccf-filters input, .mccf-filters select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; }
  .mccf-coverage-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
  .mccf-coverage-chip { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; background: #fff; font-size: 12px; }
  .mccf-coverage-chip strong { display: block; font-size: 13px; margin-bottom: 4px; color: #0f172a; }
  .mccf-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
  .mccf-badge--linked { background: #dcfce7; color: #166534; }
  .mccf-badge--unlinked { background: #fef3c7; color: #92400e; }
  .mccf-badge--legacy { background: #e0e7ff; color: #3730a3; }
  .mccf-detail { border: 1px solid #bfdbfe; background: #f8fbff; border-radius: 12px; padding: 16px 18px; }
  .mccf-detail h3 { margin: 0 0 8px; font-size: 16px; }
  .mccf-detail-meta { font-size: 12px; color: #64748b; margin-bottom: 12px; }
  .mccf-detail-text { font-size: 13px; line-height: 1.55; color: #334155; white-space: pre-wrap; }
  .mccf-excerpt-list { margin: 12px 0 0; padding-left: 18px; font-size: 13px; }
  .mccf-excerpt-list li { margin-bottom: 8px; }
  .mccf-pagination { display: flex; gap: 10px; align-items: center; font-size: 13px; margin-top: 12px; }
  .mccf-key { font-family: ui-monospace, monospace; font-size: 10px; color: #475569; }
  .mccf-pill-sm { min-height: 20px !important; padding: 0 7px !important; font-size: 10px !important; font-weight: 720 !important; }
  .mccf-bcaa-section-card { border: 1px solid rgba(15,23,42,.08); border-radius: 12px; overflow: hidden; background: #fff; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
  .mccf-bcaa-section-head { padding: 10px 12px; background: linear-gradient(180deg,#17345d 0%,#102440 100%); border-bottom: 1px solid rgba(255,255,255,.08); font-size: 11px; font-weight: 700; color: #fff; letter-spacing: .04em; text-transform: uppercase; }
  .mccf-bcaa-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 10px; line-height: 1.25; color: #1e293b; }
  .mccf-bcaa-table th, .mccf-bcaa-table td { border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 5px 6px; vertical-align: top; }
  .mccf-bcaa-table th:last-child, .mccf-bcaa-table td:last-child { border-right: none; }
  .mccf-bcaa-table tbody tr:last-child td { border-bottom: none; }
  .mccf-bcaa-table th { background: #f1f5f9; font-size: 8.5px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; color: #475569; line-height: 1.2; }
  .mccf-bcaa-table td { font-size: 10px; }
  .mccf-col-item { width: 3%; }
  .mccf-col-subject { width: 11%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .mccf-col-sub { width: 5%; white-space: nowrap; }
  .mccf-col-desc { width: 20%; }
  .mccf-col-location { width: 13%; }
  .mccf-col-applicable { width: 4%; text-align: center; }
  .mccf-col-remarks { width: 9%; }
  .mccf-col-integrity { width: 8%; }
  .mccf-col-finding { width: 8%; }
  .mccf-col-regulation { width: 12%; word-break: break-word; }
  .mccf-manual-title { margin: 0 0 14px; font-size: 14px; font-weight: 700; color: #0f172a; }
  .mccf-row--missing-ref td { background: #fff7ed; }
  .mccf-row--na td { background: #f8fafc; color: #94a3b8; }
  .mccf-row--na .mccf-ref-btn { color: #94a3b8; }
  .mccf-location-lines { margin: 0; padding: 0; list-style: none; }
  .mccf-location-lines li { margin-bottom: 3px; }
  .cmp-page .mccf-bcaa-table .mccf-ref-btn,
  .cmp-page .mccf-pair-pane .mccf-ref-btn,
  .cmp-page .mccf-modal-body .mccf-ref-btn {
    height: auto !important;
    min-height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    display: inline !important;
    align-items: unset !important;
    justify-content: unset !important;
    gap: 0 !important;
    background: none !important;
    color: #1d4ed8 !important;
    font-size: 10px !important;
    font-weight: 400 !important;
    letter-spacing: normal !important;
    box-shadow: none !important;
    white-space: normal !important;
    text-align: left !important;
    line-height: 1.25 !important;
    text-decoration: underline !important;
    text-underline-offset: 2px !important;
    cursor: pointer !important;
    transform: none !important;
  }
  .cmp-page .mccf-bcaa-table .mccf-ref-btn:hover,
  .cmp-page .mccf-pair-pane .mccf-ref-btn:hover,
  .cmp-page .mccf-modal-body .mccf-ref-btn:hover {
    background: none !important;
    color: #1e3a8a !important;
    transform: none !important;
    box-shadow: none !important;
  }
  .cmp-page .mccf-bcaa-table .mccf-pair-btn,
  .cmp-page .mccf-pair-head .mccf-pair-btn {
    height: auto !important;
    min-height: 18px !important;
    padding: 1px 6px !important;
    border-radius: 6px !important;
    border: 1px solid #cbd5e1 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0 !important;
    background: #f8fafc !important;
    color: #475569 !important;
    font-size: 9px !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    box-shadow: none !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    cursor: pointer !important;
    transform: none !important;
    margin-top: 3px !important;
  }
  .cmp-page .mccf-bcaa-table .mccf-pair-btn:hover,
  .cmp-page .mccf-pair-head .mccf-pair-btn:hover {
    background: #f1f5f9 !important;
    color: #334155 !important;
    transform: none !important;
    box-shadow: none !important;
  }
  .mccf-reg-links { margin: 0; padding: 0; list-style: none; }
  .mccf-integrity-row { display: flex; align-items: center; gap: 6px; }
  .mccf-integrity-bar { height: 7px; flex: 1; border-radius: 999px; background: #e7edf4; overflow: hidden; min-width: 48px; }
  .mccf-integrity-bar span { display: block; height: 100%; border-radius: 999px; }
  .mccf-integrity-bar span.ok { background: linear-gradient(90deg,#166534 0%,#22c55e 100%); }
  .mccf-integrity-bar span.warn { background: linear-gradient(90deg,#d97706 0%,#f59e0b 100%); }
  .mccf-integrity-bar span.danger { background: linear-gradient(90deg,#b91c1c 0%,#ef4444 100%); }
  .mccf-integrity-bar span.muted { background: #94a3b8; }
  .mccf-integrity-value { font-size: 9px; font-weight: 800; color: #102845; min-width: 28px; text-align: right; }
  .mccf-review-banner { border: 1px solid #fcd34d; background: #fffbeb; border-radius: 12px; padding: 12px 14px; font-size: 13px; color: #92400e; }
  .mccf-review-flag { display: inline-block; margin-top: 3px; padding: 1px 6px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 9px; font-weight: 700; }
  .mccf-layout-toggle { display: flex; gap: 8px; margin-bottom: 12px; font-size: 13px; flex-wrap: wrap; }
  .mccf-layout-toggle a { padding: 6px 10px; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none; color: #334155; }
  .mccf-layout-toggle a.is-active { background: #0f172a; color: #fff; border-color: #0f172a; }
  .mccf-pairs-grid { display: grid; gap: 12px; }
  .mccf-pair-card { border: 1px solid rgba(15,23,42,.08); border-radius: 12px; overflow: hidden; background: #fff; }
  .mccf-pair-head { padding: 10px 12px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; gap: 10px; align-items: center; font-size: 11px; }
  .mccf-pair-split { display: grid; grid-template-columns: 1fr 1fr; min-height: 180px; }
  @media (max-width: 900px) { .mccf-pair-split { grid-template-columns: 1fr; } }
  .mccf-pair-pane { padding: 10px 12px; border-right: 1px solid #e2e8f0; font-size: 10px; line-height: 1.35; overflow: auto; max-height: 320px; }
  .mccf-pair-pane:last-child { border-right: none; }
  .mccf-pair-pane h4 { margin: 0 0 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
  .mccf-pair-pane.is-loading { color: #64748b; font-style: italic; }
  .mccf-modal-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: none; align-items: center; justify-content: center; z-index: 1200; padding: 20px; }
  .mccf-modal-backdrop.is-open { display: flex; }
  .mccf-modal { width: min(960px, 96vw); max-height: 90vh; background: #fff; border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(15,23,42,.25); }
  .mccf-modal-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
  .mccf-modal-head h3 { margin: 0; font-size: 15px; color: #0f172a; }
  .mccf-modal-sub { margin-top: 4px; font-size: 11px; color: #64748b; }
  .mccf-modal-close { border: 0; background: #e2e8f0; border-radius: 8px; width: 32px; height: 32px; cursor: pointer; font-size: 18px; line-height: 1; }
  .mccf-modal-body { padding: 0; overflow: auto; flex: 1; font-size: 11px; line-height: 1.45; }
  .mccf-modal-body .mccf-reader-line { margin: 0 0 8px; }
  .mccf-modal-body .mccf-reader-section-head { padding: 10px 14px; border-bottom: 1px solid #e2e8f0; background: #fff; font-size: 12px; }
  .mccf-modal-body .mccf-reader-canvas { padding: 12px 14px 18px; }
  .mccf-modal-body .mccf-reader-fallback { padding: 12px 14px 18px; }
  .mccf-modal-body .mccf-reader-fallback h4 { margin: 0 0 8px; font-size: 12px; }
  .mccf-hl, .mccf-hl-line mark.mccf-hl, [data-mccf-highlight="1"] { background: #fef08a !important; box-shadow: inset 0 0 0 1px #facc15; border-radius: 2px; }
  .mccf-easa-preview .rl-easa-bl-li.mccf-hl-line { background: #fef08a !important; box-shadow: inset 0 0 0 1px #facc15; border-radius: 4px; padding: 4px 6px; margin-left: -6px; margin-right: -6px; }
  .mccf-easa-preview mark.mccf-hl { background: #fef08a !important; color: inherit; box-shadow: inset 0 0 0 1px #facc15; border-radius: 2px; padding: 0 1px; }
  .mccf-modal-context { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; font-size: 11px; line-height: 1.45; color: #334155; }
  .mccf-modal-context-kicker { font-size: 9px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #64748b; margin: 0 0 4px; }
  .mccf-modal-context-subject { font-size: 12px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
  .mccf-modal-context-desc { margin: 0; white-space: pre-wrap; }
  .mccf-modal-integrity { margin-top: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0; }
  .mccf-modal-integrity-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
  .mccf-modal-integrity-head strong { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
  .mccf-modal-integrity-reasons { margin: 0; padding-left: 16px; color: #475569; }
  .mccf-integrity-job {
    margin: 0 0 16px;
    padding: 12px 14px;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    background: linear-gradient(180deg, #f8fbff 0%, #f1f5f9 100%);
  }
  .mccf-integrity-job-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 8px;
  }
  .mccf-integrity-job-head strong { font-size: 13px; color: #0f172a; }
  .mccf-integrity-job-meta { font-size: 12px; color: #64748b; }
  .mccf-integrity-job-bar {
    height: 8px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
  }
  .mccf-integrity-job-bar span {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, #1d4ed8 0%, #3b82f6 100%);
    transition: width .3s ease;
  }
  .mccf-integrity-job-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .mccf-integrity-pending { color: #94a3b8; font-size: 11px; font-weight: 700; }
  .mccf-modal-content { }
  .mccf-modal-content .mccf-pair-split { min-height: 240px; }
  .mccf-easa-preview .rl-easa-detail-meta { font-size: 11px; color: #475569; margin: 0; padding: 12px 16px 0; white-space: pre-wrap; word-break: break-word; }
  .mccf-easa-preview .rl-easa-detail-body { font-variant-ligatures: none; font-feature-settings: "liga" 0, "clig" 0, "calt" 0, "dlig" 0; white-space: normal; word-break: break-word; margin: 0; font-size: 13px; line-height: 1.65; color: #1e293b; padding: 14px 16px; background: #fff; border: none; border-radius: 0; max-height: none; overflow: visible; }
  .mccf-easa-preview .rl-easa-bl-article { max-width: 100%; min-width: 0; }
  .mccf-easa-preview .rl-easa-bl-h { margin: 0.85rem 0 0.4rem; font-weight: 700; line-height: 1.35; color: #0f172a; }
  .mccf-easa-preview .rl-easa-bl-h:first-child { margin-top: 0; }
  .mccf-easa-preview .rl-easa-bl-p { margin: 0.35rem 0 0; }
  .mccf-easa-preview .rl-easa-bl-li { margin: 0.35rem 0 0; display: flex; gap: 8px; align-items: baseline; max-width: 100%; }
  .mccf-easa-preview .rl-easa-bl-marker { flex: 0 0 auto; min-width: 1.6rem; color: #475569; font-weight: 600; }
  .mccf-easa-preview .rl-easa-bl-litext { flex: 1; min-width: 0; }
  .mccf-easa-preview .rl-easa-bl-tbl { width: 100%; border-collapse: collapse; margin: 0.5rem 0 0; font-size: 12px; }
  .mccf-easa-preview .rl-easa-bl-tbl td, .mccf-easa-preview .rl-easa-bl-tbl th { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
  .mccf-easa-preview .rl-easa-bl-tbl th { background: #f8fafc; font-weight: 700; }
  .mccf-col-regulation .mccf-ref-btn { display: inline; word-break: break-word; white-space: normal; }
  .mccf-reg-token-line { margin-bottom: 2px; }
  .cmp-page .mccf-modal-close {
    height: 32px !important;
    min-height: 32px !important;
    width: 32px !important;
    padding: 0 !important;
    border-radius: 8px !important;
    border: 0 !important;
    background: #e2e8f0 !important;
    color: #334155 !important;
    font-size: 18px !important;
    font-weight: 400 !important;
    box-shadow: none !important;
  }
  .mccf-link-editor-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: none; align-items: center; justify-content: center; z-index: 1300; padding: 20px; }
  .mccf-link-editor-backdrop.is-open { display: flex; }
  .mccf-link-editor { width: min(760px, 96vw); max-height: 92vh; background: #fff; border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(15,23,42,.28); }
  .mccf-link-editor-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
  .mccf-link-editor-head h3 { margin: 0; font-size: 15px; color: #0f172a; }
  .mccf-link-editor-sub { margin-top: 4px; font-size: 11px; color: #64748b; line-height: 1.4; }
  .mccf-link-editor-body { padding: 14px 16px 16px; overflow: auto; flex: 1; display: grid; gap: 14px; font-size: 12px; }
  .mccf-link-editor-section h4 { margin: 0 0 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 800; }
  .mccf-link-current-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
  .mccf-link-current-item { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; font-size: 11px; }
  .mccf-link-current-item strong { display: block; color: #0f172a; margin-bottom: 2px; font-size: 11px; }
  .mccf-link-current-meta { font-size: 10px; color: #64748b; }
  .mccf-link-current-actions { display: flex; gap: 4px; flex-shrink: 0; align-items: center; }
  .mccf-link-picker-grid { display: grid; grid-template-columns: 1.4fr .8fr 1.4fr .8fr; gap: 8px; }
  @media (max-width: 720px) { .mccf-link-picker-grid { grid-template-columns: 1fr 1fr; } }
  .mccf-link-picker-grid label { display: grid; gap: 4px; font-size: 10px; font-weight: 700; color: #475569; }
  .mccf-link-picker-grid select, .mccf-link-ref-input { width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; background: #fff; color: #0f172a; }
  .mccf-link-ref-input { resize: vertical; min-height: 52px; line-height: 1.4; font-family: inherit; }
  .mccf-link-picker-main { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 10px; margin-top: 10px; }
  @media (max-width: 720px) { .mccf-link-picker-main { grid-template-columns: 1fr; } }
  .mccf-link-section-panel, .mccf-link-preview-panel { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; min-height: 180px; display: flex; flex-direction: column; overflow: hidden; }
  .mccf-link-panel-head { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; font-size: 10px; color: #64748b; display: flex; justify-content: space-between; gap: 8px; align-items: center; }
  .mccf-link-breadcrumb { font-size: 10px; color: #64748b; }
  .mccf-link-breadcrumb button { border: 0; background: none; padding: 0; color: #2563eb; font-size: 10px; cursor: pointer; text-decoration: underline; }
  .mccf-link-section-list { margin: 0; padding: 0; list-style: none; overflow: auto; flex: 1; max-height: 220px; }
  .mccf-link-section-row { display: flex; align-items: flex-start; gap: 6px; padding: 7px 10px; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
  .mccf-link-section-row:last-child { border-bottom: 0; }
  .mccf-link-section-row:hover { background: #f8fafc; }
  .mccf-link-section-row.is-selected { background: #eff6ff; box-shadow: inset 0 0 0 1px #bfdbfe; }
  .mccf-link-section-pick { flex: 1; min-width: 0; }
  .mccf-link-section-title { display: block; font-size: 11px; color: #0f172a; line-height: 1.3; }
  .mccf-link-section-snippet { display: block; margin-top: 3px; color: #64748b; font-size: 10px; line-height: 1.35; }
  .mccf-link-preview { padding: 10px 12px; overflow: auto; flex: 1; font-size: 11px; color: #475569; line-height: 1.45; white-space: pre-wrap; }
  .mccf-link-preview.is-empty { color: #94a3b8; font-style: italic; }
  .mccf-link-editor-foot { display: flex; justify-content: flex-end; gap: 6px; padding-top: 10px; flex-wrap: wrap; }
  .mccf-link-ref-actions { display: flex; justify-content: flex-end; margin-top: 6px; }
  .mccf-excerpt-list .mccf-link-edit-actions { margin-top: 4px; display: flex; gap: 6px; flex-wrap: wrap; }
  .cmp-page .mccf-link-editor .mccf-pair-btn,
  .cmp-page .mccf-link-editor-foot .mccf-pair-btn,
  .cmp-page .mccf-link-current-actions .mccf-pair-btn,
  .cmp-page .mccf-link-ref-actions .mccf-pair-btn,
  .cmp-page .mccf-link-section-row .mccf-pair-btn,
  .mccf-link-editor-backdrop .mccf-pair-btn {
    height: auto !important;
    min-height: 18px !important;
    padding: 1px 6px !important;
    border-radius: 6px !important;
    border: 1px solid #cbd5e1 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0 !important;
    background: #f8fafc !important;
    color: #475569 !important;
    font-size: 9px !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    box-shadow: none !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    cursor: pointer !important;
    transform: none !important;
    margin: 0 !important;
    width: auto !important;
  }
  .cmp-page .mccf-link-editor .mccf-pair-btn:hover,
  .cmp-page .mccf-link-editor-foot .mccf-pair-btn:hover,
  .cmp-page .mccf-link-current-actions .mccf-pair-btn:hover,
  .cmp-page .mccf-link-ref-actions .mccf-pair-btn:hover,
  .cmp-page .mccf-link-section-row .mccf-pair-btn:hover,
  .mccf-link-editor-backdrop .mccf-pair-btn:hover {
    background: #f1f5f9 !important;
    color: #334155 !important;
    transform: none !important;
    box-shadow: none !important;
  }
  .cmp-page .mccf-link-editor .mccf-pair-btn[disabled],
  .mccf-link-editor-backdrop .mccf-pair-btn[disabled] {
    opacity: .45 !important;
    cursor: not-allowed !important;
  }
  .cmp-page .mccf-link-editor button:not(.mccf-pair-btn):not(.mccf-modal-close),
  .mccf-link-editor-backdrop button:not(.mccf-pair-btn):not(.mccf-modal-close) {
    height: auto !important;
    min-height: 0 !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    display: inline !important;
    background: none !important;
    color: #2563eb !important;
    font-size: 10px !important;
    font-weight: 400 !important;
    box-shadow: none !important;
    width: auto !important;
    transform: none !important;
  }
  .cmp-page .mccf-excerpt-list .mccf-pair-btn,
  .cmp-page .mccf-detail .mccf-pair-btn {
    height: auto !important;
    min-height: 18px !important;
    padding: 1px 6px !important;
    border-radius: 6px !important;
    border: 1px solid #cbd5e1 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 0 !important;
    background: #f8fafc !important;
    color: #475569 !important;
    font-size: 9px !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    box-shadow: none !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    cursor: pointer !important;
    transform: none !important;
    margin-top: 3px !important;
  }
  .cmp-page .mccf-excerpt-list .mccf-pair-btn:hover,
  .cmp-page .mccf-detail .mccf-pair-btn:hover {
    background: #f1f5f9 !important;
    color: #334155 !important;
    transform: none !important;
    box-shadow: none !important;
  }
</style>

<div class="mccf-layout">
  <?php if ($sourceSets === array()): ?>
    <section class="cmp-card">
      <p style="margin:0;">No MCCF source sets found. Import the BCAA MCCF checklist (DOCX) into canonical requirements first.</p>
    </section>
  <?php else: ?>

    <?php if ($easaChangesPending): ?>
      <section class="mccf-review-banner cmp-card" style="padding:12px 14px;">
        <strong>EASA source updates detected.</strong>
        Requirements with linked regulation sources may need manual review before the next controlled release.
        <?php if ($easaMonitorChanges !== array()): ?>
          <ul style="margin:8px 0 0;padding-left:18px;">
            <?php foreach ($easaMonitorChanges as $change): ?>
              <li><?= h((string)($change['label'] ?? $change['url'] ?? 'EASA monitor')) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if (is_array($detail)): ?>
      <section class="mccf-detail cmp-card" style="padding:16px 18px;">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
          <div>
            <h3><?= h((string)($detail['subject'] ?? 'Requirement')) ?></h3>
            <div class="mccf-detail-meta">
              <span class="mccf-key"><?= h((string)($detail['requirement_key'] ?? '')) ?></span>
              · <?= h((string)($detail['manual_part'] ?? '')) ?>
              · Item <?= h(ControlledPublishingMccfBrowserService::formatItemRef($detail)) ?>
              <?php if (trim((string)($detail['regulation_ref'] ?? '')) !== ''): ?>
                · <?= h((string)$detail['regulation_ref']) ?>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= h(mccf_browser_query(array('req' => 0))) ?>" style="font-size:13px;">Close detail</a>
        </div>
        <?php if (is_array($detailIntegrity)): ?>
          <div style="margin:0 0 12px;max-width:420px;">
            <div style="font-size:12px;font-weight:700;margin-bottom:6px;">Integrity score</div>
            <?= mccf_integrity_bar_html($detailIntegrity) ?>
          </div>
        <?php endif; ?>
        <?php if (trim((string)($detail['manual_section_ref'] ?? '')) !== ''): ?>
          <p style="margin:0 0 10px;font-size:13px;"><strong>Manual section ref:</strong> <?= h((string)$detail['manual_section_ref']) ?></p>
        <?php endif; ?>
        <div style="margin:0 0 12px;">
          <button type="button" class="mccf-pair-btn" data-mccf-action="edit-links" data-req="<?= (int)$requirementId ?>">Edit manual references</button>
        </div>
        <div class="mccf-detail-text"><?= h((string)($detail['requirement_text'] ?? '')) ?></div>
        <?php if (trim((string)($detail['remarks'] ?? '')) !== ''): ?>
          <p style="margin:12px 0 0;font-size:13px;color:#64748b;"><strong>Remarks / revision abstract:</strong> <?= h((string)$detail['remarks']) ?></p>
        <?php endif; ?>
        <?php if (trim((string)($detail['regulation_ref'] ?? '')) !== ''): ?>
          <h4 style="margin:16px 0 8px;font-size:14px;">Applicable regulation source</h4>
          <p style="margin:0 0 8px;font-size:13px;"><?= h((string)$detail['regulation_ref']) ?></p>
          <?php if ($detailRegLinks !== array()): ?>
            <ul class="mccf-reg-links">
              <?php foreach ($detailRegLinks as $regLink): ?>
                <?php
                $batchId = (int)($regLink['target_batch_id'] ?? 0);
                $nodeUid = trim((string)($regLink['target_node_uid'] ?? ''));
                $token = (string)($regLink['rule_token'] ?? '');
                $resolved = ($regLink['match_confidence'] ?? '') !== 'UNRESOLVED' && $batchId > 0 && $nodeUid !== '';
                ?>
                <li>
                  <strong><?= h($token) ?></strong>
                  <span class="mccf-badge <?= $resolved ? 'mccf-badge--linked' : 'mccf-badge--unlinked' ?>" style="margin-left:6px;">
                    <?= h((string)($regLink['match_confidence'] ?? 'AUTO')) ?>
                  </span>
                  <?php if ($resolved): ?>
                    · <a href="<?= h(ControlledPublishingMccfRegulationLinkService::resourceLibraryEasaHref($batchId, $nodeUid)) ?>">Open in EASA Resource Library</a>
                  <?php else: ?>
                    · <a href="<?= h(ControlledPublishingMccfRegulationLinkService::regulationsSearchHref($token)) ?>">Search regulations</a>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif ($detailRegParsed !== array()): ?>
            <ul class="mccf-reg-links">
              <?php foreach ($detailRegParsed as $parsed): ?>
                <li>
                  <strong><?= h((string)$parsed['token']) ?></strong>
                  · <a href="<?= h(ControlledPublishingMccfRegulationLinkService::regulationsSearchHref((string)$parsed['token'])) ?>">Search EASA</a>
                  <?php if (!$regLinksAvailable): ?>
                    <span style="color:#64748b;"> (apply regulation-links migration + auto-link script)</span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
        <h4 style="margin:16px 0 8px;font-size:14px;">Linked manual excerpts (<?= (int)($detail['link_count'] ?? 0) ?>)</h4>
        <?php if (!empty($detail['linked_excerpts']) && is_array($detail['linked_excerpts'])): ?>
          <ul class="mccf-excerpt-list">
            <?php foreach ($detail['linked_excerpts'] as $excerpt): ?>
              <?php
              $exManual = strtoupper(trim((string)($excerpt['manual_code'] ?? $detail['manual_code'] ?? 'OM')));
              $exPart = trim((string)($excerpt['manual_part'] ?? ''));
              $exSec = trim((string)($excerpt['section_ref'] ?? ''));
              $exTitle = trim((string)($excerpt['title'] ?? ''));
              $exLabel = ControlledPublishingMccfBcaaViewService::bookVersionLabel($exManual)
                  . ' Part ' . $exPart . ' §' . $exSec
                  . ($exTitle !== '' ? (' — ' . $exTitle) : '');
              ?>
              <li>
                <strong><?= h($exLabel) ?></strong>
                <span class="mccf-badge mccf-badge--linked" style="margin-left:6px;"><?= h((string)($excerpt['link_type'] ?? 'PRIMARY')) ?></span>
                <?php if (trim((string)($excerpt['confidence'] ?? '')) === 'MANUAL'): ?>
                  <span class="mccf-badge mccf-badge--legacy" style="margin-left:4px;">Manual</span>
                <?php endif; ?>
                <div class="mccf-link-edit-actions">
                  <button type="button" class="mccf-pair-btn" data-mccf-action="manual" data-req="<?= (int)$requirementId ?>" data-excerpt="<?= h((string)($excerpt['excerpt_key'] ?? '')) ?>">Preview</button>
                  <button type="button" class="mccf-pair-btn" data-mccf-action="edit-links" data-req="<?= (int)$requirementId ?>" data-link-id="<?= (int)($excerpt['link_id'] ?? 0) ?>">Edit</button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif (trim((string)($detail['legacy_excerpt_id'] ?? '')) !== ''): ?>
          <p style="margin:0;font-size:13px;color:#64748b;">No active link row, but legacy inline excerpt id: <code><?= h((string)$detail['legacy_excerpt_id']) ?></code></p>
        <?php else: ?>
          <p style="margin:0;font-size:13px;color:#b45309;">No manual excerpt linked to this requirement.</p>
          <p style="margin:8px 0 0;"><button type="button" class="mccf-pair-btn" data-mccf-action="edit-links" data-req="<?= (int)$requirementId ?>">Add manual reference</button></p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="cmp-card">
      <h2 style="margin:0 0 12px;">Filters</h2>
      <form method="get" class="mccf-filters">
        <input type="hidden" name="layout" value="<?= h($layout) ?>">
        <div class="mccf-filters-row">
          <label>
            <span>MCCF source set</span>
            <select name="source_set_id" onchange="this.form.submit()">
              <?php foreach ($sourceSets as $set): ?>
                <?php $sid = (int)($set['id'] ?? 0); ?>
                <option value="<?= $sid ?>" <?= $sid === $sourceSetId ? 'selected' : '' ?>>
                  <?= h((string)($set['source_set_key'] ?? '')) ?>
                  (<?= (int)($set['requirements'] ?? 0) ?> req)
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Manual part</span>
            <select name="part">
              <option value="">All parts</option>
              <?php foreach ($parts as $partOpt): ?>
                <option value="<?= h($partOpt) ?>" <?= $partOpt === $part ? 'selected' : '' ?>><?= h($partOpt) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Coverage</span>
            <select name="coverage">
              <option value="all" <?= $coverage === 'all' ? 'selected' : '' ?>>All</option>
              <option value="linked" <?= $coverage === 'linked' ? 'selected' : '' ?>>Linked only</option>
              <option value="unlinked" <?= $coverage === 'unlinked' ? 'selected' : '' ?>>Unlinked only</option>
            </select>
          </label>
          <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Subject, key, section ref, regulation…">
          </label>
          <button type="submit">Apply</button>
        </div>
      </form>
    </section>

    <?php if (($summary['by_part'] ?? array()) !== array()): ?>
      <section class="cmp-card">
        <h2 style="margin:0 0 12px;">Coverage by part</h2>
        <div class="mccf-coverage-grid">
          <?php foreach ($summary['by_part'] as $partRow): ?>
            <?php
            $partLabel = (string)($partRow['part'] ?? '—');
            $partLinked = (int)($partRow['linked'] ?? 0);
            $partTotal = (int)($partRow['total'] ?? 0);
            $partHref = mccf_browser_query(array(
                'part' => $partLabel === '—' ? '' : $partLabel,
                'layout' => $layout,
                'page' => 1,
                'req' => 0,
            ));
            ?>
            <a class="mccf-coverage-chip" href="<?= h($partHref) ?>" style="text-decoration:none;color:inherit;">
              <strong><?= h($partLabel) ?></strong>
              <?= $partLinked ?> / <?= $partTotal ?> linked
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($sourceSetId > 0 && in_array($layout, array('bcaa', 'pairs'), true)): ?>
      <section class="cmp-card mccf-integrity-job" id="mccfIntegrityJobPanel">
        <div class="mccf-integrity-job-head">
          <div>
            <strong>AI integrity scoring</strong>
            <div class="mccf-integrity-job-meta" id="mccfIntegrityJobMeta">
              <?php if (!$integrityJobsAvailable): ?>
                Apply <code>scripts/sql/2026_06_17_mccf_integrity_jobs.sql</code> to enable background scoring.
              <?php else: ?>
                Scores are computed in the background and cached. Use Refresh to re-score all requirements.
              <?php endif; ?>
            </div>
          </div>
          <?php if ($integrityJobsAvailable): ?>
            <div class="mccf-integrity-job-actions">
              <button type="button" class="mccf-pair-btn" id="mccfIntegrityRefreshBtn">Refresh scores</button>
              <button type="button" class="mccf-pair-btn" id="mccfIntegrityCancelBtn" hidden>Cancel</button>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($integrityJobsAvailable): ?>
          <div class="mccf-integrity-job-bar" aria-hidden="true">
            <span id="mccfIntegrityJobBar" style="width:0%;"></span>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="cmp-card">
      <div class="mccf-layout-toggle">
        <a href="<?= h(mccf_browser_query(array('layout' => 'bcaa', 'page' => 1, 'req' => 0))) ?>" class="<?= $layout === 'bcaa' ? 'is-active' : '' ?>">CAA Checklist View</a>
        <a href="<?= h(mccf_browser_query(array('layout' => 'pairs', 'page' => 1, 'req' => 0))) ?>" class="<?= $layout === 'pairs' ? 'is-active' : '' ?>">Regulation ↔ Manual pairs</a>
        <a href="<?= h(mccf_browser_query(array('layout' => 'coverage', 'page' => 1, 'req' => 0))) ?>" class="<?= $layout === 'coverage' ? 'is-active' : '' ?>">Coverage view</a>
      </div>
      <h2 style="margin:0 0 12px;"><?= $layout === 'bcaa' ? 'MCCF Checklist (CAA Format)' : ($layout === 'pairs' ? 'Regulation ↔ Manual coverage' : 'Requirements') ?></h2>
      <p style="margin:0 0 12px;font-size:13px;color:#64748b;">
        <?php if ($layout === 'bcaa'): ?>
          Columns match the BCAA Word MCCF. Rows without a linked <?= h(is_array($sourceSet) && str_contains((string)($sourceSet['source_set_key'] ?? ''), 'OMM') ? 'OMM 4.0' : 'OM 6.0') ?> excerpt are highlighted. Click regulation or location references to open read-only previews.
        <?php elseif ($layout === 'pairs'): ?>
          Side-by-side regulation source text and linked manual section for each requirement.
        <?php else: ?>
          Showing <?= count($search['rows']) ?> of <?= (int)$search['total'] ?> matching rows
        <?php endif; ?>
        <?php if (is_array($sourceSet) && !empty($sourceSet['last_synced_at'])): ?>
          · Last synced <?= h((string)$sourceSet['last_synced_at']) ?>
        <?php endif; ?>
        <?php if (!$regLinksAvailable): ?>
          · Regulation auto-links: apply <code>scripts/sql/2026_06_06_mccf_regulation_links.sql</code> then run <code>php scripts/link_mccf_regulation_sources.php --apply</code>
        <?php endif; ?>
      </p>
      <?php if ($layout === 'bcaa' || $layout === 'pairs'): ?>
        <p class="mccf-manual-title"><?= h($manualDisplayTitle) ?></p>
      <?php endif; ?>
      <?php if ($layout === 'bcaa'): ?>
        <?php if ($bcaaSections === array()): ?>
          <p style="margin:0;">No requirements match the current filters.</p>
        <?php else: ?>
          <?php foreach ($bcaaSections as $section): ?>
            <div class="mccf-bcaa-section-card">
              <div class="mccf-bcaa-section-head"><?= h((string)$section['label']) ?></div>
              <table class="mccf-bcaa-table">
                <thead>
                  <tr>
                    <th class="mccf-col-item">#</th>
                    <th class="mccf-col-subject">Subject</th>
                    <th class="mccf-col-sub">Sub #</th>
                    <th class="mccf-col-desc">Description / supplementary information</th>
                    <th class="mccf-col-location"><?= h($manualRefColumn) ?></th>
                    <th class="mccf-col-applicable">Appl.</th>
                    <th class="mccf-col-remarks">Rev.</th>
                    <th class="mccf-col-integrity">AI Integrity</th>
                    <th class="mccf-col-finding">Finding</th>
                    <th class="mccf-col-regulation">Regulation</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($section['rows'] as $row): ?>
                    <?php
                    $rid = (int)($row['id'] ?? 0);
                    $needsRegReview = $reviewSvc->rowNeedsRegulationReview(
                        $row,
                        is_array($row['regulation_links'] ?? null) ? $row['regulation_links'] : array(),
                        $easaChangesPending
                    );
                    ?>
                    <tr class="<?= h(mccf_row_class($row)) ?>">
                      <td class="mccf-col-item"><?= h((string)($row['bcaa_item_label'] ?? '')) ?></td>
                      <td class="mccf-col-subject" title="<?= h((string)($row['subject'] ?? '')) ?>"><?= !empty($row['bcaa_show_group']) ? h((string)($row['subject'] ?? '')) : '' ?></td>
                      <td class="mccf-col-sub"><?= h((string)($row['bcaa_sub_label'] ?? '—')) ?></td>
                      <td class="mccf-col-desc">
                        <a href="<?= h(mccf_browser_query(array('req' => $rid))) ?>"><?= h((string)($row['requirement_text'] ?? '')) ?></a>
                        <button type="button" class="mccf-pair-btn" data-mccf-action="pair" data-req="<?= $rid ?>">Compare ↔</button>
                      </td>
                      <td class="mccf-col-location">
                        <ul class="mccf-location-lines">
                          <?php foreach ($row['location_lines'] ?? array() as $loc): ?>
                            <?php if (!is_array($loc)) { continue; } ?>
                            <li>
                              <?php if (!empty($loc['clickable'])): ?>
                                <button type="button" class="mccf-ref-btn" data-mccf-action="manual" data-req="<?= $rid ?>" data-excerpt="<?= h((string)($loc['excerpt_key'] ?? '')) ?>"><?= h((string)($loc['label'] ?? '')) ?></button>
                              <?php else: ?>
                                <?= h((string)($loc['label'] ?? '')) ?>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                        <button type="button" class="mccf-pair-btn" data-mccf-action="edit-links" data-req="<?= $rid ?>">Edit refs</button>
                      </td>
                      <td class="mccf-col-applicable"><?= mccf_applicable_pill_html($row) ?></td>
                      <td class="mccf-col-remarks"><?= h((string)($row['remarks'] ?? '')) ?></td>
                      <td class="mccf-col-integrity" data-integrity-req="<?= $rid ?>"<?= $needsRegReview ? ' data-needs-reg-review="1"' : '' ?>>
                        <?php if (is_array($row['integrity'] ?? null)): ?>
                          <?= mccf_integrity_bar_html($row['integrity'], true) ?>
                        <?php else: ?>
                          <span class="mccf-integrity-pending" title="Awaiting background integrity scoring">—</span>
                        <?php endif; ?>
                        <?php if ($needsRegReview): ?>
                          <span class="mccf-review-flag">Reg review</span>
                        <?php endif; ?>
                      </td>
                      <td class="mccf-col-finding"><?= h((string)($row['finding_ref'] ?? '')) ?></td>
                      <td class="mccf-col-regulation"><?= mccf_regulation_cell_html($row, $rid) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php elseif ($layout === 'pairs'): ?>
        <?php if ($bcaaSections === array()): ?>
          <p style="margin:0;">No requirements match the current filters.</p>
        <?php else: ?>
          <div class="mccf-pairs-grid" id="mccfPairsGrid">
            <?php foreach ($bcaaSections as $section): ?>
              <?php foreach ($section['rows'] as $row): ?>
                <?php $rid = (int)($row['id'] ?? 0); ?>
                <article class="mccf-pair-card" data-req-id="<?= $rid ?>">
                  <div class="mccf-pair-head">
                    <div>
                      <strong><?= h((string)($row['subject'] ?? '')) ?></strong>
                      · Item <?= h((string)($row['bcaa_sub_label'] ?? ControlledPublishingMccfBrowserService::formatItemRef($row))) ?>
                      · <?= h((string)($row['book_version_label'] ?? 'OM Rev 6.0')) ?>
                    </div>
                    <button type="button" class="mccf-pair-btn" data-mccf-action="pair" data-req="<?= $rid ?>">Expand</button>
                  </div>
                  <div class="mccf-pair-split">
                    <div class="mccf-pair-pane is-loading" data-pane="regulation"><h4>Regulation</h4>Loading…</div>
                    <div class="mccf-pair-pane is-loading" data-pane="manual"><h4>Manual coverage</h4>Loading…</div>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php elseif ($search['rows'] === array()): ?>
        <p style="margin:0;">No requirements match the current filters.</p>
      <?php else: ?>
        <div style="overflow:auto;">
          <table class="cmp-table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
              <tr>
                <th align="left">Part</th>
                <th align="left">Item</th>
                <th align="left">Subject</th>
                <th align="left">Manual section ref</th>
                <th align="left">Regulation</th>
                <th align="left">Coverage</th>
                <th align="left"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($search['rows'] as $row): ?>
                <?php
                $rid = (int)($row['id'] ?? 0);
                $linkCount = (int)($row['link_count'] ?? 0);
                $legacy = trim((string)($row['legacy_excerpt_id'] ?? ''));
                $badgeClass = $linkCount > 0 ? 'mccf-badge--linked' : ($legacy !== '' ? 'mccf-badge--legacy' : 'mccf-badge--unlinked');
                $coverageLabel = ControlledPublishingMccfBrowserService::coverageLabel($linkCount, $legacy);
                ?>
                <tr>
                  <td><?= h((string)($row['manual_part'] ?? '')) ?></td>
                  <td><?= h(ControlledPublishingMccfBrowserService::formatItemRef($row)) ?></td>
                  <td class="mccf-subject">
                    <div><?= h((string)($row['subject'] ?? '')) ?></div>
                    <div class="mccf-key"><?= h((string)($row['requirement_key'] ?? '')) ?></div>
                  </td>
                  <td style="max-width:220px;"><?= h((string)($row['manual_section_ref'] ?? '')) ?></td>
                  <td style="max-width:160px;"><?= h((string)($row['regulation_ref'] ?? '')) ?></td>
                  <td><span class="mccf-badge <?= h($badgeClass) ?>"><?= h($coverageLabel) ?></span></td>
                  <td><a href="<?= h(mccf_browser_query(array('req' => $rid))) ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($totalPages > 1): ?>
          <div class="mccf-pagination">
            <?php if ($page > 1): ?>
              <a href="<?= h(mccf_browser_query(array('page' => $page - 1))) ?>">← Previous</a>
            <?php endif; ?>
            <span>Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
            <?php if ($page < $totalPages): ?>
              <a href="<?= h(mccf_browser_query(array('page' => $page + 1))) ?>">Next →</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>

<div class="mccf-modal-backdrop" id="mccfModalBackdrop" aria-hidden="true">
  <div class="mccf-modal" role="dialog" aria-modal="true" aria-labelledby="mccfModalTitle">
    <div class="mccf-modal-head">
      <div>
        <h3 id="mccfModalTitle">Preview</h3>
        <div class="mccf-modal-sub" id="mccfModalSub"></div>
      </div>
      <button type="button" class="mccf-modal-close" id="mccfModalClose" aria-label="Close">×</button>
    </div>
    <div class="mccf-modal-body" id="mccfModalBody"></div>
  </div>
</div>

<div class="mccf-link-editor-backdrop" id="mccfLinkEditorBackdrop" aria-hidden="true">
  <div class="mccf-link-editor" role="dialog" aria-modal="true" aria-labelledby="mccfLinkEditorTitle">
    <div class="mccf-link-editor-head">
      <div>
        <h3 id="mccfLinkEditorTitle">Edit manual references</h3>
        <div class="mccf-link-editor-sub" id="mccfLinkEditorSub"></div>
      </div>
      <button type="button" class="mccf-modal-close" id="mccfLinkEditorClose" aria-label="Close">×</button>
    </div>
    <div class="mccf-link-editor-body" id="mccfLinkEditorBody"></div>
  </div>
</div>

<script>
(function () {
  var apiUrl = '/admin/api/mccf_browser_api.php';
  var mccfSourceSetId = <?= (int)$sourceSetId ?>;
  var mccfIntegrityJobsAvailable = <?= $integrityJobsAvailable ? 'true' : 'false' ?>;
  var mccfInitialIntegrityRun = <?= json_encode($integrityJobStatus['run'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var backdrop = document.getElementById('mccfModalBackdrop');
  var modalTitle = document.getElementById('mccfModalTitle');
  var modalSub = document.getElementById('mccfModalSub');
  var modalBody = document.getElementById('mccfModalBody');
  var closeBtn = document.getElementById('mccfModalClose');

  function apiCall(action, payload) {
    return fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(Object.assign({ action: action }, payload || {}))
    }).then(function (res) { return res.json(); });
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  function integrityBarHtml(integrity, compact) {
    if (!integrity) return '';
    var score = Math.max(0, Math.min(100, parseInt(integrity.score || 0, 10)));
    var tone = integrity.tone || 'muted';
    var barCls = tone === 'ok' ? 'ok' : (tone === 'warn' ? 'warn' : (tone === 'bad' ? 'danger' : 'muted'));
    var html = '<div class="mccf-integrity-row" title="' + escapeHtml((integrity.label || '') + ' — ' + score + '%') + '">'
      + '<div class="mccf-integrity-bar"><span class="' + barCls + '" style="width:' + score + '%;"></span></div>'
      + '<div class="mccf-integrity-value">' + score + '%</div>';
    if (!compact) {
      html += '<div class="mccf-integrity-label">' + escapeHtml(integrity.label || '') + '</div>';
    }
    return html + '</div>';
  }

  function requirementContextHtml(req, integrity) {
    if (!req) return '';
    var html = '<div class="mccf-modal-context">';
    html += '<div class="mccf-modal-context-kicker">MCCF description / supplementary information</div>';
    if (req.subject) {
      html += '<div class="mccf-modal-context-subject">' + escapeHtml(req.subject) + '</div>';
    }
    if (req.requirement_text) {
      html += '<div class="mccf-modal-context-desc">' + escapeHtml(req.requirement_text) + '</div>';
    } else {
      html += '<div class="mccf-modal-context-desc" style="color:#94a3b8;font-style:italic;">No description text on this requirement.</div>';
    }
    if (req.item_ref || req.regulation_ref) {
      html += '<div style="margin-top:8px;font-size:10px;color:#64748b;">';
      if (req.item_ref) html += 'Item ' + escapeHtml(req.item_ref);
      if (req.item_ref && req.regulation_ref) html += ' · ';
      if (req.regulation_ref) html += escapeHtml(req.regulation_ref);
      html += '</div>';
    }
    if (integrity) {
      html += '<div class="mccf-modal-integrity">';
      html += '<div class="mccf-modal-integrity-head"><strong>AI integrity</strong></div>';
      html += integrityBarHtml(integrity, false);
      if (integrity.reasons && integrity.reasons.length) {
        html += '<ul class="mccf-modal-integrity-reasons">';
        integrity.reasons.forEach(function (reason) {
          html += '<li>' + escapeHtml(reason) + '</li>';
        });
        html += '</ul>';
      }
      html += '</div>';
    }
    html += '</div>';
    return html;
  }

  function wrapModalContent(contextHtml, bodyHtml) {
    return (contextHtml || '') + '<div class="mccf-modal-content">' + (bodyHtml || '') + '</div>';
  }

  function scrollHighlight(root) {
    if (!root) return;
    var target = root.querySelector('[data-mccf-highlight="1"], .mccf-hl-line, mark.mccf-hl');
    if (target && target.scrollIntoView) {
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function openModal(title, subtitle, html) {
    modalTitle.textContent = title || 'Preview';
    modalSub.textContent = subtitle || '';
    modalBody.innerHTML = html || '';
    backdrop.classList.add('is-open');
    backdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    scrollHighlight(modalBody);
  }

  function closeModal() {
    backdrop.classList.remove('is-open');
    backdrop.setAttribute('aria-hidden', 'true');
    modalBody.innerHTML = '';
    document.body.style.overflow = '';
  }

  function openRegulation(reqId, ruleToken) {
    openModal('Regulation', 'Loading…', '<p>Loading regulation source…</p>');
    apiCall('regulation_preview', { requirement_id: reqId, rule_token: ruleToken }).then(function (data) {
      if (!data.ok) {
        openModal('Regulation', '', wrapModalContent(requirementContextHtml(data.requirement), '<p>' + escapeHtml(data.error || 'Could not load regulation preview.') + '</p>'));
        return;
      }
      openModal(
        data.title || 'Regulation',
        data.subtitle || '',
        wrapModalContent(requirementContextHtml(data.requirement), data.html || '')
      );
    }).catch(function () {
      openModal('Regulation', '', '<p>Could not load regulation preview.</p>');
    });
  }

  function openManual(reqId, excerptKey) {
    openModal('Manual section', 'Loading…', '<p>Loading manual section…</p>');
    apiCall('manual_preview', { requirement_id: reqId, excerpt_key: excerptKey || '' }).then(function (data) {
      if (!data.ok) {
        openModal('Manual section', data.book_label || '', wrapModalContent(requirementContextHtml(data.requirement), '<p>' + escapeHtml(data.error || 'Could not load manual preview.') + '</p>'));
        return;
      }
      var html = '';
      (data.sections || []).forEach(function (section) {
        html += section.html || '';
      });
      openModal(
        data.book_label || 'Manual section',
        (data.sections && data.sections[0] && data.sections[0].label) || '',
        wrapModalContent(requirementContextHtml(data.requirement), html || '<p>No manual content available.</p>')
      );
    }).catch(function () {
      openModal('Manual section', '', '<p>Could not load manual preview.</p>');
    });
  }

  function openPair(reqId) {
    openModal('Regulation ↔ Manual', 'Loading…', '<div class="mccf-pair-split"><div class="mccf-pair-pane is-loading"><h4>Regulation</h4>Loading…</div><div class="mccf-pair-pane is-loading"><h4>Manual coverage</h4>Loading…</div></div>');
    apiCall('coverage_pair', { requirement_id: reqId }).then(function (data) {
      if (!data.ok) {
        openModal('Regulation ↔ Manual', '', '<p>Could not load coverage pair.</p>');
        return;
      }
      var regHtml = (data.regulation && data.regulation.ok) ? (data.regulation.html || '') : '<p>' + escapeHtml((data.regulation && data.regulation.error) || 'No regulation text.') + '</p>';
      var manualHtml = '';
      if (data.manual && data.manual.ok && data.manual.sections) {
        data.manual.sections.forEach(function (section) { manualHtml += section.html || ''; });
      } else {
        manualHtml = '<p>' + escapeHtml((data.manual && data.manual.error) || 'No linked manual section.') + '</p>';
      }
      var subtitle = (data.item_ref || '') + (data.subject ? (' — ' + data.subject) : '');
      var pairHtml = '<div class="mccf-pair-split">'
        + '<div class="mccf-pair-pane"><h4>Regulation</h4>' + regHtml + '</div>'
        + '<div class="mccf-pair-pane"><h4>Manual coverage</h4>' + manualHtml + '</div>'
        + '</div>';
      openModal(
        'Regulation ↔ Manual',
        subtitle,
        wrapModalContent(requirementContextHtml(data.requirement, data.integrity), pairHtml)
      );
      scrollHighlight(modalBody);
    }).catch(function () {
      openModal('Regulation ↔ Manual', '', '<p>Could not load coverage pair.</p>');
    });
  }

  var linkBackdrop = document.getElementById('mccfLinkEditorBackdrop');
  var linkEditorBody = document.getElementById('mccfLinkEditorBody');
  var linkEditorSub = document.getElementById('mccfLinkEditorSub');
  var linkEditorClose = document.getElementById('mccfLinkEditorClose');
  var linkEditorState = null;

  function closeLinkEditor() {
    if (!linkBackdrop) return;
    linkBackdrop.classList.remove('is-open');
    linkBackdrop.setAttribute('aria-hidden', 'true');
    if (linkEditorBody) linkEditorBody.innerHTML = '';
    linkEditorState = null;
    document.body.style.overflow = '';
  }

  function openLinkEditorShell(title, subtitle) {
    if (!linkBackdrop || !linkEditorBody) return;
    document.getElementById('mccfLinkEditorTitle').textContent = title || 'Edit manual references';
    if (linkEditorSub) linkEditorSub.textContent = subtitle || '';
    linkEditorBody.innerHTML = '<p style="margin:0;color:#64748b;">Loading…</p>';
    linkBackdrop.classList.add('is-open');
    linkBackdrop.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function renderCurrentLinks(links) {
    if (!links || !links.length) {
      return '<p style="margin:0;font-size:12px;color:#64748b;">No manual references linked yet.</p>';
    }
    var html = '<ul class="mccf-link-current-list">';
    links.forEach(function (link) {
      html += '<li class="mccf-link-current-item">'
        + '<div><strong>' + escapeHtml(link.display_label || link.excerpt_key || 'Section') + '</strong>'
        + '<div class="mccf-link-current-meta">' + escapeHtml(link.link_type || 'PRIMARY')
        + (link.confidence ? (' · ' + escapeHtml(link.confidence)) : '') + '</div></div>'
        + '<div class="mccf-link-current-actions">'
        + '<button type="button" class="mccf-pair-btn" data-link-action="edit" data-link-id="' + link.id + '">Change</button>'
        + '<button type="button" class="mccf-pair-btn" data-link-action="delete" data-link-id="' + link.id + '">Remove</button>'
        + '</div></li>';
    });
    html += '</ul>';
    return html;
  }

  function renderBreadcrumb(state) {
    var html = '<span class="mccf-link-breadcrumb">';
    html += 'Part ' + escapeHtml(String(state.picker.part || '—')) + ' · Ch. ' + escapeHtml(String(state.picker.chapter || '—'));
    if (state.picker.parent_section_ref) {
      html += ' · §' + escapeHtml(state.picker.parent_section_ref);
      html += ' <button type="button" data-link-action="breadcrumb-up">↑ Up</button>';
    }
    html += '</span>';
    return html;
  }

  function renderSectionOptions(sections, selectedPickerId) {
    if (!sections || !sections.length) {
      return '<p style="margin:10px;padding:0 2px;font-size:11px;color:#64748b;">No sections at this level.</p>';
    }
    var html = '<ul class="mccf-link-section-list">';
    sections.forEach(function (section) {
      var pickerId = section.id || '';
      var selected = selectedPickerId && String(selectedPickerId) === String(pickerId);
      html += '<li class="mccf-link-section-row' + (selected ? ' is-selected' : '') + '" data-section-id="' + escapeHtml(pickerId) + '" data-section-ref="' + escapeHtml(section.section_ref || '') + '">'
        + '<div class="mccf-link-section-pick">'
        + '<span class="mccf-link-section-title">' + escapeHtml(section.label || ('§' + section.section_ref)) + '</span>'
        + (section.preview ? ('<span class="mccf-link-section-snippet">' + escapeHtml(section.preview) + '</span>') : '')
        + '</div>';
      if (section.has_children) {
        html += '<button type="button" class="mccf-pair-btn" data-link-action="drill" data-drill-ref="' + escapeHtml(section.section_ref || '') + '">Sub →</button>';
      }
      html += '</li>';
    });
    html += '</ul>';
    return html;
  }

  function renderLinkEditorForm() {
    if (!linkEditorState || !linkEditorBody) return;
    var state = linkEditorState;
    var editing = state.editingLinkId ? state.links.find(function (l) { return parseInt(l.id, 10) === parseInt(state.editingLinkId, 10); }) : null;
    var pickerTitle = editing ? 'Change linked section' : 'Add manual reference';
    var bookOptions = (state.books || []).map(function (book) {
      var selected = book.manual_code === state.picker.manual_code ? ' selected' : '';
      return '<option value="' + escapeHtml(book.manual_code) + '"' + selected + '>' + escapeHtml(book.label) + '</option>';
    }).join('');
    var partOptions = (state.parts || []).map(function (part) {
      var selected = String(part.part) === String(state.picker.part) ? ' selected' : '';
      return '<option value="' + escapeHtml(part.part) + '"' + selected + '>' + escapeHtml(part.label) + '</option>';
    }).join('');
    var chapterOptions = (state.chapters || []).map(function (chapter) {
      var selected = String(chapter.chapter) === String(state.picker.chapter) ? ' selected' : '';
      return '<option value="' + escapeHtml(chapter.chapter) + '"' + selected + '>' + escapeHtml(chapter.label) + '</option>';
    }).join('');

    var previewText = state.picker.preview ? escapeHtml(state.picker.preview) : 'Select a section to preview its text.';
    var previewClass = state.picker.preview ? 'mccf-link-preview' : 'mccf-link-preview is-empty';

    linkEditorBody.innerHTML = ''
      + '<section class="mccf-link-editor-section"><h4>Current references</h4>' + renderCurrentLinks(state.links) + '</section>'
      + '<section class="mccf-link-editor-section"><h4>MCCF display ref</h4>'
      + '<textarea class="mccf-link-ref-input" id="mccfManualSectionRef" rows="2" placeholder="e.g. Part 1 – Ch. 8.6">' + escapeHtml(state.manual_section_ref || '') + '</textarea>'
      + '<div class="mccf-link-ref-actions"><button type="button" class="mccf-pair-btn" data-link-action="save-ref">Save display ref</button></div>'
      + '</section>'
      + '<section class="mccf-link-editor-section"><h4>' + pickerTitle + '</h4>'
      + '<div class="mccf-link-picker-grid">'
      + '<label>Book<select id="mccfPickBook">' + bookOptions + '</select></label>'
      + '<label>Part<select id="mccfPickPart">' + (partOptions || '<option value="">Select part</option>') + '</select></label>'
      + '<label>Chapter<select id="mccfPickChapter">' + (chapterOptions || '<option value="">Select chapter</option>') + '</select></label>'
      + '<label>Link type<select id="mccfPickLinkType"><option value="PRIMARY"' + ((!editing || editing.link_type === 'PRIMARY') ? ' selected' : '') + '>Primary</option><option value="SUPPORTING"' + ((editing && editing.link_type === 'SUPPORTING') ? ' selected' : '') + '>Supporting</option></select></label>'
      + '</div>'
      + '<div class="mccf-link-picker-main">'
      + '<div class="mccf-link-section-panel">'
      + '<div class="mccf-link-panel-head"><strong>Sections</strong>' + renderBreadcrumb(state) + '</div>'
      + '<div id="mccfSectionList">' + renderSectionOptions(state.sections, state.picker.selected_section_picker_id) + '</div>'
      + '</div>'
      + '<div class="mccf-link-preview-panel">'
      + '<div class="mccf-link-panel-head"><strong>Preview</strong></div>'
      + '<div class="' + previewClass + '" id="mccfSectionPreview">' + previewText + '</div>'
      + '</div>'
      + '</div>'
      + '<div class="mccf-link-editor-foot">'
      + (state.editingLinkId ? '<button type="button" class="mccf-pair-btn" data-link-action="cancel-edit">Cancel edit</button>' : '')
      + '<button type="button" class="mccf-pair-btn" data-link-action="save-link"' + (state.picker.selected_section_ref ? '' : ' disabled') + '>' + (editing ? 'Save change' : 'Add reference') + '</button>'
      + '</div>'
      + '</section>';
  }

  function loadLinkEditorParts() {
    if (!linkEditorState) return Promise.resolve();
    return apiCall('manual_link_parts', { manual_code: linkEditorState.picker.manual_code }).then(function (data) {
      if (data.ok) linkEditorState.parts = data.parts || [];
    });
  }

  function loadLinkEditorChapters() {
    if (!linkEditorState) return Promise.resolve();
    return apiCall('manual_link_chapters', {
      manual_code: linkEditorState.picker.manual_code,
      part: linkEditorState.picker.part
    }).then(function (data) {
      if (data.ok) linkEditorState.chapters = data.chapters || [];
    });
  }

  function loadLinkEditorSections() {
    if (!linkEditorState) return Promise.resolve();
    return apiCall('manual_link_sections', {
      manual_code: linkEditorState.picker.manual_code,
      part: linkEditorState.picker.part,
      chapter: linkEditorState.picker.chapter,
      parent_section_ref: linkEditorState.picker.parent_section_ref || ''
    }).then(function (data) {
      if (data.ok) {
        linkEditorState.sections = data.sections || [];
        if (linkEditorState.picker.selected_section_picker_id) {
          var selected = linkEditorState.sections.find(function (s) {
            return String(s.id) === String(linkEditorState.picker.selected_section_picker_id);
          });
          linkEditorState.picker.preview = selected ? selected.preview : linkEditorState.picker.preview;
        }
      }
    });
  }

  function applyEditingLinkPicker() {
    if (!linkEditorState || !linkEditorState.editingLinkId) return Promise.resolve();
    var editing = (linkEditorState.links || []).find(function (link) {
      return parseInt(link.id, 10) === parseInt(linkEditorState.editingLinkId, 10);
    });
    if (!editing) return Promise.resolve();
    linkEditorState.picker.manual_code = (editing.manual_code || linkEditorState.picker.manual_code || 'OM').toUpperCase();
    linkEditorState.picker.part = String(editing.manual_part || linkEditorState.picker.part || '');
    var ref = String(editing.section_ref || '');
    var refParts = ref.split('.').filter(Boolean);
    if (refParts.length) {
      linkEditorState.picker.chapter = refParts[0];
      linkEditorState.picker.parent_section_ref = refParts.length > 2 ? refParts.slice(0, -1).join('.') : '';
    }
    linkEditorState.picker.selected_section_picker_id = editing.section_picker_id || null;
    linkEditorState.picker.selected_section_ref = editing.section_ref || null;
    linkEditorState.picker.preview = editing.excerpt_preview || '';
    return loadLinkEditorParts().then(function () {
      return loadLinkEditorChapters();
    }).then(function () {
      return loadLinkEditorSections();
    });
  }

  function reloadLinkEditorContext(reqId, focusLinkId) {
    return apiCall('manual_link_context', { requirement_id: reqId }).then(function (data) {
      if (!data.ok) {
        if (linkEditorBody) linkEditorBody.innerHTML = '<p>' + escapeHtml(data.error || 'Could not load editor.') + '</p>';
        return;
      }
      var defaultBook = (data.manual_code || 'OM').toUpperCase();
      var books = data.books || [];
      if (!books.some(function (b) { return b.manual_code === defaultBook; }) && books.length) {
        defaultBook = books[0].manual_code;
      }
      linkEditorState = {
        requirement_id: reqId,
        subject: data.subject || '',
        manual_code: data.manual_code || '',
        manual_section_ref: data.manual_section_ref || '',
        links: data.links || [],
        books: books,
        parts: [],
        chapters: [],
        sections: [],
        editingLinkId: focusLinkId || null,
        picker: {
          manual_code: defaultBook,
          part: '',
          chapter: '',
          parent_section_ref: '',
          selected_section_picker_id: null,
          selected_section_ref: null,
          preview: ''
        }
      };
      if (linkEditorSub) {
        linkEditorSub.textContent = (data.requirement_key || '') + (data.subject ? (' — ' + data.subject) : '');
      }
      return loadLinkEditorParts().then(function () {
        if (linkEditorState.editingLinkId) {
          return applyEditingLinkPicker();
        }
        if (linkEditorState.parts.length) {
          linkEditorState.picker.part = linkEditorState.parts[0].part;
        }
        return loadLinkEditorChapters();
      }).then(function () {
        if (linkEditorState.editingLinkId) {
          return null;
        }
        if (linkEditorState.chapters.length) {
          linkEditorState.picker.chapter = linkEditorState.chapters[0].chapter;
        }
        return loadLinkEditorSections();
      }).then(function () {
        if (linkEditorState.editingLinkId && (!linkEditorState.sections || !linkEditorState.sections.length)) {
          return loadLinkEditorSections();
        }
        return null;
      }).then(function () {
        renderLinkEditorForm();
      });
    });
  }

  function openLinkEditor(reqId, focusLinkId) {
    openLinkEditorShell('Edit manual references', 'Loading…');
    reloadLinkEditorContext(reqId, focusLinkId).catch(function () {
      if (linkEditorBody) linkEditorBody.innerHTML = '<p>Could not load manual reference editor.</p>';
    });
  }

  if (linkEditorBody) {
    linkEditorBody.addEventListener('change', function (event) {
      if (!linkEditorState) return;
      if (event.target.id === 'mccfPickBook') {
        linkEditorState.picker.manual_code = event.target.value;
        linkEditorState.picker.part = '';
        linkEditorState.picker.chapter = '';
        linkEditorState.picker.parent_section_ref = '';
        linkEditorState.picker.selected_section_picker_id = null;
        linkEditorState.picker.selected_section_ref = null;
        linkEditorState.picker.preview = '';
        loadLinkEditorParts().then(function () {
          if (linkEditorState.parts.length) linkEditorState.picker.part = linkEditorState.parts[0].part;
          return loadLinkEditorChapters();
        }).then(function () {
          if (linkEditorState.chapters.length) linkEditorState.picker.chapter = linkEditorState.chapters[0].chapter;
          return loadLinkEditorSections();
        }).then(renderLinkEditorForm);
      } else if (event.target.id === 'mccfPickPart') {
        linkEditorState.picker.part = event.target.value;
        linkEditorState.picker.chapter = '';
        linkEditorState.picker.parent_section_ref = '';
        linkEditorState.picker.selected_section_picker_id = null;
        linkEditorState.picker.selected_section_ref = null;
        linkEditorState.picker.preview = '';
        loadLinkEditorChapters().then(function () {
          if (linkEditorState.chapters.length) linkEditorState.picker.chapter = linkEditorState.chapters[0].chapter;
          return loadLinkEditorSections();
        }).then(renderLinkEditorForm);
      } else if (event.target.id === 'mccfPickChapter') {
        linkEditorState.picker.chapter = event.target.value;
        linkEditorState.picker.parent_section_ref = '';
        linkEditorState.picker.selected_section_picker_id = null;
        linkEditorState.picker.selected_section_ref = null;
        linkEditorState.picker.preview = '';
        loadLinkEditorSections().then(renderLinkEditorForm);
      }
    });

    linkEditorBody.addEventListener('click', function (event) {
      if (!linkEditorState) return;

      var actionBtn = event.target.closest('[data-link-action]');
      if (actionBtn) {
        var action = actionBtn.getAttribute('data-link-action');
        if (action === 'drill') {
          event.preventDefault();
          event.stopPropagation();
          linkEditorState.picker.parent_section_ref = actionBtn.getAttribute('data-drill-ref') || '';
          linkEditorState.picker.selected_section_picker_id = null;
        linkEditorState.picker.selected_section_ref = null;
          linkEditorState.picker.preview = '';
          loadLinkEditorSections().then(renderLinkEditorForm);
          return;
        }
        if (action === 'breadcrumb-up') {
          event.preventDefault();
          var ref = String(linkEditorState.picker.parent_section_ref || '');
          var parts = ref.split('.').filter(Boolean);
          if (parts.length > 1) {
            linkEditorState.picker.parent_section_ref = parts.slice(0, -1).join('.');
          } else {
            linkEditorState.picker.parent_section_ref = '';
          }
          linkEditorState.picker.selected_section_picker_id = null;
        linkEditorState.picker.selected_section_ref = null;
          linkEditorState.picker.preview = '';
          loadLinkEditorSections().then(renderLinkEditorForm);
          return;
        }
      }

      var sectionRow = event.target.closest('.mccf-link-section-row');
      if (sectionRow && !event.target.closest('[data-link-action="drill"]')) {
        event.preventDefault();
        linkEditorState.picker.selected_section_picker_id = sectionRow.getAttribute('data-section-id') || null;
        linkEditorState.picker.selected_section_ref = sectionRow.getAttribute('data-section-ref') || null;
        var selected = (linkEditorState.sections || []).find(function (s) {
          return String(s.id) === String(linkEditorState.picker.selected_section_picker_id);
        });
        linkEditorState.picker.preview = selected ? selected.preview : '';
        renderLinkEditorForm();
        return;
      }

      if (!actionBtn) return;
      var action = actionBtn.getAttribute('data-link-action');
      if (action === 'cancel-edit') {
        linkEditorState.editingLinkId = null;
        renderLinkEditorForm();
      } else if (action === 'edit') {
        linkEditorState.editingLinkId = parseInt(actionBtn.getAttribute('data-link-id') || '0', 10);
        applyEditingLinkPicker().then(renderLinkEditorForm);
      } else if (action === 'delete') {
        if (!window.confirm('Remove this manual reference?')) return;
        apiCall('manual_link_delete', { link_id: parseInt(actionBtn.getAttribute('data-link-id') || '0', 10) }).then(function (data) {
          if (!data.ok) {
            window.alert(data.error || 'Could not remove reference.');
            return;
          }
          reloadLinkEditorContext(linkEditorState.requirement_id).then(function () {
            window.location.reload();
          });
        });
      } else if (action === 'save-ref') {
        var refInput = document.getElementById('mccfManualSectionRef');
        apiCall('manual_section_ref_update', {
          requirement_id: linkEditorState.requirement_id,
          manual_section_ref: refInput ? refInput.value : ''
        }).then(function (data) {
          if (!data.ok) {
            window.alert(data.error || 'Could not save manual section ref.');
            return;
          }
          window.location.reload();
        });
      } else if (action === 'save-link') {
        if (!linkEditorState.picker.selected_section_ref) return;
        var linkTypeEl = document.getElementById('mccfPickLinkType');
        var linkType = linkTypeEl ? linkTypeEl.value : 'PRIMARY';
        var payload = {
          manual_code: linkEditorState.picker.manual_code,
          part: linkEditorState.picker.part,
          section_ref: linkEditorState.picker.selected_section_ref,
          section_picker_id: linkEditorState.picker.selected_section_picker_id,
          link_type: linkType
        };
        var promise;
        if (linkEditorState.editingLinkId) {
          promise = apiCall('manual_link_update', Object.assign({ link_id: linkEditorState.editingLinkId }, payload));
        } else {
          promise = apiCall('manual_link_add', Object.assign({ requirement_id: linkEditorState.requirement_id }, payload));
        }
        promise.then(function (data) {
          if (!data.ok) {
            window.alert(data.error || 'Could not save reference.');
            return;
          }
          window.location.reload();
        });
      }
    });
  }

  if (linkEditorClose) linkEditorClose.addEventListener('click', closeLinkEditor);
  if (linkBackdrop) {
    linkBackdrop.addEventListener('click', function (event) {
      if (event.target === linkBackdrop) closeLinkEditor();
    });
  }

  function fillPairCard(card) {
    if (!card || card.getAttribute('data-loaded') === '1') return;
    var reqId = parseInt(card.getAttribute('data-req-id') || '0', 10);
    if (!reqId) return;
    card.setAttribute('data-loaded', '1');
    apiCall('coverage_pair', { requirement_id: reqId }).then(function (data) {
      var regPane = card.querySelector('[data-pane="regulation"]');
      var manualPane = card.querySelector('[data-pane="manual"]');
      if (!regPane || !manualPane) return;
      if (!data.ok) {
        regPane.textContent = 'Could not load.';
        manualPane.textContent = 'Could not load.';
        regPane.classList.remove('is-loading');
        manualPane.classList.remove('is-loading');
        return;
      }
      regPane.classList.remove('is-loading');
      manualPane.classList.remove('is-loading');
      regPane.innerHTML = '<h4>Regulation</h4>' + ((data.regulation && data.regulation.ok) ? (data.regulation.html || '') : '<p>' + ((data.regulation && data.regulation.error) || 'No regulation text.') + '</p>');
      var manualHtml = '';
      if (data.manual && data.manual.ok && data.manual.sections) {
        data.manual.sections.forEach(function (section) { manualHtml += section.html || ''; });
      } else {
        manualHtml = '<p>No linked manual section.</p>';
      }
      manualPane.innerHTML = '<h4>Manual coverage</h4>' + manualHtml;
    });
  }

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-mccf-action]');
    if (!btn) return;
    var action = btn.getAttribute('data-mccf-action');
    var reqId = parseInt(btn.getAttribute('data-req') || '0', 10);
    if (action === 'edit-links') {
      event.preventDefault();
      if (!reqId) return;
      openLinkEditor(reqId, parseInt(btn.getAttribute('data-link-id') || '0', 10) || null);
      return;
    }
    if (!reqId) return;
    if (action === 'regulation') {
      event.preventDefault();
      openRegulation(reqId, btn.getAttribute('data-rule') || '');
    } else if (action === 'manual') {
      event.preventDefault();
      openManual(reqId, btn.getAttribute('data-excerpt') || '');
    } else if (action === 'pair') {
      event.preventDefault();
      openPair(reqId);
    }
  });

  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', function (event) {
    if (event.target === backdrop) closeModal();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      if (linkBackdrop && linkBackdrop.classList.contains('is-open')) closeLinkEditor();
      else closeModal();
    }
  });

  var pairsGrid = document.getElementById('mccfPairsGrid');
  if (pairsGrid && 'IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) fillPairCard(entry.target);
      });
    }, { rootMargin: '120px 0px' });
    pairsGrid.querySelectorAll('.mccf-pair-card').forEach(function (card) { observer.observe(card); });
  } else if (pairsGrid) {
    pairsGrid.querySelectorAll('.mccf-pair-card').forEach(fillPairCard);
  }

  function applyIntegrityScoresToTable(scores) {
    if (!scores) return;
    Object.keys(scores).forEach(function (rid) {
      var cell = document.querySelector('[data-integrity-req="' + rid + '"]');
      if (!cell) return;
      var needsReview = cell.getAttribute('data-needs-reg-review') === '1';
      cell.innerHTML = integrityBarHtml(scores[rid], true);
      if (needsReview) {
        var flag = document.createElement('span');
        flag.className = 'mccf-review-flag';
        flag.textContent = 'Reg review';
        cell.appendChild(flag);
      }
    });
  }

  function collectIntegrityRequirementIds() {
    var ids = [];
    document.querySelectorAll('[data-integrity-req]').forEach(function (cell) {
      var id = parseInt(cell.getAttribute('data-integrity-req') || '0', 10);
      if (id > 0) ids.push(id);
    });
    return ids;
  }

  function refreshCachedIntegrityScores() {
    var ids = collectIntegrityRequirementIds();
    if (!ids.length) return Promise.resolve();
    var batchSize = 200;
    var offset = 0;
    function nextBatch() {
      var batch = ids.slice(offset, offset + batchSize);
      if (!batch.length) return Promise.resolve();
      return apiCall('integrity_job_scores', { requirement_ids: batch }).then(function (data) {
        if (data.ok) applyIntegrityScoresToTable(data.scores || {});
        offset += batchSize;
        return nextBatch();
      });
    }
    return nextBatch();
  }

  var integrityPollTimer = null;
  var integrityJobPanel = document.getElementById('mccfIntegrityJobPanel');
  var integrityJobMeta = document.getElementById('mccfIntegrityJobMeta');
  var integrityJobBar = document.getElementById('mccfIntegrityJobBar');
  var integrityRefreshBtn = document.getElementById('mccfIntegrityRefreshBtn');
  var integrityCancelBtn = document.getElementById('mccfIntegrityCancelBtn');
  var activeIntegrityRun = mccfInitialIntegrityRun || null;
  var integrityTickInFlight = false;

  function renderIntegrityJobStatus(run) {
    activeIntegrityRun = run || null;
    if (!integrityJobMeta || !integrityJobBar) return;

    if (!run) {
      integrityJobMeta.textContent = 'No scoring run yet. Click Refresh scores to compute integrity for all requirements.';
      integrityJobBar.style.width = '0%';
      if (integrityCancelBtn) integrityCancelBtn.hidden = true;
      if (integrityRefreshBtn) integrityRefreshBtn.disabled = false;
      return;
    }

    var processed = parseInt(run.processed_count || 0, 10);
    var total = parseInt(run.total_count || 0, 10);
    var pct = parseInt(run.percent || 0, 10);
    var status = String(run.status || '');

    if (status === 'running' || status === 'queued') {
      integrityJobMeta.textContent = 'Background scoring: ' + processed + ' / ' + total + ' requirements (' + pct + '%).'
        + (processed === 0 ? ' First item can take up to a minute while EASA text is loaded.' : '')
        + ' Progress continues while this page is open.';
      integrityJobBar.style.width = Math.max(0, Math.min(100, pct)) + '%';
      if (integrityCancelBtn) integrityCancelBtn.hidden = false;
      if (integrityRefreshBtn) integrityRefreshBtn.disabled = true;
    } else if (status === 'completed') {
      integrityJobMeta.textContent = 'Last run completed — ' + total + ' requirements scored'
        + (run.completed_at ? (' · ' + run.completed_at) : '') + '.';
      integrityJobBar.style.width = '100%';
      if (integrityCancelBtn) integrityCancelBtn.hidden = true;
      if (integrityRefreshBtn) integrityRefreshBtn.disabled = false;
    } else if (status === 'failed') {
      integrityJobMeta.textContent = 'Scoring failed: ' + (run.error_message || 'Unknown error') + '. Click Refresh to retry.';
      integrityJobBar.style.width = '0%';
      if (integrityCancelBtn) integrityCancelBtn.hidden = true;
      if (integrityRefreshBtn) integrityRefreshBtn.disabled = false;
    } else if (status === 'cancelled') {
      integrityJobMeta.textContent = 'Scoring cancelled at ' + processed + ' / ' + total + '. Click Refresh to run again.';
      integrityJobBar.style.width = total > 0 ? ((processed / total) * 100) + '%' : '0%';
      if (integrityCancelBtn) integrityCancelBtn.hidden = true;
      if (integrityRefreshBtn) integrityRefreshBtn.disabled = false;
    }
  }

  function pollIntegrityJobStatus() {
    if (!mccfIntegrityJobsAvailable || mccfSourceSetId <= 0) return Promise.resolve();
    var tickPromise = Promise.resolve();
    if (activeIntegrityRun && activeIntegrityRun.is_active && activeIntegrityRun.id && !integrityTickInFlight) {
      integrityTickInFlight = true;
      tickPromise = apiCall('integrity_job_tick', { run_id: activeIntegrityRun.id }).finally(function () {
        integrityTickInFlight = false;
      });
    }
    return tickPromise.then(function () {
      return apiCall('integrity_job_status', { source_set_id: mccfSourceSetId });
    }).then(function (data) {
      if (!data.ok) return;
      var prevProcessed = activeIntegrityRun ? parseInt(activeIntegrityRun.processed_count || 0, 10) : -1;
      renderIntegrityJobStatus(data.run || null);
      var nextProcessed = data.run ? parseInt(data.run.processed_count || 0, 10) : prevProcessed;
      if (nextProcessed !== prevProcessed) {
        return refreshCachedIntegrityScores();
      }
      if (data.run && data.run.status === 'completed') {
        return refreshCachedIntegrityScores();
      }
    });
  }

  function startIntegrityPolling() {
    if (integrityPollTimer) clearInterval(integrityPollTimer);
    pollIntegrityJobStatus();
    integrityPollTimer = setInterval(pollIntegrityJobStatus, 3000);
  }

  if (mccfIntegrityJobsAvailable && mccfSourceSetId > 0 && integrityJobPanel) {
    renderIntegrityJobStatus(mccfInitialIntegrityRun);
    refreshCachedIntegrityScores().finally(startIntegrityPolling);

    if (integrityRefreshBtn) {
      integrityRefreshBtn.addEventListener('click', function () {
        integrityRefreshBtn.disabled = true;
        apiCall('integrity_job_start', { source_set_id: mccfSourceSetId }).then(function (data) {
          if (!data.ok) {
            window.alert(data.error || 'Could not start integrity scoring.');
            integrityRefreshBtn.disabled = false;
            return;
          }
          if (!data.worker_spawned) {
            window.alert('Server background worker could not be started; scoring continues via this page. For fully independent runs: php scripts/run_mccf_integrity_job.php --run-id=' + (data.run_id || ''));
          }
          pollIntegrityJobStatus();
        });
      });
    }

    if (integrityCancelBtn) {
      integrityCancelBtn.addEventListener('click', function () {
        if (!activeIntegrityRun || !activeIntegrityRun.id) return;
        apiCall('integrity_job_cancel', { run_id: activeIntegrityRun.id }).then(function (data) {
          if (!data.ok) {
            window.alert(data.error || 'Could not cancel run.');
            return;
          }
          pollIntegrityJobStatus();
        });
      });
    }
  }
})();
</script>
<?php

compliance_page_close();
cw_footer();
