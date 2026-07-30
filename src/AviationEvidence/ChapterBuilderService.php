<?php
declare(strict_types=1);

/**
 * Heuristic flight/training outline from speech segment text.
 */
final class ChapterBuilderService
{
    /**
     * @var list<array{title:string,category:string,patterns:list<string>}>
     */
    private const CHAPTER_SPECS = array(
        array('title' => 'Recording Start', 'category' => 'ground', 'patterns' => array('recording', 'testing', 'microphone')),
        array('title' => 'Engine Start', 'category' => 'ground', 'patterns' => array('engine start', 'starting engine', 'mixture rich', 'master switch', 'prime the engine', 'crank', 'oil pressure')),
        array('title' => 'Pre-Taxi Control Checks', 'category' => 'ground', 'patterns' => array('before taxi', 'pre-taxi', 'flight controls', 'freedom of motion', 'after starting engine', 'elevator trim', 'parking brake')),
        array('title' => 'Taxi Out', 'category' => 'ground', 'patterns' => array('taxi out', 'taxiing out', 'taxi to', 'hold short', 'run-up', 'run up')),
        array('title' => 'Takeoff', 'category' => 'departure', 'patterns' => array('take off', 'takeoff', 'cleared for takeoff', 'rotate', 'departure', 'line up and wait')),
        array('title' => 'Climb / Departure', 'category' => 'departure', 'patterns' => array('climb', 'climbing', 'contact departure', 'turn left heading', 'turn right heading', 'maintain')),
        array('title' => 'Pattern / Maneuvers', 'category' => 'airwork', 'patterns' => array('pattern', 'downwind', 'base leg', 'final', 'go around', 'touch and go', 'stall', 'steep turn')),
        array('title' => 'Approach & Landing', 'category' => 'arrival', 'patterns' => array('cleared to land', 'short final', 'landing', 'flare', 'runway')),
        array('title' => 'Taxi In / Shutdown', 'category' => 'ground', 'patterns' => array('taxi back', 'taxi in', 'shut down', 'shutdown', 'mixture idle', 'master off')),
    );

    /**
     * @param list<array<string,mixed>> $speechSegments
     * @return list<array<string,mixed>>
     */
    public function build(array $speechSegments): array
    {
        if ($speechSegments === array()) {
            return array();
        }

        $chapters = array();
        $lastEndMs = -1;
        $assignedTitles = array();

        foreach ($speechSegments as $segment) {
            $segmentId = (int)($segment['id'] ?? 0);
            $text = strtolower(trim((string)($segment['provider_segment_text'] ?? '')));
            if ($segmentId <= 0 || $text === '') {
                continue;
            }

            $startMs = (int)($segment['start_time_ms'] ?? 0);
            $endMs = (int)($segment['end_time_ms'] ?? $startMs);
            $match = $this->matchChapter($text);
            if ($match === null) {
                continue;
            }

            $title = (string)$match['title'];
            if (isset($assignedTitles[$title]) && ($startMs - $assignedTitles[$title]) < 120000) {
                continue;
            }

            if ($chapters !== array()) {
                $lastIndex = count($chapters) - 1;
                $chapters[$lastIndex]['end_time_ms'] = max((int)$chapters[$lastIndex]['start_time_ms'], $startMs - 1);
            }

            $chapters[] = array(
                'title' => $title,
                'category' => (string)$match['category'],
                'start_time_ms' => $startMs,
                'end_time_ms' => $endMs,
                'confidence' => (float)$match['confidence'],
                'supporting_segment_ids' => array($segmentId),
            );
            $assignedTitles[$title] = $startMs;
            $lastEndMs = $endMs;
        }

        if ($chapters === array()) {
            $first = $speechSegments[0];
            $last = $speechSegments[count($speechSegments) - 1];
            $chapters[] = array(
                'title' => 'Flight Recording',
                'category' => 'general',
                'start_time_ms' => (int)($first['start_time_ms'] ?? 0),
                'end_time_ms' => (int)($last['end_time_ms'] ?? 0),
                'confidence' => 0.4,
                'supporting_segment_ids' => array((int)($first['id'] ?? 0)),
            );
            return $chapters;
        }

        $lastSegment = $speechSegments[count($speechSegments) - 1];
        $lastIndex = count($chapters) - 1;
        $chapters[$lastIndex]['end_time_ms'] = max(
            (int)$chapters[$lastIndex]['end_time_ms'],
            (int)($lastSegment['end_time_ms'] ?? 0)
        );

        return $chapters;
    }

    /**
     * @return array{title:string,category:string,confidence:float}|null
     */
    private function matchChapter(string $lowerText): ?array
    {
        $best = null;
        $bestScore = 0.0;

        foreach (self::CHAPTER_SPECS as $spec) {
            foreach ($spec['patterns'] as $pattern) {
                $pattern = strtolower($pattern);
                if (!str_contains($lowerText, $pattern)) {
                    continue;
                }
                $score = 0.55 + min(0.35, strlen($pattern) / 40.0);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = array(
                        'title' => $spec['title'],
                        'category' => $spec['category'],
                        'confidence' => min(0.95, round($score, 4)),
                    );
                }
            }
        }

        return $best;
    }
}
