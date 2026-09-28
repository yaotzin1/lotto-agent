<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Llm\AnthropicApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AnthropicApiClientTest extends TestCase
{
    public function testGenerateTextParsesAnthropicResponse(): void
    {
        $mockResponseBody = json_encode([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Analiza statystyczna: Pula liczb wykazuje wysokie prawdopodobieństwo w pasmach 12-25.',
                ],
            ],
            'stop_reason' => 'end_turn',
        ]);

        $requestedPayload = null;
        $mockResponse = new MockResponse($mockResponseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestedPayload, $mockResponse) {
            $requestedPayload = json_decode($options['body'] ?? '{}', true);
            return $mockResponse;
        });

        $client = new AnthropicApiClient($httpClient, new NullLogger(), 'test_anthropic_key');
        $result = $client->generateText('Przeanalizuj liczby', 'Jesteś ekspertem');

        $this->assertStringContainsString('Analiza statystyczna', $result);
        $this->assertSame('Jesteś ekspertem', $requestedPayload['system']);
        $this->assertSame('Przeanalizuj liczby', $requestedPayload['messages'][0]['content']);
    }

    public function testChatWithToolsHandlesToolUse(): void
    {
        $mockResponseBody = json_encode([
            'id' => 'msg_456',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Sprawdzam statystyki gorących liczb...',
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_01A',
                    'name' => 'fetch_hot_cold_stats',
                    'input' => ['game' => 'Lotto', 'months' => 6],
                ],
            ],
            'stop_reason' => 'tool_use',
        ]);

        $mockResponse = new MockResponse($mockResponseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = new AnthropicApiClient($httpClient, new NullLogger(), 'test_anthropic_key');

        $messages = [
            ['role' => 'user', 'content' => 'Wykonaj analizę'],
        ];

        $tools = [
            [
                'name' => 'fetch_hot_cold_stats',
                'description' => 'Pobierz statystyki',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'game' => ['type' => 'STRING'],
                    ],
                ],
            ],
        ];

        $turnResponse = $client->chatWithTools($messages, $tools);

        $this->assertSame('Sprawdzam statystyki gorących liczb...', $turnResponse['text']);
        $this->assertCount(1, $turnResponse['tool_calls']);
        $this->assertSame('toolu_01A', $turnResponse['tool_calls'][0]['id']);
        $this->assertSame('fetch_hot_cold_stats', $turnResponse['tool_calls'][0]['name']);
        $this->assertSame('Lotto', $turnResponse['tool_calls'][0]['args']['game']);
    }

    public function testThrowsExceptionWhenApiKeyMissing(): void
    {
        $httpClient = new MockHttpClient();
        $client = new AnthropicApiClient($httpClient, new NullLogger(), '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API key is missing');

        $client->generateText('Test');
    }
}
