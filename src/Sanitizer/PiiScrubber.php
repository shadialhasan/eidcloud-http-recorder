<?php

declare(strict_types=1);

namespace EidCloud\HttpRecorder\Sanitizer;

use EidCloud\HttpRecorder\Model\RecordedInteraction;

final class PiiScrubber
{
    /** @var string[] */
    private array $sensitiveHeaderNames = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'apikey',
        'x-auth-token',
        'cookie',
        'set-cookie',
        'token',
        'access-token',
        'secret',
    ];

    /** @var string[] */
    private array $sensitiveFieldNames = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'api_key',
        'apikey',
        'token',
        'access_token',
        'refresh_token',
        'auth_token',
        'private_key',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
        'ssn',
    ];

    /**
     * @param string[] $additionalSensitiveHeaders
     * @param string[] $additionalSensitiveFields
     */
    public function __construct(
        array $additionalSensitiveHeaders = [],
        array $additionalSensitiveFields = []
    ) {
        $this->sensitiveHeaderNames = array_unique(array_merge(
            $this->sensitiveHeaderNames,
            array_map('strtolower', $additionalSensitiveHeaders)
        ));
        $this->sensitiveFieldNames = array_unique(array_merge(
            $this->sensitiveFieldNames,
            array_map('strtolower', $additionalSensitiveFields)
        ));
    }

    public function sanitizeInteraction(RecordedInteraction $interaction): RecordedInteraction
    {
        $sanitizedReqHeaders = $this->sanitizeHeaders($interaction->requestHeaders);
        $sanitizedResHeaders = $this->sanitizeHeaders($interaction->responseHeaders);
        $sanitizedReqBody = $this->sanitizeBody($interaction->requestBody);
        $sanitizedResBody = $this->sanitizeBody($interaction->responseBody);
        $sanitizedUri = $this->sanitizeUri($interaction->uri);

        return new RecordedInteraction(
            id: $interaction->id,
            timestamp: $interaction->timestamp,
            method: $interaction->method,
            uri: $sanitizedUri,
            requestHeaders: $sanitizedReqHeaders,
            requestBody: $sanitizedReqBody,
            statusCode: $interaction->statusCode,
            responseHeaders: $sanitizedResHeaders,
            responseBody: $sanitizedResBody,
            durationMs: $interaction->durationMs,
            metadata: $interaction->metadata
        );
    }

    /**
     * @param array<string, string|string[]> $headers
     * @return array<string, string|string[]>
     */
    public function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower((string)$key);
            if (in_array($lowerKey, $this->sensitiveHeaderNames, true)) {
                if (is_array($value)) {
                    $sanitized[$key] = array_map(fn($v) => $this->maskHeaderValue($lowerKey, (string)$v), $value);
                } else {
                    $sanitized[$key] = $this->maskHeaderValue($lowerKey, (string)$value);
                }
            } else {
                if (is_array($value)) {
                    $sanitized[$key] = array_map(fn($v) => $this->scrubPatterns((string)$v), $value);
                } else {
                    $sanitized[$key] = $this->scrubPatterns((string)$value);
                }
            }
        }
        return $sanitized;
    }

    private function maskHeaderValue(string $headerName, string $val): string
    {
        if (str_contains($headerName, 'auth') && preg_match('/^(Bearer\s+)(.+)$/i', $val, $matches)) {
            return $matches[1] . '[REDACTED_TOKEN]';
        }
        if (str_contains($headerName, 'auth') && preg_match('/^(Basic\s+)(.+)$/i', $val, $matches)) {
            return $matches[1] . '[REDACTED_BASIC_AUTH]';
        }
        return '[REDACTED]';
    }

    public function sanitizeUri(string $uri): string
    {
        $parsed = parse_url($uri);
        if ($parsed === false || !isset($parsed['query'])) {
            return $uri;
        }

        parse_str($parsed['query'], $queryParams);
        $sanitizedParams = $this->sanitizeArrayData($queryParams);
        $newQuery = http_build_query($sanitizedParams);

        $reconstructed = '';
        if (isset($parsed['scheme'])) {
            $reconstructed .= $parsed['scheme'] . '://';
        }
        if (isset($parsed['host'])) {
            $reconstructed .= $parsed['host'];
            if (isset($parsed['port'])) {
                $reconstructed .= ':' . $parsed['port'];
            }
        }
        $reconstructed .= $parsed['path'] ?? '';
        if ($newQuery !== '') {
            $reconstructed .= '?' . $newQuery;
        }
        if (isset($parsed['fragment'])) {
            $reconstructed .= '#' . $parsed['fragment'];
        }

        return $reconstructed;
    }

    public function sanitizeBody(string $body): string
    {
        if (trim($body) === '') {
            return $body;
        }

        // Try JSON
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $cleaned = $this->sanitizeArrayData($decoded);
            return (string)json_encode($cleaned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Try URL-encoded form data (e.g. key=value&secret=xyz)
        if (str_contains($body, '=') && !str_starts_with(trim($body), '<')) {
            parse_str($body, $formValues);
            if (!empty($formValues) && is_array($formValues)) {
                $isRealForm = true;
                foreach ($formValues as $k => $v) {
                    if (!is_string($k) || $k === '') {
                        $isRealForm = false;
                        break;
                    }
                }
                if ($isRealForm) {
                    $cleaned = $this->sanitizeArrayData($formValues);
                    return http_build_query($cleaned);
                }
            }
        }

        // Fallback to regex scrubbing
        return $this->scrubPatterns($body);
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<mixed, mixed>
     */
    private function sanitizeArrayData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $val) {
            $keyStr = strtolower((string)$key);
            $isSensitive = false;
            foreach ($this->sensitiveFieldNames as $sensitive) {
                if (str_contains($keyStr, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($val)) {
                $result[$key] = $this->sanitizeArrayData($val);
            } elseif (is_string($val)) {
                $result[$key] = $this->scrubPatterns($val);
            } else {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    public function scrubPatterns(string $text): string
    {
        // Redact Credit Cards (Visa, MasterCard, Amex, Discover - 13 to 16 digits)
        $text = (string)preg_replace(
            '/\b(?:\d{4}[ -]?){3}\d{4}\b|\b\d{15,16}\b/',
            '[REDACTED_CREDIT_CARD]',
            $text
        );

        // Redact JWT tokens (header.payload.signature)
        $text = (string)preg_replace(
            '/eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
            '[REDACTED_JWT]',
            $text
        );

        // Redact standard API keys / bearer tokens in plaintext strings
        $text = (string)preg_replace(
            '/(?i)\b(bearer\s+)([A-Za-z0-9\-_.+=]{16,})\b/',
            '$1[REDACTED_TOKEN]',
            $text
        );

        return $text;
    }
}
