#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/scripts/sql/2026_08_09_cvr_operational_session_leg_reviews.sql');
$service = file_get_contents($root . '/src/CvrOperationalSessionLegReviewService.php');
$splitService = file_get_contents($root . '/src/CvrAdminLegSplitService.php');
$endpoint = file_get_contents($root . '/public/api/cvr/operational_session_leg_review.php');
$api = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/APIClient.swift');
$views = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Views/OperationalWorkflowViews.swift');
$store = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/CVRWorkflowStore.swift');
$uploads = file_get_contents($root . '/ipca-cvr-unit/IPCACVRUnit/Services/UploadManager.swift');

$checks = [
    'migration stores append-only immutable review revisions' =>
        str_contains($migration, 'ipca_operational_session_leg_reviews')
        && str_contains($migration, 'UNIQUE KEY uk_ipca_session_leg_review_uuid')
        && str_contains($migration, 'supersedes_revision_uuid'),
    'device endpoint supports preview and acceptance' =>
        str_contains($endpoint, "if (\$method === 'GET')")
        && str_contains($endpoint, "if (\$method === 'POST')")
        && str_contains($endpoint, 'requireDevice()'),
    'preview normalizes derived leg index for the iOS contract' =>
        str_contains($service, "\$leg['sequence_number']")
        && str_contains($service, "\$leg['leg_index']"),
    'empty derivation supports manual first-leg creation' =>
        str_contains($views, '} else {')
        && str_contains($views, 'sequenceNumber: 1')
        && str_contains($views, 'startingHobbs: preview?.startingHobbs ?? 0')
        && str_contains($api, 'case offBlockUTC = "off_block_utc"'),
    'review is constrained to the authenticated Operational Session Dispatch' =>
        str_contains($service, 'AND device_id = ?')
        && str_contains($service, 'operational_session_uuid IS NOT NULL'),
    'acceptance requires immutable Garmin provenance' =>
        str_contains($service, 'A verified Garmin CSV must be linked')
        && str_contains($service, 'hash_equals($evidenceHash, $submittedHash)')
        && str_contains($service, "'evidence_sha256' => \$evidenceHash"),
    'retry is idempotent and UUID conflicts are rejected' =>
        str_contains($service, 'reviewByUuid($revisionUuid)')
        && str_contains($service, "'already_present' => true")
        && str_contains($service, 'Leg-review revision UUID conflict.'),
    'meter evidence boundaries and continuity are enforced' =>
        str_contains($service, 'assertEvidenceBounds')
        && str_contains($service, 'Verified leg meter boundaries must form a continuous chain.'),
    'fuel and operation totals are enforced server-side' =>
        str_contains($service, "'fuel_onboard' => \$this->nullableFloat")
        && str_contains($service, 'Verified leg fuel consumption must match the Check-In total.')
        && str_contains($service, 'Verified leg landings must match the Check-In total.'),
    'leg review uses effective Log adjustments and operation event fallback' =>
        str_contains($splitService, 'ipca_cvr_flight_log_adjustments')
        && str_contains($splitService, 'manual_takeoff_adjustment')
        && str_contains($splitService, 'manual_landing_adjustment')
        && str_contains($service, 'latestFlightLogAdjustment')
        && str_contains($service, 'operationEventCounts')
        && str_contains($views, 'takeoffEvents')
        && str_contains($views, '!response.review.proposedLegs.isEmpty'),
    'GPS evidence outranks the informative planned route' =>
        str_contains($splitService, 'detectCsvEndpoints')
        && str_contains($splitService, '$observedDeparture')
        && str_contains($splitService, '$observedArrival')
        && str_contains($splitService, '$observations')
        && str_contains($splitService, 'usort($observations')
        && strpos($splitService, '$observedDeparture') < strpos($splitService, 'if (count($planned) >= 1)'),
    'late server enrichment never overwrites active leg edits' =>
        str_contains($views, 'let legsBeforeServerRefresh = legs')
        && str_contains($views, 'if legs == legsBeforeServerRefresh'),
    'accepted revision projects current legs into Master Logbook source' =>
        str_contains($service, "payload['leg_segments']")
        && str_contains($service, 'MasterLogbookLogbookProposalService'),
    'iOS review allows corrections before explicit acceptance' =>
        str_contains($api, 'func operationalLegReview(')
        && str_contains($api, 'func acceptOperationalLegReview(')
        && str_contains($views, 'title: "CONTINUE TO LEG VERIFICATION"')
        && str_contains($views, 'ACCEPT LEGS'),
    'leg review acceptance is durable and nonblocking without internet' =>
        str_contains($views, 'LOCAL CHECK-IN LOADED')
        && str_contains($views, 'seedFirstLegFromLocalCheckInIfNeeded')
        && str_contains($views, 'acceptOperationalLegReviewLocally')
        && str_contains($views, 'server sync follows later')
        && str_contains($store, 'componentType: "operational_leg_review"')
        && str_contains($store, 'locallyAcceptedLegReviewDispatchUUIDs')
        && str_contains($uploads, 'uploadQueuedOperationalLegReviewComponent')
        && str_contains($uploads, 'Legs are verified locally · server synchronization waits for Check-In.'),
    'iOS editor supports full operational correction workflow' =>
        str_contains($views, 'ALL DATES AND TIMES ARE CALIFORNIA LOCAL TIME')
        && str_contains($views, 'Label("UNDO", systemImage: "arrow.uturn.backward")')
        && str_contains($views, 'title: "ADD LEG"')
        && str_contains($views, 'Label("DELETE LEG"')
        && str_contains($views, '"FUEL ONBOARD"')
        && str_contains($views, '"FUEL REMAINING"'),
    'review displays crew and cumulative leg metrics' =>
        str_contains($views, 'CREW · ALL LEGS')
        && str_contains($views, 'BLOCK CUMUL')
        && str_contains($views, 'TACHO CUMUL')
        && str_contains($views, 'fuelValue(cumulativeFuel')
        && str_contains($views, 'title: "LANDINGS"'),
    'Log preserves review status and Admin revision access' =>
        str_contains($service, 'statusForDevice')
        && str_contains($endpoint, "status_only")
        && str_contains($api, 'operationalLegReviewStatus')
        && str_contains($views, 'verified ? "LEGS VERIFIED" : "VERIFY LEGS"')
        && str_contains($views, 'guard !verified || adminUnlocked else { return }'),
    'iOS blocks acceptance on amber total warnings' =>
        str_contains($views, 'Leg Hobbs total')
        && str_contains($views, 'Leg Tacho total')
        && str_contains($views, 'Leg landings total')
        && str_contains($views, 'Leg fuel consumed')
        && str_contains($views, '!validationWarnings.isEmpty'),
    'card return remains blocked until accepted review receipt' =>
        str_contains($store, 'markPostFlightLegReviewAccepted')
        && str_contains($store, 'handoff.phase = .returnCardToGarmin'),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Operational Session leg review contract FAILED\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Operational Session leg review contract passed (" . count($checks) . " checks).\n";
