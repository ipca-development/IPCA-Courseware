<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_out(array $x): void {
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(string $s): array {
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
}

function presign_spaces_put_via_internal_endpoint(string $cookieHeader, array $payload): array {
    $url = 'http://127.0.0.1/student/api/progress_test_spaces_presign.php';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/json',
            $cookieHeader !== '' ? ('Cookie: ' . $cookieHeader) : null
        ]),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60
    ]);

    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($out === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("Presign request failed (HTTP {$code}) {$err} " . substr((string)$out, 0, 300));
    }

    $j = read_json((string)$out);
    if (empty($j['ok']) || empty($j['url']) || empty($j['public_url']) || empty($j['key'])) {
        throw new RuntimeException('Invalid presign response');
    }

    return $j;
}

function upload_file_to_presigned_put(string $putUrl, string $localFile, string $contentType): void {
    if (!is_file($localFile)) {
        throw new RuntimeException('Local file not found for upload: ' . $localFile);
    }

    $fh = fopen($localFile, 'rb');
    if (!$fh) {
        throw new RuntimeException('Cannot open local file for upload: ' . $localFile);
    }

    $size = filesize($localFile);
    $ch = curl_init($putUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fh,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $size,
            'x-amz-acl: public-read'
        ],
        CURLOPT_TIMEOUT => 300
    ]);

    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if ($out === false || $code < 200 || $code >= 299) {
        throw new RuntimeException("Spaces upload failed (HTTP {$code}) {$err} " . substr((string)$out, 0, 300));
    }
}

try {
    $u = cw_current_user($pdo);
    $role = (string)($u['role'] ?? '');

    if ($role !== 'student' && $role !== 'admin') {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'Forbidden']);
    }

    $testId  = (int)($_POST['test_id'] ?? 0);
    $idx     = (int)($_POST['idx'] ?? 0);
    $timeout = (int)($_POST['timeout'] ?? 0);

    if ($testId <= 0 || $idx <= 0) {
        json_out(['ok' => false, 'error' => 'Missing test_id or idx']);
    }

    $userId = (int)($u['id'] ?? 0);

    if ($role === 'student') {
        $own = $pdo->prepare("
            SELECT 1
            FROM progress_tests_v2
            WHERE id=? AND user_id=?
            LIMIT 1
        ");
        $own->execute([$testId, $userId]);

        if (!$own->fetchColumn()) {
            http_response_code(403);
            json_out(['ok' => false, 'error' => 'Forbidden']);
        }
    }

    $itemSt = $pdo->prepare("
        SELECT id, idx
        FROM progress_test_items_v2
        WHERE test_id=? AND idx=?
        LIMIT 1
    ");
    $itemSt->execute([$testId, $idx]);
    $item = $itemSt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        json_out(['ok' => false, 'error' => 'Question item not found']);
    }

    $itemId = (int)$item['id'];

    if ($timeout === 1) {
        $upd = $pdo->prepare("
            UPDATE progress_test_items_v2
            SET transcript_text='[TIMEOUT]',
                audio_path=NULL,
                updated_at=NOW()
            WHERE id=?
        ");
        $upd->execute([$itemId]);

        json_out([
            'ok' => true,
            'test_id' => $testId,
            'idx' => $idx,
            'timeout' => true
        ]);
    }

    if (empty($_FILES['audio']) || !isset($_FILES['audio']['tmp_name'])) {
        json_out(['ok' => false, 'error' => 'Missing audio upload']);
    }

    $tmp = $_FILES['audio']['tmp_name'];
    $err = (int)($_FILES['audio']['error'] ?? UPLOAD_ERR_OK);

    if ($err !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => 'Upload failed with error code ' . $err]);
    }

    if (!is_uploaded_file($tmp)) {
        json_out(['ok' => false, 'error' => 'Invalid uploaded file']);
    }

    $cookieHeader = '';
    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $cookieHeader = (string)$_SERVER['HTTP_COOKIE'];
    }

    $presign = presign_spaces_put_via_internal_endpoint($cookieHeader, [
        'test_id' => $testId,
        'kind'    => 'answer',
        'idx'     => $idx,
        'ext'     => 'webm'
    ]);

    upload_file_to_presigned_put((string)$presign['url'], $tmp, 'audio/webm');

    $spacesKeyPath = (string)$presign['key'];
    $publicUrl = (string)$presign['public_url'];

    $upd = $pdo->prepare("
        UPDATE progress_test_items_v2
        SET audio_path=?,
            transcript_text=NULL,
            updated_at=NOW()
        WHERE id=?
    ");
    $upd->execute([$spacesKeyPath, $itemId]);

    json_out([
        'ok' => true,
        'test_id' => $testId,
        'idx' => $idx,
        'audio_path' => $publicUrl
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    json_out([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}