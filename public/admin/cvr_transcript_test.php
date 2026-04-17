<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/openai.php';
require_once __DIR__ . '/../../src/spaces.php';

@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');
set_time_limit(300);

cw_require_admin();

function cvrt_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function cvrt_default_prompt(): string
{
    return "You are transcribing cockpit audio for training analysis.

The audio may contain:
- ATC / radio transmissions
- cockpit intercom conversations between pilots
- background cockpit noise, static, interference, engine noise, bumps, clicks, and unintelligible sounds
- multilingual or accented speech, especially English, Spanish, Dutch, and accented English

Transcription goals:
- preserve aviation phraseology as accurately as possible
- preserve runway numbers, altitudes, headings, frequencies, call signs, readbacks, and airport names
- do not over-correct non-native pronunciation
- short clipped radio transmissions should still be transcribed faithfully
- when audio is unclear, transcribe as best as possible rather than inventing detail";
}

function cvrt_amz_now(): array
{
    $dt = new DateTime('now', new DateTimeZone('UTC'));
    return [
        'amz_date'  => $dt->format('Ymd\THis\Z'),
        'date_only' => $dt->format('Ymd'),
    ];
}

function cvrt_rawurlencode_path(string $path): string
{
    $parts = explode('/', ltrim($path, '/'));
    $enc = [];
    foreach ($parts as $part) {
        $enc[] = rawurlencode($part);
    }
    return '/' . implode('/', $enc);
}

function cvrt_build_canonical_query(array $query): string
{
    if (!$query) {
        return '';
    }

    ksort($query);
    $pairs = [];
    foreach ($query as $k => $v) {
        $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return implode('&', $pairs);
}

function cvrt_sign(string $key, string $msg): string
{
    return hash_hmac('sha256', $msg, $key, true);
}

function cvrt_spaces_request(string $method, string $objectKey = '', array $query = []): array
{
    $cfg = cw_spaces_config();
    $times = cvrt_amz_now();

    $bucket = (string)$cfg['bucket'];
    $region = (string)$cfg['region'];
    $accessKey = (string)$cfg['key'];
    $secretKey = (string)$cfg['secret'];
    $endpoint = (string)$cfg['endpoint'];

    $canonicalUri = $objectKey === '' ? '/' : cvrt_rawurlencode_path($objectKey);
    $canonicalQuery = cvrt_build_canonical_query($query);
    $payloadHash = hash('sha256', '');

    $host = $bucket . '.' . $endpoint;

    $canonicalHeaders =
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $payloadHash . "\n" .
        'x-amz-date:' . $times['amz_date'] . "\n";

    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest =
        strtoupper($method) . "\n" .
        $canonicalUri . "\n" .
        $canonicalQuery . "\n" .
        $canonicalHeaders . "\n" .
        $signedHeaders . "\n" .
        $payloadHash;

    $credentialScope = $times['date_only'] . '/' . $region . '/s3/aws4_request';
    $stringToSign =
        'AWS4-HMAC-SHA256' . "\n" .
        $times['amz_date'] . "\n" .
        $credentialScope . "\n" .
        hash('sha256', $canonicalRequest);

    $kDate    = cvrt_sign('AWS4' . $secretKey, $times['date_only']);
    $kRegion  = cvrt_sign($kDate, $region);
    $kService = cvrt_sign($kRegion, 's3');
    $kSigning = cvrt_sign($kService, 'aws4_request');
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization =
        'AWS4-HMAC-SHA256 ' .
        'Credential=' . $accessKey . '/' . $credentialScope . ', ' .
        'SignedHeaders=' . $signedHeaders . ', ' .
        'Signature=' . $signature;

    $url = 'https://' . $host . $canonicalUri;
    if ($canonicalQuery !== '') {
        $url .= '?' . $canonicalQuery;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: ' . $host,
        'x-amz-content-sha256: ' . $payloadHash,
        'x-amz-date: ' . $times['amz_date'],
        'Authorization: ' . $authorization,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Spaces request failed: ' . $err);
    }

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Spaces request failed with HTTP ' . $code . '. Response: ' . substr((string)$body, 0, 800));
    }

    return [
        'http_code' => $code,
        'body'      => (string)$body,
        'url'       => $url,
    ];
}

