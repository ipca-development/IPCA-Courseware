<?php
declare(strict_types=1);

require_once __DIR__ . '/CommunicationConfigService.php';
require_once __DIR__ . '/CommunicationObjectStore.php';
require_once __DIR__ . '/CommunicationSupport.php';
require_once __DIR__ . '/CommunicationTrainingThumbnailRenderer.php';
require_once dirname(__DIR__) . '/openai.php';

/**
 * Private IPCA Media Library for training-video stills.
 * Isolated from messaging, Community CDN, and chat attachments.
 */
final class CommunicationTrainingMediaLibraryService
{
    public const GET_EXPIRES = 300;
    public const MAX_IMAGE_BYTES = 15728640;
    public const IMAGE_MIME = array('image/jpeg', 'image/jpg', 'image/png', 'image/webp');

    public function __construct(
        private PDO $pdo,
        private CommunicationObjectStore $store
    ) {
    }

    /** @return array<string,mixed> */
    public function adminList(string $orientation = '', int $limit = 120): array
    {
        $this->requireTable();
        $limit = max(1, min(400, $limit));
        $sql = 'SELECT * FROM ipca_training_media_library WHERE deleted_at_utc IS NULL';
        $params = array();
        $orientation = strtolower(trim($orientation));
        if ($orientation === 'landscape' || $orientation === 'portrait' || $orientation === 'square') {
            $sql .= ' AND orientation = ?';
            $params[] = $orientation;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $assets = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assets[] = $this->adminAsset($row);
        }
        return array('assets' => $assets);
    }

