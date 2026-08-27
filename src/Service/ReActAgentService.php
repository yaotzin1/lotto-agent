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
     * @param bool $includeNeighbours Whether to include neighbouring numbers (+1/-1) in analysis
     * @return array{pool: array<int>, selected_pool: array<int>, reasoning: string, steps: array}
     */
    public function runAgentLoop(
        string $game,
        int $poolSize,
        string|callable|null $strategy = 'balanced',
        ?callable $onStepCallback = null,
        ?int $sessions = null,
        ?int $months = null,
        bool $includeNeighbours = false
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

        if ($strategy === 'syndicate') {
            $strategyInstruction = "STRATEGIA SELEKCJI: Syndykat Klastrowy (Cluster-Breakout Strategy).
Pula wejściowa DOKŁADNIE $poolSize liczb MUSI składać się z trzech składowych w ścisłych proporcjach:
1. SĄSIEDZI (ok. 60% puli): Wybierz matematycznych sąsiadów (+1/-1) ostatnich liczb wygranych o najwyższej sile klastra (użyj narzędzia 'fetch_neighbours_analysis').
2. POWTÓRKI WYGRANYCH (ok. 20% puli): Wybierz bezpośrednie liczby wygrane z ostatniego losowania (anchors).
3. ZIMNE / IZOLOWANE (ok. 20% puli): Dobierz uśpione liczby z odległych stref bębna (dystans > 2 od strefy wygranych) dla przełamania serii.
Koniecznie wywołaj narzędzie 'fetch_neighbours_analysis' w Fazie 1!";
        } elseif ($strategy === 'aggressive') {
            $strategyInstruction = "STRATEGIA SELEKCJI: Ostra Gra (Łowca Trendów - maksymalne ryzyko na klastry i liczby gorące). Skup się w 80-90% na najczęstszych liczbach (Gorących) i ich bezpośrednich sąsiadach/anomaliach. Zignoruj klasyczny balans parzystości czy równomierny rozkład w zakresie.";
        } else {
            $strategyInstruction = "STRATEGIA SELEKCJI: Zbalansowana (Klasyczna Hybryda, równowaga). Wybierz około 60-70% liczb Gorących oraz 30-40% Zimnych/Opóźnionych. Zadbaj o zrównoważony balans parzyste/nieparzyste i równomierny rozkład w zakresie.";
        }

        $timeframeInstruction = '';
        if ($sessions !== null && $sessions > 0) {
            $timeframeInstruction = "\nZAKRES ANALIZY: Analizuj dane wyłącznie dla OSTATNICH $sessions LOSOWAŃ. Wywołując narzędzie 'fetch_neighbours_analysis', 'fetch_hot_cold_stats', 'fetch_overdue_stats' lub 'fetch_recent_draws', UŻYWAJ parametru 'sessions': $sessions.";
        } elseif ($months !== null && $months > 0) {
            $timeframeInstruction = "\nZAKRES ANALIZY: Analizuj dane dla ostatnich $months miesięcy. Wywołując narzędzie 'fetch_neighbours_analysis' lub 'fetch_hot_cold_stats', UŻYWAJ parametru 'months': $months.";
        }

        $neighbourInstruction = $includeNeighbours
            ? "\nUWZGLĘDNIANIE SĄSIADÓW (+1/-1): Zwróć szczególną uwagę na dodawanie matematycznych sąsiadów liczb gorących do puli kandydującej."
            : '';

        $systemPrompt = "Jesteś autonomicznym Głównym Analitykiem AI (ReAct Agent) ds. Gier Liczbowych.
Twoim celem jest wyselekcjonowanie optymalnej puli wejściowej DOKŁADNIE $poolSize unikalnych liczb (zakres 1-$maxNumber) dla gry $game.

$strategyInstruction$timeframeInstruction$neighbourInstruction

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

        $contents = [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => "Wykonaj analizę dla gry $game i wytypuj pulę $poolSize liczb stosując strategię: $strategy."],
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

            $content = $this->geminiApiClient->generateContentWithTools($contents, $toolsDeclarations, $systemPrompt);
            $parts = $content['parts'] ?? [];

            if (empty($parts)) {
                break;
            }

            $hasFunctionCall = false;

            // Gemini może zwrócić KILKA wywołań w jednej turze. Wcześniej
            // wykonywane było tylko pierwsze, ale do historii trafiały wszystkie
            // części modelu i tylko JEDNA odpowiedź — czyli historia niezgodna
            // z kontraktem v1beta. Teraz zbieramy odpowiedzi na wszystkie.
            $functionResponseParts = [];

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

                    $decodedResult = json_decode($toolResultJson, true);
                    $functionResponseParts[] = [
                        'functionResponse' => [
                            'name' => $toolName,
                            'response' => is_array($decodedResult) ? $decodedResult : ['output' => $toolResultJson],
                        ],
                    ];

                    continue;
                } elseif (isset($part['text'])) {
                    $text = $part['text'];

                    if (isset($part['thought']) && $part['thought'] === true) {
                        if ($onStepCallback) {
                            $onStepCallback('thought', ['text' => $text]);
                        }
                        continue;
                    }

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

                    // ŚWIADOMIE BEZ regexu po samych liczbach.
                    // Wcześniej `preg_match_all('/\d+/', $text)` zamieniało zdanie
                    // "Analiza 50 losowań, 60% sąsiadów, 20% powtórek" w pulę
                    // [50, 60, 20]. Akceptujemy wyłącznie kontrakt JSON powyżej.
                }
            }

            if ($hasFunctionCall) {
                // Tura modelu zachowana w całości (łącznie z thought_signatures),
                // a po niej komplet odpowiedzi narzędziowych.
                $contents[] = ['role' => 'model', 'parts' => $parts];
                $contents[] = ['role' => 'user', 'parts' => $functionResponseParts];

                continue;
            }

            if (!empty($finalPool)) {
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
                    if (isset($part['thought']) && $part['thought'] === true) {
                        continue;
                    }
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
        $isFallback = false;

        if (count($finalPool) < $pickCount) {
            $isFallback = true;

            if (count($lastEvaluatedPool) >= $pickCount) {
                // Pula, którą agent sam poddał ewaluacji — bez dosypywania 1,2,3...
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
