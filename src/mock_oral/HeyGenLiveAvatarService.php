<?php
declare(strict_types=1);

require_once __DIR__ . '/../openai.php';

final class HeyGenLiveAvatarService
{
    public function mintSessionToken(int $sessionId, int $userId): array
    {
        $apiKey = trim((string)(getenv('CW_HEYGEN_API_KEY') ?: getenv('HEYGEN_API_KEY') ?: ''));
        $avatarId = trim((string)(getenv('CW_HEYGEN_AVATAR_ID') ?: getenv('HEYGEN_AVATAR_ID') ?: ''));

        if ($apiKey === '') {
            return [
                'ok' => true,
                'presentation_mode' => 'fallback',
                'message' => 'HeyGen not configured; use browser TTS fallback.',
                'session_id' => $sessionId,
            ];
        }

        $payload = [
            'quality' => 'high',
            'avatar_id' => $avatarId !== '' ? $avatarId : null,
            'voice' => [
                'voice_id' => getenv('CW_HEYGEN_VOICE_ID') ?: null,
            ],
        ];
        $payload = array_filter($payload, static fn($v) => $v !== null);

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
            return [
                'ok' => true,
                'presentation_mode' => 'fallback',
                'message' => 'HeyGen token unavailable; using TTS fallback.',
                'session_id' => $sessionId,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        $token = (string)($decoded['data']['token'] ?? $decoded['token'] ?? '');
        if ($token === '') {
            return [
                'ok' => true,
                'presentation_mode' => 'fallback',
                'message' => 'HeyGen response missing token.',
                'session_id' => $sessionId,
            ];
        }

        return [
            'ok' => true,
            'presentation_mode' => 'heygen',
            'token' => $token,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ];
    }
}