function cvrt_list_input_files(string $prefix): array
{
    $resp = cvrt_spaces_request('GET', '', [
        'list-type' => '2',
        'prefix'    => $prefix,
        'max-keys'  => '200',
    ]);

    $xml = @simplexml_load_string($resp['body']);
    if (!$xml) {
        throw new RuntimeException('Could not parse Spaces XML listing.');
    }

    $files = [];
    foreach ($xml->Contents as $item) {
        $key = trim((string)$item->Key);
        if ($key === '' || substr($key, -1) === '/') {
            continue;
        }

        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'wav', 'm4a', 'mp4', 'mpeg', 'mpga', 'webm'], true)) {
            continue;
        }

        $files[] = [
            'key'           => $key,
            'basename'      => basename($key),
            'size_bytes'    => (int)$item->Size,
            'last_modified' => (string)$item->LastModified,
        ];
    }

    usort($files, function (array $a, array $b) {
        return strcmp((string)$b['last_modified'], (string)$a['last_modified']);
    });

    return $files;
}

function cvrt_download_to_temp(string $objectKey): string
{
    $resp = cvrt_spaces_request('GET', $objectKey);

    $tmp = tempnam(sys_get_temp_dir(), 'cvrt_');
    if ($tmp === false) {
        throw new RuntimeException('Could not create temp file.');
    }

    $ext = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
    $tmpWithExt = $tmp . ($ext !== '' ? '.' . $ext : '.bin');

    if (!@rename($tmp, $tmpWithExt)) {
        $tmpWithExt = $tmp;
    }

    if (@file_put_contents($tmpWithExt, $resp['body']) === false) {
        throw new RuntimeException('Could not write temp audio file.');
    }

    return $tmpWithExt;
}

function cvrt_openai_transcribe(string $audioFilePath, string $prompt): array
{
    $apiKey = cw_openai_key();

    $postFields = [
        'file'   => new CURLFile(
            $audioFilePath,
            mime_content_type($audioFilePath) ?: 'audio/mpeg',
            basename($audioFilePath)
        ),
        'model'  => 'gpt-4o-transcribe',
        'prompt' => $prompt,
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('OpenAI transcription request failed: ' . $err);
    }

    $json = json_decode((string)$resp, true);
    if (!is_array($json)) {
        throw new RuntimeException('OpenAI transcription returned non-JSON. HTTP ' . $code . ' Body: ' . substr((string)$resp, 0, 800));
    }

    if ($code < 200 || $code >= 300) {
        $msg = (string)($json['error']['message'] ?? ('HTTP ' . $code));
        throw new RuntimeException('OpenAI transcription error: ' . $msg);
    }

    return $json;
}

