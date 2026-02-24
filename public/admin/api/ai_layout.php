<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/openai.php';
cw_require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    $slideId = (int)($_POST['slide_id'] ?? 0);
    if ($slideId <= 0) throw new RuntimeException("Missing slide_id");

    $stmt = $pdo->prepare("SELECT * FROM slides WHERE id=? LIMIT 1");
    $stmt->execute([$slideId]);
    $slide = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slide) throw new RuntimeException("Slide not found");

    $imgUrl = cdn_url($CDN_BASE, (string)$slide['image_path']);

    // JSON schema (strict)
    $schema = [
        "type" => "object",
        "additionalProperties" => false,
        "properties" => [
            "title" => ["type" => "string"],
            "items" => [
                "type" => "array",
                "items" => [
                    "type" => "object",
                    "additionalProperties" => false,
                    "properties" => [
                        "kind" => ["type" => "string", "enum" => ["redact","title","text","bullets","image","video"]],
                        "x" => ["type" => "number"],
                        "y" => ["type" => "number"],
                        "w" => ["type" => "number"],
                        "h" => ["type" => "number"],
                        "text" => ["type" => "string"],
                        "confidence" => ["type" => "number"]
                    ],
                    "required" => ["kind","x","y","w","h","confidence"]
                ]
            ]
        ],
        "required" => ["title","items"]
    ];

    $instructions = <<<TXT
You are laying out an aviation training slide on a fixed 1600x900 canvas.

Return ALL real instructional content with approximate bounding boxes.

IMPORTANT: DO NOT include UI chrome text:
- MAIN MENU, HOW TO USE THIS COURSE, SAVE & EXIT
- breadcrumb path lines with "/" separators
- footer/copyright/page controls

Instead, mark those UI areas as redaction rectangles: kind="redact".

Detect:
- title (kind="title")
- text paragraphs (kind="text")
- bullet lists (kind="bullets") with bullet lines separated by "\\n"
- images/diagrams (kind="image")
- video region (kind="video") if play button or embedded player

Coordinates must be in the 1600x900 coordinate system.
TXT;

    // ✅ Correct Responses API structured outputs shape:
    $payload = [
        "model" => cw_openai_model(),
        "input" => [
            [
                "role" => "system",
                "content" => [
                    ["type" => "input_text", "text" => $instructions]
                ]
            ],
            [
                "role" => "user",
                "content" => [
                    ["type" => "input_text", "text" => "Analyze this slide and return layout JSON."],
                    ["type" => "input_image", "image_url" => $imgUrl]
                ]
            ]
        ],
        "text" => [
            "format" => [
                "type" => "json_schema",
                "name" => "slide_layout_v1",
                "schema" => $schema,
                "strict" => true
            ]
        ],
        "temperature" => 0.2
    ];

    $resp = cw_openai_responses($payload);
    $layout = cw_openai_extract_json_text($resp);

    $objects = [];

    foreach (($layout['items'] ?? []) as $it) {
        $kind = (string)($it['kind'] ?? '');
        $x = max(0, min(1600, (float)($it['x'] ?? 0)));
        $y = max(0, min(900,  (float)($it['y'] ?? 0)));
        $w = max(10, min(1600, (float)($it['w'] ?? 100)));
        $h = max(10, min(900,  (float)($it['h'] ?? 50)));
        $text = (string)($it['text'] ?? '');

        if ($kind === 'redact') {
            $objects[] = [
                "type"=>"rect",
                "left"=>$x, "top"=>$y, "width"=>$w, "height"=>$h,
                "scaleX"=>1, "scaleY"=>1,
                "fill"=>"rgba(255,255,255,0.96)",
                "stroke"=>"#dddddd", "strokeWidth"=>1,
                "selectable"=>true, "evented"=>true,
                "data"=>["kind"=>"redact"]
            ];
            continue;
        }

        if ($kind === 'title' || $kind === 'text' || $kind === 'bullets') {
            $fs = ($kind === 'title') ? 40 : 26;
            $objects[] = [
                "type"=>"textbox",
                "left"=>$x, "top"=>$y, "width"=>$w, "height"=>$h,
                "scaleX"=>1, "scaleY"=>1,
                "text"=>$text,
                "fontSize"=>$fs,
                "fill"=>"#0b2a4a",
                "backgroundColor"=>"rgba(255,255,255,0.75)",
                "editable"=>true,
                "selectable"=>true,
                "evented"=>true,
                "data"=>["kind"=>"ai_text"]
            ];
            continue;
        }

        if ($kind === 'image' || $kind === 'video') {
            $label = ($kind === 'video') ? 'VIDEO' : 'IMAGE';
            $objects[] = [
                "type"=>"rect",
                "left"=>$x, "top"=>$y, "width"=>$w, "height"=>$h,
                "scaleX"=>1, "scaleY"=>1,
                "fill"=>"rgba(0,0,0,0.03)",
                "stroke"=>"#0b2a4a", "strokeWidth"=>2,
                "rx"=>12,"ry"=>12,
                "selectable"=>true, "evented"=>true,
                "data"=>["kind"=>($kind==='video'?'video':'image'), "label"=>$label]
            ];
        }
    }

    echo json_encode([
        "ok"=>true,
        "slide_id"=>$slideId,
        "design_json"=>["version"=>"5.3.0", "objects"=>$objects],
        "layout"=>$layout
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);
}