<?php
declare(strict_types=1);

/**
 * Sync compliance.inbox.* automation events into automation_event_definitions.
 *
 * Why this script exists:
 *
 *   The automation rules builder reads its event catalog through
 *   automation_event_definition_rows() in src/automation_catalog.php, which:
 *     1. Queries automation_event_definitions JOIN automation_event_categories.
 *     2. If that returns any rows, those are canonical (DB wins).
 *     3. Otherwise it falls back to the in-code defaults.
 *
 *   We added three new compliance events in the catalog defaults:
 *     - compliance.inbox.email_received
 *     - compliance.inbox.email_sent
 *     - compliance.inbox.email_bounced
 *
 *   If your environment has the DB tables populated with the platform's
 *   existing catalog, the new events won't appear in the rules builder UI
 *   until they are seeded into the DB too. Running this script does that
 *   safely (insert-if-missing, never overwrite).
 *
 *   If your environment has NO automation_event_definitions table at all
 *   (i.e. the in-code defaults are canonical), the script no-ops cleanly.
 *
 * Usage (CLI, on the platform host, after `source /etc/ipca/ipca-courseware-cli.env`):
 *
 *   php scripts/sync_compliance_automation_events.php
 *
 * It is idempotent — re-running adds nothing if the rows already exist.
 */

require_once __DIR__ . '/../src/db.php';

$pdo = cw_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function table_exists(PDO $pdo, string $name): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute(array($name));

        return (bool)$st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

if (!table_exists($pdo, 'automation_event_definitions') || !table_exists($pdo, 'automation_event_categories')) {
    fwrite(STDOUT, "automation_event_definitions / categories table not present — nothing to sync.\n"
        . "The in-code defaults in src/automation_catalog.php already cover compliance.inbox.* events.\n");
    exit(0);
}

// 1. Ensure the 'compliance' category exists.
$st = $pdo->prepare("SELECT id FROM automation_event_categories WHERE category_key = ? LIMIT 1");
$st->execute(array('compliance'));
$categoryId = (int)$st->fetchColumn();
if ($categoryId === 0) {
    $pdo->prepare(
        "INSERT INTO automation_event_categories
            (category_key, label, description, sort_order, is_active)
         VALUES (?, ?, ?, ?, 1)"
    )->execute(array(
        'compliance',
        'Compliance',
        'Approvals, audit-sensitive actions, required reviews, and policy-linked workflow events.',
        40,
    ));
    $categoryId = (int)$pdo->lastInsertId();
    fwrite(STDOUT, "Inserted automation_event_categories.compliance (id={$categoryId}).\n");
} else {
    fwrite(STDOUT, "automation_event_categories.compliance already present (id={$categoryId}).\n");
}

// 2. Upsert each compliance.inbox.* event.
$events = array(
    array(
        'event_key' => 'compliance.inbox.email_received',
        'label' => 'Compliance Inbox — Email Received',
        'description' => 'Triggered when the Postmark inbound webhook delivers a new email to the compliance inbox.',
        'sort_order' => 110,
    ),
    array(
        'event_key' => 'compliance.inbox.email_sent',
        'label' => 'Compliance Inbox — Email Sent',
        'description' => 'Triggered when a compliance admin sends an outbound email (reply or new message) via Postmark.',
        'sort_order' => 111,
    ),
    array(
        'event_key' => 'compliance.inbox.email_bounced',
        'label' => 'Compliance Inbox — Email Bounced',
        'description' => 'Triggered when Postmark reports a hard/soft bounce or spam complaint on an outbound compliance email.',
        'sort_order' => 112,
    ),
);

$check = $pdo->prepare('SELECT id FROM automation_event_definitions WHERE event_key = ? LIMIT 1');
$insert = $pdo->prepare(
    'INSERT INTO automation_event_definitions
        (event_key, label, description, category_id, sort_order, is_active)
     VALUES (?, ?, ?, ?, ?, 1)'
);

$added = 0;
$skipped = 0;
foreach ($events as $e) {
    $check->execute(array($e['event_key']));
    if ((int)$check->fetchColumn() > 0) {
        $skipped++;
        fwrite(STDOUT, "  [skip ] {$e['event_key']} already exists.\n");
        continue;
    }
    $insert->execute(array(
        $e['event_key'],
        $e['label'],
        $e['description'],
        $categoryId,
        $e['sort_order'],
    ));
    $added++;
    fwrite(STDOUT, "  [added] {$e['event_key']}\n");
}

fwrite(STDOUT, "\nDone. Added {$added}, skipped {$skipped}. Reload the Automation Flows admin page to see the new events.\n");
