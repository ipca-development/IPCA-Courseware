<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationException.php';
require_once __DIR__ . '/CommunicationSupport.php';

final class CommunicationHttp
{
    /**
     * @return array<string,mixed>
     */
    public static function input(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }
        return array_merge($_GET, $_POST);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function json(int $code, array $payload): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, CommunicationSupport::jsonFlags());
        exit;
    }

    public static function fail(CommunicationException $e): void
    {
        self::json($e->httpStatus, array(
            'ok' => false,
            'error' => $e->getMessage(),
            'error_code' => $e->errorCode,
        ));
    }

    public static function method(string $allowed): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== strtoupper($allowed)) {
            throw new CommunicationException('method_not_allowed', 'Method not allowed.', 405);
        }
    }
}