function cvrt_format_seconds($seconds): string
{
    $seconds = (float)$seconds;
    if ($seconds < 0) {
        $seconds = 0;
    }

    $total = (int)floor($seconds);
    $h = (int)floor($total / 3600);
    $m = (int)floor(($total % 3600) / 60);
    $s = $total % 60;

    if ($h > 0) {
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function cvrt_extract_json_text_from_responses(array $resp): array
{
    if (!empty($resp['output_text']) && is_string($resp['output_text'])) {
        $decoded = json_decode($resp['output_text'], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if (!empty($resp['output']) && is_array($resp['output'])) {
        foreach ($resp['output'] as $item) {
            if (!empty($item['content']) && is_array($item['content'])) {
                foreach ($item['content'] as $content) {
                    if (isset($content['text']) && is_string($content['text'])) {
                        $decoded = json_decode($content['text'], true);
                        if (is_array($decoded)) {
                            return $decoded;
                        }
                    }
                }
            }
        }
    }

    return [];
}

function cvrt_heuristic_label(string $text): string
{
    $t = strtolower(trim($text));

    if ($t === '') {
        return 'NOISE';
    }

    if (preg_match('/^\[?(noise|static|silence|unintelligible|inaudible)\]?$/i', $t)) {
        return 'NOISE';
    }

    if (strlen($t) <= 4) {
        return 'NOISE';
    }

    if (preg_match('/\b(cleared|roger|wilco|affirm|negative|standby|ground|tower|approach|departure|center|line up|hold short|contact|squawk|traffic|runway|heading|maintain|climb|descend|altitude|frequency|decimal)\b/i', $t)) {
        return 'ATC';
    }

    if (preg_match('/\b(checklist|flaps|mixture|fuel pump|your controls|my controls|rotate|airspeed alive|set power|before takeoff|after takeoff|landing light|trim)\b/i', $t)) {
        return 'INTERCOM';
    }

    if (preg_match('/[0-9]/', $t) && preg_match('/\b(runway|heading|altitude|squawk|point|decimal)\b/i', $t)) {
        return 'ATC';
    }

    return 'INTERCOM';
}

function cvrt_classify_segments(array $segments): array
{
    if (!$segments) {
        return [];
    }

    $compact = [];
    foreach ($segments as $seg) {
        $compact[] = [
            'id'      => (string)($seg['id'] ?? ''),
            'speaker' => (string)($seg['speaker'] ?? ''),
            'text'    => trim((string)($seg['text'] ?? '')),
        ];
    }

    $payload = [
        'model' => cw_openai_model(),
        'input' => [
            [
                'role' => 'system',
                'content' => 'You classify cockpit transcript segments. Return ONLY valid JSON. For each segment, choose exactly one label: ATC, INTERCOM, or NOISE. ATC means radio / controller / radio transmission style. INTERCOM means pilots talking to each other in the cockpit. NOISE means static, bumps, clicks, engines, unclear gibberish, or not useful speech. JSON format only: {"segments":[{"id":"...","label":"ATC"}]}'
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'segments' => $compact
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]
        ],
        'max_output_tokens' => 1200,
    ];

    try {
        $resp = cw_openai_responses($payload);
        $json = cvrt_extract_json_text_from_responses($resp);

        $map = [];
        if (!empty($json['segments']) && is_array($json['segments'])) {
            foreach ($json['segments'] as $row) {
                $id = (string)($row['id'] ?? '');
                $label = strtoupper(trim((string)($row['label'] ?? '')));
                if ($id !== '' && in_array($label, ['ATC', 'INTERCOM', 'NOISE'], true)) {
                    $map[$id] = $label;
                }
            }
        }

        foreach ($segments as $i => $seg) {
            $id = (string)($seg['id'] ?? '');
            $segments[$i]['detected_label'] = $map[$id] ?? cvrt_heuristic_label((string)($seg['text'] ?? ''));
        }
    } catch (Throwable $e) {
        foreach ($segments as $i => $seg) {
            $segments[$i]['detected_label'] = cvrt_heuristic_label((string)($seg['text'] ?? ''));
        }
    }

    return $segments;
}

function cvrt_assign_pilot_labels(array $segments): array
{
    $pilotMap = [];
    $pilotIndex = 0;
    $pilotNames = ['Pilot A', 'Pilot B', 'Pilot C', 'Pilot D'];

    foreach ($segments as $i => $seg) {
        $segments[$i]['bubble_actor'] = '';
        $label = (string)($seg['detected_label'] ?? '');

        if ($label !== 'INTERCOM') {
            continue;
        }

        $speaker = trim((string)($seg['speaker'] ?? ''));
        if ($speaker === '') {
            $speaker = 'UNK';
        }

        if (!isset($pilotMap[$speaker])) {
            $pilotMap[$speaker] = $pilotNames[$pilotIndex] ?? ('Pilot ' . $speaker);
            $pilotIndex++;
        }

        $segments[$i]['bubble_actor'] = $pilotMap[$speaker];
    }

    return $segments;
}

function cvrt_prepare_segments(array $transcription): array
{
    $segments = [];

    $fullText = trim((string)($transcription['text'] ?? ''));
    if ($fullText === '') {
        return [];
    }

    $parts = preg_split('/(\r\n|\r|\n|(?<=[\.\!\?])\s+)/', $fullText);
    if (!is_array($parts)) {
        $parts = [$fullText];
    }

    $offsetSeconds = 0.0;

    foreach ($parts as $idx => $part) {
        $text = trim((string)$part);
        if ($text === '') {
            continue;
        }

        $segments[] = [
            'id'      => 'seg_' . $idx,
            'start'   => $offsetSeconds,
            'end'     => $offsetSeconds + 5.0,
            'speaker' => '',
            'text'    => $text,
        ];

        $offsetSeconds += 5.0;
    }

    $segments = cvrt_classify_segments($segments);
    $segments = cvrt_assign_pilot_labels($segments);

    return $segments;
}

function cvrt_file_size_label(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

$spacesPrefix = 'cvr_testing/input/';
$error = '';
$success = '';
$files = [];
$segments = [];
$transcriptionRaw = null;
$selectedKey = '';
$selectedMeta = null;
$prompt = cvrt_default_prompt();

try {
    $files = cvrt_list_input_files($spacesPrefix);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selectedKey = trim((string)($_POST['selected_key'] ?? ''));
        $prompt = trim((string)($_POST['prompt'] ?? ''));

        if ($prompt === '') {
            $prompt = cvrt_default_prompt();
        }

        if ($selectedKey === '') {
            throw new RuntimeException('Please select an audio file.');
        }

        $allowedKeys = array_column($files, 'key');
        if (!in_array($selectedKey, $allowedKeys, true)) {
            throw new RuntimeException('Selected file is not available in the test input folder.');
        }

        foreach ($files as $f) {
            if ((string)$f['key'] === $selectedKey) {
                $selectedMeta = $f;
                break;
            }
        }

        $tmpFile = cvrt_download_to_temp($selectedKey);

        try {
            $transcriptionRaw = cvrt_openai_transcribe($tmpFile, $prompt);
            $segments = cvrt_prepare_segments($transcriptionRaw);
            $success = 'Transcript test completed.';
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($selectedMeta === null && $selectedKey !== '') {
    foreach ($files as $f) {
        if ((string)$f['key'] === $selectedKey) {
            $selectedMeta = $f;
            break;
        }
    }
}

cw_header('CVR Transcript Test');
?>
<style>
  .cvrt-stack{display:flex;flex-direction:column;gap:22px}
  .cvrt-hero{padding:26px 28px}
  .cvrt-eyebrow{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.14em;
    color:#7b8ba3;
    font-weight:700;
    margin-bottom:10px;
  }
  .cvrt-title{
    margin:0;
    font-size:32px;
    line-height:1.02;
    letter-spacing:-0.04em;
    color:#152235;
    font-weight:800;
  }
  .cvrt-sub{
    margin-top:12px;
    font-size:15px;
    color:#6f7f95;
    max-width:920px;
    line-height:1.55;
  }

  .cvrt-grid{
    display:grid;
    grid-template-columns:1.05fr .95fr;
    gap:18px;
  }

  .cvrt-card{padding:22px 24px}
  .cvrt-card-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    margin-bottom:16px;
  }
  .cvrt-card-title{
    margin:0;
    font-size:20px;
    line-height:1.1;
    letter-spacing:-0.02em;
    color:#152235;
  }
  .cvrt-card-sub{
    margin-top:6px;
    font-size:14px;
    color:#728198;
    line-height:1.45;
  }

  .cvrt-field{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
  .cvrt-label{
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.1em;
    color:#6f7f95;
    font-weight:800;
  }
  .cvrt-select,
  .cvrt-textarea{
    width:100%;
    border-radius:14px;
    border:1px solid rgba(15,23,42,0.10);
    background:#fff;
    color:#152235;
    font-size:14px;
    line-height:1.5;
    box-sizing:border-box;
  }
  .cvrt-select{
    min-height:48px;
    padding:0 14px;
  }
  .cvrt-textarea{
    min-height:240px;
    resize:vertical;
    padding:14px 16px;
    font-family:inherit;
  }

  .cvrt-actions{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
  }
  .cvrt-button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:46px;
    padding:0 18px;
    border-radius:12px;
    border:0;
    background:#123b72;
    color:#fff;
    text-decoration:none;
    font-size:14px;
    font-weight:800;
    cursor:pointer;
    box-shadow:0 10px 24px rgba(18,59,114,0.18);
  }
  .cvrt-button:hover{opacity:.96}

  .cvrt-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:30px;
    padding:0 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.10em;
  }
  .cvrt-pill.ok{background:#dcfce7;color:#166534}
  .cvrt-pill.warn{background:#fef3c7;color:#92400e}
  .cvrt-pill.info{background:#dbeafe;color:#1d4ed8}

  .cvrt-alert{
    padding:14px 16px;
    border-radius:14px;
    font-size:14px;
    line-height:1.5;
    margin-bottom:14px;
  }
  .cvrt-alert.error{
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
  }
  .cvrt-alert.success{
    background:#ecfdf5;
    color:#166534;
    border:1px solid #bbf7d0;
  }

  .cvrt-meta-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(120px,1fr));
    gap:14px;
    margin-top:4px;
  }
  .cvrt-metric{
    background:#f9fafb;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:14px;
  }
  .cvrt-metric-label{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.1em;
    color:#7b8ba3;
    font-weight:800;
    margin-bottom:8px;
  }
  .cvrt-metric-value{
    font-size:20px;
    font-weight:800;
    color:#152235;
    line-height:1.15;
    word-break:break-word;
  }

  .cvrt-empty{
    padding:18px;
    border-radius:16px;
    border:1px dashed rgba(15,23,42,0.12);
    background:linear-gradient(180deg,#ffffff 0%,#fbfcfe 100%);
    color:#728198;
    font-size:14px;
  }

  .cvrt-transcript-wrap{
    display:flex;
    flex-direction:column;
    gap:14px;
  }
  .cvrt-row{
    display:grid;
    grid-template-columns:86px 1fr;
    gap:12px;
    align-items:flex-start;
  }
  .cvrt-time{
    padding-top:8px;
    font-size:12px;
    font-weight:700;
    color:#7b8ba3;
    text-align:left;
    white-space:nowrap;
  }
  .cvrt-bubble-lane{
    display:flex;
    width:100%;
  }
  .cvrt-row.atc .cvrt-bubble-lane{
    justify-content:flex-start;
  }
  .cvrt-row.intercom .cvrt-bubble-lane{
    justify-content:flex-end;
  }
  .cvrt-row.noise .cvrt-bubble-lane{
    justify-content:center;
  }

  .cvrt-bubble{
    max-width:min(78%, 820px);
    border-radius:22px;
    padding:12px 14px;
    box-shadow:0 10px 22px rgba(15,23,42,0.06);
    border:1px solid rgba(15,23,42,0.05);
  }
  .cvrt-bubble.atc{
    background:#f1f5f9;
    color:#111827;
  }
  .cvrt-bubble.intercom{
    background:#1d4ed8;
    color:#ffffff;
  }
  .cvrt-bubble.noise{
    background:#f3f4f6;
    color:#6b7280;
  }

  .cvrt-bubble-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:6px;
  }
  .cvrt-actor{
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.10em;
  }
  .cvrt-actor.atc{color:#334155}
  .cvrt-actor.intercom{color:#dbeafe}
  .cvrt-actor.noise{color:#6b7280}
  .cvrt-bubble-text{
    font-size:14px;
    line-height:1.55;
    white-space:pre-wrap;
    word-wrap:break-word;
  }

  details.cvrt-debug{
    margin-top:16px;
  }
  details.cvrt-debug summary{
    cursor:pointer;
    font-size:13px;
    font-weight:800;
    color:#123b72;
  }
  .cvrt-json{
    margin-top:12px;
    white-space:pre-wrap;
    background:#0f172a;
    color:#e2e8f0;
    border-radius:14px;
    padding:16px;
    overflow:auto;
    font-family:Menlo, Monaco, Consolas, monospace;
    font-size:12px;
    line-height:1.5;
  }

  @media (max-width:1200px){
    .cvrt-grid{grid-template-columns:1fr}
  }

  @media (max-width:760px){
    .cvrt-meta-grid{grid-template-columns:1fr 1fr}
    .cvrt-row{
      grid-template-columns:1fr;
      gap:6px;
    }
    .cvrt-time{
      padding-top:0;
      font-size:11px;
    }
    .cvrt-bubble{
      max-width:100%;
    }
  }
</style>

<div class="cvrt-stack">

  <div class="card cvrt-hero">
    <div class="cvrt-eyebrow">Admin Test Workspace</div>
    <h2 class="cvrt-title">CVR Transcript Test</h2>
    <div class="cvrt-sub">
      Drop your test audio into <strong><?= cvrt_h($spacesPrefix) ?></strong> in DigitalOcean Spaces, select the file here, adjust the prompt, and review the output as ATC, intercom, and probable noise.
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="cvrt-alert error"><?= cvrt_h($error) ?></div>
  <?php endif; ?>

  <?php if ($success !== ''): ?>
    <div class="cvrt-alert success"><?= cvrt_h($success) ?></div>
  <?php endif; ?>

  <div class="cvrt-grid">
    <div class="card cvrt-card">
      <div class="cvrt-card-head">
        <div>
          <h3 class="cvrt-card-title">Run Transcript Test</h3>
          <div class="cvrt-card-sub">Select an audio file from Spaces, adjust the prompt, and run a fresh transcription.</div>
        </div>
        <div class="cvrt-pill <?= count($files) > 0 ? 'ok' : 'warn' ?>">
          <?= count($files) > 0 ? 'Ready' : 'No Files' ?>
        </div>
      </div>

      <form method="post" action="">
        <div class="cvrt-field">
          <label class="cvrt-label" for="selected_key">Audio File</label>
          <select class="cvrt-select" id="selected_key" name="selected_key">
            <option value="">Select file…</option>
            <?php foreach ($files as $file): ?>
              <option value="<?= cvrt_h((string)$file['key']) ?>" <?= ((string)$file['key'] === $selectedKey ? 'selected' : '') ?>>
                <?= cvrt_h((string)$file['basename']) ?> — <?= cvrt_h(cvrt_file_size_label((int)$file['size_bytes'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="cvrt-field">
          <label class="cvrt-label" for="prompt">Transcription Prompt</label>
          <textarea class="cvrt-textarea" id="prompt" name="prompt"><?= cvrt_h($prompt) ?></textarea>
        </div>

        <div class="cvrt-actions">
          <button type="submit" class="cvrt-button">Run Transcript Test</button>
        </div>
      </form>
    </div>

    <div class="card cvrt-card">
      <div class="cvrt-card-head">
        <div>
          <h3 class="cvrt-card-title">Input Folder Status</h3>
          <div class="cvrt-card-sub">Current source folder for this test page.</div>
        </div>
        <div class="cvrt-pill info">Spaces</div>
      </div>

      <div class="cvrt-meta-grid">
        <div class="cvrt-metric">
          <div class="cvrt-metric-label">Bucket</div>
          <div class="cvrt-metric-value"><?= cvrt_h((string)cw_spaces_config()['bucket']) ?></div>
        </div>
        <div class="cvrt-metric">
          <div class="cvrt-metric-label">Region</div>
          <div class="cvrt-metric-value"><?= cvrt_h((string)cw_spaces_config()['region']) ?></div>
        </div>
        <div class="cvrt-metric">
          <div class="cvrt-metric-label">Prefix</div>
          <div class="cvrt-metric-value"><?= cvrt_h($spacesPrefix) ?></div>
        </div>
        <div class="cvrt-metric">
          <div class="cvrt-metric-label">Files Found</div>
          <div class="cvrt-metric-value"><?= (int)count($files) ?></div>
        </div>
      </div>

      <?php if ($selectedMeta): ?>
        <div class="cvrt-empty" style="margin-top:16px;">
          <strong>Selected:</strong> <?= cvrt_h((string)$selectedMeta['basename']) ?><br>
          <strong>Object key:</strong> <?= cvrt_h((string)$selectedMeta['key']) ?><br>
          <strong>Size:</strong> <?= cvrt_h(cvrt_file_size_label((int)$selectedMeta['size_bytes'])) ?><br>
          <strong>Updated:</strong> <?= cvrt_h((string)$selectedMeta['last_modified']) ?>
        </div>
      <?php else: ?>
        <div class="cvrt-empty" style="margin-top:16px;">
          Drop test audio files into the Spaces folder <strong><?= cvrt_h($spacesPrefix) ?></strong> and they will appear in the selector above.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card cvrt-card">
    <div class="cvrt-card-head">
      <div>
        <h3 class="cvrt-card-title">Transcript Output</h3>
        <div class="cvrt-card-sub">ATC is aligned left, intercom is aligned right, and probable noise is centered.</div>
      </div>
      <div class="cvrt-pill <?= $segments ? 'ok' : 'warn' ?>">
        <?= $segments ? ((int)count($segments) . ' Segments') : 'Waiting' ?>
      </div>
    </div>

    <?php if (!$segments): ?>
      <div class="cvrt-empty">
        No transcript result yet. Select a file, adjust the prompt, and run the test.
      </div>
    <?php else: ?>
      <div class="cvrt-transcript-wrap">
        <?php foreach ($segments as $seg): ?>
          <?php
            $label = (string)($seg['detected_label'] ?? 'INTERCOM');
            $rowClass = 'intercom';
            $actor = 'Pilot';
            if ($label === 'ATC') {
                $rowClass = 'atc';
                $actor = 'ATC';
            } elseif ($label === 'NOISE') {
                $rowClass = 'noise';
                $actor = 'Noise';
            } else {
                $rowClass = 'intercom';
                $actor = (string)($seg['bubble_actor'] ?? 'Pilot');
                if ($actor === '') {
                    $actor = 'Pilot';
                }
            }

            $timeLabel = cvrt_format_seconds((float)($seg['start'] ?? 0));
            if ((float)($seg['end'] ?? 0) > (float)($seg['start'] ?? 0)) {
                $timeLabel .= '–' . cvrt_format_seconds((float)($seg['end'] ?? 0));
            }
          ?>
          <div class="cvrt-row <?= cvrt_h($rowClass) ?>">
            <div class="cvrt-time"><?= cvrt_h($timeLabel) ?></div>
            <div class="cvrt-bubble-lane">
              <div class="cvrt-bubble <?= cvrt_h($rowClass) ?>">
                <div class="cvrt-bubble-head">
                  <div class="cvrt-actor <?= cvrt_h($rowClass) ?>"><?= cvrt_h($actor) ?></div>
                </div>
                <div class="cvrt-bubble-text"><?= cvrt_h((string)($seg['text'] ?? '')) ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($transcriptionRaw !== null): ?>
        <details class="cvrt-debug">
          <summary>Show raw transcription JSON</summary>
          <div class="cvrt-json"><?= cvrt_h(json_encode($transcriptionRaw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></div>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<?php cw_footer(); ?>