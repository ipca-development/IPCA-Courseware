<?php
declare(strict_types=1);

/**
 * Fixed layout profile for authoritative OM/OMM reader page maps.
 *
 * Matches the controlled book editor .cpb-sheet dimensions (US Letter).
 * Pagination height estimates use these constants — never the browser viewport.
 */
final class ControlledPublishingReaderLayoutProfile
{
    public const PROFILE_KEY = 'LETTER_READER_v1';

    /** @var array<string,mixed> */
    private const SPEC = array(
        'layout_profile' => self::PROFILE_KEY,
        'paper_size' => 'Letter',
        'page_width_px' => 816,
        'page_height_px' => 1056,
        'sheet_padding_top_px' => 48,
        'sheet_padding_bottom_px' => 64,
        'sheet_padding_x_px' => 56,
        'header_band_px' => 96,
        'footer_band_px' => 72,
        'header_margin_bottom_px' => 20,
        'footer_margin_top_px' => 24,
        'body_capacity_px' => 732,
        'font_family' => "Georgia, 'Times New Roman', serif",
        'font_size_pt' => 11,
        'line_height' => 1.55,
        'line_height_px' => 17,
        'chars_per_line' => 92,
        'split_words_per_chunk' => 16,
    );

    /**
     * @return array<string,mixed>
     */
    public static function spec(): array
    {
        return self::SPEC;
    }

    public static function profileKey(): string
    {
        return self::PROFILE_KEY;
    }

    /**
     * SHA-256 of canonical layout JSON (sorted keys, stable encoding).
     */
    public static function layoutHash(): string
    {
        $payload = self::SPEC;
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
