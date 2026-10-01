<?php

declare(strict_types=1);

namespace EidCloud\HttpRecorder\Proxy;

use EidCloud\HttpRecorder\HttpRecorder;
use EidCloud\HttpRecorder\Model\RecordedInteraction;

final class ProxyServer
{
    private bool $running = false;
    /** @var resource|null */
    private $serverSocket = null;

    public function __construct(
        private readonly HttpRecorder $recorder,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8888,
        private readonly ?string $targetBaseUri = null
    ) {
    }

    public function start(?int $maxRequests = null): void
    {
        $address = "tcp://{$this->host}:{$this->port}";
        $errno = 0;
        $errstr = '';
        $this->serverSocket = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if (!$this->serverSocket) {
            throw new \RuntimeException("Failed to bind proxy server on {$address}: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->serverSocket, false);
        $this->running = true;

        echo "🚀 EidCloud HTTP Recorder Proxy listening on {$address}" . PHP_EOL;
        if ($this->targetBaseUri) {
            echo "🎯 Forwarding reverse proxy target: {$this->targetBaseUri}" . PHP_EOL;
        } else {
            echo "🌐 Operating in Forward Proxy Mode (HTTP & HTTPS CONNECT)" . PHP_EOL;
        }

        $processedRequests = 0;

        while ($this->running) {
            if ($maxRequests !== null && $processedRequests >= $maxRequests) {
                break;
            }

            $read = [$this->serverSocket];
            $write = null;
            $except = null;

            $numChangedStreams = @stream_select($read, $write, $except, 0, 200000); // 200ms timeout
            if ($numChangedStreams === false) {
                break;
            }

            if ($numChangedStreams > 0) {
                $client = @stream_socket_accept($this->serverSocket, 1.0);
                if ($client) {
                    stream_set_blocking($client, true);
                    $this->handleClient($client);
                    $processedRequests++;
                }
            }
        }

        $this->stop();
    }

    public function stop(): void
    {
        $this->running = false;
        if ($this->serverSocket && is_resource($this->serverSocket)) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    /**
     * @param resource $client
     */
    private function handleClient($client): void
    {
        try {
            $rawRequest = $this->readHttpRequest($client);
            if ($rawRequest === '') {
                @fclose($client);
                return;
            }

            $parsed = $this->parseRawHttpRequest($rawRequest);
            if (!$parsed) {
                @fwrite($client, "HTTP/1.1 400 Bad Request\r\nContent-Length: 15\r\n\r\n400 Bad Request");
                @fclose($client);
                return;
            }

            [$method, $targetUri, $httpVersion, $headers, $body] = $parsed;

            if (strtoupper($method) === 'CONNECT') {
                $this->handleHttpsConnect($client, $targetUri);
                return;
            }

            $startTime = microtime(true);
            $response = $this->forwardRequest($method, $targetUri, $headers, $body);
            $endTime = microtime(true);
            $durationMs = ($endTime - $startTime) * 1000.0;

            [$resStatusCode, $resHeaders, $resBody, $rawResponse] = $response;

            @fwrite($client, $rawResponse);
            @fclose($client);

            $interaction = new RecordedInteraction(
                id: uniqid('req_', true),
                timestamp: $startTime,
                method: $method,
                uri: $targetUri,
                requestHeaders: $headers,
                requestBody: $body,
                statusCode: $resStatusCode,
                responseHeaders: $resHeaders,
                responseBody: $resBody,
                durationMs: $durationMs,
                metadata: ['client_ip' => stream_socket_get_name($client, true)]
            );

            $this->recorder->record($interaction);
        } catch (\Throwable $e) {
            $errBody = "HTTP/1.1 502 Bad Gateway\r\nContent-Length: " . strlen($e->getMessage()) . "\r\n\r\n" . $e->getMessage();
            @fwrite($client, $errBody);
            @fclose($client);
        }
    }

    /**
     * @param resource $client
     */
    private function readHttpRequest($client): string
    {
        $data = '';
        $contentLength = null;
        $headerComplete = false;

        while (!feof($client)) {
            $chunk = @fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;

            if (!$headerComplete && ($headerEnd = strpos($data, "\r\n\r\n")) !== false) {
                $headerComplete = true;
                $headerPart = substr($data, 0, $headerEnd);
                if (preg_match('/Content-Length:\s*(\d+)/i', $headerPart, $matches)) {
                    $contentLength = (int)$matches[1];
                } else {
                    $contentLength = 0;
                }
            }

            if ($headerComplete && $contentLength !== null) {
                $bodyLength = strlen($data) - ($headerEnd + 4);
                if ($bodyLength >= $contentLength) {
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: array<string, string>, 4: string}|null
     */
    public function parseRawHttpRequest(string $raw): ?array
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        $headerPart = $parts[0];
        $body = $parts[1] ?? '';

        $lines = explode("\r\n", $headerPart);
        if (count($lines) < 1) {
            return null;
        }

        $requestLine = array_shift($lines);
        $reqParts = explode(' ', $requestLine);
        if (count($reqParts) < 2) {
            return null;
        }

        $method = strtoupper($reqParts[0]);
        $uri = $reqParts[1];
        $version = $reqParts[2] ?? 'HTTP/1.1';

        $headers = [];
        foreach ($lines as $line) {
            $hParts = explode(':', $line, 2);
            if (count($hParts) === 2) {
                $headers[trim($hParts[0])] = trim($hParts[1]);
            }
        }

        if ($this->targetBaseUri !== null) {
            $uri = rtrim($this->targetBaseUri, '/') . '/' . ltrim($uri, '/');
        } elseif (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
            $host = $headers['Host'] ?? 'localhost';
            $uri = 'http://' . $host . $uri;
        }

        return [$method, $uri, $version, $headers, $body];
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string, 3: string}
     */
    private function forwardRequest(string $method, string $uri, array $headers, string $body): array
    {
        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $kLower = strtolower($k);
            if ($kLower === 'host' || $kLower === 'connection' || $kLower === 'content-length') {
                continue;
            }
            $curlHeaders[] = "{$k}: {$v}";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
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
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL forward failed for {$uri}: {$error}");
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string)$response, 0, $headerSize);
        $resBody = substr((string)$response, $headerSize);

        $resHeaders = [];
        $headerLines = explode("\r\n", trim($rawHeaders));
        foreach ($headerLines as $line) {
            if (str_starts_with(strtoupper($line), 'HTTP/')) {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $resHeaders[trim($parts[0])] = trim($parts[1]);
            }
        }

        return [$statusCode, $resHeaders, $resBody, (string)$response];
    }

    /**
     * @param resource $client
     */
    private function handleHttpsConnect($client, string $targetUri): void
    {
        $parts = explode(':', $targetUri);
        $targetHost = $parts[0];
        $targetPort = isset($parts[1]) ? (int)$parts[1] : 443;

        $targetSocket = @stream_socket_client("tcp://{$targetHost}:{$targetPort}", $errno, $errstr, 5.0);
        if (!$targetSocket) {
            @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\n\r\n");
            @fclose($client);
            return;
        }

        @fwrite($client, "HTTP/1.1 200 Connection Established\r\n\r\n");

        $startTime = microtime(true);
        stream_set_blocking($client, false);
        stream_set_blocking($targetSocket, false);

        $tunneling = true;
        while ($tunneling) {
            $read = [$client, $targetSocket];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 10) <= 0) {
                break;
            }

            foreach ($read as $r) {
                if ($r === $client) {
                    $buf = @fread($client, 65536);
                    if ($buf === false || $buf === '') {
                        $tunneling = false;
                        break;
                    }
                    @fwrite($targetSocket, $buf);
                } elseif ($r === $targetSocket) {
                    $buf = @fread($targetSocket, 65536);
                    if ($buf === false || $buf === '') {
                        $tunneling = false;
                        break;
                    }
                    @fwrite($client, $buf);
                }
            }
        }

        @fclose($client);
        @fclose($targetSocket);

        $durationMs = (microtime(true) - $startTime) * 1000.0;
        $this->recorder->record(new RecordedInteraction(
            id: uniqid('req_tunnel_', true),
            timestamp: $startTime,
            method: 'CONNECT',
            uri: "https://{$targetHost}:{$targetPort}",
            requestHeaders: ['Host' => "{$targetHost}:{$targetPort}"],
            requestBody: '[TUNNELED_TLS_STREAM]',
            statusCode: 200,
            responseHeaders: ['Status' => 'Connection Established'],
            responseBody: '[ENCRYPTED_STREAM_TUNNELED]',
            durationMs: $durationMs,
            metadata: ['type' => 'CONNECT_TUNNEL']
        ));
    }
}
