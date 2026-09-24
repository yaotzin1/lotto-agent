<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\AgentTools\ToolRegistry;
use App\Service\Llm\LlmClientResolver;
use Psr\Log\LoggerInterface;

class ReActAgentService
{
    public function __construct(
        private readonly GeminiApiClient $geminiApiClient,
        private readonly ToolRegistry $toolRegistry,
        private readonly GameRegistryService $gameRegistryService,
        private readonly LoggerInterface $logger,
        private readonly DecadeDistributionService $decadeDistributionService = new DecadeDistributionService(),
        private readonly ?LlmClientResolver $llmClientResolver = null
    ) {
    }

    /**
     * Executes the ReAct Agent reasoning & tool loop for lottery analysis.
     *
     * @param string $game Game name (e.g. Lotto, MiniLotto)
     * @param int $poolSize Number of numbers to pick in candidate pool
     * @param string|callable|null $strategy AI strategy ('balanced', 'aggressive') or callback if legacy
     * @param callable|null $onStepCallback Optional callback function(string $type, array $data): void for CLI/TUI feedback
     * @param int|null $sessions Number of recent sessions to analyze
     * @param int|null $months Number of recent months to analyze
     * @param bool $includeNeighbours Whether to include neighbouring numbers (+1/-1) in analysis
     * @param bool $coverDecades Whether to balance selection evenly across all drum decades
     * @param string|null $provider AI provider to use ('gemini', 'claude', 'openai', 'deepseek')
     * @param string|null $model Specific model override (e.g. 'claude-3-7-sonnet-20250219', 'gpt-4o')
     * @return array{pool: array<int>, selected_pool: array<int>, reasoning: string, steps: array, is_fallback: bool}
     */
    public function runAgentLoop(
        string $game,
        int $poolSize,
        string|callable|null $strategy = 'balanced',
        ?callable $onStepCallback = null,
        ?int $sessions = null,
        ?int $months = null,
        bool $includeNeighbours = false,
        bool $coverDecades = false,
        ?string $provider = null,
        ?string $model = null
    ): array {
        if (is_callable($strategy)) {
            $onStepCallback = $strategy;
            $strategy = 'balanced';
        }
        if (empty($strategy) || !is_string($strategy)) {
            $strategy = 'balanced';
        }

        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNumber = $config['from'] ?? 49;
        $pickCount = $config['pick'] ?? 6;

        if ($strategy === 'decades' || $coverDecades) {
            $decQuotas = $this->decadeDistributionService->calculateDecadeQuotas($poolSize, $maxNumber);
            $decades = $this->decadeDistributionService->getDecadesForGame($maxNumber);
            $quotaLines = [];
            foreach ($decades as $idx => $d) {
                $quotaLines[] = sprintf("• Dekada %s: %d liczb", $d['label'], $decQuotas[$idx] ?? 0);
            }
            $decadeInstruction = "\nWYMAGANIE DEKADOWE (KRYTYCZNE DLA STRATEGII DECADES):\n"
                . "Twoja ostateczna pula $poolSize liczb MUSI zachować równomierny rozkład wg poniższych limitów:\n"
                . implode("\n", $quotaLines) . "\n"
                . "Użyj 'evaluate_distribution', aby upewnić się, że żadna dekada nie została pominięta ani przeładowana.\n";
        } else {
            $decadeInstruction = '';
        }

        $strategyGuidance = match ($strategy) {
            'syndicate' => "1. Strategia Syndykatu Klastrowego:\n"
                . "   - Zbuduj zbalansowaną pulę $poolSize liczb opierając się na analizie klastrów.\n"
                . "   - Dobierz ok. 50-60% liczb z grupy najsilniejszych klastrów sąsiedzkich (sprawdź narzędziem 'fetch_neighbours_analysis').\n"
                . "   - Dobierz ok. 20-30% liczb powtórkowych (zwycięskie liczby z poprzednich losowań wykazujące tendencję do serii).\n"
                . "   - Dobierz ok. 10-20% liczb 'uśpionych' z wysokim wskaźnikiem opóźnienia ('fetch_overdue_stats') jako zabezpieczenie na przełamanie trendu.",
            'aggressive' => "1. Strategia Ostrej Gry (Łowca Trendów - Maksymalne Ryzyko):\n"
                . "   - Cel: Skupienie na silnych anomaliach i powtarzających się klastrach.\n"
                . "   - Dobierz 80-90% liczb z ekstremalnie gorących ('fetch_hot_cold_stats') oraz ich bezpośrednich sąsiadów (+1/-1).\n"
                . "   - Zignoruj równomierny rozkład – celuj w silne zagęszczenia w okolicach najczęstszych trafień.\n"
                . "   - Szukaj par o najwyższym współwystępowaniu ('fetch_pair_co_occurrence').",
            default => "1. Strategia Zbalansowana (Klasyczna Hybryda):\n"
                . "   - Zbuduj zrównoważoną pulę $poolSize liczb łączącą kontynuację trendu i powrót do średniej.\n"
                . "   - Wybierz ok. 60% liczb Gorących (o najwyższej frekwencji) oraz 40% liczb Zimnych / Uśpionych.\n"
                . "   - Sprawdź parzystość i rozrzut sumy narzędziem 'evaluate_candidate_pool'.",
        };

        $neighbourInstruction = $includeNeighbours
            ? "\nZASADA SĄSIEDZTWA (+1/-1): Użytkownik jawnie włączył analizę sąsiadów. Jeśli liczba X jest gorąca lub często pada, koniecznie przeanalizuj liczby X-1 oraz X+1 jako potencjalnych kandydatów do puli."
            : '';

        $timeWindowInstruction = $sessions
            ? "OKNO CZASOWE: Ostatnie $sessions losowań."
            : ($months ? "OKNO CZASOWE: Ostatnie $months miesięcy." : "OKNO CZASOWE: Ostatnie 50 losowań (domyślnie).");

        $systemPrompt = "Jesteś Głównym Analitykiem Statystycznym i Kombinatorycznym w elitarnym syndykacie loteryjnym.
Twój cel: Przeprowadzić rygorystyczną, wieloetapową analizę matematyczną i wyselekcjonować kandydacką pulę DOKŁADNIE $poolSize unikalnych liczb dla gry $game (losujemy $pickCount z $maxNumber liczb).

ZASADY ANALIZY:
$strategyGuidance
$decadeInstruction
$neighbourInstruction
$timeWindowInstruction

MASZ DO DYSPOZYCJI NASTĘPUJĄCE NARZĘDZIA STATYSTYCZNE:
1. 'fetch_neighbours_analysis': Zaawansowana analiza klastrowa sąsiadów (+1/-1), powtórek wygranych i liczb izolowanych.
2. 'fetch_hot_cold_stats': Pobierz statystyki częstotliwości liczb gorących i zimnych.
3. 'fetch_overdue_stats': Oblicz opóźnienia i uśpienia liczb (reversion-to-the-mean).
4. 'fetch_pair_co_occurrence': Pobierz najczęstsze pary liczb występujące razem.
5. 'fetch_recent_draws': Pobierz ostatnie wyniki losowań.
6. 'evaluate_candidate_pool': Przeanalizuj parzystość, sumę i pary w proponowanej puli.
7. 'evaluate_distribution': Przeanalizuj rozkład dekadowy i pozycję sumy na krzywej Gaussa.
8. 'test_system_coverage': Przeprowadź symulację pokrycia w systemie skróconym.

PROCEDURA REACT (4 FAZY REAZONOWANIA):
Faza 1 [Eksploracja]: Użyj narzędzi statystycznych ('fetch_neighbours_analysis', 'fetch_hot_cold_stats', 'fetch_overdue_stats' lub 'fetch_pair_co_occurrence'), aby zebrać dane historyczne.
Faza 2 [Hipoteza]: Sformułuj hipotezę doboru $poolSize liczb zgodną ze strategią ($strategy).
Faza 3 [Weryfikacja]: Przetestuj proponowaną pulę narzędziami 'evaluate_candidate_pool' oraz 'evaluate_distribution'.
Faza 4 [Synteza]: Zwróć końcowy wynik WYŁĄCZNIE w formacie JSON:
{
  \"reasoning\": \"Zwięzłe uzasadnienie strategii i wybranych liczb (max 3 zdania).\",
  \"selected_pool\": [2, 7, 12, 24, 38, ...]
}";

        $toolsDeclarations = $this->toolRegistry->getGeminiFunctionDeclarations();

        $client = $this->llmClientResolver !== null
            ? $this->llmClientResolver->resolve($provider, $model)
            : ($model !== null ? $this->geminiApiClient->withModel($model) : $this->geminiApiClient);

        $messages = [
            [
                'role' => 'user',
                'content' => "Wykonaj analizę dla gry $game i wytypuj pulę $poolSize liczb stosując strategię: $strategy.",
            ],
        ];

        $steps = [];
        $maxTurns = 8;
        $finalPool = [];
        $finalReasoning = '';
        $lastEvaluatedPool = [];

        for ($turn = 1; $turn <= $maxTurns; $turn++) {
            $this->logger->info("ReAct Agent turn $turn", [
                'game' => $game,
                'strategy' => $strategy,
                'provider' => $client->getProviderName(),
                'model' => $client->getModel(),
            ]);

            $turnResponse = $client->chatWithTools($messages, $toolsDeclarations, $systemPrompt);

            if (!empty($turnResponse['thought']) && $onStepCallback) {
                $onStepCallback('thought', ['text' => $turnResponse['thought']]);
            }

            $toolCalls = $turnResponse['tool_calls'] ?? [];
            $text = $turnResponse['text'] ?? '';

            if (empty($toolCalls) && empty($text)) {
                break;
            }

            if (!empty($text) && $onStepCallback) {
                $onStepCallback('thought', ['text' => $text]);
            }

            if (!empty($toolCalls)) {
                $toolResults = [];

                foreach ($toolCalls as $call) {
                    $toolName = (string) ($call['name'] ?? '');
                    $toolArgs = (array) ($call['args'] ?? []);

                    // Capture candidate pool if passed to tool
                    if (isset($toolArgs['numbers']) && is_array($toolArgs['numbers'])) {
                        $candidateNums = array_values(array_unique(array_map('intval', $toolArgs['numbers'])));
                        $validNums = array_values(array_filter($candidateNums, static fn(int $n): bool => $n >= 1 && $n <= $maxNumber));
                        if (!empty($validNums)) {
                            $lastEvaluatedPool = $validNums;
                        }
                    } elseif (isset($toolArgs['pool']) && is_array($toolArgs['pool'])) {
                        $candidateNums = array_values(array_unique(array_map('intval', $toolArgs['pool'])));
                        $validNums = array_values(array_filter($candidateNums, static fn(int $n): bool => $n >= 1 && $n <= $maxNumber));
                        if (!empty($validNums)) {
                            $lastEvaluatedPool = $validNums;
                        }
                    }

                    $stepLog = [
                        'turn' => $turn,
                        'tool' => $toolName,
                        'args' => $toolArgs,
                    ];

                    if ($onStepCallback) {
                        $onStepCallback('tool_call', $stepLog);
                    }

                    $toolResultJson = $this->toolRegistry->executeTool($toolName, $toolArgs);
                    $decodedResult = json_decode($toolResultJson, true);
                    $stepLog['result'] = $decodedResult;
                    $steps[] = $stepLog;

                    if ($onStepCallback) {
                        $onStepCallback('tool_result', $stepLog);
                    }

                    $toolResults[] = [
                        'id' => $call['id'] ?? $toolName,
                        'name' => $toolName,
                        'result' => is_array($decodedResult) ? $decodedResult : ['output' => $toolResultJson],
                        'result_json' => $toolResultJson,
                    ];
                }

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $text,
                    'tool_calls' => $toolCalls,
                    'raw' => $turnResponse['raw'] ?? null,
                ];

                $messages[] = [
                    'role' => 'tool_results',
                    'results' => $toolResults,
                ];

                continue;
            }

            // No tool calls: check if JSON final response is returned
            if (preg_match('/\{.*"selected_pool".*\}/s', $text, $match)) {
                $json = json_decode($match[0], true);
                if (is_array($json) && isset($json['selected_pool'])) {
                    $extracted = array_values(array_unique(array_map('intval', (array) $json['selected_pool'])));
                    $validPool = array_values(array_filter($extracted, static fn(int $n): bool => $n >= 1 && $n <= $maxNumber));
                    if (count($validPool) >= $pickCount) {
                        $finalPool = array_slice($validPool, 0, $poolSize);
                        $finalReasoning = $json['reasoning'] ?? '';
                        break;
                    }
                }
            }

            if (!empty($finalPool)) {
                break;
            }
        }

