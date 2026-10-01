<?php

declare(strict_types=1);

namespace EidCloud\HttpRecorder\Replayer;

use EidCloud\HttpRecorder\Model\RecordedInteraction;

final class ReplayEngine
{
    /**
     * @param RecordedInteraction[] $interactions
     * @param string|null $targetOverride
     * @param bool $preserveLatency
     * @param float $speedMultiplier
     */
    public function __construct(
        private array $interactions,
        private readonly ?string $targetOverride = null,
        private readonly bool $preserveLatency = false,
        private readonly float $speedMultiplier = 1.0
    ) {
    }

    /**
     * @return array<int, array{interaction: RecordedInteraction, success: bool, replayedStatus: int, durationMs: float, error?: string}>
     */
    public function replay(?callable $onProgress = null): array
    {
        $results = [];
        $total = count($this->interactions);

        foreach ($this->interactions as $index => $interaction) {
            if ($interaction->method === 'CONNECT') {
                continue; // Skip raw CONNECT tunnel pseudo-requests
            }

            if ($this->preserveLatency && $interaction->durationMs > 0) {
                $delayUs = (int)(($interaction->durationMs * 1000) / max(0.1, $this->speedMultiplier));
                usleep(min($delayUs, 5000000)); // Cap simulated sleep to 5s safety
            }

            $targetUri = $this->resolveTargetUri($interaction->uri);
            $start = microtime(true);

            try {
                $res = $this->executeHttpRequest(
                    $interaction->method,
                    $targetUri,
                    $interaction->requestHeaders,
                    $interaction->requestBody
                );
                $duration = (microtime(true) - $start) * 1000.0;
                $entry = [
                    'interaction' => $interaction,
                    'success' => ($res['status'] >= 200 && $res['status'] < 400),
                    'replayedStatus' => $res['status'],
                    'durationMs' => $duration,
                ];
            } catch (\Throwable $e) {
                $duration = (microtime(true) - $start) * 1000.0;
                $entry = [
                    'interaction' => $interaction,
                    'success' => false,
                    'replayedStatus' => 0,
                    'durationMs' => $duration,
                    'error' => $e->getMessage(),
                ];
            }

            $results[] = $entry;

            if ($onProgress !== null) {
                $onProgress($index + 1, $total, $entry);
            }
        }

        return $results;
    }

    private function resolveTargetUri(string $originalUri): string
    {
        if ($this->targetOverride === null || trim($this->targetOverride) === '') {
            return $originalUri;
        }

        $parsedOriginal = parse_url($originalUri);
        $path = $parsedOriginal['path'] ?? '/';
        if (isset($parsedOriginal['query'])) {
            $path .= '?' . $parsedOriginal['query'];
        }

        return rtrim($this->targetOverride, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, string|string[]> $headers
     * @return array{status: int, body: string}
     */
    private function executeHttpRequest(string $method, string $uri, array $headers, string $body): array
    {
        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $kLower = strtolower($k);
            if (in_array($kLower, ['host', 'content-length', 'connection', 'accept-encoding'], true)) {
                continue;
            }
            if (is_array($v)) {
                foreach ($v as $subV) {
                    $curlHeaders[] = "{$k}: {$subV}";
                }
            } else {
                $curlHeaders[] = "{$k}: {$v}";
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $curlHeaders[] = 'Content-Length: ' . strlen($body);
        }

        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Replay failed for {$uri}: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => (string)$response,
        ];
    }
}
