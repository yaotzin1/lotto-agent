<?php

declare(strict_types=1);

namespace App\Service\Llm;

interface LlmClientInterface
{
    /**
     * Provider identifier (e.g. 'gemini', 'claude', 'openai', 'deepseek').
     */
    public function getProviderName(): string;

    /**
     * Current active model name.
     */
    public function getModel(): string;

    /**
     * Return a client instance configured with a specific model name.
     */
    public function withModel(string $model): self;

    /**
     * Text generation (e.g. for LottoStatsCommand AI commentary or prompts).
     */
    public function generateText(string $prompt, ?string $systemInstruction = null, float $temperature = 0.4): string;

    /**
     * Executes one turn with tool calling support.
     *
     * @param array<int, array{role: string, content?: string, thought?: ?string, tool_calls?: array, results?: array, raw?: mixed}> $messages
     * @param array<int, array{name: string, description: string, parameters: array}> $tools Declarations from ToolRegistry
     * @param string|null $systemInstruction
     * @return array{text: string, thought: ?string, tool_calls: array<int, array{id: string, name: string, args: array}>, raw?: mixed}
     */
    public function chatWithTools(array $messages, array $tools, ?string $systemInstruction = null): array;

    /**
     * Ask for candidate pool numbers.
     *
     * @return array<int>
     */
    public function askForPool(
        string $gameType,
        string $hotStr,
        string $coldStr,
        int $poolSize,
        string $strategy = 'balanced',
        int $maxNumber = 0
    ): array;

    /**
     * Ask for coupon recommendations.
     */
    public function askForRecommendation(
        string $gameType,
        int $pickCount,
        int $betsCount,
        string $hotStr,
        string $coldStr,
        bool $includeNeighbours,
        bool $isJson
    ): string;
}
