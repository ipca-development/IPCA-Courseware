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
        $this->drawCategory($canvas, $meta, $regular, 15, (int)round($width / 2), 88, $maxText, true);
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
        $this->drawCategory($canvas, $meta, $regular, 18, 160, 42, 700, false);
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
        $this->drawCategory($canvas, $meta, $regular, 16, 40, 100, $maxText, false);
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
        $this->drawCategory($canvas, $meta, $regular, 16, 40, 120, $maxText, false);
        $this->drawText($canvas, $this->displayTitle((string)($meta['title'] ?? '')), $font, 38, 40, 168, 255, 1.0, $maxText, 3, false, 0);
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
        $this->drawCategory($canvas, $meta, $regular, 16, 160, $height - 108, 520, false);
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
        $photo = @imagecreatefromstring($backgroundBytes);
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
     * @param resource|\GdImage $canvas
     */
    private function drawLogo($canvas, int $x, int $y, ?string $font, bool $center): void
    {
        $this->drawText($canvas, 'IPCA', $font, $center ? 28 : 32, $x, $y, 255, 1.0, 400, 1, $center, 1);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        if ($center) {
            $width = 72;
            imageline($canvas, $x - (int)round($width / 2), $y + 18, $x + (int)round($width / 2), $y + 18, $white);
            return;
        }
        imageline($canvas, $x, $y + 20, $x + 86, $y + 20, $white);
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
