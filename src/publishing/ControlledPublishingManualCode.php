<?php
declare(strict_types=1);

/**
 * Keeps URL/database identity codes machine-safe while presenting readable
 * manual codes to users.
 */
final class ControlledPublishingManualCode
{
    public static function normalizeIdentity(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/\s+/u', '_', $value) ?? '';
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,31}$/', $value)) {
            throw new RuntimeException(
                'Manual code must be 2–32 letters or numbers; spaces and hyphens are allowed.'
            );
        }

        return $value;
    }

    public static function display(string $value): string
    {
        $value = trim($value);
        return preg_replace('/_+/u', ' ', $value) ?? $value;
    }
}
