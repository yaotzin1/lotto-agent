<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AgentTools\LottoToolInterface;
use App\Service\AgentTools\ToolRegistry;
use App\Service\DecadeDistributionService;
use App\Service\GameRegistryService;
use App\Service\GeminiApiClient;
use App\Service\Llm\LlmCommonPromptsTrait;
use App\Service\ReActAgentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Testy jednostkowe dla promptów AI (LlmCommonPromptsTrait) oraz pętli ReAct
 * (automatyczne wstrzykiwanie parametrów sessions/months/game do narzędzi i instrukcje systemowe).
 */
class ReActAgentAndPromptsTest extends TestCase
{
    public function testReActAgentInjectsCliSessionsAndGameIntoHistoryToolCalls(): void
    {
        $capturedSystemPrompts = [];
        $capturedToolArgs = [];

        $mockTool = new class($capturedToolArgs) implements LottoToolInterface {
            /** @var array<int, array<string, mixed>> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function getName(): string
            {
                return 'fetch_pair_co_occurrence';
            }

            public function getDescription(): string
            {
                return 'Mock pair tool';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'OBJECT', 'properties' => []];
            }

            public function execute(array $args): string
            {
                $this->captured[] = $args;

                return json_encode(['draws_analyzed' => $args['sessions'] ?? 0, 'top_pairs' => []]);
            }
        };

        $mockRecentTool = new class($capturedToolArgs) implements LottoToolInterface {
            /** @var array<int, array<string, mixed>> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function getName(): string
            {
                return 'fetch_recent_draws';
            }

            public function getDescription(): string
            {
                return 'Mock recent draws tool';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'OBJECT', 'properties' => []];
            }

            public function execute(array $args): string
            {
                $this->captured[] = $args;

                return json_encode(['fetched_draws_count' => $args['count'] ?? 0]);
            }
        };

        $toolRegistry = new ToolRegistry([$mockTool, $mockRecentTool]);

        $geminiMock = $this->createMock(GeminiApiClient::class);
        $turn = 0;
        $geminiMock->method('chatWithTools')->willReturnCallback(
            static function (array $messages, array $tools, string $systemPrompt) use (&$turn, &$capturedSystemPrompts): array {
                $turn++;
                $capturedSystemPrompts[] = $systemPrompt;

                if ($turn === 1) {
                    // Model wywołuje narzędzia BEZ podania game oraz sessions
                    return [
                        'thought' => 'Sprawdzam pary i ostatnie losowania.',
                        'text' => '',
                        'tool_calls' => [
                            ['id' => 'call_1', 'name' => 'fetch_pair_co_occurrence', 'args' => ['top_k' => 5]],
                            ['id' => 'call_2', 'name' => 'fetch_recent_draws', 'args' => []],
                        ],
                    ];
                }

                return [
                    'thought' => 'Gotowe.',
                    'text' => json_encode([
                        'reasoning' => 'Wybrano zbalansowaną pulę na bazie 5 sesji.',
                        'selected_pool' => [2, 5, 9, 14, 19, 23, 28, 31, 36, 40, 41, 42],
                    ]),
                    'tool_calls' => [],
                ];
            }
        );

        $agent = new ReActAgentService(
            $geminiMock,
            $toolRegistry,
            new GameRegistryService(),
            new NullLogger(),
            new DecadeDistributionService()
        );

        $result = $agent->runAgentLoop(
            'MiniLotto',
            12,
            'syndicate',
            null,
            5,
            null,
            true,
            true
        );

        self::assertFalse($result['is_fallback']);
        self::assertCount(12, $result['selected_pool']);

        // Sprawdzenie, czy ReActAgentService wstrzyknął game='MiniLotto' i sessions=5 do obu narzędzi
        self::assertCount(2, $capturedToolArgs);
        self::assertSame('MiniLotto', $capturedToolArgs[0]['game']);
        self::assertSame(5, $capturedToolArgs[0]['sessions']);
        self::assertSame('MiniLotto', $capturedToolArgs[1]['game']);
        self::assertSame(5, $capturedToolArgs[1]['sessions']);
        self::assertSame(5, $capturedToolArgs[1]['count']);

        // Sprawdzenie zawartości promptu systemowego
        $prompt = $capturedSystemPrompts[0];
        self::assertStringContainsString('OKNO CZASOWE: Ostatnie 5 losowań', $prompt);
        self::assertStringContainsString('Strategia Syndykatu Klastrowego', $prompt);
        self::assertStringContainsString('ZASADA SĄSIEDZTWA (+1/-1)', $prompt);
        self::assertStringContainsString('WYMAGANIE DEKADOWE', $prompt);
        self::assertStringContainsString('OCHRONA PRZED PRZEUCZENIEM (ANTY-OVERCLUSTERING)', $prompt);
        self::assertStringContainsString('shift_in_sigmas', $prompt);
    }

    public function testReActAgentInjectsMonthsWhenSessionsIsNull(): void
    {
        $capturedToolArgs = [];

        $mockOverdueTool = new class($capturedToolArgs) implements LottoToolInterface {
            /** @var array<int, array<string, mixed>> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function getName(): string
            {
                return 'fetch_overdue_stats';
            }

            public function getDescription(): string
            {
                return 'Mock overdue tool';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'OBJECT', 'properties' => []];
            }

            public function execute(array $args): string
            {
                $this->captured[] = $args;

                return json_encode(['total_draws_analyzed' => 30]);
            }
        };

        $toolRegistry = new ToolRegistry([$mockOverdueTool]);
        $geminiMock = $this->createMock(GeminiApiClient::class);
        $turn = 0;
        $geminiMock->method('chatWithTools')->willReturnCallback(
            static function () use (&$turn): array {
                $turn++;
                if ($turn === 1) {
                    return [
                        'thought' => 'Sprawdzam opóźnienia.',
                        'text' => '',
                        'tool_calls' => [
                            ['id' => 'c1', 'name' => 'fetch_overdue_stats', 'args' => []],
                        ],
                    ];
                }

                return [
                    'thought' => 'Koniec.',
                    'text' => json_encode([
                        'reasoning' => 'Analiza z 3 miesięcy.',
                        'selected_pool' => [1, 3, 7, 12, 18, 22, 27, 33, 39, 44],
                    ]),
                    'tool_calls' => [],
                ];
            }
        );

        $agent = new ReActAgentService(
            $geminiMock,
            $toolRegistry,
            new GameRegistryService(),
            new NullLogger()
        );

        $agent->runAgentLoop('Lotto', 10, 'balanced', null, null, 3);

        self::assertCount(1, $capturedToolArgs);
        self::assertSame('Lotto', $capturedToolArgs[0]['game']);
        self::assertSame(3, $capturedToolArgs[0]['months']);
    }

    public function testLlmCommonPromptsTraitSupportsAllStrategiesAndRangeFiltering(): void
    {
        $client = new class {
            use LlmCommonPromptsTrait;

            public string $lastPrompt = '';
            public ?string $lastSystemInstruction = null;
            public string $mockResponse = '2, 7, 14, 15, 28, 41, 42, 43, 99, 0';

            public function generateText(string $prompt, ?string $systemInstruction = null, float $temperature = 0.4): string
            {
                $this->lastPrompt = $prompt;
                $this->lastSystemInstruction = $systemInstruction;

                return $this->mockResponse;
            }
        };

        // 1. Strategia syndicate + filtrowanie do maxNumber=42 (MiniLotto) -> 43, 99 i 0 muszą odpaść
        $poolSyndicate = $client->askForPool('MiniLotto', '4, 17, 23', '1, 9, 38', 6, 'syndicate', 42);
        self::assertSame([2, 7, 14, 15, 28, 41], $poolSyndicate);
        self::assertStringContainsString('Strategia Syndykatu Klastrowego', (string) $client->lastSystemInstruction);
        self::assertStringContainsString('1..42', (string) $client->lastSystemInstruction);

        // 2. Strategia decades
        $client->askForPool('Lotto', '7, 13', '2, 48', 6, 'decades', 49);
        self::assertStringContainsString('Strategia Równomiernych Dekad', (string) $client->lastSystemInstruction);

        // 3. Strategia aggressive
        $client->askForPool('Lotto', '7, 13', '2, 48', 6, 'aggressive', 49);
        self::assertStringContainsString('Strategia Ostrej Gry', (string) $client->lastSystemInstruction);

        // 4. Strategia balanced (domyślna)
        $client->askForPool('Lotto', '7, 13', '2, 48', 6, 'balanced', 49);
        self::assertStringContainsString('Strategia Hybrydowa (Zbalansowana)', (string) $client->lastSystemInstruction);

        // 5. askForRecommendation z sąsiadami i bez sąsiadów (poprawna numeracja kroków)
        $client->askForRecommendation('MiniLotto', 5, 10, '4, 17', '1, 9', false, true);
        self::assertStringContainsString('3. Pokrycie i ochrona przed kanibalizacją par', (string) $client->lastSystemInstruction);
        self::assertStringNotContainsString('4. Pokrycie', (string) $client->lastSystemInstruction);
        self::assertStringContainsString('Struktura JSON:', (string) $client->lastSystemInstruction);

        $client->askForRecommendation('MiniLotto', 5, 10, '4, 17', '1, 9', true, false);
        self::assertStringContainsString('3. Klastrowanie sąsiadów (+1/-1)', (string) $client->lastSystemInstruction);
        self::assertStringContainsString('4. Pokrycie i ochrona przed kanibalizacją par', (string) $client->lastSystemInstruction);
        self::assertStringContainsString('**WYTYPOWANE LICZBY:**', (string) $client->lastSystemInstruction);
    }
}
