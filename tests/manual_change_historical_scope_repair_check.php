<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/publishing/BooksManualsChangePlanService.php';
require_once $root . '/src/publishing/BooksManualsChangeReviewResolutionService.php';

function historicalScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$service = new BooksManualsChangeReviewResolutionService(new PDO('sqlite::memory:'));
$build = new ReflectionMethod($service, 'buildHistoricalScopeRepairCandidate');
$accepted = array(
    'wizard_status' => 'accepted',
    'section_drafts' => array(
        '3.3' => array('nodes' => array('3.3.2' => "Accepted duty wording.\n")),
        '5.6' => array(
            'nodes' => array(
                '5.6.1' => 'Accepted reporting principles.',
                '5.6.3' => 'Unresolved reporting control remains untouched.',
                '5.6.4' => 'Accepted initial notification wording.',
                '5.6.7' => 'Current allowed repair wording remains untouched.',
            ),
        ),
        '5.7' => array(
            'nodes' => array(
                '5.7' => 'Accepted monitoring wording.',
                '5.7.3' => 'Accepted monitoring process.',
            ),
        ),
        '8.1' => array('nodes' => array('8.1' => 'Current training repair wording remains untouched.')),
    ),
);
$current = $accepted;
foreach (array(
    '3.3.2' => 'Patch rewrite duty wording.',
    '5.6.1' => 'Patch rewrite reporting principles.',
    '5.6.4' => 'Patch rewrite initial notification.',
    '5.7' => 'Patch rewrite monitoring wording.',
    '5.7.3' => 'Patch rewrite monitoring process.',
) as $node => $replacement) {
    foreach ($current['section_drafts'] as &$draft) {
        if (array_key_exists($node, $draft['nodes'])) {
            $draft['nodes'][$node] = $replacement;
        }
    }
    unset($draft);
}
$restoreNodes = array('3.3.2', '5.6.1', '5.6.4', '5.7', '5.7.3');
$repair = $build->invoke($service, $accepted, $current, $restoreNodes);
$candidate = (array)$repair['candidate_proposal'];

foreach ($restoreNodes as $node) {
    $acceptedText = null;
    $candidateText = null;
    foreach ($accepted['section_drafts'] as $draft) {
        if (array_key_exists($node, $draft['nodes'])) {
            $acceptedText = $draft['nodes'][$node];
        }
    }
    foreach ($candidate['section_drafts'] as $draft) {
        if (array_key_exists($node, $draft['nodes'])) {
            $candidateText = $draft['nodes'][$node];
        }
    }
    historicalScopeAssert(
        $candidateText === $acceptedText,
        "Node {$node} was not restored byte-for-byte."
    );
}
historicalScopeAssert(
    $candidate['section_drafts']['5.6']['nodes']['5.6.3']
        === $current['section_drafts']['5.6']['nodes']['5.6.3'],
    'An unresolved ECCAIRS repair node changed during historical restoration.'
);
historicalScopeAssert(
    $candidate['section_drafts']['5.6']['nodes']['5.6.7']
        === $current['section_drafts']['5.6']['nodes']['5.6.7'],
    'The allowed ECCAIRS repair node changed during historical restoration.'
);
historicalScopeAssert(
    $candidate['section_drafts']['8.1']['nodes']['8.1']
        === $current['section_drafts']['8.1']['nodes']['8.1'],
    'The allowed training repair node changed during historical restoration.'
);
historicalScopeAssert(
    count((array)$repair['restored_node_fingerprints']) === 5,
    'Restored node fingerprints are incomplete.'
);
historicalScopeAssert(
    isset($repair['frozen_node_fingerprints']['5.6.3'])
        && isset($repair['frozen_node_fingerprints']['5.6.7'])
        && isset($repair['frozen_node_fingerprints']['8.1']),
    'Unchanged semantic repair nodes were not frozen.'
);

$resolutionSource = file_get_contents(
    $root . '/src/publishing/BooksManualsChangeReviewResolutionService.php'
) ?: '';
$authorSource = file_get_contents(
    $root . '/src/publishing/BooksManualsChangeAuthorService.php'
) ?: '';
$pageSource = file_get_contents(
    $root . '/public/admin/books_manuals/change_architect.php'
) ?: '';
$repairScript = file_get_contents(
    $root . '/scripts/repair_manual_change_historical_scope.php'
) ?: '';
historicalScopeAssert(
    str_contains($resolutionSource, 'HISTORICAL_SCOPE_REPAIR')
        && str_contains($resolutionSource, 'REVIEW_DIVERGENCE_RESOLVED')
        && str_contains(
            $resolutionSource,
            'INDEPENDENT_REVIEW_HISTORICAL_SCOPE_REPAIR_VERIFIED'
        ),
    'Governed historical scope repair provenance is incomplete.'
);
historicalScopeAssert(
    str_contains($repairScript, "array('3.3.2', '5.6.1', '5.6.4', '5.7', '5.7.3')")
        && str_contains(
            $resolutionSource,
            'explicitly governed repair set and failed-patch provenance'
        ),
    'The exact Plan 20 historical restoration node set is not enforced.'
);
historicalScopeAssert(
    str_contains($authorSource, 'current_accepted_wording')
        && str_contains($authorSource, 'target_state_evidence')
        && str_contains($authorSource, 'preservation_boundaries')
        && str_contains($authorSource, 'FROZEN UNAFFECTED NODE FINGERPRINTS'),
    'Node-scoped Author context is incomplete.'
);
historicalScopeAssert(
    str_contains($pageSource, 'Historical Scope Repair')
        && str_contains($pageSource, 'Content Correction')
        && str_contains($pageSource, 'Proposed minimal correction')
        && str_contains($pageSource, 'Check(s) expected to resolve'),
    'Step 5 does not distinguish deterministic restoration from content correction.'
);

echo "PASS: historical scope restoration is exact, governed, and node-frozen.\n";
