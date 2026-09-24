<?php

declare(strict_types=1);

namespace App\Service\Llm;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiApiClient implements LlmClientInterface
{
    use LlmCommonPromptsTrait;

    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
    private const DEEPSEEK_API_URL = 'https://api.deepseek.com/chat/completions';

    private const OPENAI_FALLBACKS = [
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4-turbo',
    ];

    private const DEEPSEEK_FALLBACKS = [
        'deepseek-chat',
        'deepseek-reasoner',
    ];

    private string $activeModel = 'gpt-4o';
    private bool $modelExplicitlySet = false;
    private bool $isDeepSeek = false;
    private ?string $customBaseUrl = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%openai_api_key%')]
        private readonly ?string $openaiApiKey = null,
        #[Autowire('%deepseek_api_key%')]
        private readonly ?string $deepseekApiKey = null,
        #[Autowire('%openai_base_url%')]
        private readonly ?string $defaultBaseUrl = null
    ) {
        $this->customBaseUrl = !empty($this->defaultBaseUrl) ? rtrim($this->defaultBaseUrl, '/') : null;
    }

    public function getProviderName(): string
    {
        return $this->isDeepSeek ? 'deepseek' : 'openai';
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

    public function forDeepSeek(): self
    {
        $clone = clone $this;
        $clone->isDeepSeek = true;
        if (!$clone->modelExplicitlySet) {
            $clone->activeModel = 'deepseek-chat';
        }
        return $clone;
    }

    public function withBaseUrl(string $url): self
    {
        $clone = clone $this;
        $clone->customBaseUrl = rtrim(trim($url), '/');
        return $clone;
    }

    public function generateText(string $prompt, ?string $systemInstruction = null, float $temperature = 0.4): string
    {
        $messages = [];
        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        $response = $this->sendRequest($payload);
        $content = $response['choices'][0]['message']['content'] ?? '';

        return trim((string) $content);
    }

    public function chatWithTools(array $messages, array $tools, ?string $systemInstruction = null): array
    {
        $openAiTools = [];
        foreach ($tools as $tool) {
            $openAiTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => SchemaHelper::normalize($tool['parameters']),
                ],
            ];
        }

        $openAiMessages = [];
        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $openAiMessages[] = [
                'role' => 'system',
                'content' => $systemInstruction,
            ];
        }

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            if ($role === 'user') {
                $openAiMessages[] = [
                    'role' => 'user',
                    'content' => (string) ($msg['content'] ?? ''),
                ];
            } elseif ($role === 'assistant') {
                $item = [
                    'role' => 'assistant',
                    'content' => !empty($msg['content']) ? (string) $msg['content'] : null,
                ];

                if (!empty($msg['tool_calls'])) {
                    $toolCalls = [];
                    foreach ($msg['tool_calls'] as $call) {
                        $toolCalls[] = [
                            'id' => (string) ($call['id'] ?? ('call_' . bin2hex(random_bytes(6)))),
                            'type' => 'function',
                            'function' => [
                                'name' => (string) ($call['name'] ?? ''),
                                'arguments' => json_encode($call['args'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            ],
                        ];
                    }
                    $item['tool_calls'] = $toolCalls;
                }

                $openAiMessages[] = $item;
            } elseif ($role === 'tool_results') {
                foreach ($msg['results'] ?? [] as $res) {
                    $contentStr = is_string($res['result_json'] ?? null)
                        ? $res['result_json']
                        : json_encode($res['result'] ?? []);

                    $openAiMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($res['id'] ?? ('call_' . ($res['name'] ?? ''))),
                        'content' => $contentStr,
                    ];
                }
            }
        }

        $payload = [
            'messages' => $openAiMessages,
            'temperature' => 0.2,
        ];

        if ($openAiTools !== []) {
            $payload['tools'] = $openAiTools;
        }

        $response = $this->sendRequest($payload);
        $choice = $response['choices'][0]['message'] ?? [];

        $text = (string) ($choice['content'] ?? '');
        $thought = isset($choice['reasoning_content']) ? (string) $choice['reasoning_content'] : null;
        $toolCalls = [];

        foreach ($choice['tool_calls'] ?? [] as $tc) {
            $fn = $tc['function'] ?? [];
            $argsRaw = $fn['arguments'] ?? '{}';
            $parsedArgs = json_decode((string) $argsRaw, true);

            $toolCalls[] = [
                'id' => (string) ($tc['id'] ?? ('call_' . ($fn['name'] ?? ''))),
                'name' => (string) ($fn['name'] ?? ''),
                'args' => is_array($parsedArgs) ? $parsedArgs : [],
            ];
        }

        return [
            'text' => trim($text),
            'thought' => $thought,
            'tool_calls' => $toolCalls,
            'raw' => $choice,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendRequest(array $payload, int $timeoutSeconds = 120): array
    {
        $apiKey = $this->resolveApiKey();
        $apiUrl = $this->resolveApiUrl();

        $fallbacks = $this->isDeepSeek ? self::DEEPSEEK_FALLBACKS : self::OPENAI_FALLBACKS;
        $modelsToTry = $this->modelExplicitlySet
            ? [$this->activeModel]
            : array_values(array_unique([$this->activeModel, ...$fallbacks]));

        $lastException = null;

        foreach ($modelsToTry as $index => $model) {
            $currentPayload = $payload;
            $currentPayload['model'] = $model;

            try {
                $response = $this->httpClient->request('POST', $apiUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
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

                $isRetryable = ($statusCode === 429 || $statusCode === 503 || $statusCode >= 500);
                $hasMore = isset($modelsToTry[$index + 1]);

                if ($isRetryable && $hasMore) {
                    $nextModel = $modelsToTry[$index + 1];
                    $this->logger->warning(sprintf(
                        "%s API error (%d) with model %s. Switching to %s...",
                        $this->isDeepSeek ? 'DeepSeek' : 'OpenAI',
                        $statusCode,
                        $model,
                        $nextModel
                    ));
                    continue;
                }

                $providerName = $this->isDeepSeek ? 'DeepSeek' : 'OpenAI';
                $this->logger->error("$providerName API Request Failed ($statusCode): $errorContent");
                throw new \RuntimeException("$providerName API error ($statusCode): " . mb_strimwidth($errorContent, 0, 300, '...'), 0, $e);
            }
        }

        throw $lastException ?? new \RuntimeException("Failed to execute request with {$this->getProviderName()} API.");
    }

    private function resolveApiKey(): string
    {
        if ($this->isDeepSeek) {
            $key = $this->deepseekApiKey ?: $this->openaiApiKey;
            if (empty($key) || trim($key) === '') {
                throw new \RuntimeException(
                    "DeepSeek API key is missing. Set DEEPSEEK_API_KEY (or OPENAI_API_KEY) in .env.local / .env.dev."
                );
            }
            return trim($key);
        }

        $key = $this->openaiApiKey;
        if (empty($key) || trim($key) === '') {
            throw new \RuntimeException(
                "OpenAI API key is missing. Set OPENAI_API_KEY in .env.local / .env.dev, or choose another provider (e.g. --provider=gemini)."
            );
        }
        return trim($key);
    }

    private function resolveApiUrl(): string
    {
        if ($this->customBaseUrl !== null && $this->customBaseUrl !== '') {
            return $this->customBaseUrl . '/chat/completions';
        }

        return $this->isDeepSeek ? self::DEEPSEEK_API_URL : self::OPENAI_API_URL;
    }
}
