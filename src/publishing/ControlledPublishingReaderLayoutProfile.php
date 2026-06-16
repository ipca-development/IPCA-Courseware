<?php
declare(strict_types=1);

/**
 * Fixed layout profile for authoritative OM/OMM reader page maps.
 *
 * Matches the controlled manual template (US Letter) scaled to the reader
 * viewport unit (680×880 px). All official page breaks are computed against
 * these constants — never against the student browser viewport.
 */
final class ControlledPublishingReaderLayoutProfile
{
    public const PROFILE_KEY = 'LETTER_READER_v1';

    /** @var array<string,mixed> */
    private const SPEC = array(
        'layout_profile' => self::PROFILE_KEY,
        'paper_size' => 'Letter',
        'page_width_px' => 680,
        'page_height_px' => 880,
        'content_padding_x' => 28,
        'content_padding_y' => 16,
        'header_band_px' => 72,
        'footer_band_px' => 48,
        'body_width_px' => 624,
        'body_capacity_px' => 736,
        'font_family' => "Georgia, 'Times New Roman', serif",
        'font_size_pt' => 11,
        'line_height' => 1.55,
        'line_height_px' => 17,
        'chars_per_line' => 78,
        'split_words_per_chunk' => 12,
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
     * Binds stored page maps to a specific layout definition.
     */
    public static function layoutHash(): string
    {
        $payload = self::SPEC;
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Inline CSS for frozen page shells (official dimensions — not responsive).
     */
    public static function frozenPageInlineStyle(): string
    {
        $s = self::SPEC;

        return sprintf(
            'width:%dpx;height:%dpx;max-width:%dpx;max-height:%dpx;'
            . 'font-family:%s;font-size:%dpt;line-height:%s;box-sizing:border-box;'
            . 'display:flex;flex-direction:column;overflow:hidden;',
            (int)$s['page_width_px'],
            (int)$s['page_height_px'],
            (int)$s['page_width_px'],
            (int)$s['page_height_px'],
            (string)$s['font_family'],
            (int)$s['font_size_pt'],
            (string)$s['line_height']
        );
    }

    public static function frozenCoverInlineStyle(): string
    {
        $s = self::SPEC;

        return sprintf(
            'width:%dpx;min-height:%dpx;max-width:%dpx;'
            . 'font-family:%s;font-size:%dpt;line-height:%s;box-sizing:border-box;overflow:visible;',
            (int)$s['page_width_px'],
            (int)$s['page_height_px'],
            (int)$s['page_width_px'],
            (string)$s['font_family'],
            (int)$s['font_size_pt'],
            (string)$s['line_height']
        );
    }
}
