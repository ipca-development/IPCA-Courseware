<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/publishing/ControlledPublishingManualCode.php';
require_once dirname(__DIR__) . '/src/publishing/BooksManualsWorkflowService.php';

function identity_settings_assert(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

identity_settings_assert(
    'manual code accepts spaces while keeping a safe internal identity',
    ControlledPublishingManualCode::normalizeIdentity('TM PPL') === 'TM_PPL'
);
identity_settings_assert(
    'stored underscore codes display naturally',
    ControlledPublishingManualCode::display('TM_GEN') === 'TM GEN'
);
identity_settings_assert(
    'editable display codes normalize without changing machine identity',
    ControlledPublishingManualCode::normalizeDisplay('tm ppl-easa') === 'TM PPL-EASA'
);
$libraryPage = (string)file_get_contents(
    dirname(__DIR__) . '/public/admin/books_manuals/index.php'
);
$controlledBooksPage = (string)file_get_contents(
    dirname(__DIR__) . '/public/admin/compliance/controlled_books.php'
);
identity_settings_assert(
    'Manual Settings exposes the editable Manual / Book Name',
    str_contains($libraryPage, 'name="book_title"')
        && str_contains($libraryPage, 'Manual / Book Name')
);
identity_settings_assert(
    'Manual Settings exposes an editable reader-facing code',
    str_contains($libraryPage, 'name="manual_code"')
        && str_contains($libraryPage, 'The internal book identity and existing links remain unchanged.')
);
identity_settings_assert(
    'manual creation accepts readable spaced codes',
    str_contains($libraryPage, 'pattern="[A-Za-z0-9][A-Za-z0-9 _-]{1,31}"')
        && str_contains($libraryPage, 'Spaces are allowed and handled automatically.')
        && str_contains(
            $controlledBooksPage,
            'pattern="[A-Za-z0-9][A-Za-z0-9 _-]{1,31}"'
        )
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE ipca_publishing_books (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        manual_code TEXT NOT NULL,
        display_manual_code TEXT NULL,
        updated_at TEXT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE ipca_publishing_book_versions (
        id INTEGER PRIMARY KEY,
        book_id INTEGER NOT NULL,
        lifecycle_status TEXT NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE ipca_publishing_lifecycle_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        book_version_id INTEGER NOT NULL,
        from_status TEXT NOT NULL,
        to_status TEXT NOT NULL,
        action_key TEXT NOT NULL,
        note TEXT NULL,
        actor_user_id INTEGER NULL
    )'
);
$pdo->exec(
    'CREATE TABLE ipca_publishing_version_workflow (
        book_version_id INTEGER PRIMARY KEY,
        last_transition_at TEXT NULL,
        last_transition_by INTEGER NULL
    )'
);
$pdo->exec(
    "INSERT INTO ipca_publishing_books (id, title, manual_code)
     VALUES (5, 'Incorrect Name', 'TM_PPL')"
);
$workflow = new BooksManualsWorkflowService($pdo);
$workflow->renameBook(5, 'Training Manual General', 42);
$workflow->renameManualCode(5, 'PPL EASA', 42);

identity_settings_assert(
    'Manual Settings renames the shared IPCA Library book',
    $pdo->query('SELECT title FROM ipca_publishing_books WHERE id = 5')->fetchColumn()
        === 'Training Manual General'
);
identity_settings_assert(
    'Manual Settings changes only the reader-facing code',
    $pdo->query(
        'SELECT manual_code || "|" || display_manual_code
           FROM ipca_publishing_books
          WHERE id = 5'
    )->fetchColumn() === 'TM_PPL|PPL EASA'
);
$workflowSource = (string)file_get_contents(
    dirname(__DIR__) . '/src/publishing/BooksManualsWorkflowService.php'
);
identity_settings_assert(
    'book rename records an audited same-lifecycle event when a version exists',
    str_contains($workflowSource, "'rename_book'")
        && str_contains($workflowSource, "'rename_manual_code'")
        && str_contains($workflowSource, "'book_key_unchanged' => true")
        && str_contains($workflowSource, '$status,')
);

echo "Books & Manuals identity settings: PASS\n";
