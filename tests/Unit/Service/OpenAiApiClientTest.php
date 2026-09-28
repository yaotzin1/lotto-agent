<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Llm\OpenAiApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiApiClientTest extends TestCase
{
    public function testGenerateTextParsesOpenAiResponse(): void
    {
        $mockResponseBody = json_encode([
            'id' => 'chatcmpl_123',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Rekomendacja: Wybierz zakłady zoptymalizowane pod kątem Gaussa.',
                    ],
                ],
            ],
        ]);

        $requestedPayload = null;
        $mockResponse = new MockResponse($mockResponseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requestedPayload, $mockResponse) {
            $requestedPayload = json_decode($options['body'] ?? '{}', true);
            return $mockResponse;
        });

        $client = new OpenAiApiClient($httpClient, new NullLogger(), 'test_openai_key');
        $result = $client->generateText('Przeanalizuj zakłady', 'System instruction');

        $this->assertSame('Rekomendacja: Wybierz zakłady zoptymalizowane pod kątem Gaussa.', $result);
        $this->assertSame('system', $requestedPayload['messages'][0]['role']);
        $this->assertSame('System instruction', $requestedPayload['messages'][0]['content']);
        $this->assertSame('Przeanalizuj zakłady', $requestedPayload['messages'][1]['content']);
    }

    public function testChatWithToolsHandlesToolCalls(): void
    {
        $mockResponseBody = json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Wywołuję narzędzie test_system_coverage...',
                        'tool_calls' => [
                            [
                                'id' => 'call_abc999',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'test_system_coverage',
                                    'arguments' => json_encode(['numbers' => [1, 2, 3, 4, 5, 6], 'game' => 'Lotto']),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $mockResponse = new MockResponse($mockResponseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = new OpenAiApiClient($httpClient, new NullLogger(), 'test_openai_key');

        $messages = [
            ['role' => 'user', 'content' => 'Testuj pokrycie'],
        ];

        $tools = [
            [
                'name' => 'test_system_coverage',
                'description' => 'Testuje pokrycie',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'numbers' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                    ],
                ],
            ],
        ];

        $turnResponse = $client->chatWithTools($messages, $tools);

        $this->assertSame('Wywołuję narzędzie test_system_coverage...', $turnResponse['text']);
        $this->assertCount(1, $turnResponse['tool_calls']);
        $this->assertSame('call_abc999', $turnResponse['tool_calls'][0]['id']);
        $this->assertSame('test_system_coverage', $turnResponse['tool_calls'][0]['name']);
        $this->assertSame([1, 2, 3, 4, 5, 6], $turnResponse['tool_calls'][0]['args']['numbers']);
    }

    public function testDeepSeekUsesDeepSeekUrlAndModel(): void
    {
        $mockResponseBody = json_encode([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Wynik DeepSeek',
                        'reasoning_content' => 'Myślenie analityczne DeepSeek R1...',
                    ],
                ],
            ],
        ]);

        $requestedUrl = null;
        $mockResponse = new MockResponse($mockResponseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requestedUrl, $mockResponse) {
            $requestedUrl = $url;
            return $mockResponse;
        });

        $client = (new OpenAiApiClient($httpClient, new NullLogger(), null, 'test_deepseek_key'))->forDeepSeek();
        $turnResponse = $client->chatWithTools([['role' => 'user', 'content' => 'Wykonaj analizę']], []);

        $this->assertSame('Wynik DeepSeek', $turnResponse['text']);
        $this->assertSame('Myślenie analityczne DeepSeek R1...', $turnResponse['thought']);
        $this->assertStringContainsString('api.deepseek.com', (string) $requestedUrl);
    }
}
