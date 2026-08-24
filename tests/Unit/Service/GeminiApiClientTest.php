<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GeminiApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GeminiApiClientTest extends TestCase
{
    public function testGenerateContentExtractsTextAndFiltersThoughts(): void
    {
        $mockResponseBody = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'thought' => true,
                                'text' => 'Reasoning process: analyzing hot numbers...',
                            ],
                            [
                                'text' => '1, 5, 12, 19, 24, 38',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $mockResponse = new MockResponse($mockResponseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = new GeminiApiClient($httpClient, new NullLogger(), 'test_key');

        $result = $client->generateContent(['contents' => []]);

        $this->assertSame('1, 5, 12, 19, 24, 38', $result);
    }

    public function testGenerateContentUsesPrimaryGemini37FlashModelFirst(): void
    {
        $requestedUrl = null;
        $mockResponse = new MockResponse(json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Result from 3.7 flash'],
                        ],
                    ],
                ],
            ],
        ]), ['http_code' => 200]);

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrl, $mockResponse) {
            $requestedUrl = $url;
            return $mockResponse;
        });

        $client = new GeminiApiClient($httpClient, new NullLogger(), 'test_key');
        $result = $client->generateContent(['contents' => []]);

        $this->assertSame('Result from 3.7 flash', $result);
        $this->assertNotNull($requestedUrl);
        $this->assertStringContainsString('models/gemini-3.7-flash:generateContent', $requestedUrl);
    }

    public function testGenerateContentFallsBackOn429Or503(): void
    {
        $requestedUrls = [];
        $response1 = new MockResponse('Rate limit exceeded', ['http_code' => 429]);
        $response2 = new MockResponse(json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Fallback result'],
                        ],
                    ],
                ],
            ],
        ]), ['http_code' => 200]);

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls, $response1, $response2) {
            $requestedUrls[] = $url;
            return count($requestedUrls) === 1 ? $response1 : $response2;
        });

        $client = new GeminiApiClient($httpClient, new NullLogger(), 'test_key');
        $result = $client->generateContent(['contents' => []]);

        $this->assertSame('Fallback result', $result);
        $this->assertCount(2, $requestedUrls);
        $this->assertStringContainsString('models/gemini-3.7-flash:generateContent', $requestedUrls[0]);
        $this->assertStringContainsString('models/gemini-3.6-flash:generateContent', $requestedUrls[1]);
    }
}
