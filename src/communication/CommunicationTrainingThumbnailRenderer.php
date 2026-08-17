<?php
declare(strict_types=1);

/**
 * Locked IPCA training-video thumbnail templates.
 *
 * Application code owns EXACTLY how every thumbnail looks. AI must never
 * draw typography, the logo, or layout.
 */
final class CommunicationTrainingThumbnailRenderer
{
    public const LANDSCAPE = 'IPCA_ALPHA_LANDSCAPE_V1';
    public const PORTRAIT = 'IPCA_ALPHA_PORTRAIT_V1';
    public const PRIVATE_PILOT_LANDSCAPE = 'IPCA_PRIVATE_PILOT_LANDSCAPE_V1';
    public const PRIVATE_PILOT_PORTRAIT = 'IPCA_PRIVATE_PILOT_PORTRAIT_V1';
    public const INSTRUMENT_LANDSCAPE = 'IPCA_INSTRUMENT_LANDSCAPE_V1';
    public const INSTRUMENT_PORTRAIT = 'IPCA_INSTRUMENT_PORTRAIT_V1';
    public const COMMERCIAL_LANDSCAPE = 'IPCA_COMMERCIAL_LANDSCAPE_V1';
    public const COMMERCIAL_PORTRAIT = 'IPCA_COMMERCIAL_PORTRAIT_V1';
    public const CFI_LANDSCAPE = 'IPCA_CFI_LANDSCAPE_V1';
    public const CFI_PORTRAIT = 'IPCA_CFI_PORTRAIT_V1';
    public const SYSTEMS_LANDSCAPE = 'IPCA_SYSTEMS_LANDSCAPE_V1';
    public const SYSTEMS_PORTRAIT = 'IPCA_SYSTEMS_PORTRAIT_V1';
    public const LANDSCAPE_WIDTH = 1280;
    public const LANDSCAPE_HEIGHT = 720;
    public const PORTRAIT_WIDTH = 720;
    public const PORTRAIT_HEIGHT = 1280;
    public const BRAND_LINE = 'ALPHA TRAINER PRO';

