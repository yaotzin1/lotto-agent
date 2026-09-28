<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Service\GeminiApiClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class LlmClientResolver
{
    public function __construct(
        private readonly GeminiApiClient $geminiClient,
        private readonly AnthropicApiClient $anthropicClient,
        private readonly OpenAiApiClient $openAiClient,
        #[Autowire('%ai_provider%')]
        private readonly string $defaultProvider = 'gemini',
        #[Autowire('%ai_model%')]
        private readonly string $defaultModel = ''
    ) {
    }

    public function resolve(?string $provider = null, ?string $model = null): LlmClientInterface
    {
        $chosenProvider = strtolower(trim((string) ($provider ?: ($this->defaultProvider ?: 'gemini'))));

        $client = match ($chosenProvider) {
            'claude', 'anthropic' => $this->anthropicClient,
            'openai' => $this->openAiClient,
            'deepseek' => $this->openAiClient->forDeepSeek(),
            default => $this->geminiClient,
        };

        $chosenModel = trim((string) ($model ?: $this->defaultModel));
        if ($chosenModel !== '') {
            $client = $client->withModel($chosenModel);
        }

        return $client;
    }

    /**
     * @return array<string>
     */
    public function getAvailableProviders(): array
    {
        return ['gemini', 'claude', 'openai', 'deepseek'];
    }

    public function getDefaultProvider(): string
    {
        return $this->defaultProvider ?: 'gemini';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Returns structured catalog of supported providers and their models.
     *
     * @return array<string, array{
     *     displayName: string,
     *     defaultModel: string,
     *     models: array<string, array{name: string, description: string, recommendedFor: string}>,
     *     fallbacks: array<string>
     * }>
     */
    public function getProvidersCatalog(): array
    {
        return [
            'gemini' => [
                'displayName' => 'Google Gemini',
                'defaultModel' => 'gemini-3.7-flash',
                'models' => [
                    'gemini-3.7-flash' => [
                        'name' => 'Gemini 3.7 Flash',
                        'description' => 'Fast, modern multi-token reasoning and function calling',
                        'recommendedFor' => 'Default daily usage & ReAct agent loops',
                    ],
                    'gemini-2.5-pro' => [
                        'name' => 'Gemini 2.5 Pro',
                        'description' => 'High-capacity analytical reasoning',
                        'recommendedFor' => 'Deep combinatorial analysis & large pools',
                    ],
                    'gemini-3.8-flash' => [
                        'name' => 'Gemini 3.8 Flash',
                        'description' => 'High-throughput next-generation Flash model',
                        'recommendedFor' => 'Fastest live tool executions',
                    ],
                    'gemini-2.5-flash' => [
                        'name' => 'Gemini 2.5 Flash',
                        'description' => 'Stable production flash model',
                        'recommendedFor' => 'Cost-effective analysis',
                    ],
                ],
                'fallbacks' => [
                    'gemini-3.7-flash',
                    'gemini-3.6-flash',
                    'gemini-3.5-flash',
                    'gemini-2.5-flash',
                    'gemini-flash-latest',
                    'gemini-pro-latest',
                    'gemini-2.5-pro',
                    'gemini-3.5-flash-lite',
                ],
            ],
            'claude' => [
                'displayName' => 'Anthropic Claude',
                'defaultModel' => 'claude-3-7-sonnet-20250219',
                'models' => [
                    'claude-3-7-sonnet-20250219' => [
                        'name' => 'Claude 3.7 Sonnet',
                        'description' => 'Hybrid reasoning with leading function calling & code precision',
                        'recommendedFor' => 'Best balance of statistical rigor and speed',
                    ],
                    'claude-3-opus-20240229' => [
                        'name' => 'Claude 3 Opus',
                        'description' => 'Deep analytical reasoning, complex mathematical formulation',
                        'recommendedFor' => 'Top quantitative data analysis & risk synthesis',
                    ],
                    'claude-3-5-sonnet-20241022' => [
                        'name' => 'Claude 3.5 Sonnet v2',
                        'description' => 'Industry-standard structured coding and analysis model',
                        'recommendedFor' => 'Reliable JSON outputs & verification',
                    ],
                    'claude-3-5-haiku-20241022' => [
                        'name' => 'Claude 3.5 Haiku',
                        'description' => 'Ultra-fast lightweight reasoning model',
                        'recommendedFor' => 'Quick single-turn commentary',
                    ],
                ],
                'fallbacks' => [
                    'claude-3-7-sonnet-20250219',
                    'claude-3-5-sonnet-20241022',
                    'claude-3-opus-20240229',
                    'claude-3-5-haiku-20241022',
                ],
            ],
            'openai' => [
                'displayName' => 'OpenAI',
                'defaultModel' => 'gpt-4o',
                'models' => [
                    'gpt-4o' => [
                        'name' => 'GPT-4o',
                        'description' => 'Flagship multimodal model with native JSON and function calling',
                        'recommendedFor' => 'All-around lottery strategy & balance evaluation',
                    ],
                    'o3-mini' => [
                        'name' => 'OpenAI o3-mini',
                        'description' => 'Specialized chain-of-thought STEM reasoning model',
                        'recommendedFor' => 'Complex logic and combinatorial calculations',
                    ],
                    'gpt-4o-mini' => [
                        'name' => 'GPT-4o Mini',
                        'description' => 'High speed, lightweight model',
                        'recommendedFor' => 'Low latency batch analyses',
                    ],
                    'gpt-4-turbo' => [
                        'name' => 'GPT-4 Turbo',
                        'description' => 'High context accuracy fallback',
                        'recommendedFor' => 'Historical stability',
                    ],
                ],
                'fallbacks' => [
                    'gpt-4o',
                    'gpt-4o-mini',
                    'gpt-4-turbo',
                ],
            ],
            'deepseek' => [
                'displayName' => 'DeepSeek',
                'defaultModel' => 'deepseek-chat',
                'models' => [
                    'deepseek-chat' => [
                        'name' => 'DeepSeek-V3',
                        'description' => 'Open-weight state-of-the-art model for logic and text',
                        'recommendedFor' => 'Budget-friendly high-performance data processing',
                    ],
                    'deepseek-reasoner' => [
                        'name' => 'DeepSeek-R1',
                        'description' => 'Dedicated mathematical reasoning and verification model',
                        'recommendedFor' => 'Rigorous probability evaluation & trend hypothesis',
                    ],
                ],
                'fallbacks' => [
                    'deepseek-chat',
                    'deepseek-reasoner',
                ],
            ],
        ];
    }
}
