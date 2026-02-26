<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
cw_require_admin();
header('Content-Type: application/json; charset=utf-8');

function sc_get($pdo, $slideId, $lang){
  $stmt = $pdo->prepare("SELECT content_json, plain_text FROM slide_content WHERE slide_id=? AND lang=? LIMIT 1");
  $stmt->execute([$slideId,$lang]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function sc_upsert($pdo, $slideId, $lang, $contentJson, $plainText){
  $stmt = $pdo->prepare("
    INSERT INTO slide_content (slide_id, lang, content_json, plain_text)
    VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE content_json=VALUES(content_json), plain_text=VALUES(plain_text)
  ");
  $stmt->execute([$slideId,$lang,json_encode($contentJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$plainText]);
}

try {
  $slideId = (int)($_GET['slide_id'] ?? ($_POST['slide_id'] ?? 0));

  if (($_GET['mode'] ?? '') === 'get') {
    if ($slideId <= 0) throw new RuntimeException("Missing slide_id");
    $en = sc_get($pdo,$slideId,'en');
    $es = sc_get($pdo,$slideId,'es');
    echo json_encode(['ok'=>true, 'en_plain'=>$en['plain_text'] ?? '', 'es_plain'=>$es['plain_text'] ?? '']);
    exit;
  }

  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException("Invalid JSON");

  $slideId = (int)($data['slide_id'] ?? 0);
  if ($slideId <= 0) throw new RuntimeException("Missing slide_id");

  $action = (string)($data['action'] ?? '');

  // load slide image
  $stmt = $pdo->prepare("SELECT image_path FROM slides WHERE id=? LIMIT 1");
  $stmt->execute([$slideId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException("Slide not found");
  $imgUrl = cdn_url($CDN_BASE, (string)$row['image_path']);

  if ($action === 'save_manual') {
    $en = (string)($data['en_plain'] ?? '');
    $es = (string)($data['es_plain'] ?? '');
    if ($en !== '') sc_upsert($pdo,$slideId,'en',['blocks'=>[['type'=>'raw','text'=>$en]]],$en);
    if ($es !== '') sc_upsert($pdo,$slideId,'es',['blocks'=>[['type'=>'raw','text'=>$es]]],$es);
    echo json_encode(['ok'=>true]);
    exit;
  }

  if ($action === 'extract') {
    $schema = [
      "type"=>"object",
      "additionalProperties"=>false,
      "properties"=>[
        "title"=>["type"=>"string"],
        "blocks"=>[
          "type"=>"array",
          "items"=>[
            "type"=>"object",
            "additionalProperties"=>false,
            "properties"=>[
              "type"=>["type"=>"string","enum"=>["paragraph","bullets"]],
              "text"=>["type"=>"string"]
            ],
            "required"=>["type","text"]
          ]
        ]
      ],
      "required"=>["title","blocks"]
    ];

    $instructions = <<<TXT
Extract the instructional text from the slide screenshot.
IGNORE UI chrome:
- MAIN MENU / HOW TO USE THIS COURSE / SAVE & EXIT
- breadcrumb paths with "/"
- footer/copyright/page controls
Return:
- title
- blocks[] where each block is either:
  * paragraph (plain sentences)
  * bullets (each bullet on its own line starting with "• ")
TXT;

    $payload = [
      "model" => cw_openai_model(),
      "input" => [
        ["role"=>"system","content"=>[["type"=>"input_text","text"=>$instructions]]],
        ["role"=>"user","content"=>[
          ["type"=>"input_text","text"=>"Extract canonical content."],
          ["type"=>"input_image","image_url"=>$imgUrl]
        ]]
      ],
      "text" => [
        "format" => [
          "type"=>"json_schema",
          "name"=>"slide_content_v1",
          "schema"=>$schema,
          "strict"=>true
        ]
      ],
      "temperature"=>0.2
    ];

    $resp = cw_openai_responses($payload);
    $json = cw_openai_extract_json_text($resp);

    $plain = $json['title'] . "\n\n";
    foreach ($json['blocks'] as $b) {
      $plain .= trim($b['text']) . "\n\n";
    }
    $plain = trim($plain);

    sc_upsert($pdo,$slideId,'en',$json,$plain);

    echo json_encode(['ok'=>true,'plain_text'=>$plain]);
    exit;
  }

  if ($action === 'translate') {
    $en = sc_get($pdo,$slideId,'en');
    $src = trim((string)($en['plain_text'] ?? ''));
    if ($src === '') throw new RuntimeException("No English content yet. Run AI Extract (EN) first.");

    $schema = [
      "type"=>"object",
      "additionalProperties"=>false,
      "properties"=>[
        "translated"=>["type"=>"string"]
      ],
      "required"=>["translated"]
    ];

    $payload = [
      "model" => cw_openai_model(),
      "input" => [
        ["role"=>"system","content"=>[["type"=>"input_text","text"=>"Translate to Spanish. Keep aviation terms clear. Preserve bullet formatting."]]],
        ["role"=>"user","content"=>[["type"=>"input_text","text"=>$src]]]
      ],
      "text" => [
        "format" => [
          "type"=>"json_schema",
          "name"=>"slide_translate_v1",
          "schema"=>$schema,
          "strict"=>true
        ]
      ],
      "temperature"=>0.2
    ];

    $resp = cw_openai_responses($payload);
    $json = cw_openai_extract_json_text($resp);
    $plain = trim((string)($json['translated'] ?? ''));

    sc_upsert($pdo,$slideId,'es',['blocks'=>[['type'=>'raw','text'=>$plain]]],$plain);

    echo json_encode(['ok'=>true,'plain_text'=>$plain]);
    exit;
  }

  throw new RuntimeException("Unknown action");
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}