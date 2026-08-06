<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\AgentTools\ToolRegistry;
use Psr\Log\LoggerInterface;

class ReActAgentService
{
    public function __construct(
        private readonly GeminiApiClient $geminiApiClient,
        private readonly ToolRegistry $toolRegistry,
        private readonly GameRegistryService $gameRegistryService,
        private readonly LoggerInterface $logger
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
     * @return array{pool: array<int>, selected_pool: array<int>, reasoning: string, steps: array}
     */
    public function runAgentLoop(
        string $game,
        int $poolSize,
        string|callable|null $strategy = 'balanced',
        ?callable $onStepCallback = null,
        ?int $sessions = null,
        ?int $months = null
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

        $strategyInstruction = $strategy === 'aggressive'
            ? "STRATEGIA SELEKCJI: Ostra Gra (Łowca Trendów - maksymalne ryzyko na klastry i liczby gorące). Skup się w 80-90% na najczęstszych liczbach (Gorących) i ich bezpośrednich sąsiadach/anomaliach. Zignoruj klasyczny balans parzystości czy równomierny rozkład w zakresie."
            : "STRATEGIA SELEKCJI: Zbalansowana (Klasyczna Hybryda, równowaga). Wybierz około 60-70% liczb Gorących oraz 30-40% Zimnych. Zadbaj o zrównoważony balans parzyste/nieparzyste i równomierny rozkład w zakresie.";

        $timeframeInstruction = '';
        if ($sessions !== null && $sessions > 0) {
            $timeframeInstruction = "\nZAKRES ANALIZY: Analizuj dane wyłącznie dla OSTATNICH $sessions LOSOWAŃ. Wywołując narzędzie 'fetch_hot_cold_stats' lub 'fetch_recent_draws', UŻYWAJ parametru 'sessions': $sessions (oraz 'count': $sessions).";
        } elseif ($months !== null && $months > 0) {
            $timeframeInstruction = "\nZAKRES ANALIZY: Analizuj dane dla ostatnich $months miesięcy. Wywołując narzędzie 'fetch_hot_cold_stats', UŻYWAJ parametru 'months': $months.";
        }

        $systemPrompt = "Jesteś autonomicznym Analitykiem AI (ReAct Agent) ds. Gier Liczbowych.
Twoim celem jest wyselekcjonowanie optymalnej puli wejściowej DOKŁADNIE $poolSize unikalnych liczb (zakres 1-$maxNumber) dla gry $game.

$strategyInstruction$timeframeInstruction

MASZ DO DYSPOZYCJI NASTĘPUJĄCE NARZĘDZIA STATYSTYCZNE:
1. 'fetch_hot_cold_stats': Pobierz statystyki częstotliwości liczb gorących i zimnych.
2. 'fetch_recent_draws': Pobierz ostatnie wyniki losowań.
3. 'evaluate_candidate_pool': Przeanalizuj parzystość, sumę i wariancję wytypowanej puli.
4. 'test_system_coverage': Przeprowadź symulację pokrycia i gwarancji wygranych w systemie skróconym.

POSTĘPOWANIE (PROCEDURA REACT):
1. Zawsze zacznij od pobrania statystyk ('fetch_hot_cold_stats' lub 'fetch_recent_draws').
2. Zaproponuj wstępną pulę $poolSize liczb i przetestuj ją narzędziem 'evaluate_candidate_pool'.
3. Dostosuj pulę zgodnie ze wskazaną strategią ($strategy) i przetestuj pokrycie narzędziem 'test_system_coverage'.
4. Po przeanalizowaniu narzędziami, zwróć końcowy wynik WYŁĄCZNIE w formacie JSON:
{
  \"reasoning\": \"Zwięzłe uzasadnienie strategii i wybranych liczb (max 3 zdania).\",
  \"selected_pool\": [2, 7, 12, 24, 38, ...]
}";

        $toolsDeclarations = $this->toolRegistry->getGeminiFunctionDeclarations();

        $contents = [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $systemPrompt . "\n\nWykonaj analizę dla gry $game i wytypuj pulę $poolSize liczb stosując strategię: $strategy."],
                ],
            ],
        ];

        $steps = [];
        $maxTurns = 8;
        $finalPool = [];
        $finalReasoning = '';
        $lastEvaluatedPool = [];

        for ($turn = 1; $turn <= $maxTurns; $turn++) {
            $this->logger->info("ReAct Agent turn $turn", ['game' => $game, 'strategy' => $strategy]);

            $content = $this->geminiApiClient->generateContentWithTools($contents, $toolsDeclarations);
            $parts = $content['parts'] ?? [];

            if (empty($parts)) {
                break;
            }

            $hasFunctionCall = false;

            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    $hasFunctionCall = true;
                    $call = $part['functionCall'];
                    $toolName = $call['name'] ?? '';
                    $toolArgs = $call['args'] ?? [];

                    // Capture candidate pool if passed to tool
                    if (isset($toolArgs['numbers']) && is_array($toolArgs['numbers'])) {
                        $candidateNums = array_values(array_unique(array_map('intval', $toolArgs['numbers'])));
                        $validNums = array_values(array_filter($candidateNums, fn($n) => $n >= 1 && $n <= $maxNumber));
                        if (!empty($validNums)) {
                            $lastEvaluatedPool = $validNums;
                        }
                    } elseif (isset($toolArgs['pool']) && is_array($toolArgs['pool'])) {
                        $candidateNums = array_values(array_unique(array_map('intval', $toolArgs['pool'])));
                        $validNums = array_values(array_filter($candidateNums, fn($n) => $n >= 1 && $n <= $maxNumber));
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
                    $stepLog['result'] = json_decode($toolResultJson, true);
                    $steps[] = $stepLog;

                    if ($onStepCallback) {
                        $onStepCallback('tool_result', $stepLog);
                    }

                    // Append assistant turn preserving exact parts returned by Gemini (including thought_signatures)
                    $contents[] = [
                        'role' => 'model',
                        'parts' => $parts,
                    ];

                    // Append functionResponse turn for Gemini API v1beta (role: user)
                    $decodedResult = json_decode($toolResultJson, true);
                    $contents[] = [
                        'role' => 'user',
                        'parts' => [
                            [
                                'functionResponse' => [
                                    'name' => $toolName,
                                    'response' => is_array($decodedResult) ? $decodedResult : ['output' => $toolResultJson],
                                ],
                            ],
                        ],
                    ];

                    break; // Handle one function call per loop iteration
                } elseif (isset($part['text'])) {
                    $text = $part['text'];

                    if ($onStepCallback) {
                        $onStepCallback('thought', ['text' => $text]);
                    }

                    // Check if JSON final response is returned
                    if (preg_match('/\{.*"selected_pool".*\}/s', $text, $match)) {
                        $json = json_decode($match[0], true);
                        if (is_array($json) && isset($json['selected_pool'])) {
                            $extracted = array_values(array_unique(array_map('intval', (array) $json['selected_pool'])));
                            $validPool = array_values(array_filter($extracted, fn($n) => $n >= 1 && $n <= $maxNumber));
                            if (count($validPool) >= $pickCount) {
                                $finalPool = array_slice($validPool, 0, $poolSize);
                                $finalReasoning = $json['reasoning'] ?? '';
                                break 2;
                            }
                        }
                    }

                    // Fallback regex for integer numbers
                    preg_match_all('/\d+/', $text, $numMatches);
                    $extracted = array_values(array_unique(array_map('intval', $numMatches[0] ?? [])));
                    $validExtracted = array_values(array_filter($extracted, fn($n) => $n >= 1 && $n <= $maxNumber));
                    if (count($validExtracted) >= $pickCount) {
                        $finalPool = array_slice($validExtracted, 0, $poolSize);
                        $finalReasoning = "Wytypowano pulę w oparciu o analizę ReAct Agent ($strategy).";
                    }
                }
            }

            if (!$hasFunctionCall && !empty($finalPool)) {
                break;
            }
        }

        // If turn limit reached or JSON final response missing, attempt one final prompt without tools
        if (count($finalPool) < $pickCount) {
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => "Na podstawie wszystkich wykonanych kroków przedstaw ostateczną pulę DOKŁADNIE $poolSize liczb w formacie JSON:\n{\n  \"reasoning\": \"Uzasadnienie strategii ($strategy)...\",\n  \"selected_pool\": [ ... ]\n}"],
                ],
            ];

            try {
                $finalContent = $this->geminiApiClient->generateContentWithTools($contents, []);
                $parts = $finalContent['parts'] ?? [];
                foreach ($parts as $part) {
                    if (isset($part['text'])) {
                        $text = $part['text'];
                        if (preg_match('/\{.*"selected_pool".*\}/s', $text, $match)) {
                            $json = json_decode($match[0], true);
                            if (is_array($json) && isset($json['selected_pool'])) {
                                $extracted = array_values(array_unique(array_map('intval', (array) $json['selected_pool'])));
                                $validPool = array_values(array_filter($extracted, fn($n) => $n >= 1 && $n <= $maxNumber));
                                if (count($validPool) >= $pickCount) {
                                    $finalPool = array_slice($validPool, 0, $poolSize);
                                    $finalReasoning = $json['reasoning'] ?? '';
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Błąd podczas pobierania ostatecznego podsumowania ReAct: ' . $e->getMessage());
            }
        }

        // Guaranteed fallback using evaluated pool or smart range
        if (count($finalPool) < $pickCount) {
            if (!empty($lastEvaluatedPool)) {
                $poolRange = range(1, $maxNumber);
                $merged = array_values(array_unique(array_merge($lastEvaluatedPool, $poolRange)));
                $finalPool = array_slice($merged, 0, $poolSize);
                $finalReasoning = sprintf("Wytypowano pulę na podstawie przeprowadzonej analizy statystyk i ewaluacji narzędziami ReAct (%s).", $strategy);
            } else {
                $poolRange = range(1, $maxNumber);
                shuffle($poolRange);
                $finalPool = array_slice($poolRange, 0, $poolSize);
                $finalReasoning = sprintf("Wygenerowano pulę rezerwową (%s).", $strategy);
            }
        }

        sort($finalPool);

        return [
            'pool' => $finalPool,
            'selected_pool' => $finalPool,
            'reasoning' => $finalReasoning,
            'steps' => $steps,
        ];
    }
}
