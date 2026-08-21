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
    "INSERT INTO ipca_publishing_books (id, title) VALUES (5, 'Incorrect Name')"
);
(new BooksManualsWorkflowService($pdo))->renameBook(5, 'Training Manual General', 42);

identity_settings_assert(
    'Manual Settings renames the shared IPCA Library book',
    $pdo->query('SELECT title FROM ipca_publishing_books WHERE id = 5')->fetchColumn()
        === 'Training Manual General'
);
$workflowSource = (string)file_get_contents(
    dirname(__DIR__) . '/src/publishing/BooksManualsWorkflowService.php'
);
identity_settings_assert(
    'book rename records an audited same-lifecycle event when a version exists',
    str_contains($workflowSource, "'rename_book'")
        && str_contains($workflowSource, '$status,')
);

echo "Books & Manuals identity settings: PASS\n";