        // If turn limit reached or JSON final response missing, attempt one final prompt without tools
        if (count($finalPool) < $pickCount) {
            $messages[] = [
                'role' => 'user',
                'content' => "Na podstawie wszystkich wykonanych kroków przedstaw ostateczną pulę DOKŁADNIE $poolSize liczb w formacie JSON:\n{\n  \"reasoning\": \"Uzasadnienie strategii ($strategy)...\",\n  \"selected_pool\": [ ... ]\n}",
            ];

            try {
                $finalResponse = $client->chatWithTools($messages, []);
                $text = $finalResponse['text'] ?? '';
                if (preg_match('/\{.*"selected_pool".*\}/s', $text, $match)) {
                    $json = json_decode($match[0], true);
                    if (is_array($json) && isset($json['selected_pool'])) {
                        $extracted = array_values(array_unique(array_map('intval', (array) $json['selected_pool'])));
                        $validPool = array_values(array_filter($extracted, static fn(int $n): bool => $n >= 1 && $n <= $maxNumber));
                        if (count($validPool) >= $pickCount) {
                            $finalPool = array_slice($validPool, 0, $poolSize);
                            $finalReasoning = $json['reasoning'] ?? '';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Błąd podczas pobierania ostatecznego podsumowania ReAct: ' . $e->getMessage());
            }
        }

        // Guaranteed fallback using evaluated pool or smart range
        $isFallback = false;

        if (count($finalPool) < $pickCount) {
            $isFallback = true;

            if ($strategy === 'decades' || $coverDecades) {
                $decadeRes = $this->decadeDistributionService->generateDecadePool($maxNumber, $poolSize, [], 'random', [], $includeNeighbours);
                $finalPool = $decadeRes['pool'];
                $finalReasoning = 'UWAGA: agent nie zwrócił użytecznego wyniku. Wygenerowano losową pulę zbalansowaną dekadowo w trybie awaryjnym.';
            } elseif (count($lastEvaluatedPool) >= $pickCount) {
                $finalPool = array_slice($lastEvaluatedPool, 0, $poolSize);
                $finalReasoning = sprintf(
                    'UWAGA: agent nie zwrócił poprawnej odpowiedzi JSON. Użyto ostatniej puli, '
                    . 'którą sam przekazał do narzędzia ewaluacyjnego (%d liczb, strategia %s). '
                    . 'To NIE jest pełny wynik analizy.',
                    count($finalPool),
                    $strategy
                );
            } else {
                $poolRange = range(1, $maxNumber);
                shuffle($poolRange);
                $finalPool = array_slice($poolRange, 0, $poolSize);
                $finalReasoning = sprintf(
                    'UWAGA: agent nie zwrócił użytecznego wyniku. Wygenerowano pulę LOSOWĄ (%s). '
                    . 'Nie stoi za nią żadna analiza statystyczna.',
                    $strategy
                );
            }

            $this->logger->warning('ReAct Agent nie zwrócił poprawnej puli; użyto trybu awaryjnego.', [
                'game' => $game,
                'strategy' => $strategy,
                'had_evaluated_pool' => count($lastEvaluatedPool) >= $pickCount,
            ]);
        }

        sort($finalPool);

        return [
            'pool' => $finalPool,
            'selected_pool' => $finalPool,
            'reasoning' => $finalReasoning,
            'steps' => $steps,
            'is_fallback' => $isFallback,
        ];
    }
}