    /**
     * @param resource $stream
     * @return array<string,mixed>
     */
    public function putAdminAsset(
        $stream,
        string $mimeType,
        int $byteSize,
        string $filename,
        int $adminUserId
    ): array {
        $this->requireTable();
        $mimeType = strtolower(trim($mimeType));
        $mimeType = preg_replace('/;.*$/', '', $mimeType) ?? $mimeType;
        if (!in_array($mimeType, self::IMAGE_MIME, true)) {
            throw new CommunicationException('validation_error', 'Upload a JPEG, PNG, or WebP photograph.', 400);
        }
        if ($byteSize < 1 || $byteSize > self::MAX_IMAGE_BYTES) {
            throw new CommunicationException('validation_error', 'That photograph is too large.', 400);
        }
        $bytes = stream_get_contents($stream);
        if (!is_string($bytes) || $bytes === '') {
            throw new CommunicationException('validation_error', 'The upload was empty.', 400);
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
            throw new CommunicationException('validation_error', 'That file is not a readable image.', 400);
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        $orientation = CommunicationTrainingThumbnailRenderer::orientationFromDimensions($width, $height);
        if ($orientation === '') {
            $orientation = 'landscape';
        }
        $assetUuid = CommunicationSupport::uuid();
        $key = 'media-library/1/' . $assetUuid . '.photo';
        $memory = fopen('php://memory', 'rb+');
        if ($memory === false) {
            throw new RuntimeException('Could not buffer the photograph.');
        }
        fwrite($memory, $bytes);
        rewind($memory);
        $this->store->putStream($key, $memory, strlen($bytes), $mimeType);
        fclose($memory);

        $analysis = $this->analyzePhotograph($bytes, $width, $height, $filename, $orientation, $key);
        $now = CommunicationSupport::nowUtc();
        $this->pdo->prepare(
            'INSERT INTO ipca_training_media_library
                (asset_uuid, storage_key, original_filename, mime_type, byte_size, width, height, orientation,
                 analysis_json, analysis_text, analysis_status, created_by_user_id, created_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array(
            $assetUuid,
            $key,
            mb_substr(trim($filename), 0, 255),
            $mimeType,
            strlen($bytes),
            $width,
            $height,
            $orientation,
            json_encode($analysis['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $analysis['text'],
            $analysis['status'],
            $adminUserId,
            $now,
        ));
        return array('asset' => $this->adminAsset($this->requireAsset($assetUuid)));
    }

    /** @return array<string,mixed> */
    public function deleteAdmin(string $assetUuid): array
    {
        $row = $this->requireAsset($assetUuid);
        $this->pdo->prepare(
            'UPDATE ipca_training_media_library SET deleted_at_utc = ? WHERE id = ?'
        )->execute(array(CommunicationSupport::nowUtc(), (int)$row['id']));
        return array('deleted' => true, 'asset_uuid' => $assetUuid);
    }

    /**
     * Rank Media Library photographs for a training video.
     * Orientation is a hard filter: opposite-orientation and square sources are never used.
     * Used or similar photographs are skipped so two videos do not share a still.
     *
     * @param array{title?:string,description?:string,category?:string,aircraft?:string,program?:string} $context
     * @param list<int> $excludeAssetIds
     * @param list<string> $excludePhashes
     * @return list<array<string,mixed>>
     */
    public function rankForVideo(
        array $context,
        string $orientation,
        int $limit = 3,
        array $excludeAssetIds = array(),
        array $excludePhashes = array()
    ): array {
        if (!$this->tableExists()) {
            return array();
        }
        $orientation = CommunicationTrainingThumbnailRenderer::videoOrientation(
            $orientation === 'portrait' ? 9 : 16,
            $orientation === 'portrait' ? 16 : 9
        );
        $excludeLookup = array();
        foreach ($excludeAssetIds as $id) {
            $excludeLookup[(int)$id] = true;
        }
        $stmt = $this->pdo->query(
            'SELECT * FROM ipca_training_media_library WHERE deleted_at_utc IS NULL ORDER BY id DESC LIMIT 400'
        );
        $scored = array();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assetOrientation = strtolower(trim((string)$row['orientation']));
            if ($assetOrientation !== $orientation) {
                continue;
            }
            if (isset($excludeLookup[(int)$row['id']])) {
                continue;
            }
            $analysis = json_decode((string)($row['analysis_json'] ?? ''), true);
            $phash = is_array($analysis) ? (string)($analysis['phash'] ?? '') : '';
            if ($this->phashTooSimilar($phash, $excludePhashes)) {
                continue;
            }
            $scored[] = array('row' => $row, 'score' => $this->semanticScore($context, $row));
        }
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $shortlist = array_slice($scored, 0, 12);
        $ranked = $this->maybeAiRerank($context, $orientation, $shortlist);
        $out = array();
        foreach (array_slice($ranked, 0, max(1, $limit)) as $item) {
            $asset = $this->adminAsset($item['row']);
            $asset['score'] = round((float)$item['score'], 4);
            $out[] = $asset;
        }
        return $out;
    }

    public function getImageBytes(string $assetUuid): ?string
    {
        $row = $this->findByUuid($assetUuid);
        if ($row === null) {
            return null;
        }
        return $this->store->getBytes((string)$row['storage_key']);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $assetUuid): ?array
    {
        if (!$this->tableExists() || !CommunicationSupport::isUuid($assetUuid)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_training_media_library WHERE asset_uuid = ? AND deleted_at_utc IS NULL LIMIT 1'
        );
        $stmt->execute(array(strtolower(trim($assetUuid))));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        if (!$this->tableExists() || $id < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ipca_training_media_library WHERE id = ? AND deleted_at_utc IS NULL LIMIT 1'
        );
        $stmt->execute(array($id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Reserved AI-generated-background hook. Returns null until a later
     * background model is wired. Must never draw IPCA typography or layout.
     *
     * @param array<string,mixed> $context
     */
    public function generateAiBackground(array $context, string $orientation): ?string
    {
        unset($context, $orientation);
        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function adminAsset(array $row): array
    {
        $analysis = json_decode((string)($row['analysis_json'] ?? ''), true);
        if (!is_array($analysis)) {
            $analysis = array();
        }
        $key = trim((string)($row['storage_key'] ?? ''));
        return array(
            'id' => (int)$row['id'],
            'asset_uuid' => (string)$row['asset_uuid'],
            'filename' => (string)($row['original_filename'] ?? ''),
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => (int)$row['byte_size'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'orientation' => (string)$row['orientation'],
            'analysis' => $analysis,
            'analysis_text' => (string)($row['analysis_text'] ?? ''),
            'analysis_status' => (string)($row['analysis_status'] ?? ''),
            'preview_url' => $key !== ''
                ? '/admin/api/media_library_preview.php?asset_uuid=' . rawurlencode((string)$row['asset_uuid'])
                : '',
            'created_at_utc' => (string)$row['created_at_utc'],
        );
    }

    /**
     * @return array{json:array<string,mixed>,text:string,status:string}
     */
    private function analyzePhotograph(
        string $bytes,
        int $width,
        int $height,
        string $filename,
        string $orientation,
        string $storageKey
    ): array {
        $fallback = $this->filenameAnalysis($bytes, $filename, $width, $height, $orientation);
        try {
            $ai = $this->openAiAnalyze($bytes, $filename, $orientation, $storageKey);
            if ($ai !== null) {
                $merged = array_merge($fallback['json'], $ai);
                $merged['width'] = $width;
                $merged['height'] = $height;
                $merged['orientation'] = $orientation;
                $merged['aspect_ratio'] = $height > 0 ? round($width / $height, 4) : 0;
                $merged['phash'] = (string)($fallback['json']['phash'] ?? '');
                $text = $this->analysisText($merged, $filename);
                return array('json' => $merged, 'text' => $text, 'status' => 'ready');
            }
        } catch (Throwable) {
            // Filename/dimension tags remain if the vision model is unavailable.
        }
        return $fallback;
    }

    /**
     * @return array{json:array<string,mixed>,text:string,status:string}
     */
    private function filenameAnalysis(string $bytes, string $filename, int $width, int $height, string $orientation): array
    {
        $stem = strtolower((string)pathinfo($filename, PATHINFO_FILENAME));
        $tokens = preg_split('/[^a-z0-9]+/i', $stem) ?: array();
        $tokens = array_values(array_filter($tokens, static fn(string $t): bool => strlen($t) > 1));
        $json = array(
            'aircraft' => array(),
            'setting' => array(),
            'people' => array(),
            'activity' => array(),
            'maneuver' => '',
            'avionics' => array(),
            'environment' => array(),
            'weather' => array(),
            'concepts' => $tokens,
            'subject' => $stem,
            'search_text' => implode(' ', $tokens),
            'focal_x' => 0.5,
            'focal_y' => $orientation === 'portrait' ? 0.38 : 0.48,
            'width' => $width,
            'height' => $height,
            'orientation' => $orientation,
            'aspect_ratio' => $height > 0 ? round($width / $height, 4) : 0,
            'phash' => $this->dHash($bytes),
        );
        return array(
            'json' => $json,
            'text' => $this->analysisText($json, $filename),
            'status' => 'filename',
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function openAiAnalyze(string $bytes, string $filename, string $orientation, string $storageKey): ?array
    {
        if (trim((string)getenv('CW_OPENAI_API_KEY')) === '') {
            return null;
        }
        $preview = $this->downscaleJpeg($bytes, 1024);
        if ($preview === null) {
            return null;
        }
        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'aircraft' => array('type' => 'array', 'items' => array('type' => 'string')),
                'setting' => array('type' => 'array', 'items' => array('type' => 'string')),
                'people' => array('type' => 'array', 'items' => array('type' => 'string')),
                'activity' => array('type' => 'array', 'items' => array('type' => 'string')),
                'maneuver' => array('type' => 'string'),
                'avionics' => array('type' => 'array', 'items' => array('type' => 'string')),
                'environment' => array('type' => 'array', 'items' => array('type' => 'string')),
                'weather' => array('type' => 'array', 'items' => array('type' => 'string')),
                'concepts' => array('type' => 'array', 'items' => array('type' => 'string')),
                'subject' => array('type' => 'string'),
                'search_text' => array('type' => 'string'),
                'focal_x' => array('type' => 'number'),
                'focal_y' => array('type' => 'number'),
            ),
            'required' => array(
                'aircraft', 'setting', 'people', 'activity', 'maneuver', 'avionics',
                'environment', 'weather', 'concepts', 'subject', 'search_text', 'focal_x', 'focal_y',
            ),
        );
        $instructions = 'Analyze this aviation photograph for an IPCA flight-school media library. '
            . 'Tag aircraft type if recognizable, setting (cockpit/exterior/classroom/ramp/sim), '
            . 'people (student/instructor), maneuver or activity, avionics/instruments, '
            . 'weather/environment, and other useful aviation concepts. '
            . 'Return focal_x/focal_y between 0 and 1 for the visual subject. '
            . 'search_text must be a dense keyword string. Do not invent a thumbnail layout.';
        $payload = array(
            'model' => cw_openai_model(),
            'input' => array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => $instructions))),
                array('role' => 'user', 'content' => array(
                    array(
                        'type' => 'input_text',
                        'text' => 'Filename: ' . $filename . '. Apparent orientation: ' . $orientation . '.',
                    ),
                    array('type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . base64_encode($preview)),
                )),
            ),
            'text' => array(
                'format' => array(
                    'type' => 'json_schema',
                    'name' => 'ipca_media_library_tags_v1',
                    'schema' => $schema,
                    'strict' => true,
                ),
            ),
        );
        unset($storageKey);
        $resp = cw_openai_responses($payload, 90);
        $json = cw_openai_extract_json_text($resp);
        return $json === array() ? null : $json;
    }

    /**
     * @param array{title?:string,description?:string,category?:string,aircraft?:string,program?:string} $context
     * @param list<array{row:array<string,mixed>,score:float}> $shortlist
     * @return list<array{row:array<string,mixed>,score:float}>
     */
    private function maybeAiRerank(array $context, string $orientation, array $shortlist): array
    {
        if (count($shortlist) < 2 || trim((string)getenv('CW_OPENAI_API_KEY')) === '') {
            return $shortlist;
        }
        $candidates = array();
        foreach ($shortlist as $item) {
            $row = $item['row'];
            $candidates[] = array(
                'asset_uuid' => (string)$row['asset_uuid'],
                'orientation' => (string)$row['orientation'],
                'filename' => (string)$row['original_filename'],
                'analysis_text' => mb_substr((string)$row['analysis_text'], 0, 400),
            );
        }
        try {
            $schema = array(
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array(
                    'asset_uuids' => array('type' => 'array', 'items' => array('type' => 'string')),
                ),
                'required' => array('asset_uuids'),
            );
            $prompt = "Rank these IPCA Media Library photographs for a training video.\n"
                . 'Orientation already filtered to: ' . $orientation . ".\n"
                . 'Title: ' . (string)($context['title'] ?? '') . "\n"
                . 'Description: ' . (string)($context['description'] ?? '') . "\n"
                . 'Category: ' . (string)($context['category'] ?? '') . "\n"
                . 'Aircraft: ' . (string)($context['aircraft'] ?? '') . "\n"
                . 'Program: ' . (string)($context['program'] ?? '') . "\n"
                . 'Candidates JSON: ' . json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
                . 'Return the best asset_uuids, best first, using only the given UUIDs. Semantic fit matters more than filename.';
            $payload = array(
                'model' => cw_openai_model(),
                'input' => $prompt,
                'text' => array(
                    'format' => array(
                        'type' => 'json_schema',
                        'name' => 'ipca_thumbnail_rank_v1',
                        'schema' => $schema,
                        'strict' => true,
                    ),
                ),
            );
            $resp = cw_openai_responses($payload, 60);
            $json = cw_openai_extract_json_text($resp);
            $order = $json['asset_uuids'] ?? array();
            if (!is_array($order) || $order === array()) {
                return $shortlist;
            }
            $byUuid = array();
            foreach ($shortlist as $item) {
                $byUuid[(string)$item['row']['asset_uuid']] = $item;
            }
            $ranked = array();
            $bonus = count($order);
            foreach ($order as $uuid) {
                $uuid = strtolower(trim((string)$uuid));
                if (!isset($byUuid[$uuid])) {
                    continue;
                }
                $item = $byUuid[$uuid];
                $item['score'] = (float)$item['score'] + ($bonus * 10);
                $ranked[] = $item;
                unset($byUuid[$uuid]);
                $bonus--;
            }
            foreach ($byUuid as $item) {
                $ranked[] = $item;
            }
            return $ranked;
        } catch (Throwable) {
            return $shortlist;
        }
    }

    /**
     * @param array{title?:string,description?:string,category?:string,aircraft?:string,program?:string} $context
     * @param array<string,mixed> $row
     */
    private function semanticScore(array $context, array $row): float
    {
        $haystack = strtolower(
            (string)($row['analysis_text'] ?? '') . ' ' . (string)($row['original_filename'] ?? '')
        );
        $needles = $this->tokens(implode(' ', array(
            (string)($context['title'] ?? ''),
            (string)($context['description'] ?? ''),
            (string)($context['category'] ?? ''),
            (string)($context['aircraft'] ?? ''),
            (string)($context['program'] ?? ''),
        )));
        if ($needles === array()) {
            return 0.15;
        }
        $score = 0.0;
        $hits = 0;
        foreach ($needles as $token) {
            if ($token === '') {
                continue;
            }
            if (str_contains($haystack, $token)) {
                $hits++;
                $score += strlen($token) >= 5 ? 1.6 : 1.0;
            }
        }
        $coverage = $hits / max(1, count($needles));
        return $score + ($coverage * 2.0);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: array();
        $stop = array(
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'video', 'training',
            'ipca', 'a', 'an', 'of', 'to', 'in', 'on', 'at', 'is', 'are',
        );
        $out = array();
        foreach ($parts as $part) {
            if (strlen($part) < 3 || in_array($part, $stop, true)) {
                continue;
            }
            $out[] = $part;
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $json
     */
    private function analysisText(array $json, string $filename): string
    {
        $chunks = array((string)pathinfo($filename, PATHINFO_FILENAME));
        foreach (array('aircraft', 'setting', 'people', 'activity', 'avionics', 'environment', 'weather', 'concepts') as $key) {
            $value = $json[$key] ?? array();
            if (is_array($value)) {
                foreach ($value as $item) {
                    $chunks[] = (string)$item;
                }
            } elseif (is_string($value) && $value !== '') {
                $chunks[] = $value;
            }
        }
        foreach (array('maneuver', 'subject', 'search_text') as $key) {
            if (trim((string)($json[$key] ?? '')) !== '') {
                $chunks[] = (string)$json[$key];
            }
        }
        return strtolower(trim(implode(' ', $chunks)));
    }

    /**
     * @param list<string> $excludePhashes
     */
    private function phashTooSimilar(string $phash, array $excludePhashes): bool
    {
        if (!$this->phashUsable($phash)) {
            return false;
        }
        foreach ($excludePhashes as $other) {
            $other = strtolower(trim((string)$other));
            if (!$this->phashUsable($other)) {
                continue;
            }
            if ($this->hammingHex($phash, $other) <= 10) {
                return true;
            }
        }
        return false;
    }

    private function phashUsable(string $phash): bool
    {
        $phash = strtolower(trim($phash));
        if ($phash === '' || !preg_match('/^[0-9a-f]{16}$/', $phash)) {
            return false;
        }
        return $phash !== '0000000000000000' && $phash !== 'ffffffffffffffff';
    }

    private function hammingHex(string $a, string $b): int
    {
        $aBin = hex2bin($a);
        $bBin = hex2bin($b);
        if (!is_string($aBin) || !is_string($bBin) || strlen($aBin) !== strlen($bBin)) {
            return 64;
        }
        $distance = 0;
        $len = strlen($aBin);
        for ($i = 0; $i < $len; $i++) {
            $xor = ord($aBin[$i]) ^ ord($bBin[$i]);
            $distance += substr_count(decbin($xor), '1');
        }
        return $distance;
    }

    private function dHash(string $bytes): string
    {
        if (!extension_loaded('gd')) {
            return '';
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return '';
        }
        $small = imagecreatetruecolor(9, 8);
        if ($small === false) {
            return '';
        }
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = imagecolorat($small, $x, $y);
                $right = imagecolorat($small, $x + 1, $y);
                $leftY = $this->luma($left);
                $rightY = $this->luma($right);
                $bits .= $leftY > $rightY ? '1' : '0';
            }
        }
        $hex = '';
        foreach (str_split($bits, 8) as $chunk) {
            $hex .= sprintf('%02x', bindec($chunk));
        }
        return $hex;
    }

    private function luma(int $color): int
    {
        $r = ($color >> 16) & 255;
        $g = ($color >> 8) & 255;
        $b = $color & 255;
        return (int)round((0.299 * $r) + (0.587 * $g) + (0.114 * $b));
    }

    private function downscaleJpeg(string $bytes, int $maxEdge): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, $maxEdge / max($width, $height, 1));
        $dstW = max(1, (int)round($width * $scale));
        $dstH = max(1, (int)round($height * $scale));
        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            return null;
        }
        imagecopyresampled($dst, $image, 0, 0, 0, 0, $dstW, $dstH, $width, $height);
        ob_start();
        imagejpeg($dst, null, 75);
        $out = (string)ob_get_clean();
        return $out !== '' ? $out : null;
    }

    /** @return array<string,mixed> */
    private function requireAsset(string $assetUuid): array
    {
        $row = $this->findByUuid($assetUuid);
        if ($row === null) {
            throw new CommunicationException('not_found', 'That photograph was not found.', 404);
        }
        return $row;
    }

    private function requireTable(): void
    {
        if (!$this->tableExists()) {
            throw new CommunicationException('not_found', 'The IPCA Media Library is not installed yet.', 404);
        }
    }

    private function tableExists(): bool
    {
        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->query(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ipca_training_media_library'"
                );
                return $stmt !== false && $stmt->fetchColumn() !== false;
            }
            $stmt = $this->pdo->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ipca_training_media_library'"
            );
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
