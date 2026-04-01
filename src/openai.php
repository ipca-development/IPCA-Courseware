<?php
declare(strict_types=1);

function cw_openai_key(): string {
    $k = getenv('CW_OPENAI_API_KEY') ?: '';
    if ($k === '') throw new RuntimeException("CW_OPENAI_API_KEY is missing");
    return $k;
}
//OPEN AI
function cw_openai_model(): string {
    return getenv('CW_OPENAI_MODEL') ?: 'gpt-5.4';
}

function cw_openai_responses(array $payload): array {
    $key = cw_openai_key();

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("OpenAI request failed: " . $err);
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RuntimeException("OpenAI returned non-JSON (HTTP $code): " . substr($resp, 0, 300));
    }

    if ($code < 200 || $code >= 300) {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException("OpenAI error: " . $msg);
    }

    return $json;
}

function cw_openai_extract_json_text(array $resp): array {
    $out = $resp['output'] ?? [];
    if (!is_array($out)) return [];

    $text = '';
    foreach ($out as $item) {
        if (!is_array($item)) continue;
        $content = $item['content'] ?? [];
        if (!is_array($content)) continue;
        foreach ($content as $c) {
            if (is_array($c) && ($c['type'] ?? '') === 'output_text') {
                $text .= (string)($c['text'] ?? '');
            }
        }
    }

    $text = trim($text);
    if ($text === '') return [];

    $json = json_decode($text, true);
    if (!is_array($json)) {
        throw new RuntimeException("Model returned non-JSON text: " . substr($text, 0, 200));
    }
    return $json;
}