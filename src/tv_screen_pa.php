<?php
declare(strict_types=1);

require_once __DIR__ . '/openai.php';

function tv_pa_voices(): array
{
    return array(
        'marin' => 'International Terminal — default airport PA',
        'onyx' => 'Authority Control — deep commanding terminal voice',
        'coral' => 'Gate Announcement — clear broadcast announcer',
        'echo' => 'Operations Desk — crisp neutral PA tone',
        'ash' => 'Standard Ops — calm measured terminal voice',
        'sage' => 'Premium Lounge — warm professional announcer',
        'cedar' => 'Runway Control — firm operational command voice',
    );
}

function tv_pa_default_voice(): string
{
    $env = trim((string)(getenv('CW_OPENAI_TTS_VOICE') ?: ''));
    if ($env !== '' && array_key_exists($env, tv_pa_voices())) {
        return $env;
    }
    return 'marin';
}

function tv_pa_voice_or_default(?string $voice): string
{
    $voice = strtolower(trim((string)$voice));
    if ($voice !== '' && array_key_exists($voice, tv_pa_voices())) {
        return $voice;
    }
    return tv_pa_default_voice();
}

function tv_pa_tts_model(): string
{
    return getenv('CW_OPENAI_TTS_MODEL') ?: 'gpt-4o-mini-tts';
}

function tv_pa_speed(): float
{
    $speed = (float)(getenv('CW_TV_PA_TTS_SPEED') ?: 0.94);
    if ($speed < 0.80) {
        $speed = 0.80;
    }
    if ($speed > 1.10) {
        $speed = 1.10;
    }
    return $speed;
}

function tv_pa_instructions(string $messageType = 'standard'): string
{
    $base = 'You are a professional international airport public-address announcer broadcasting through ceiling speakers in a major terminal. '
        . 'Sound like a real PA system: calm authority, slightly compressed microphone presence, measured cadence, and deliberate pauses between phrases. '
        . 'Keep diction crisp and operational. Do not sound conversational, cheerful, or like a virtual assistant. '
        . 'Avoid stage acting; sound like live terminal operations audio.';

    $messageType = strtolower(trim($messageType));
    if ($messageType === 'urgent') {
        return $base . ' This is an urgent operational override. Increase urgency slightly while staying controlled, intelligible, and professional.';
    }

    return $base;
}

function tv_pa_build_speech(array $row): string
{
    $speech = trim((string)($row['voice_text'] ?? ''));
    if ($speech !== '') {
        return $speech;
    }

    $title = trim((string)($row['title'] ?? ''));
    $body = trim(preg_replace('/\s+/u', ' ', str_replace(array("\r\n", "\n", "\r"), '. ', (string)($row['body'] ?? ''))) ?? '');
    return trim($title . ($body !== '' ? '. ' . $body : ''));
}

function tv_pa_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/tv_announcements';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function tv_pa_cache_file(int $messageId, string $speech, string $voice, string $messageType): string
{
    $model = tv_pa_tts_model();
    $instructions = tv_pa_instructions($messageType);
    $speed = (string)tv_pa_speed();
    $sha = sha1($speech . '|' . $voice . '|' . $model . '|' . $instructions . '|' . $speed);
    return tv_pa_cache_dir() . '/msg_' . $messageId . '_' . $sha . '.mp3';
}

function tv_pa_synthesize_mp3(string $speech, string $voice, string $messageType = 'standard'): string
{
    $speech = trim($speech);
    if ($speech === '') {
        return '';
    }

    if (strlen($speech) > 4000) {
        $speech = substr($speech, 0, 4000);
    }

    $voice = tv_pa_voice_or_default($voice);
    $payload = json_encode(array(
        'model' => tv_pa_tts_model(),
        'voice' => $voice,
        'input' => $speech,
        'instructions' => tv_pa_instructions($messageType),
        'response_format' => 'mp3',
        'speed' => tv_pa_speed(),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . cw_openai_key(),
            'Content-Type: application/json',
        ),
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
    ));

    $audio = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($audio === false || $http < 200 || $http >= 300) {
        return '';
    }

    return (string)$audio;
}

function tv_pa_stream_mp3(string $audio, bool $prefetch = false): void
{
    if ($prefetch) {
        http_response_code(204);
        exit;
    }

    header('Content-Type: audio/mpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    echo $audio;
    exit;
}
