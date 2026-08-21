<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents(
    $root . '/src/publishing/ControlledPublishingDocxImportService.php'
);
$api = (string)file_get_contents(
    $root . '/public/admin/api/controlled_book_docx_import_api.php'
);
$page = (string)file_get_contents(
    $root . '/public/admin/compliance/controlled_book_docx_import.php'
);

$requirements = array(
    'service accepts a progress callback' => str_contains($service, '?callable $progress = null'),
    'service reports parsing progress' => str_contains($service, "'parsing'"),
    'service reports content-write progress' => str_contains($service, "'importing'"),
    'service reports finalization' => str_contains($service, "'finalizing'"),
    'API exposes authenticated status polling' => str_contains($api, "\$action === 'import_status'"),
    'API stores progress atomically' => str_contains($api, 'cp_docx_import_write_status')
        && str_contains($api, 'rename($temporary, $path)'),
    'API continues after a client disconnect' => str_contains($api, 'ignore_user_abort(true)'),
    'API releases the session during polling' => str_contains($api, 'session_write_close()'),
    'API records the real server exception' => str_contains($api, "'error_type'"),
    'API explains upload-limit failures' => str_contains(
        $api,
        'exceeds the server upload-size limit'
    ),
    'page renders an accessible progress bar' => str_contains($page, 'role="progressbar"')
        && str_contains($page, 'aria-valuenow'),
    'page reports upload progress' => str_contains($page, "xhr.upload.addEventListener('progress'"),
    'page polls server-side import status' => str_contains($page, 'function pollImportStatus(token)'),
    'page recovers from connection interruption' => str_contains(
        $page,
        'The connection was interrupted. The server is still being checked'
    ),
    'page shows detailed failures' => str_contains($page, 'function failImport(token, message, detail)'),
);

foreach ($requirements as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

echo "Controlled publishing DOCX import progress: PASS\n";
