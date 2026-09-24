<?php

declare(strict_types=1);

namespace App\Service\Llm;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicApiClient implements LlmClientInterface
{
    use LlmCommonPromptsTrait;

    private const MESSAGES_API_URL = 'https://api.anthropic.com/v1/messages';

    private const FALLBACK_MODELS = [
        'claude-3-7-sonnet-20250219',
        'claude-3-5-sonnet-20241022',
        'claude-3-opus-20240229',
        'claude-3-5-haiku-20241022',
    ];

    private string $activeModel = 'claude-3-7-sonnet-20250219';
    private bool $modelExplicitlySet = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%anthropic_api_key%')]
        private readonly ?string $apiKey = null
    ) {
    }

    public function getProviderName(): string
    {
        return 'claude';
    }

    public function getModel(): string
    {
        return $this->activeModel;
    }

    public function withModel(string $model): self
    {
        $clone = clone $this;
        $clone->activeModel = trim($model);
        $clone->modelExplicitlySet = true;
        return $clone;
    }

    public function generateText(string $prompt, ?string $systemInstruction = null, float $temperature = 0.4): string
    {
        $this->ensureApiKey();

        $payload = [
            'max_tokens' => 4096,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $payload['system'] = $systemInstruction;
        }

        $response = $this->sendRequest($payload);

        $textParts = [];
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $textParts[] = $block['text'];
            }
        }

        return trim(implode("\n", $textParts));
    }

    public function chatWithTools(array $messages, array $tools, ?string $systemInstruction = null): array
    {
        $this->ensureApiKey();

        $anthropicTools = [];
        foreach ($tools as $tool) {
            $anthropicTools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => SchemaHelper::normalize($tool['parameters']),
            ];
        }

        $anthropicMessages = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            if ($role === 'user') {
                $anthropicMessages[] = [
                    'role' => 'user',
                    'content' => (string) ($msg['content'] ?? ''),
                ];
            } elseif ($role === 'assistant') {
                $blocks = [];
                if (!empty($msg['content'])) {
                    $blocks[] = ['type' => 'text', 'text' => (string) $msg['content']];
                }
                foreach ($msg['tool_calls'] ?? [] as $call) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $call['id'] ?? ('toolu_' . bin2hex(random_bytes(6))),
                        'name' => $call['name'],
                        'input' => (object) ($call['args'] ?? []),
                    ];
                }
                if ($blocks !== []) {
                    $anthropicMessages[] = [
                        'role' => 'assistant',
                        'content' => $blocks,
                    ];
                }
            } elseif ($role === 'tool_results') {
                $blocks = [];
                foreach ($msg['results'] ?? [] as $res) {
                    $contentStr = is_string($res['result_json'] ?? null)
                        ? $res['result_json']
                        : json_encode($res['result'] ?? []);

                    $blocks[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $res['id'] ?? ('toolu_' . ($res['name'] ?? 'unknown')),
                        'content' => $contentStr,
                    ];
                }
                if ($blocks !== []) {
                    $anthropicMessages[] = [
                        'role' => 'user',
                        'content' => $blocks,
                    ];
                }
            }
        }

        $payload = [
            'max_tokens' => 4096,
            'temperature' => 0.2,
            'messages' => $anthropicMessages,
        ];

        if ($anthropicTools !== []) {
            $payload['tools'] = $anthropicTools;
        }

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $payload['system'] = $systemInstruction;
        }

        $resArray = $this->sendRequest($payload);

        $textParts = [];
        $thought = null;
        $toolCalls = [];

        foreach ($resArray['content'] ?? [] as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $textParts[] = (string) ($block['text'] ?? '');
            } elseif ($type === 'thinking') {
                $thought = (string) ($block['thinking'] ?? '');
            } elseif ($type === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ('toolu_' . ($block['name'] ?? ''))),
                    'name' => (string) ($block['name'] ?? ''),
                    'args' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return [
            'text' => trim(implode("\n", $textParts)),
            'thought' => $thought,
            'tool_calls' => $toolCalls,
            'raw' => $resArray['content'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendRequest(array $payload, int $timeoutSeconds = 120): array
    {
        $modelsToTry = $this->modelExplicitlySet
            ? [$this->activeModel]
            : array_values(array_unique([$this->activeModel, ...self::FALLBACK_MODELS]));

        $lastException = null;

        foreach ($modelsToTry as $index => $model) {
            $currentPayload = $payload;
            $currentPayload['model'] = $model;

            try {
                $response = $this->httpClient->request('POST', self::MESSAGES_API_URL, [
                    'headers' => [
                        'x-api-key' => trim((string) $this->apiKey),
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ],
                    'json' => $currentPayload,
                    'timeout' => $timeoutSeconds,
                ]);

                return $response->toArray();
            } catch (\Throwable $e) {
                $statusCode = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface
                    ? $e->getResponse()->getStatusCode()
                    : 0;
                $errorContent = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface
                    ? $e->getResponse()->getContent(false)
                    : '';

                $lastException = $e;

                $isRetryable = ($statusCode === 429 || $statusCode === 503 || $statusCode === 529 || $statusCode >= 500);
                $hasMore = isset($modelsToTry[$index + 1]);

                if ($isRetryable && $hasMore) {
                    $nextModel = $modelsToTry[$index + 1];
                    $this->logger->warning(sprintf(
                        "Anthropic API error (%d) with model %s. Switching to %s...",
                        $statusCode,
                        $model,
                        $nextModel
                    ));
                    continue;
                }

                $this->logger->error("Anthropic API Request Failed ($statusCode): $errorContent");
                throw new \RuntimeException("Anthropic API error ($statusCode): " . mb_strimwidth($errorContent, 0, 300, '...'), 0, $e);
            }
        }

        throw $lastException ?? new \RuntimeException("Failed to execute request with Anthropic API.");
    }

    private function ensureApiKey(): void
    {
        if (empty($this->apiKey) || trim($this->apiKey) === '') {
            throw new \RuntimeException(
                "Anthropic API key is missing. Set ANTHROPIC_API_KEY in .env.local / .env.dev, or choose another provider (e.g. --provider=gemini)."
            );
        }
    }
}
