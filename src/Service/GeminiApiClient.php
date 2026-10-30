<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Llm\LlmClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiApiClient implements LlmClientInterface
{
    use \App\Service\Llm\LlmCommonPromptsTrait;

    private const MODELS_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    
    private const FALLBACK_MODELS = [
        'gemini-3.8-flash',
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-2.5-pro',
        'gemini-2.5-flash',
        'gemini-flash-latest',
        'gemini-pro-latest',
        'gemini-3.5-flash-lite',
    ];

    private string $activeModel = 'gemini-3.8-flash';
    private bool $modelExplicitlySet = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%gemini_api_key%')]
        private readonly string $geminiApiKey
    ) {
    }

    public function getProviderName(): string
    {
        return 'gemini';
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
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => ['temperature' => $temperature],
        ];

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        return $this->generateContent($payload);
    }

    public function chatWithTools(array $messages, array $tools, ?string $systemInstruction = null): array
    {
        $contents = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            if ($role === 'user') {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [
                        ['text' => (string) ($msg['content'] ?? '')],
                    ],
                ];
            } elseif ($role === 'assistant') {
                if (isset($msg['raw']) && is_array($msg['raw']) && !empty($msg['raw'])) {
                    $contents[] = [
                        'role' => 'model',
                        'parts' => $msg['raw'],
                    ];
                } else {
                    $parts = [];
                    if (!empty($msg['content'])) {
                        $parts[] = ['text' => (string) $msg['content']];
                    }
                    foreach ($msg['tool_calls'] ?? [] as $call) {
                        $parts[] = [
                            'functionCall' => [
                                'name' => $call['name'],
                                'args' => $call['args'] ?? [],
                            ],
                        ];
                    }
                    if ($parts !== []) {
                        $contents[] = [
                            'role' => 'model',
                            'parts' => $parts,
                        ];
                    }
                }
            } elseif ($role === 'tool_results') {
                $functionResponseParts = [];
                foreach ($msg['results'] ?? [] as $res) {
                    $responseVal = is_array($res['result'] ?? null)
                        ? $res['result']
                        : ['output' => (string) ($res['result_json'] ?? '')];

                    $functionResponseParts[] = [
                        'functionResponse' => [
                            'name' => (string) ($res['name'] ?? ''),
                            'response' => $responseVal,
                        ],
                    ];
                }
                if ($functionResponseParts !== []) {
                    $contents[] = [
                        'role' => 'user',
                        'parts' => $functionResponseParts,
                    ];
                }
            }
        }

        $resArray = $this->generateContentWithTools($contents, $tools, $systemInstruction);
        $parts = $resArray['parts'] ?? [];

        $textParts = [];
        $thought = null;
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['thought']) && $part['thought'] === true) {
                $thought = (string) ($part['text'] ?? '');
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $textParts[] = $part['text'];
            }
            if (isset($part['functionCall'])) {
                $call = $part['functionCall'];
                $toolName = (string) ($call['name'] ?? '');
                $toolCalls[] = [
                    'id' => $toolName,
                    'name' => $toolName,
                    'args' => (array) ($call['args'] ?? []),
                ];
            }
        }

        return [
            'text' => trim(implode("\n", $textParts)),
            'thought' => $thought,
            'tool_calls' => $toolCalls,
            'raw' => $parts,
        ];
    }

    public function listModels(): array
    {
        $maxRetries = 5;
        $retryDelay = 1;
        $data = [];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->httpClient->request('GET', self::MODELS_API_URL, [
                    'headers' => ['x-goog-api-key' => trim($this->geminiApiKey)],
                    'timeout' => 10,
                ]);
                $data = $response->toArray();
                break;
            } catch (\Throwable $e) {
                $statusCode = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getStatusCode() : 0;
                if ($attempt < $maxRetries && ($statusCode === 404 || $statusCode === 503 || $statusCode === 429 || $statusCode >= 500)) {
                    if ($statusCode !== 404) {
                        sleep($retryDelay);
                        $retryDelay *= 2;
                    }
                } else {
                    throw $e;
                }
            }
        }

        return $data['models'] ?? [];
    }

    public function generateContent(array $payload, int $timeoutSeconds = 120): string
    {
        $modelsToTry = array_values(array_unique([$this->activeModel, ...self::FALLBACK_MODELS]));

        $maxRetries = count($modelsToTry);
        $retryDelay = 2;
        $aiText = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $currentModel = $modelsToTry[$attempt - 1];
            $apiUrl = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $currentModel);

            try {
                $response = $this->httpClient->request('POST', $apiUrl, [
                    'headers' => ['x-goog-api-key' => trim($this->geminiApiKey)],
                    'json' => $payload,
                    'timeout' => $timeoutSeconds,
                ]);

                $resArray = $response->toArray();
                $parts = $resArray['candidates'][0]['content']['parts'] ?? [];
                $textParts = [];

                foreach ($parts as $part) {
                    if (isset($part['thought']) && $part['thought'] === true) {
                        continue;
                    }
                    if (isset($part['text']) && is_string($part['text'])) {
                        $textParts[] = $part['text'];
                    }
                }

                $aiText = trim(implode("\n", $textParts));
                if ($aiText === '' && !empty($parts[0]['text'])) {
                    $aiText = trim((string)$parts[0]['text']);
                }

                break;
            } catch (\Throwable $e) {
                $statusCode = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getStatusCode() : 0;
                $errorContent = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getContent(false) : '';

                if ($attempt < $maxRetries && ($statusCode === 404 || $statusCode === 503 || $statusCode === 429 || $statusCode >= 500)) {
                    $nextModel = $modelsToTry[$attempt];
                    $waitSec = $retryDelay;
                    if (preg_match('/"retryDelay":\s*"(\d+)s"/', $errorContent, $match)) {
                        $waitSec = max((int)$match[1], 2);
                    }

                    $this->logger->warning(sprintf(
                        "Gemini API error (%d) with model %s. Switching to %s...",
                        $statusCode,
                        $currentModel,
                        $nextModel
                    ));
                    if ($statusCode !== 404) {
                        sleep($waitSec);
                        $retryDelay = max($retryDelay * 2, $waitSec);
                    }
                } else {
                    $this->logger->error("Gemini API Request Failed ($statusCode): $errorContent", ['payload' => $payload]);
                    throw $e;
                }
            }
        }

        return $aiText;
    }

    public function generateContentWithTools(
        array $contents,
        array $toolsDeclarations,
        ?string $systemInstruction = null,
        int $timeoutSeconds = 120
    ): array {
        $modelsToTry = array_values(array_unique([$this->activeModel, ...self::FALLBACK_MODELS]));

        $maxRetries = count($modelsToTry);
        $retryDelay = 2;
        $candidateContent = [];

        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.2],
        ];

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        if (!empty($toolsDeclarations)) {
            $payload['tools'] = [
                ['functionDeclarations' => $toolsDeclarations]
            ];
        }

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $currentModel = $modelsToTry[$attempt - 1];
            $apiUrl = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $currentModel);

            try {
                $response = $this->httpClient->request('POST', $apiUrl, [
                    'headers' => ['x-goog-api-key' => trim($this->geminiApiKey)],
                    'json' => $payload,
                    'timeout' => $timeoutSeconds,
                ]);

                $resArray = $response->toArray();
                $candidateContent = $resArray['candidates'][0]['content'] ?? [];
                break;
            } catch (\Throwable $e) {
                $statusCode = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getStatusCode() : 0;
                $errorContent = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getContent(false) : '';

                if ($attempt < $maxRetries && ($statusCode === 404 || $statusCode === 503 || $statusCode === 429 || $statusCode >= 500)) {
                    $nextModel = $modelsToTry[$attempt];
                    $waitSec = $retryDelay;
                    if (preg_match('/"retryDelay":\s*"(\d+)s"/', $errorContent, $match)) {
                        $waitSec = max((int)$match[1], 2);
                    }

                    $this->logger->warning(sprintf(
                        "Gemini API error (%d) with model %s. Switching to %s...",
                        $statusCode,
                        $currentModel,
                        $nextModel
                    ));
                    if ($statusCode !== 404) {
                        sleep($waitSec);
                        $retryDelay = max($retryDelay * 2, $waitSec);
                    }
                } else {
                    $this->logger->error("Gemini API Request Failed ($statusCode): $errorContent", ['payload' => $payload]);
                    throw new \RuntimeException("Gemini API error ($statusCode): " . mb_strimwidth($errorContent, 0, 300, '...'), 0, $e);
                }
            }

        }

        return $candidateContent;
    }
}
