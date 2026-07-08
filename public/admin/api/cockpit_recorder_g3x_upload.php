<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/CockpitRecorderService.php';
require_once __DIR__ . '/../../../src/GarminCsvImportProfile.php';

cw_require_admin();

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

function cockpit_g3x_upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded CSV is larger than the server upload limit.',
        UPLOAD_ERR_PARTIAL => 'The uploaded CSV was only partially received.',
        UPLOAD_ERR_NO_FILE => 'No Garmin CSV file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload temp directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded CSV.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        default => 'Unknown upload error.',
    };
}

function cockpit_g3x_upload_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$id = trim((string)($_POST['id'] ?? $_GET['id'] ?? ''));

try {
    if ($id === '') {
        throw new RuntimeException('Recording id is required.');
    }

    $upload = is_array($_FILES['g3x_csv'] ?? null) ? $_FILES['g3x_csv'] : array();
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(cockpit_g3x_upload_error_text($uploadError));
    }

    $tmpName = (string)($upload['tmp_name'] ?? '');
    if ($tmpName === '' || (!is_uploaded_file($tmpName) && !is_file($tmpName))) {
        throw new RuntimeException('Uploaded Garmin CSV is missing.');
    }

    $service = new CockpitRecorderService($pdo);
    $recording = $service->recordingByAnyId($id);
    if (!$recording) {
        throw new RuntimeException('Recording not found.');
    }

    $importProfile = GarminCsvImportProfile::normalize((string)($_POST['import_profile'] ?? ''));
    $service->storeSupplementalG3X((string)$recording['recording_uid'], $tmpName, $importProfile);

    cockpit_g3x_upload_redirect('/admin/cockpit_recorder.php?g3x_upload=attached&id=' . urlencode((string)$recording['id']));
} catch (Throwable $e) {
    $target = '/admin/cockpit_recorder.php?error=' . urlencode($e->getMessage());
    if ($id !== '') {
        $target .= '&id=' . urlencode($id);
    }
    cockpit_g3x_upload_redirect($target);
}
