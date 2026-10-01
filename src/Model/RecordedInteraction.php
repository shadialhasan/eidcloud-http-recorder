<?php

declare(strict_types=1);

namespace EidCloud\HttpRecorder\Model;

final class RecordedInteraction implements \JsonSerializable
{
    /**
     * @param array<string, string|string[]> $requestHeaders
     * @param array<string, string|string[]> $responseHeaders
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly float $timestamp,
        public readonly string $method,
        public readonly string $uri,
        public readonly array $requestHeaders,
        public readonly string $requestBody,
        public readonly int $statusCode,
        public readonly array $responseHeaders,
        public readonly string $responseBody,
        public readonly float $durationMs,
        public readonly array $metadata = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)($data['id'] ?? uniqid('req_', true)),
            timestamp: (float)($data['timestamp'] ?? microtime(true)),
            method: strtoupper((string)($data['method'] ?? 'GET')),
            uri: (string)($data['uri'] ?? '/'),
            requestHeaders: (array)($data['requestHeaders'] ?? []),
            requestBody: (string)($data['requestBody'] ?? ''),
            statusCode: (int)($data['statusCode'] ?? 200),
            responseHeaders: (array)($data['responseHeaders'] ?? []),
            responseBody: (string)($data['responseBody'] ?? ''),
            durationMs: (float)($data['durationMs'] ?? 0.0),
            metadata: (array)($data['metadata'] ?? [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp,
            'method' => $this->method,
            'uri' => $this->uri,
            'requestHeaders' => $this->requestHeaders,
            'requestBody' => $this->requestBody,
            'statusCode' => $this->statusCode,
            'responseHeaders' => $this->responseHeaders,
            'responseBody' => $this->responseBody,
            'durationMs' => $this->durationMs,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
