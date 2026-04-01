<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

function sc_upsert(PDO $pdo, int $slideId, string $lang, array $contentJson, string $plainText): void {
    $stmt = $pdo->prepare("
      INSERT INTO slide_content (slide_id, lang, content_json, plain_text)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE content_json=VALUES(content_json), plain_text=VALUES(plain_text)
    ");
    $stmt->execute([
        $slideId,
        $lang,
        json_encode($contentJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $plainText
    ]);
}

try {
    // -------------------------
    // GET: return EN/ES for editor
    // -------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $slideId = (int)($_GET['slide_id'] ?? 0);
        if ($slideId <= 0) throw new RuntimeException('Missing slide_id');

        $stmt = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
        $stmt->execute([$slideId]);
        $en = (string)($stmt->fetchColumn() ?: '');

        $stmt = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='es' LIMIT 1");
        $stmt->execute([$slideId]);
        $es = (string)($stmt->fetchColumn() ?: '');

        echo json_encode(['ok'=>true, 'en_plain'=>$en, 'es_plain'=>$es]);
        exit;
    }

    // -------------------------
    // POST: extract/translate/save
    // -------------------------
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Invalid JSON body');

    $slideId = (int)($data['slide_id'] ?? 0);
    if ($slideId <= 0) throw new RuntimeException('Missing slide_id');

    $action = (string)($data['action'] ?? '');
    $lang   = (string)($data['lang'] ?? 'en');

    // Manual save
    if ($action === 'save_manual') {
        $en = (string)($data['en_plain'] ?? '');
        $es = (string)($data['es_plain'] ?? '');

        if ($en !== '') sc_upsert($pdo, $slideId, 'en', ['blocks'=>[['type'=>'raw','text'=>$en]]], $en);
        if ($es !== '') sc_upsert($pdo, $slideId, 'es', ['blocks'=>[['type'=>'raw','text'=>$es]]], $es);

        echo json_encode(['ok'=>true]);
        exit;
    }

    // Extract EN (vision)
    if ($action === 'extract' && $lang === 'en') {
        $stmt = $pdo->prepare("SELECT image_path FROM slides WHERE id=? LIMIT 1");
        $stmt->execute([$slideId]);
        $imgPath = (string)$stmt->fetchColumn();
        if ($imgPath === '') throw new RuntimeException('Slide image_path missing');

        $imgUrl = cdn_url($CDN_BASE, $imgPath);

        $schema = [
            "type"=>"object",
            "additionalProperties"=>false,
            "properties"=>["english_text"=>["type"=>"string"]],
            "required"=>["english_text"]
        ];

        $instructions = "Extract ONLY instructional slide content in English. Ignore UI chrome. Preserve bullets and line breaks.";

        $payload = [
            "model" => cw_openai_model(),
            "input" => [
                ["role"=>"system","content"=>[["type"=>"input_text","text"=>$instructions]]],
                ["role"=>"user","content"=>[
                    ["type"=>"input_text","text"=>"Extract English slide text."],
                    ["type"=>"input_image","image_url"=>$imgUrl]
                ]]
            ],
            "text" => [
                "format" => [
                    "type"=>"json_schema",
                    "name"=>"extract_en_v1",
                    "schema"=>$schema,
                    "strict"=>true
                ]
            ],
            "temperature"=>0.2
        ];

        $resp = cw_openai_responses($payload);
        $j = cw_openai_extract_json_text($resp);
        $plain = trim((string)($j['english_text'] ?? ''));

        sc_upsert($pdo, $slideId, 'en', ['blocks'=>[['type'=>'raw','text'=>$plain]]], $plain);

        echo json_encode(['ok'=>true,'plain_text'=>$plain]);
        exit;
    }

    // Translate ES from existing EN
    if ($action === 'translate' && $lang === 'es') {
        $stmt = $pdo->prepare("SELECT plain_text FROM slide_content WHERE slide_id=? AND lang='en' LIMIT 1");
        $stmt->execute([$slideId]);
        $en = trim((string)($stmt->fetchColumn() ?: ''));
        if ($en === '') throw new RuntimeException('No EN content found to translate');

        $schemaT = [
            "type"=>"object",
            "additionalProperties"=>false,
            "properties"=>["spanish_text"=>["type"=>"string"]],
            "required"=>["spanish_text"]
        ];

        $payloadT = [
            "model" => cw_openai_model(),
            "input" => [
                ["role"=>"system","content"=>[["type"=>"input_text","text"=>"Translate to Spanish. Preserve bullets and line breaks."]]],
                ["role"=>"user","content"=>[["type"=>"input_text","text"=>$en]]]
            ],
            "text" => [
                "format" => [
                    "type"=>"json_schema",
                    "name"=>"translate_es_v1",
                    "schema"=>$schemaT,
                    "strict"=>true
                ]
            ],
            "temperature"=>0.2
        ];

        $respT = cw_openai_responses($payloadT);
        $jT = cw_openai_extract_json_text($respT);
        $es = trim((string)($jT['spanish_text'] ?? ''));

        sc_upsert($pdo, $slideId, 'es', ['blocks'=>[['type'=>'raw','text'=>$es]]], $es);

        echo json_encode(['ok'=>true,'plain_text'=>$es]);
        exit;
    }

    throw new RuntimeException('Unknown action');

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}