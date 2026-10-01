<?php

declare(strict_types=1);

namespace EidCloud\HttpRecorder\Tests;

use EidCloud\HttpRecorder\HttpRecorder;
use EidCloud\HttpRecorder\Model\RecordedInteraction;
use EidCloud\HttpRecorder\Sanitizer\PiiScrubber;
use EidCloud\HttpRecorder\Replayer\ReplayEngine;
use EidCloud\HttpRecorder\Generators\TestGenerator;

final class HttpRecorderTest
{
    private int $assertions = 0;

    public function run(): void
    {
        $this->testRecordedInteractionSerialization();
        $this->testPiiScrubberHeaders();
        $this->testPiiScrubberJsonBody();
        $this->testPiiScrubberUrlEncodedBody();
        $this->testPiiScrubberCreditCardsAndTokens();
        $this->testPiiScrubberUriQueryParams();
        $this->testHttpRecorderSaveAndLoad();
        $this->testTestGeneratorPhpUnit();
        $this->testTestGeneratorPest();
        $this->testTestGeneratorCurlScript();
        $this->testTestGeneratorOpenApiSpec();
        $this->testReplayEngineTargetOverride();
    }

    public function assert(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \AssertionError("Failed: {$message}");
        }
    }

    public function getAssertionCount(): int
    {
        return $this->assertions;
    }

    private function testRecordedInteractionSerialization(): void
    {
        $interaction = new RecordedInteraction(
            id: 'test_123',
            timestamp: 1700000000.123,
            method: 'POST',
            uri: 'http://api.example.com/v1/users',
            requestHeaders: ['Content-Type' => 'application/json', 'Authorization' => 'Bearer token_secret'],
            requestBody: '{"username":"john","password":"mypassword123"}',
            statusCode: 201,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '{"id":1,"status":"created"}',
            durationMs: 45.2,
            metadata: ['client_ip' => '127.0.0.1']
        );

        $arr = $interaction->toArray();
        $this->assert($arr['id'] === 'test_123', 'Interaction ID matches');
        $this->assert($arr['method'] === 'POST', 'Interaction method matches');
        $this->assert($arr['statusCode'] === 201, 'Interaction statusCode matches');

        $rehydrated = RecordedInteraction::fromArray($arr);
        $this->assert($rehydrated->id === $interaction->id, 'Rehydrated ID matches');
        $this->assert($rehydrated->durationMs === 45.2, 'Rehydrated duration matches');
    }

    private function testPiiScrubberHeaders(): void
    {
        $scrubber = new PiiScrubber();
        $headers = [
            'Authorization' => 'Bearer secret-jwt-token-string',
            'X-API-KEY' => 'my-super-secret-key-12345',
            'Accept' => 'application/json',
            'Cookie' => 'sessionid=xyz; auth=abc',
        ];

        $sanitized = $scrubber->sanitizeHeaders($headers);
        $this->assert($sanitized['Authorization'] === 'Bearer [REDACTED_TOKEN]', 'Authorization header redacted');
        $this->assert($sanitized['X-API-KEY'] === '[REDACTED]', 'API Key header redacted');
        $this->assert($sanitized['Cookie'] === '[REDACTED]', 'Cookie redacted');
        $this->assert($sanitized['Accept'] === 'application/json', 'Standard headers preserved');
    }

    private function testPiiScrubberJsonBody(): void
    {
        $scrubber = new PiiScrubber();
        $json = json_encode([
            'username' => 'alice',
            'password' => 'SuperSecretPass#99',
            'access_token' => 'ghp_xxxxxxxxxxxx',
            'nested' => [
                'credit_card' => '4532 1234 5678 9010',
                'regular_field' => 'hello world'
            ]
        ]);

        $sanitizedJson = $scrubber->sanitizeBody($json);
        $decoded = json_decode($sanitizedJson, true);

        $this->assert($decoded['username'] === 'alice', 'Username preserved');
        $this->assert($decoded['password'] === '[REDACTED]', 'Password redacted');
        $this->assert($decoded['access_token'] === '[REDACTED]', 'Access token redacted');
        $this->assert($decoded['nested']['credit_card'] === '[REDACTED]', 'Credit card in nested object redacted');
        $this->assert($decoded['nested']['regular_field'] === 'hello world', 'Regular field in nested object preserved');
    }

    private function testPiiScrubberUrlEncodedBody(): void
    {
        $scrubber = new PiiScrubber();
        $formData = 'username=john_doe&password=secret_password&action=login';
        $sanitized = $scrubber->sanitizeBody($formData);

        parse_str($sanitized, $params);
        $this->assert($params['username'] === 'john_doe', 'Form username preserved');
        $this->assert($params['password'] === '[REDACTED]', 'Form password redacted');
        $this->assert($params['action'] === 'login', 'Form action preserved');
    }

    private function testPiiScrubberCreditCardsAndTokens(): void
    {
        $scrubber = new PiiScrubber();
        $text = 'Paid with card 4111-2222-3333-4444 using Bearer secret_live_key_9988776655443322';
        $cleaned = $scrubber->scrubPatterns($text);

        $this->assert(str_contains($cleaned, '[REDACTED_CREDIT_CARD]'), 'Card pattern detected and redacted');
        $this->assert(str_contains($cleaned, '[REDACTED_TOKEN]'), 'Bearer token pattern detected and redacted');
        $this->assert(!str_contains($cleaned, '4111-2222-3333-4444'), 'Original card removed');
    }

    private function testPiiScrubberUriQueryParams(): void
    {
        $scrubber = new PiiScrubber();
        $uri = 'https://api.gateway.com/v1/auth/callback?code=xyz&api_key=secret9988&redirect_url=https%3A%2F%2Fapp.com';
        $sanitized = $scrubber->sanitizeUri($uri);

        $this->assert(str_contains($sanitized, 'api_key=%5BREDACTED%5D') || str_contains($sanitized, 'api_key=[REDACTED]'), 'Query param secret redacted');
        $this->assert(str_contains($sanitized, 'code=xyz'), 'Safe query params preserved');
    }

    private function testHttpRecorderSaveAndLoad(): void
    {
        $tmpFile = sys_get_temp_dir() . '/test_traffic_' . uniqid() . '.json';
        $recorder = new HttpRecorder($tmpFile, autoScrub: true);

        $interaction = new RecordedInteraction(
            id: 'req_001',
            timestamp: microtime(true),
            method: 'GET',
            uri: 'http://test.local/items',
            requestHeaders: ['Authorization' => 'Bearer sensitive_token_abc'],
            requestBody: '',
            statusCode: 200,
            responseHeaders: ['Content-Type' => 'application/json'],
            responseBody: '[{"id":1,"name":"Widget"}]',
            durationMs: 12.5
        );

        $recorder->record($interaction);
        $this->assert(file_exists($tmpFile), 'Traffic file created on record');

        $loadedRecorder = new HttpRecorder($tmpFile);
        $this->assert($loadedRecorder->count() === 1, 'Loaded interactions count matches 1');
        $first = $loadedRecorder->getInteractions()[0];
        $this->assert($first->requestHeaders['Authorization'] === 'Bearer [REDACTED_TOKEN]', 'AutoScrub was applied on record');
        $this->assert($first->statusCode === 200, 'Status code preserved');

        @unlink($tmpFile);
    }

    private function testTestGeneratorPhpUnit(): void
    {
        $interactions = [
            new RecordedInteraction(
                id: 'req_1',
                timestamp: microtime(true),
                method: 'GET',
                uri: 'http://example.com/api/users',
                requestHeaders: ['Accept' => 'application/json'],
                requestBody: '',
                statusCode: 200,
                responseHeaders: ['Content-Type' => 'application/json'],
                responseBody: '{"users":[]}',
                durationMs: 20.0
            )
        ];

        $generator = new TestGenerator($interactions);
        $phpUnit = $generator->generatePhpUnit('UserApiTest');

        $this->assert(str_contains($phpUnit, 'class UserApiTest extends TestCase'), 'PHPUnit test class definition present');
        $this->assert(str_contains($phpUnit, '$this->assertSame(200, $expectedStatus);'), 'Assertion for status code generated');
    }

    private function testTestGeneratorPest(): void
    {
        $interactions = [
            new RecordedInteraction(
                id: 'req_1',
                timestamp: microtime(true),
                method: 'POST',
                uri: 'http://example.com/api/orders',
                requestHeaders: ['Content-Type' => 'application/json'],
                requestBody: '{"item":"item_1"}',
                statusCode: 201,
                responseHeaders: ['Content-Type' => 'application/json'],
                responseBody: '{"id":100}',
                durationMs: 35.0
            )
        ];

        $generator = new TestGenerator($interactions);
        $pest = $generator->generatePest();

        $this->assert(str_contains($pest, "test('replays POST /api/orders successfully with status 201"), 'Pest test description correct');
        $this->assert(str_contains($pest, 'expect($expectedStatus)->toBe(201);'), 'Pest assertion generated');
    }

    private function testTestGeneratorCurlScript(): void
    {
        $interactions = [
            new RecordedInteraction(
                id: 'req_1',
                timestamp: microtime(true),
                method: 'GET',
                uri: 'http://example.com/health',
                requestHeaders: [],
                requestBody: '',
                statusCode: 200,
                responseHeaders: [],
                responseBody: 'OK',
                durationMs: 5.0
            )
        ];

        $generator = new TestGenerator($interactions);
        $curlScript = $generator->generateExecutableCurlScript();

        $this->assert(str_contains($curlScript, 'curl_init()'), 'cURL init call generated');
        $this->assert(str_contains($curlScript, '$statusCode === (int)$item[\'statusCode\']'), 'Status comparison assertion exists');
    }

    private function testTestGeneratorOpenApiSpec(): void
    {
        $interactions = [
            new RecordedInteraction(
                id: 'req_1',
                timestamp: microtime(true),
                method: 'POST',
                uri: 'https://api.company.com/v1/auth/login',
                requestHeaders: ['Content-Type' => 'application/json'],
                requestBody: '{"email":"test@example.com","remember_me":true,"attempts":3}',
                statusCode: 200,
                responseHeaders: ['Content-Type' => 'application/json'],
                responseBody: '{"token":"xyz","user":{"id":42,"active":true}}',
                durationMs: 50.0
            )
        ];

        $generator = new TestGenerator($interactions);
        $spec = $generator->generateOpenApiSpec('Auth API', '2.0.0');

        $this->assert($spec['openapi'] === '3.1.0', 'OpenAPI version 3.1.0');
        $this->assert(isset($spec['paths']['/v1/auth/login']['post']), 'Path and method exist in spec');

        $postOp = $spec['paths']['/v1/auth/login']['post'];
        $reqSchema = $postOp['requestBody']['content']['application/json']['schema'];
        $this->assert($reqSchema['type'] === 'object', 'Request body schema inferred as object');
        $this->assert($reqSchema['properties']['email']['type'] === 'string', 'Inferred email as string');
        $this->assert($reqSchema['properties']['remember_me']['type'] === 'boolean', 'Inferred remember_me as boolean');
        $this->assert($reqSchema['properties']['attempts']['type'] === 'integer', 'Inferred attempts as integer');

        $resSchema = $postOp['responses']['200']['content']['application/json']['schema'];
        $this->assert($resSchema['properties']['user']['type'] === 'object', 'Inferred nested user as object');
        $this->assert($resSchema['properties']['user']['properties']['id']['type'] === 'integer', 'Inferred nested user ID as integer');
    }

    private function testReplayEngineTargetOverride(): void
    {
        $interactions = [
            new RecordedInteraction(
                id: 'req_1',
                timestamp: microtime(true),
                method: 'GET',
                uri: 'http://prod.myapi.com/v1/users?page=2',
                requestHeaders: [],
                requestBody: '',
                statusCode: 200,
                responseHeaders: [],
                responseBody: '{}',
                durationMs: 10.0
            )
        ];

        $engine = new ReplayEngine($interactions, targetOverride: 'http://localhost:9000');
        $refMethod = new \ReflectionMethod($engine, 'resolveTargetUri');
        $refMethod->setAccessible(true);

        $resolved = $refMethod->invoke($engine, 'http://prod.myapi.com/v1/users?page=2');
        $this->assert($resolved === 'http://localhost:9000/v1/users?page=2', 'Target override rewrites host and preserves path/query');
    }
}
