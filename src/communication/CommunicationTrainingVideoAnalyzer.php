<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/openai.php';
require_once __DIR__ . '/CommunicationObjectStore.php';
require_once __DIR__ . '/CommunicationException.php';

/**
 * Analyzes a private training video and writes a short student-facing
 * "what you will learn" explanation. Frames/audio are extracted when ffmpeg
 * is available; typography/layout is never generated here.
 */
final class CommunicationTrainingVideoAnalyzer
{
    public const MAX_CHARS = 420;

    public function __construct(private CommunicationObjectStore $store)
    {
    }

    /**
     * @param array<string,mixed> $video
     * @return array{explanation:string,used_video:bool,used_ai:bool}
     */
    public function explain(array $video): array
    {
        $context = $this->context($video);
        $probe = $this->probeVideo($video);
        $usedAi = false;
        $explanation = '';
        if (trim((string)getenv('CW_OPENAI_API_KEY')) !== '') {
            try {
                $explanation = $this->openAiExplain($context, $probe);
                $usedAi = $explanation !== '';
            } catch (Throwable) {
                $explanation = '';
            }
        }
        if ($explanation === '') {
            $explanation = $this->fallbackExplain($context);
        }
        return array(
            'explanation' => $this->limit($explanation),
            'used_video' => $probe['frames'] !== array() || $probe['transcript'] !== '',
            'used_ai' => $usedAi,
        );
    }

    /**
     * @param array<string,mixed> $video
     * @return array{title:string,description:string,category:string,aircraft:string,program:string,duration:string,orientation:string}
     */
    private function context(array $video): array
    {
        $ms = (int)($video['duration_ms'] ?? 0);
        $duration = '';
        if ($ms >= 1000) {
            $seconds = (int)round($ms / 1000);
            $duration = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
        }
        return array(
            'title' => trim((string)($video['title'] ?? '')),
            'description' => trim((string)($video['description'] ?? '')),
            'category' => trim((string)($video['category'] ?? '')),
            'aircraft' => trim((string)($video['aircraft'] ?? '')),
            'program' => trim((string)($video['program'] ?? '')),
            'duration' => $duration,
            'orientation' => trim((string)($video['orientation'] ?? '')),
        );
    }

