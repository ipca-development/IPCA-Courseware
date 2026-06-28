<?php
declare(strict_types=1);

/**
 * Lightweight wall-clock profiler for cockpit reconstruction stages.
 */
final class CockpitReconstructionProfiler
{
    /** @var array<string,float> */
    private array $marks = array();

    /** @var array<string,float> */
    private array $active = array();

    public function start(string $label): void
    {
        $this->active[$label] = microtime(true);
    }

    public function stop(string $label): float
    {
        if (!isset($this->active[$label])) {
            return 0.0;
        }
        $elapsed = microtime(true) - $this->active[$label];
        $this->marks[$label] = round($elapsed, 4);
        unset($this->active[$label]);
        return $elapsed;
    }

    /**
     * @param array<string,float> $marks
     */
    public function merge(array $marks): void
    {
        foreach ($marks as $label => $seconds) {
            $this->marks[$label] = round((float)$seconds, 4);
        }
    }

    /**
     * @return array<string,float>
     */
    public function toArray(): array
    {
        return $this->marks;
    }

    public function log(int|string $recordingId): void
    {
        error_log('[cockpit-recon-profile:' . $recordingId . '] ' . json_encode($this->marks, JSON_UNESCAPED_SLASHES));
    }
}