    /**
     * @param array{title?:string,category?:string,focal_x?:float,focal_y?:float} $meta
     */
    public function render(string $template, array $meta, ?string $backgroundBytes = null): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD is required to render IPCA thumbnails.');
        }
        $template = strtoupper(trim($template));
        return match ($template) {
            self::PORTRAIT => $this->renderPortrait($meta, $backgroundBytes),
            self::PRIVATE_PILOT_LANDSCAPE => $this->renderPrivatePilotLandscape($meta, $backgroundBytes),
            self::PRIVATE_PILOT_PORTRAIT => $this->renderPrivatePilotPortrait($meta, $backgroundBytes),
            self::INSTRUMENT_LANDSCAPE => $this->renderInstrumentLandscape($meta, $backgroundBytes),
            self::INSTRUMENT_PORTRAIT => $this->renderInstrumentPortrait($meta, $backgroundBytes),
            self::COMMERCIAL_LANDSCAPE => $this->renderCommercialLandscape($meta, $backgroundBytes),
            self::COMMERCIAL_PORTRAIT => $this->renderCommercialPortrait($meta, $backgroundBytes),
            self::CFI_LANDSCAPE => $this->renderCfiLandscape($meta, $backgroundBytes),
            self::CFI_PORTRAIT => $this->renderCfiPortrait($meta, $backgroundBytes),
            self::SYSTEMS_LANDSCAPE => $this->renderSystemsLandscape($meta, $backgroundBytes),
            self::SYSTEMS_PORTRAIT => $this->renderSystemsPortrait($meta, $backgroundBytes),
            default => $this->renderLandscape($meta, $backgroundBytes),
        };
    }

    public static function templateForOrientation(string $orientation): string
    {
        return strtolower(trim($orientation)) === 'portrait' ? self::PORTRAIT : self::LANDSCAPE;
    }

    public static function templateFor(string $orientation, string $categorySlug = ''): string
    {
        $portrait = strtolower(trim($orientation)) === 'portrait';
        return match (strtolower(trim($categorySlug))) {
            'private-pilot' => $portrait ? self::PRIVATE_PILOT_PORTRAIT : self::PRIVATE_PILOT_LANDSCAPE,
            'instrument' => $portrait ? self::INSTRUMENT_PORTRAIT : self::INSTRUMENT_LANDSCAPE,
            'commercial' => $portrait ? self::COMMERCIAL_PORTRAIT : self::COMMERCIAL_LANDSCAPE,
            'cfi' => $portrait ? self::CFI_PORTRAIT : self::CFI_LANDSCAPE,
            'systems' => $portrait ? self::SYSTEMS_PORTRAIT : self::SYSTEMS_LANDSCAPE,
            default => $portrait ? self::PORTRAIT : self::LANDSCAPE,
        };
    }

    public static function orientationFromDimensions(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            return '';
        }
        if ($height > $width) {
            return 'portrait';
        }
        if ($width > $height) {
            return 'landscape';
        }
        return 'square';
    }

    public static function videoOrientation(int $width, int $height): string
    {
        $orientation = self::orientationFromDimensions($width, $height);
        return $orientation === 'portrait' ? 'portrait' : 'landscape';
    }

    /**
     * Apply container rotation so a phone portrait file stored as 1920×1080
     * with rotate=90 is treated as 1080×1920.
     *
     * @return array{width:int,height:int}
     */
    public static function displayDimensions(int $width, int $height, int $rotationDegrees): array
    {
        $width = max(0, $width);
        $height = max(0, $height);
        if ($width < 1 || $height < 1) {
            return array('width' => $width, 'height' => $height);
        }
        $quarter = ((int)round($rotationDegrees / 90) % 4 + 4) % 4;
        if ($quarter === 1 || $quarter === 3) {
            return array('width' => $height, 'height' => $width);
        }
        return array('width' => $width, 'height' => $height);
    }

    /**
     * HandBrake portrait exports are often 1920×1080 with SAR 81:256 / DAR 9:16.
     *
     * @return array{width:int,height:int}
     */
    public static function displaySizeFromProbe(
        int $codedWidth,
        int $codedHeight,
        int $rotationDegrees = 0,
        string $sampleAspectRatio = '1:1',
        string $displayAspectRatio = ''
    ): array {
        $size = self::displayDimensions($codedWidth, $codedHeight, $rotationDegrees);
        $quarter = ((int)round($rotationDegrees / 90) % 4 + 4) % 4;
        if ($quarter === 1 || $quarter === 3) {
            return $size;
        }
        $dar = self::parseRatio($displayAspectRatio);
        if ($dar !== null) {
            $dispW = (int)round($size['height'] * $dar[0] / $dar[1]);
            if ($dispW < 1) {
                $dispW = $size['width'];
            }
            return array('width' => $dispW, 'height' => $size['height']);
        }
        $sar = self::parseRatio($sampleAspectRatio);
        if ($sar !== null && $sar[0] !== $sar[1]) {
            return array(
                'width' => max(1, (int)round($size['width'] * $sar[0] / $sar[1])),
                'height' => $size['height'],
            );
        }
        return $size;
    }

    /**
     * iPhone stills are often 4032×3024 with EXIF Orientation 6 (display as 3024×4032).
     *
     * @return array{width:int,height:int}
     */
    public static function displaySizeFromImageBytes(string $bytes, int $codedWidth, int $codedHeight): array
    {
        $exif = self::jpegExifOrientation($bytes);
        $swap = $exif === 5 || $exif === 6 || $exif === 7 || $exif === 8;
        return self::displayDimensions($codedWidth, $codedHeight, $swap ? 90 : 0);
    }

    public static function jpegExifOrientation(string $bytes): int
    {
        if (strlen($bytes) < 4 || $bytes[0] !== "\xff" || $bytes[1] !== "\xd8") {
            return 1;
        }
        $length = strlen($bytes);
        $offset = 2;
        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xff") {
                break;
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            if ($marker === 0x00 || $marker === 0xFF) {
                $offset++;
                continue;
            }
            $size = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            if ($size < 2 || $offset + 2 + $size > $length) {
                break;
            }
            if ($marker === 0xE1) {
                $orientation = self::tiffOrientation(substr($bytes, $offset + 4, $size - 2));
                if ($orientation !== null) {
                    return $orientation;
                }
            }
            $offset += 2 + $size;
        }
        return 1;
    }

    /**
     * @return resource|\GdImage|false
     */
    public static function imageFromBytes(string $bytes)
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return false;
        }
        return self::applyExifOrientation($image, self::jpegExifOrientation($bytes));
    }

    /**
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    public static function applyExifOrientation($image, int $exifOrientation)
    {
        $rotated = match ($exifOrientation) {
            2 => self::flipImage($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => self::flipImage($image, IMG_FLIP_VERTICAL),
            5 => imagerotate(self::flipImage($image, IMG_FLIP_VERTICAL), -90, 0),
            6 => imagerotate($image, -90, 0),
            7 => imagerotate(self::flipImage($image, IMG_FLIP_VERTICAL), 90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        return $rotated === false ? $image : $rotated;
    }

    /**
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    private static function flipImage($image, int $mode)
    {
        imageflip($image, $mode);
        return $image;
    }

    private static function tiffOrientation(string $payload): ?int
    {
        if (!str_starts_with($payload, "Exif\0\0")) {
            return null;
        }
        $tiff = substr($payload, 6);
        if (strlen($tiff) < 8) {
            return null;
        }
        $little = str_starts_with($tiff, 'II');
        if (!$little && !str_starts_with($tiff, 'MM')) {
            return null;
        }
        $u16 = static function (string $data, int $offset) use ($little): int {
            $raw = substr($data, $offset, 2);
            if (strlen($raw) < 2) {
                return 0;
            }
            $unpacked = unpack($little ? 'v' : 'n', $raw);
            return (int)($unpacked[1] ?? 0);
        };
        $u32 = static function (string $data, int $offset) use ($little): int {
            $raw = substr($data, $offset, 4);
            if (strlen($raw) < 4) {
                return 0;
            }
            $unpacked = unpack($little ? 'V' : 'N', $raw);
            return (int)($unpacked[1] ?? 0);
        };
        if ($u16($tiff, 2) !== 42) {
            return null;
        }
        $ifd = $u32($tiff, 4);
        if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
            return null;
        }
        $count = $u16($tiff, $ifd);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + ($i * 12);
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if ($u16($tiff, $entry) !== 0x0112) {
                continue;
            }
            $value = $u16($tiff, $entry + 8);
            return ($value >= 1 && $value <= 8) ? $value : 1;
        }
        return null;
    }

    /**
     * @return array{0:float,1:float}|null
     */
    private static function parseRatio(string $ratio): ?array
    {
        $ratio = trim($ratio);
        if ($ratio === '' || strtoupper($ratio) === 'N/A') {
            return null;
        }
        if (!preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $ratio, $match)) {
            return null;
        }
        $a = (float)$match[1];
        $b = (float)$match[2];
        if ($a <= 0 || $b <= 0) {
            return null;
        }
        return array($a, $b);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderLandscape(array $meta, ?string $backgroundBytes): string
    {
        $width = self::LANDSCAPE_WIDTH;
        $height = self::LANDSCAPE_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.48));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.42);
        $this->overlayBottomBar($canvas, $width, $height, 64);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 48, 40, $font, false);
        $category = strtoupper(trim((string)($meta['category'] ?? '')));
        $titleTop = 430;
        if ($category !== '') {
            $this->drawText($canvas, $category, $regular, 18, 48, 418, 255, 0.72, 720, 1, false, 4);
            $titleTop = 458;
        }
        $this->drawText(
            $canvas,
            $this->displayTitle((string)($meta['title'] ?? '')),
            $font,
            54,
            48,
            $titleTop,
            255,
            1.0,
            900,
            2,
            false,
            0
        );
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 15, $width / 2, $height - 26, true);
        return $this->jpeg($canvas);
    }

    /**
     * Independently composed 9:16 master. Not a crop of the 16:9 layout.
     *
     * @param array<string,mixed> $meta
     */
    private function renderPortrait(array $meta, ?string $backgroundBytes): string
    {
        $width = self::PORTRAIT_WIDTH;
        $height = self::PORTRAIT_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.38));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.38);
        $this->overlayBottomBar($canvas, $width, $height, 88);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, (int)round($width / 2), 56, $font, true);
        $category = strtoupper(trim((string)($meta['category'] ?? '')));
        $titleTop = 820;
        $maxText = $width - 80;
        if ($category !== '') {
            $this->drawText($canvas, $category, $regular, 16, (int)round($width / 2), 796, 255, 0.72, $maxText, 1, true, 3);
            $titleTop = 838;
        }
        $this->drawText(
            $canvas,
            $this->displayTitle((string)($meta['title'] ?? '')),
            $font,
            42,
            (int)round($width / 2),
            $titleTop,
            255,
            1.0,
            $maxText,
            3,
            true,
            0
        );
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, $width / 2, $height - 38, true);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderPrivatePilotLandscape(array $meta, ?string $backgroundBytes): string
    {
        $width = self::LANDSCAPE_WIDTH;
        $height = self::LANDSCAPE_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.48));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.46);
        $this->overlayAccentBar($canvas, 0, 0, 14, $height);
        $this->overlayBottomBar($canvas, $width, $height, 58);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 40, 36, $font, false);
        $this->drawCategory($canvas, $meta, $regular, 16, 40, 430, 720, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 50, 40, 458, 255, 1.0, 900, 2, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, $width - 48, $height - 24, false);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderPrivatePilotPortrait(array $meta, ?string $backgroundBytes): string
    {
        $width = self::PORTRAIT_WIDTH;
        $height = self::PORTRAIT_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.38));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.42);
        $this->overlayAccentBar($canvas, 0, 0, 12, $height);
        $this->overlayBottomBar($canvas, $width, $height, 80);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 40, 48, $font, false);
        $maxText = $width - 80;
        $this->drawCategory($canvas, $meta, $regular, 15, 40, 820, $maxText, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 40, 40, 852, 255, 1.0, $maxText, 3, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, $width / 2, $height - 36, true);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderInstrumentLandscape(array $meta, ?string $backgroundBytes): string
    {
        $width = self::LANDSCAPE_WIDTH;
        $height = self::LANDSCAPE_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.52));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.50);
        $this->overlayTopBand($canvas, $width, 78);
        $this->overlayBottomBar($canvas, $width, $height, 52);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 40, 28, $font, false);
        $this->drawCategory($canvas, $meta, $regular, 16, 400, 34, 520, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 48, 40, 560, 255, 1.0, 980, 2, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, 40, $height - 22, false);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderInstrumentPortrait(array $meta, ?string $backgroundBytes): string
    {
        $width = self::PORTRAIT_WIDTH;
        $height = self::PORTRAIT_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.42));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.48);
        $this->overlayTopBand($canvas, $width, 110);
        $this->overlayBottomBar($canvas, $width, $height, 72);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, (int)round($width / 2), 36, $font, true);
        $maxText = $width - 80;
        $this->drawCategory($canvas, $meta, $regular, 15, (int)round($width / 2), 160, $maxText, true);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 40, 40, 900, 255, 1.0, $maxText, 3, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, 40, $height - 34, false);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderCommercialLandscape(array $meta, ?string $backgroundBytes): string
    {
        $width = self::LANDSCAPE_WIDTH;
        $height = self::LANDSCAPE_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.48));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.40);
        $this->overlayTopBand($canvas, $width, 96);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 40, 34, $font, false);
        $this->drawCategory($canvas, $meta, $regular, 18, 228, 42, 700, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 52, 40, 520, 255, 1.0, 980, 2, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 15, $width - 48, $height - 28, false);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderCommercialPortrait(array $meta, ?string $backgroundBytes): string
    {
        $width = self::PORTRAIT_WIDTH;
        $height = self::PORTRAIT_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.36));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.36);
        $this->overlayTopBand($canvas, $width, 140);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 40, 44, $font, false);
        $maxText = $width - 80;
        $this->drawCategory($canvas, $meta, $regular, 16, 40, 140, $maxText, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 42, 40, 860, 255, 1.0, $maxText, 3, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, $width - 40, $height - 36, false);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderCfiLandscape(array $meta, ?string $backgroundBytes): string
    {
        $width = self::LANDSCAPE_WIDTH;
        $height = self::LANDSCAPE_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.62), (float)($meta['focal_y'] ?? 0.48));
        $this->overlayLeftPanel($canvas, 360, $height);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 36, 40, $font, false);
        $this->drawCategory($canvas, $meta, $regular, 16, 36, 240, 300, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 36, 36, 278, 255, 1.0, 300, 4, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 13, 36, $height - 28, false);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderCfiPortrait(array $meta, ?string $backgroundBytes): string
    {
        $width = self::PORTRAIT_WIDTH;
        $height = self::PORTRAIT_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.48));
        $this->overlayTopBand($canvas, $width, 240);
        $this->overlayBottomBar($canvas, $width, $height, 84);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawLogo($canvas, 40, 48, $font, false);
        $maxText = $width - 80;
        $this->drawCategory($canvas, $meta, $regular, 16, 40, 148, $maxText, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 38, 40, 200, 255, 1.0, $maxText, 3, false, 0);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, $width / 2, $height - 36, true);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderSystemsLandscape(array $meta, ?string $backgroundBytes): string
    {
        $width = self::LANDSCAPE_WIDTH;
        $height = self::LANDSCAPE_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.42));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.34);
        $this->overlayBottomBar($canvas, $width, $height, 148);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 48, 40, 500, 255, 1.0, 980, 2, false, 0);
        $this->drawLogo($canvas, 40, $height - 118, $font, false);
        $this->drawCategory($canvas, $meta, $regular, 16, 228, $height - 108, 520, false);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, $width - 48, $height - 28, false);
        return $this->jpeg($canvas);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function renderSystemsPortrait(array $meta, ?string $backgroundBytes): string
    {
        $width = self::PORTRAIT_WIDTH;
        $height = self::PORTRAIT_HEIGHT;
        $canvas = $this->baseCanvas($width, $height, $backgroundBytes, (float)($meta['focal_x'] ?? 0.5), (float)($meta['focal_y'] ?? 0.34));
        $this->overlayVerticalGradient($canvas, $width, $height, 0.30);
        $this->overlayBottomBar($canvas, $width, $height, 220);
        $font = $this->fontPath(true);
        $regular = $this->fontPath(false) ?? $font;
        $maxText = $width - 80;
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 40, 40, 920, 255, 1.0, $maxText, 3, false, 0);
        $this->drawLogo($canvas, 40, $height - 176, $font, false);
        $this->drawCategory($canvas, $meta, $regular, 16, 40, $height - 118, $maxText, false);
        $this->drawTrackedLine($canvas, self::BRAND_LINE, $regular, 14, $width / 2, $height - 36, true);
        return $this->jpeg($canvas);
    }

    /**
     * @param resource|\GdImage $canvas
     * @param array<string,mixed> $meta
     */
    private function drawCategory($canvas, array $meta, ?string $font, int $size, int $x, int $y, int $maxWidth, bool $center): void
    {
        $category = strtoupper(trim((string)($meta['category'] ?? '')));
        if ($category === '') {
            return;
        }
        $this->drawText($canvas, $category, $font, $size, $x, $y, 255, 0.72, $maxWidth, 1, $center, 3);
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function overlayAccentBar($canvas, int $x, int $y, int $barWidth, int $height): void
    {
        $color = imagecolorallocate($canvas, 7, 27, 53);
        imagefilledrectangle($canvas, $x, $y, $x + $barWidth, $y + $height, $color);
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function overlayTopBand($canvas, int $width, int $bandHeight): void
    {
        $color = imagecolorallocatealpha($canvas, 5, 18, 38, 18);
        imagefilledrectangle($canvas, 0, 0, $width, $bandHeight, $color);
        $line = imagecolorallocatealpha($canvas, 255, 255, 255, 96);
        imageline($canvas, 40, $bandHeight, $width - 40, $bandHeight, $line);
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function overlayLeftPanel($canvas, int $panelWidth, int $height): void
    {
        $color = imagecolorallocatealpha($canvas, 5, 18, 38, 12);
        imagefilledrectangle($canvas, 0, 0, $panelWidth, $height, $color);
        $line = imagecolorallocatealpha($canvas, 255, 255, 255, 96);
        imageline($canvas, $panelWidth, 36, $panelWidth, $height - 36, $line);
    }

    /**
     * @return resource|\GdImage
     */
    private function baseCanvas(int $width, int $height, ?string $backgroundBytes, float $focalX, float $focalY)
    {
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new RuntimeException('Could not create a thumbnail canvas.');
        }
        imagealphablending($canvas, true);
        $navy = imagecolorallocate($canvas, 7, 27, 53);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $navy);
        $this->fillNavyGradient($canvas, $width, $height);
        if ($backgroundBytes === null || $backgroundBytes === '') {
            return $canvas;
        }
        $photo = CommunicationTrainingThumbnailRenderer::imageFromBytes($backgroundBytes);
        if ($photo === false) {
            return $canvas;
        }
        $srcW = imagesx($photo);
        $srcH = imagesy($photo);
        if ($srcW < 1 || $srcH < 1) {
            return $canvas;
        }
        $scale = max($width / $srcW, $height / $srcH);
        $cropW = (int)ceil($width / $scale);
        $cropH = (int)ceil($height / $scale);
        $focalX = max(0.0, min(1.0, $focalX));
        $focalY = max(0.0, min(1.0, $focalY));
        $srcX = (int)round(($srcW - $cropW) * $focalX);
        $srcY = (int)round(($srcH - $cropH) * $focalY);
        $srcX = max(0, min($srcW - $cropW, $srcX));
        $srcY = max(0, min($srcH - $cropH, $srcY));
        imagecopyresampled($canvas, $photo, 0, 0, $srcX, $srcY, $width, $height, $cropW, $cropH);
        return $canvas;
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function fillNavyGradient($canvas, int $width, int $height): void
    {
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            $r = (int)round(7 + (18 * $t));
            $g = (int)round(27 + (28 * $t));
            $b = (int)round(53 + (42 * $t));
            $color = imagecolorallocate($canvas, $r, $g, $b);
            imageline($canvas, 0, $y, $width, $y, $color);
        }
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function overlayVerticalGradient($canvas, int $width, int $height, float $startFraction): void
    {
        $start = (int)round($height * $startFraction);
        for ($y = $start; $y < $height; $y++) {
            $t = ($y - $start) / max(1, $height - $start);
            $alpha = (int)round(127 * (1 - min(1.0, 0.18 + ($t * 0.82))));
            $color = imagecolorallocatealpha($canvas, 7, 27, 53, max(0, min(127, $alpha)));
            imageline($canvas, 0, $y, $width, $y, $color);
        }
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function overlayBottomBar($canvas, int $width, int $height, int $barHeight): void
    {
        $color = imagecolorallocatealpha($canvas, 5, 18, 38, 20);
        imagefilledrectangle($canvas, 0, $height - $barHeight, $width, $height, $color);
        $line = imagecolorallocatealpha($canvas, 255, 255, 255, 96);
        imageline($canvas, 48, $height - $barHeight, $width - 48, $height - $barHeight, $line);
    }

    /**
     * Official IPCA lockup. Never draw the letters IPCA as type.
     *
     * @param resource|\GdImage $canvas
     */
    private function drawLogo($canvas, int $x, int $y, ?string $font, bool $center): void
    {
        unset($font);
        $path = $this->logoPath();
        if ($path === null) {
            return;
        }
        $logo = @imagecreatefrompng($path);
        if ($logo === false) {
            return;
        }
        imagealphablending($logo, true);
        imagesavealpha($logo, true);
        $srcW = imagesx($logo);
        $srcH = imagesy($logo);
        if ($srcW < 1 || $srcH < 1) {
            return;
        }
        $maxW = $center ? 240 : 168;
        $scale = $maxW / $srcW;
        $dstW = max(1, (int)round($srcW * $scale));
        $dstH = max(1, (int)round($srcH * $scale));
        $scaled = imagecreatetruecolor($dstW, $dstH);
        if ($scaled === false) {
            return;
        }
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $clear = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $dstW, $dstH, $clear);
        imagealphablending($scaled, true);
        imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        $dx = $center ? (int)round($x - ($dstW / 2)) : $x;
        imagealphablending($canvas, true);
        imagecopy($canvas, $scaled, $dx, $y, 0, 0, $dstW, $dstH);
    }

    private function logoPath(): ?string
    {
        $candidates = array(
            dirname(__DIR__, 2) . '/public/assets/logo/ipca_logo_white.png',
        );
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function drawText(
        $canvas,
        string $text,
        ?string $font,
        int $size,
        int $x,
        int $y,
        int $gray,
        float $alpha,
        int $maxWidth,
        int $maxLines,
        bool $center,
        int $tracking
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $color = imagecolorallocatealpha(
            $canvas,
            $gray,
            $gray,
            $gray,
            (int)round(127 * (1 - max(0.0, min(1.0, $alpha))))
        );
        if ($font === null || !function_exists('imagettftext')) {
            $lines = $this->wrapPlain($text, $maxLines, $maxWidth);
            foreach ($lines as $i => $line) {
                $px = $center ? max(8, $x - (int)round((strlen($line) * 7) / 2)) : $x;
                imagestring($canvas, 5, $px, $y + ($i * 18), $line, $color);
            }
            return;
        }
        $lines = array($text);
        while ($size >= 18) {
            $lines = $this->wrapTtf($text, $font, $size, $maxWidth, $maxLines, $tracking);
            if (count($lines) <= $maxLines && !$this->lineOverflows($lines, $font, $size, $maxWidth, $tracking)) {
                break;
            }
            $size -= 2;
        }
        $lineHeight = (int)round($size * 1.18);
        foreach ($lines as $i => $line) {
            $box = $this->ttfBox($font, $size, $line, $tracking);
            $px = $center ? (int)round($x - ($box['width'] / 2)) : $x;
            $py = $y + ($i * $lineHeight);
            $this->ttfDraw($canvas, $font, $size, $px, $py, $line, $color, $tracking);
        }
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function drawTrackedLine($canvas, string $text, ?string $font, int $size, float $centerX, int $baseline, bool $center): void
    {
        $this->drawText($canvas, $text, $font, $size, (int)round($centerX), $baseline - $size, 255, 0.92, 700, 1, $center, 6);
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function ttfDraw($canvas, string $font, int $size, int $x, int $y, string $text, int $color, int $tracking): void
    {
        if ($tracking <= 0) {
            imagettftext($canvas, $size, 0, $x, $y + $size, $color, $font, $text);
            return;
        }
        $cursor = $x;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        foreach ($chars as $char) {
            imagettftext($canvas, $size, 0, $cursor, $y + $size, $color, $font, $char);
            $box = imagettfbbox($size, 0, $font, $char);
            $charW = abs((int)$box[2] - (int)$box[0]);
            $cursor += $charW + $tracking;
        }
    }

    /**
     * @return array{width:int,height:int}
     */
    private function ttfBox(string $font, int $size, string $text, int $tracking): array
    {
        if ($tracking <= 0) {
            $box = imagettfbbox($size, 0, $font, $text);
            return array(
                'width' => abs((int)$box[2] - (int)$box[0]),
                'height' => abs((int)$box[7] - (int)$box[1]),
            );
        }
        $width = 0;
        $height = $size;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        foreach ($chars as $i => $char) {
            $box = imagettfbbox($size, 0, $font, $char);
            $width += abs((int)$box[2] - (int)$box[0]);
            if ($i > 0) {
                $width += $tracking;
            }
        }
        return array('width' => $width, 'height' => $height);
    }

    /**
     * @return list<string>
     */
    private function wrapTtf(string $text, string $font, int $size, int $maxWidth, int $maxLines, int $tracking): array
    {
        $words = preg_split('/\s+/', $text) ?: array($text);
        $lines = array();
        $current = '';
        foreach ($words as $word) {
            $attempt = $current === '' ? $word : $current . ' ' . $word;
            $width = $this->ttfBox($font, $size, $attempt, $tracking)['width'];
            if ($width <= $maxWidth || $current === '') {
                $current = $attempt;
                continue;
            }
            $lines[] = $current;
            $current = $word;
            if (count($lines) >= $maxLines) {
                break;
            }
        }
        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }
        return $lines === array() ? array($text) : $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function lineOverflows(array $lines, string $font, int $size, int $maxWidth, int $tracking): bool
    {
        foreach ($lines as $line) {
            if ($this->ttfBox($font, $size, $line, $tracking)['width'] > $maxWidth + 8) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function wrapPlain(string $text, int $maxLines, int $maxWidth): array
    {
        $chars = max(8, (int)floor($maxWidth / 8));
        $wrapped = explode("\n", wordwrap($text, $chars, "\n", true));
        return array_slice($wrapped, 0, max(1, $maxLines));
    }

    private function displayTitle(string $title): string
    {
        $title = trim($title);
        return $title === '' ? 'IPCA Training' : $title;
    }

    private function fontPath(bool $bold): ?string
    {
        $candidates = $bold
            ? array(
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
                '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
                '/Library/Fonts/Arial Bold.ttf',
                '/System/Library/Fonts/Supplemental/Arial.ttf',
            )
            : array(
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
                '/System/Library/Fonts/Supplemental/Arial.ttf',
                '/Library/Fonts/Arial.ttf',
            );
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * @param resource|\GdImage $canvas
     */
    private function jpeg($canvas): string
    {
        ob_start();
        imagejpeg($canvas, null, 88);
        $bytes = (string)ob_get_clean();
        if ($bytes === '') {
            throw new RuntimeException('Thumbnail rendering produced an empty image.');
        }
        return $bytes;
    }
}
