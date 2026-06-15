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

$user = compliance_require_access($pdo);
$svc = new ControlledPublishingMccfBrowserService($pdo);
$regSvc = new ControlledPublishingMccfRegulationLinkService($pdo);
$bcaaSvc = new ControlledPublishingMccfBcaaViewService($pdo);
$integritySvc = new ControlledPublishingMccfIntegrityService();
$reviewSvc = new ControlledPublishingMccfRegulationReviewService($pdo);
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
    $detailExcerptsForScore = array();
    foreach ($detail['linked_excerpts'] ?? array() as $excerpt) {
        if (!is_array($excerpt)) {
            continue;
        }
        $detailExcerptsForScore[] = array(
            'body_text' => (string)($excerpt['excerpt_preview'] ?? ''),
            'excerpt_preview' => (string)($excerpt['excerpt_preview'] ?? ''),
        );
    }
    $detailIntegrity = $integritySvc->scoreRequirement($detail, $detailExcerptsForScore, $detailRegLinks);
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
  .mccf-modal-integrity-reasons li { margin-bottom: 4px; }
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
</style>

<div class="mccf-layout">
  <?php if ($sourceSets === array()): ?>
    <section class="cmp-card">
      <p style="margin:0;">No MCCF source sets found. Run the legacy canonical sync first (<code>php scripts/sync_legacy_compliance_canonical_sources.php --apply</code>).</p>
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
              <li>
                <strong><?= h((string)($excerpt['excerpt_key'] ?? '')) ?></strong>
                <?php if (trim((string)($excerpt['excerpt_title'] ?? '')) !== ''): ?>
                  — <?= h((string)$excerpt['excerpt_title']) ?>
                <?php endif; ?>
                <?php if (trim((string)($excerpt['excerpt_section_ref'] ?? '')) !== ''): ?>
                  <span style="color:#64748b;"> (§<?= h((string)$excerpt['excerpt_section_ref']) ?>)</span>
                <?php endif; ?>
                <span class="mccf-badge mccf-badge--linked" style="margin-left:6px;"><?= h((string)($excerpt['link_type'] ?? 'PRIMARY')) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif (trim((string)($detail['legacy_excerpt_id'] ?? '')) !== ''): ?>
          <p style="margin:0;font-size:13px;color:#64748b;">No active link row, but legacy inline excerpt id: <code><?= h((string)$detail['legacy_excerpt_id']) ?></code></p>
        <?php else: ?>
          <p style="margin:0;font-size:13px;color:#b45309;">No manual excerpt linked to this requirement.</p>
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
                      </td>
                      <td class="mccf-col-applicable"><?= mccf_applicable_pill_html($row) ?></td>
                      <td class="mccf-col-remarks"><?= h((string)($row['remarks'] ?? '')) ?></td>
                      <td class="mccf-col-integrity">
                        <?php if (is_array($row['integrity'] ?? null)): ?>
                          <?= mccf_integrity_bar_html($row['integrity'], true) ?>
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

<script>
(function () {
  var apiUrl = '/admin/api/mccf_browser_api.php';
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

  function integrityBarHtml(integrity) {
    if (!integrity) return '';
    var score = Math.max(0, Math.min(100, parseInt(integrity.score || 0, 10)));
    var tone = integrity.tone || 'muted';
    var barCls = tone === 'ok' ? 'ok' : (tone === 'warn' ? 'warn' : (tone === 'bad' ? 'danger' : 'muted'));
    return '<div class="mccf-integrity-row" title="' + escapeHtml((integrity.label || '') + ' — ' + score + '%') + '">'
      + '<div class="mccf-integrity-bar"><span class="' + barCls + '" style="width:' + score + '%;"></span></div>'
      + '<div class="mccf-integrity-value">' + score + '%</div>'
      + '<div class="mccf-integrity-label">' + escapeHtml(integrity.label || '') + '</div>'
      + '</div>';
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
      html += integrityBarHtml(integrity);
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
    if (event.key === 'Escape') closeModal();
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
})();
</script>
<?php

compliance_page_close();
cw_footer();
