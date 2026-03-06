<?php
require_once __DIR__ . '/../../../src/bootstrap.php';

cw_require_login();
header('Content-Type: application/json; charset=utf-8');

function json_out($x){
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function env_required($k){
    $v = getenv($k);
    if(!$v) throw new RuntimeException("Missing env var: ".$k);
    return $v;
}

$u = cw_current_user($pdo);
$role = (string)($u['role'] ?? '');

if ($role !== 'student' && $role !== 'admin') {
    http_response_code(403);
    json_out(['ok'=>false,'error'=>'Forbidden']);
}

$testId = (int)($_POST['test_id'] ?? 0);
$idx    = (int)($_POST['idx'] ?? 0);
$timeout = (int)($_POST['timeout'] ?? 0);

if($testId <= 0 || $idx <= 0){
    json_out(['ok'=>false,'error'=>'Missing test_id or idx']);
}

$userId = (int)$u['id'];

if ($role === 'student') {
    $own = $pdo->prepare("
        SELECT 1
        FROM progress_tests_v2
        WHERE id=? AND user_id=?
        LIMIT 1
    ");
    $own->execute([$testId,$userId]);

    if(!$own->fetchColumn()){
        http_response_code(403);
        json_out(['ok'=>false,'error'=>'Forbidden']);
    }
}

/* ----------------------------
   Locate item
---------------------------- */

$itemSt = $pdo->prepare("
SELECT id
FROM progress_test_items_v2
WHERE test_id=? AND idx=?
LIMIT 1
");

$itemSt->execute([$testId,$idx]);
$item = $itemSt->fetch(PDO::FETCH_ASSOC);

if(!$item){
    json_out(['ok'=>false,'error'=>'Question item not found']);
}

$itemId = (int)$item['id'];

/* ----------------------------
   Handle timeout
---------------------------- */

if($timeout === 1){

    $pdo->prepare("
    UPDATE progress_test_items_v2
    SET transcript_text='[TIMEOUT]',
        audio_path=NULL,
        updated_at=NOW()
    WHERE id=?
    ")->execute([$itemId]);

    json_out([
        'ok'=>true,
        'test_id'=>$testId,
        'idx'=>$idx,
        'timeout'=>true
    ]);
}

/* ----------------------------
   Validate upload
---------------------------- */

if(empty($_FILES['audio'])){
    json_out(['ok'=>false,'error'=>'Missing audio file']);
}

$tmp = $_FILES['audio']['tmp_name'];
$err = (int)($_FILES['audio']['error'] ?? UPLOAD_ERR_OK);

if($err !== UPLOAD_ERR_OK){
    json_out(['ok'=>false,'error'=>'Upload error '.$err]);
}

if(!is_uploaded_file($tmp)){
    json_out(['ok'=>false,'error'=>'Invalid uploaded file']);
}

/* ----------------------------
   Spaces configuration
---------------------------- */

$spacesKey      = env_required('SPACES_KEY');
$spacesSecret   = env_required('SPACES_SECRET');
$spacesBucket   = env_required('SPACES_BUCKET');
$spacesRegion   = env_required('SPACES_REGION');
$spacesEndpoint = env_required('SPACES_ENDPOINT');
$spacesCdn      = env_required('SPACES_CDN');

/* ----------------------------
   Build Spaces key
---------------------------- */

$spacesKeyPath =
"progress_tests_v2/"
.$testId
."/answers/q"
.str_pad($idx,2,'0',STR_PAD_LEFT)
.".webm";

/* ----------------------------
   Upload to Spaces
---------------------------- */

$url = "https://".$spacesBucket.".".$spacesEndpoint."/".$spacesKeyPath;

$fp = fopen($tmp,'rb');

$ch = curl_init($url);

curl_setopt_array($ch,[
    CURLOPT_PUT => true,
    CURLOPT_INFILE => $fp,
    CURLOPT_INFILESIZE => filesize($tmp),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'x-amz-acl: public-read',
        'Content-Type: audio/webm'
    ],
    CURLOPT_USERPWD => $spacesKey.':'.$spacesSecret
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
$err  = curl_error($ch);

curl_close($ch);
fclose($fp);

if($code < 200 || $code >= 300){
    json_out([
        'ok'=>false,
        'error'=>'Spaces upload failed',
        'code'=>$code,
        'curl'=>$err
    ]);
}

/* ----------------------------
   Save DB path
---------------------------- */

$pdo->prepare("
UPDATE progress_test_items_v2
SET audio_path=?,
    transcript_text=NULL,
    updated_at=NOW()
WHERE id=?
")->execute([$spacesKeyPath,$itemId]);

json_out([
    'ok'=>true,
    'test_id'=>$testId,
    'idx'=>$idx,
    'audio_path'=>$spacesCdn.'/'.$spacesKeyPath
]);