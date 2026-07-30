<?php
declare(strict_types=1);

/** Server-side probe automation token derived from CW_DB_PASS (same on App Platform and trusted CLI hosts). */
final class Phase0ProbeAuth
{
    public static function probeToken(int $recordingId, int $chunkIndex): string
    {
        $secret = trim((string)(getenv('CW_DB_PASS') ?: ''));
        if ($secret === '') {
            return '';
        }
        return hash_hmac('sha256', 'phase0-probe-v1|' . $recordingId . '|' . $chunkIndex, $secret);
    }

    public static function verifyToken(int $recordingId, int $chunkIndex): string
    {
        $secret = trim((string)(getenv('CW_DB_PASS') ?: ''));
        if ($secret === '') {
            return '';
        }
        return hash_hmac('sha256', 'phase0-verify-v1|' . $recordingId . '|' . $chunkIndex, $secret);
    }

    public static function requestToken(int $recordingId, int $chunkIndex): string
    {
        $header = trim((string)($_SERVER['HTTP_X_CW_PHASE0_PROBE_TOKEN'] ?? ''));
        if ($header !== '') {
            return $header;
        }
        return trim((string)($_GET['probe_token'] ?? ''));
    }

    public static function isAuthorized(int $recordingId, int $chunkIndex): bool
    {
        $user = cw_current_user(cw_db());
        if (is_array($user) && (string)($user['role'] ?? '') === 'admin') {
            return true;
        }
        $expected = self::probeToken($recordingId, $chunkIndex);
        if ($expected === '') {
            return false;
        }
        $supplied = self::requestToken($recordingId, $chunkIndex);
        return $supplied !== '' && hash_equals($expected, $supplied);
    }

    public static function isVerifyAuthorized(string $probeExecutionUuid, int $recordingId, int $chunkIndex): bool
    {
        $user = cw_current_user(cw_db());
        if (is_array($user) && (string)($user['role'] ?? '') === 'admin') {
            return true;
        }
        $secret = trim((string)(getenv('CW_DB_PASS') ?: ''));
        if ($secret === '') {
            return false;
        }
        $expectedProbe = self::probeToken($recordingId, $chunkIndex);
        $expectedVerify = hash_hmac('sha256', 'phase0-verify-v1|' . $probeExecutionUuid, $secret);
        $supplied = self::requestToken($recordingId, $chunkIndex);
        return $supplied !== ''
            && (hash_equals($expectedProbe, $supplied) || hash_equals($expectedVerify, $supplied));
    }
}
