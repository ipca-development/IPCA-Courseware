<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/spaces.php';

interface CommunicationObjectStore
{
    public function presignPut(string $key, string $contentType, int $expires): string;

    public function presignPublicPut(string $key, string $contentType, int $expires): string;

    public function presignGet(string $key, int $expires): string;

    public function publicUrl(string $key): string;

    /**
     * @return array{byte_size:int,content_type:string}|null
     */
    public function head(string $key): ?array;
}

final class CommunicationMemoryObjectStore implements CommunicationObjectStore
{
    /** @var array<string,array{bytes:string,content_type:string,byte_size:int}> */
    public array $objects = array();

    public function presignPut(string $key, string $contentType, int $expires): string
    {
        return 'https://memory.invalid/put/' . rawurlencode($key) . '?content-type=' . rawurlencode($contentType) . '&expires=' . $expires;
    }

    public function presignPublicPut(string $key, string $contentType, int $expires): string
    {
        return 'https://memory.invalid/put/' . rawurlencode($key)
            . '?content-type=' . rawurlencode($contentType)
            . '&acl=public-read&expires=' . $expires;
    }

    public function presignGet(string $key, int $expires): string
    {
        return 'https://memory.invalid/get/' . rawurlencode($key) . '?expires=' . $expires;
    }

    public function publicUrl(string $key): string
    {
        return 'https://memory.invalid/cdn/' . ltrim($key, '/');
    }

    public function put(string $key, string $bytes, string $contentType): void
    {
        $this->objects[$key] = array(
            'bytes' => $bytes,
            'content_type' => $contentType,
            'byte_size' => strlen($bytes),
        );
    }

    public function head(string $key): ?array
    {
        $row = $this->objects[$key] ?? null;
        if ($row === null) {
            return null;
        }
        return array(
            'byte_size' => (int)$row['byte_size'],
            'content_type' => (string)$row['content_type'],
        );
    }
}

final class CommunicationSpacesObjectStore implements CommunicationObjectStore
{
    public static function tryFromEnvironment(): ?self
    {
        try {
            cw_spaces_config();
            return new self();
        } catch (Throwable) {
            return null;
        }
    }

    public const PUBLIC_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function presignPut(string $key, string $contentType, int $expires): string
    {
        return cw_spaces_presign('PUT', $key, $expires, array('content-type' => $contentType));
    }

    public function presignPublicPut(string $key, string $contentType, int $expires): string
    {
        return cw_spaces_presign('PUT', $key, $expires, array(
            'content-type' => $contentType,
            'x-amz-acl' => 'public-read',
            'cache-control' => self::PUBLIC_CACHE_CONTROL,
        ));
    }

    public function presignGet(string $key, int $expires): string
    {
        return cw_spaces_presign('GET', $key, $expires);
    }

    public function publicUrl(string $key): string
    {
        return cw_spaces_public_url($key);
    }

    public function head(string $key): ?array
    {
        return cw_spaces_head_object($key);
    }
}
