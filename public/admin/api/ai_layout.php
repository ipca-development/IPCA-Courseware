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

IMPORTANT OUTPUT RULES:
- DO NOT include UI chrome (main menu/buttons, breadcrumb path with '/', footer/copyright) as content.
- If you detect repeated chrome areas, you may output kind="redact" but the app will ignore them.
- Prefer splitting content into multiple items:
  * One item for "intro paragraph(s)" above bullets (kind="text")
  * One item for the bullet list (kind="bullets")
  * If sub-bullets exist (visibly more indented), output them as separate kind="bullets" items (separate bbox if possible)
- For kind="bullets", return text lines separated by "\\n". Preserve indentation by prefixing sub-bullets with 2 or 4 leading spaces.
- Coordinates must be in 1600x900.
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

    // Helper: split bullet text into groups by indent changes
    $splitBulletsByIndent = function(string $text): array {
        $lines = preg_split("/\r\n|\n|\r/", $text);
        $lines = array_values(array_filter($lines, fn($l)=>trim($l)!==''));
        if (!$lines) return [];

        $groups = [];
        $curIndent = null;
        $cur = [];

        foreach ($lines as $l) {
            // normalize tabs to 2 spaces
            $l = str_replace("\t", "  ", $l);

            preg_match('/^(\s*)/', $l, $m);
            $indent = strlen($m[1] ?? '');

            // collapse indent to 0/2/4/6 buckets
            $bucket = (int)(floor($indent / 2) * 2);

            if ($curIndent === null) {
                $curIndent = $bucket;
                $cur[] = $l;
            } elseif ($bucket === $curIndent) {
                $cur[] = $l;
            } else {
                $groups[] = ["indent"=>$curIndent, "lines"=>$cur];
                $curIndent = $bucket;
                $cur = [$l];
            }
        }
        $groups[] = ["indent"=>$curIndent ?? 0, "lines"=>$cur];
        return $groups;
    };

    $objects = [];

    foreach (($layout['items'] ?? []) as $it) {
        $kind = (string)($it['kind'] ?? '');
        $x = max(0, min(1600, (float)($it['x'] ?? 0)));
        $y = max(0, min(900,  (float)($it['y'] ?? 0)));
        $w = max(10, min(1600, (float)($it['w'] ?? 100)));
        $h = max(10, min(900,  (float)($it['h'] ?? 50)));
        $text = (string)($it['text'] ?? '');

        if ($kind === 'redact') continue;

        if ($kind === 'title' || $kind === 'text') {
            $fs = ($kind === 'title') ? 40 : 26;
            $objects[] = [
                "type"=>"textbox",
                "left"=>$x, "top"=>$y, "width"=>$w, "height"=>$h,
                "scaleX"=>1, "scaleY"=>1,
                "text"=>$text,
                "fontFamily"=>"Manrope",
                "fontSize"=>$fs,
                "fill"=>"#0b2a4a",
                "backgroundColor"=>null,
                "editable"=>true,
                "selectable"=>true,
                "evented"=>true,
                "data"=>["kind"=>"ai_text"]
            ];
            continue;
        }

        if ($kind === 'bullets') {
            // If AI already split into separate items, we keep each item.
            // If not, we split by indent transitions into multiple textboxes.
            $groups = $splitBulletsByIndent($text);
            if (!$groups) continue;

            $totalLines = 0;
            foreach ($groups as $g) $totalLines += count($g['lines']);
            if ($totalLines <= 0) $totalLines = 1;

            $yCursor = $y;

            foreach ($groups as $g) {
                $linesCount = max(1, count($g['lines']));
                $blockH = $h * ($linesCount / $totalLines);

                $indentPx = min(120, (int)$g['indent'] * 10); // 2 spaces -> 20px, 4 -> 40px, etc.
                $bx = $x + $indentPx;
                $bw = max(50, $w - $indentPx);

                $objects[] = [
                    "type"=>"textbox",
                    "left"=>$bx, "top"=>$yCursor, "width"=>$bw, "height"=>$blockH,
                    "scaleX"=>1, "scaleY"=>1,
                    "text"=>implode("\n", $g['lines']),
                    "fontFamily"=>"Manrope",
                    "fontSize"=>26,
                    "fill"=>"#0b2a4a",
                    "backgroundColor"=>null,
                    "editable"=>true,
                    "selectable"=>true,
                    "evented"=>true,
                    "data"=>["kind"=>"ai_bullets", "indent"=>$g['indent']]
                ];

                $yCursor += $blockH;
            }
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