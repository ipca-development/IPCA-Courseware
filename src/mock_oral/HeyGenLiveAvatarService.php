<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/../remote_session_auth/remote_session_auth_constants.php';

final class HeyGenLiveAvatarService
{
    public function mintSessionToken(int $sessionId, int $userId): array
    {
        $apiKey = trim((string)(getenv('CW_HEYGEN_API_KEY') ?: getenv('HEYGEN_API_KEY') ?: ''));
        $avatarId = trim((string)(getenv('CW_HEYGEN_AVATAR_ID') ?: getenv('HEYGEN_AVATAR_ID') ?: ''));
        $voiceId = trim((string)(getenv('CW_HEYGEN_VOICE_ID') ?: getenv('HEYGEN_VOICE_ID') ?: ''));
        $quality = trim((string)(getenv('CW_HEYGEN_QUALITY') ?: 'high'));
        if (!in_array($quality, ['low', 'medium', 'high'], true)) {
            $quality = 'high';
        }

        $base = [
            'ok' => true,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ];

        if ($apiKey === '') {
            return $base + [
                'presentation_mode' => 'fallback',
                'message' => 'HeyGen API key not configured (CW_HEYGEN_API_KEY).',
            ];
        }

        if ($avatarId === '') {
            return $base + [
                'presentation_mode' => 'fallback',
                'message' => 'HeyGen avatar not configured (CW_HEYGEN_AVATAR_ID). Using AI voice until your custom avatar is ready.',
            ];
        }

        $payload = ['quality' => $quality];
        $voicePayload = array_filter(['voice_id' => $voiceId !== '' ? $voiceId : null]);
        if ($voicePayload !== []) {
            $payload['voice'] = $voicePayload;
        }

        $ch = curl_init('https://api.heygen.com/v1/streaming.create_token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $http < 200 || $http >= 300) {
            return $base + [
                'presentation_mode' => 'fallback',
                'message' => 'HeyGen token request failed (HTTP ' . $http . '). Using AI voice fallback.',
            ];
        }

        $decoded = json_decode((string)$raw, true);
        $token = (string)($decoded['data']['token'] ?? $decoded['token'] ?? '');
        if ($token === '') {
            return $base + [
                'presentation_mode' => 'fallback',
                'message' => 'HeyGen response missing session token.',
            ];
        }

        $idleSec = min(3600, max(180, RSA_SESSION_MAX_DURATION_SEC + 180));

        return $base + [
            'presentation_mode' => 'heygen',
            'token' => $token,
            'avatar_id' => $avatarId,
            'voice_id' => $voiceId,
            'quality' => $quality,
            'activity_idle_timeout_sec' => $idleSec,
            'message' => 'HeyGen live avatar ready.',
        ];
    }
}