    /**
     * @param array<string,mixed> $video
     * @return array{frames:list<string>,transcript:string}
     */
    private function probeVideo(array $video): array
    {
        $empty = array('frames' => array(), 'transcript' => '');
        $key = trim((string)($video['storage_key'] ?? ''));
        if ($key === '') {
            return $empty;
        }
        $ffmpeg = $this->ffmpegPath();
        if ($ffmpeg === null) {
            return $empty;
        }
        $url = $this->store->presignGet($key, 3600);
        $dir = rtrim(sys_get_temp_dir(), '/') . '/ipca-tv-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return $empty;
        }
        try {
            $durationSec = max(1, (int)round(((int)($video['duration_ms'] ?? 0)) / 1000));
            $stamps = $this->frameStamps($durationSec);
            $frames = array();
            foreach ($stamps as $i => $stamp) {
                $path = $dir . '/frame' . $i . '.jpg';
                $ok = $this->run(array(
                    $ffmpeg, '-hide_banner', '-loglevel', 'error',
                    '-ss', sprintf('%.2f', $stamp),
                    '-i', $url,
                    '-frames:v', '1',
                    '-vf', 'scale=640:-2',
                    '-q:v', '4',
                    '-y', $path,
                ), 45);
                if ($ok && is_readable($path) && filesize($path) > 800) {
                    $bytes = (string)file_get_contents($path);
                    if (str_starts_with($bytes, "\xff\xd8")) {
                        $frames[] = $bytes;
                    }
                }
                if (count($frames) >= 4) {
                    break;
                }
            }
            $audioPath = $dir . '/audio.mp3';
            $audioOk = $this->run(array(
                $ffmpeg, '-hide_banner', '-loglevel', 'error',
                '-t', '90',
                '-i', $url,
                '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k',
                '-y', $audioPath,
            ), 60);
            $transcript = '';
            if ($audioOk && is_readable($audioPath) && filesize($audioPath) > 400) {
                $transcript = $this->transcribe($audioPath);
            }
            return array('frames' => $frames, 'transcript' => $transcript);
        } finally {
            $this->wipeDir($dir);
        }
    }

    /**
     * @return list<float>
     */
    private function frameStamps(int $durationSec): array
    {
        if ($durationSec < 8) {
            return array(0.4);
        }
        $points = array(2.0, $durationSec * 0.22, $durationSec * 0.48, $durationSec * 0.72);
        $out = array();
        foreach ($points as $point) {
            $out[] = max(0.3, min((float)$durationSec - 0.4, (float)$point));
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<string,string> $context
     * @param array{frames:list<string>,transcript:string} $probe
     */
    private function openAiExplain(array $context, array $probe): string
    {
        $schema = array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'explanation' => array('type' => 'string'),
            ),
            'required' => array('explanation'),
        );
        $instructions = 'You write the student-facing summary for an IPCA flight-training video. '
            . 'Describe what the pilot will be able to do after watching. '
            . 'Be catchy, concrete, and practical. No hashtags, no emoji, no marketing slogans, '
            . 'no "in this video we will". 2 sentences, 320 characters max. '
            . 'Use the spoken audio and visible frames when they are provided. '
            . 'If the recording is a cockpit or lesson demonstration, say the maneuver or procedure plainly.';
        $userText = "Title: {$context['title']}\n"
            . "Category: {$context['category']}\n"
            . "Aircraft/program: {$context['aircraft']} {$context['program']}\n"
            . "Duration: {$context['duration']}\n"
            . "Orientation: {$context['orientation']}\n";
        if ($context['description'] !== '') {
            $userText .= "Existing notes: {$context['description']}\n";
        }
        if ($probe['transcript'] !== '') {
            $userText .= "Transcript excerpt:\n" . mb_substr($probe['transcript'], 0, 1800) . "\n";
        }
        $content = array(array('type' => 'input_text', 'text' => $userText));
        foreach (array_slice($probe['frames'], 0, 4) as $frame) {
            $content[] = array(
                'type' => 'input_image',
                'image_url' => 'data:image/jpeg;base64,' . base64_encode($frame),
            );
        }
        $payload = array(
            'model' => cw_openai_model(),
            'input' => array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => $instructions))),
                array('role' => 'user', 'content' => $content),
            ),
            'text' => array(
                'format' => array(
                    'type' => 'json_schema',
                    'name' => 'ipca_training_video_explain_v1',
                    'schema' => $schema,
                    'strict' => true,
                ),
            ),
        );
        $resp = cw_openai_responses($payload, 90);
        $json = cw_openai_extract_json_text($resp);
        return trim((string)($json['explanation'] ?? ''));
    }

    /**
     * @param array<string,string> $context
     */
    private function fallbackExplain(array $context): string
    {
        $title = $context['title'] !== '' ? $context['title'] : 'this maneuver';
        $extra = trim($context['aircraft'] . ' ' . $context['category']);
        if ($extra !== '') {
            return 'Learn ' . $title . ' the IPCA way on ' . $extra
                . '. Watch the demonstration, then fly the same sequence with the same calls and common errors in mind.';
        }
        return 'Learn ' . $title . ' the IPCA way. Watch the demonstration, then apply the same sequence, calls, and corrections in the aircraft.';
    }

    private function transcribe(string $path): string
    {
        if (trim((string)getenv('CW_OPENAI_API_KEY')) === '') {
            return '';
        }
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . cw_openai_key()));
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'model' => 'whisper-1',
            'file' => new CURLFile($path, 'audio/mpeg', 'audio.mp3'),
            'response_format' => 'text',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($resp) || $code < 200 || $code >= 300) {
            return '';
        }
        return trim($resp);
    }

    /**
     * @param list<string> $args
     */
    private function run(array $args, int $timeoutSeconds): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $cmd = array();
        foreach ($args as $arg) {
            $cmd[] = escapeshellarg($arg);
        }
        $descriptor = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $proc = proc_open(implode(' ', $cmd), $descriptor, $pipes, null, null);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], $timeoutSeconds);
        stream_set_timeout($pipes[2], $timeoutSeconds);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        return $status === 0;
    }

    private function ffmpegPath(): ?string
    {
        foreach (array('/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg') as $path) {
            if ($path === 'ffmpeg') {
                if (function_exists('shell_exec')) {
                    $found = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
                    if ($found !== '' && is_executable($found)) {
                        return $found;
                    }
                }
                continue;
            }
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private function wipeDir(string $dir): void
    {
        foreach ((array)glob($dir . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    private function limit(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_CHARS - 1);
        $space = mb_strrpos($cut, ' ');
        if (is_int($space) && $space > 80) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, '.,;:') . '.';
    }
}
