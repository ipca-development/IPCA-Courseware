<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';
require_once __DIR__ . '/../remote_session_auth/remote_session_auth_constants.php';

final class HeyGenLiveAvatarService
{
    private const LIVEAVATAR_API = 'https://api.liveavatar.com';
    /** IPCA Maya custom LiveAvatar (override with CW_HEYGEN_AVATAR_ID). */
    private const DEFAULT_MAYA_AVATAR_ID = '8de43fb8-8c57-4fba-9c30-295574b4749c';
    /** Maya voice on LiveAvatar (override with CW_HEYGEN_VOICE_ID). */
    private const DEFAULT_MAYA_VOICE_ID = '3607df3c-9de0-4274-b0be-7e035775ead5';

    public function mintSessionToken(int $sessionId, int $userId): array
    {
        $apiKey = trim((string)(getenv('CW_HEYGEN_API_KEY') ?: getenv('HEYGEN_API_KEY') ?: ''));
        $avatarId = trim((string)(getenv('CW_HEYGEN_AVATAR_ID') ?: getenv('HEYGEN_AVATAR_ID') ?: ''));
        $voiceId = trim((string)(getenv('CW_HEYGEN_VOICE_ID') ?: getenv('HEYGEN_VOICE_ID') ?: ''));

        $base = [
            'ok' => true,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ];

        if ($apiKey === '') {
            return $base + [
                'presentation_mode' => 'fallback',
                'message' => 'LiveAvatar API key not configured (CW_HEYGEN_API_KEY).',
            ];
        }

        if ($avatarId === '') {
            $avatarId = self::DEFAULT_MAYA_AVATAR_ID;
        }
        if ($voiceId === '') {
            $voiceId = self::DEFAULT_MAYA_VOICE_ID;
        }

        $persona = [
            'language' => 'en',
            'voice_id' => $voiceId,
        ];

        $payload = [
            'mode' => 'LITE',
            'avatar_id' => $avatarId,
            'avatar_persona' => $persona,
        ];

        $ch = curl_init(self::LIVEAVATAR_API . '/v1/sessions/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-API-KEY: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errno !== 0 || $http < 200 || $http >= 300) {
            $decoded = json_decode((string)$raw, true);
            $apiMessage = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
            $detail = $apiMessage !== '' ? $apiMessage : 'HTTP ' . $http;

            return $base + [
                'presentation_mode' => 'fallback',
                'message' => 'LiveAvatar token request failed (' . $detail . '). Using AI voice fallback.',
            ];
        }

        $decoded = json_decode((string)$raw, true);
        $token = (string)($decoded['data']['session_token'] ?? '');
        $liveAvatarSessionId = (string)($decoded['data']['session_id'] ?? '');
        if ($token === '') {
            return $base + [
                'presentation_mode' => 'fallback',
                'message' => 'LiveAvatar response missing session token.',
            ];
        }

        $idleSec = min(3600, max(180, RSA_SESSION_MAX_DURATION_SEC + 180));

        return $base + [
            'presentation_mode' => 'heygen',
            'provider' => 'liveavatar',
            'token' => $token,
            'liveavatar_session_id' => $liveAvatarSessionId,
            'avatar_id' => $avatarId,
            'voice_id' => $voiceId,
            'activity_idle_timeout_sec' => $idleSec,
            'message' => 'Maya live avatar ready.',
        ];
    }
}
