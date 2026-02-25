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
                    "required" => ["kind","x","y","w","h","confidence","text"]
                ]
            ]
        ],
        "required" => ["title","items"]
    ];

    $instructions = <<<TXT
You are laying out an aviation training slide on a fixed 1600x900 canvas.

Return ALL real instructional content with approximate bounding boxes.

IMPORTANT:
- DO NOT include UI chrome text or buttons as content.
- If you detect repeated UI areas (menus/breadcrumb/footer), you may output kind="redact" but the app may ignore them.
- Coordinates must be in 1600x900 coordinate system.
- For image/video/redact items, set text="".
TXT;

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

        // ✅ We DO NOT want redaction boxes (IPCA background already defines layout)
        if ($kind === 'redact') {
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
                "data"=>["kind"=>($kind==='video'?'video_box':'image_box'), "label"=>$label]
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