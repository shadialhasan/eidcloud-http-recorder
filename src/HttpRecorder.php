<?php

declare(strict_types=1);

namespace EidCloud\HttpRecorder;

use EidCloud\HttpRecorder\Model\RecordedInteraction;
use EidCloud\HttpRecorder\Sanitizer\PiiScrubber;
use EidCloud\HttpRecorder\Replayer\ReplayEngine;
use EidCloud\HttpRecorder\Generators\TestGenerator;

final class HttpRecorder
{
    /** @var RecordedInteraction[] */
    private array $interactions = [];
    private readonly PiiScrubber $scrubber;

    /**
     * @param string|null $filePath Existing traffic file to load or default destination
     * @param bool $autoScrub Whether to automatically scrub sensitive data upon recording
     */
    public function __construct(
        private ?string $filePath = null,
        private readonly bool $autoScrub = true,
        ?PiiScrubber $scrubber = null
    ) {
        $this->scrubber = $scrubber ?? new PiiScrubber();

        if ($this->filePath !== null && file_exists($this->filePath)) {
            $this->loadFromFile($this->filePath);
        }
    }

    public function record(RecordedInteraction $interaction): void
    {
        if ($this->autoScrub) {
            $interaction = $this->scrubber->sanitizeInteraction($interaction);
        }
        $this->interactions[] = $interaction;

        if ($this->filePath !== null) {
            $this->saveToFile($this->filePath);
        }
    }

    /**
     * @return RecordedInteraction[]
     */
    public function getInteractions(): array
    {
        return $this->interactions;
    }

    public function count(): int
    {
        return count($this->interactions);
    }

    public function clear(): void
    {
        $this->interactions = [];
        if ($this->filePath !== null && file_exists($this->filePath)) {
            @unlink($this->filePath);
        }
    }

    public function saveToFile(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !empty($dir)) {
            mkdir($dir, 0777, true);
        }

        $data = array_map(fn(RecordedInteraction $i) => $i->toArray(), $this->interactions);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to serialize interactions to JSON: ' . json_last_error_msg());
        }

        file_put_contents($filePath, $json, LOCK_EX);
        $this->filePath = $filePath;
    }

    public function loadFromFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Traffic file not found: {$filePath}");
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException("Unable to read traffic file: {$filePath}");
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Malformed JSON in traffic file: {$filePath}");
        }

        $this->interactions = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $this->interactions[] = RecordedInteraction::fromArray($item);
            }
        }
        $this->filePath = $filePath;
    }

    public function getReplayEngine(?string $targetOverride = null, bool $preserveLatency = false, float $speedMultiplier = 1.0): ReplayEngine
    {
        return new ReplayEngine(
            interactions: $this->interactions,
            targetOverride: $targetOverride,
            preserveLatency: $preserveLatency,
            speedMultiplier: $speedMultiplier
        );
    }

    public function getTestGenerator(): TestGenerator
    {
        return new TestGenerator($this->interactions);
    }

    public function getScrubber(): PiiScrubber
    {
        return $this->scrubber;
    }
}
