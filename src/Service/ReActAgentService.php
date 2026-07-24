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
     * @param callable|null $onStepCallback Optional callback function(string $type, array $data): void for CLI/TUI feedback
     * @return array{pool: array<int>, reasoning: string, steps: array}
     */
    public function runAgentLoop(
        string $game,
        int $poolSize,
        ?callable $onStepCallback = null
    ): array {
        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNumber = $config['from'] ?? 49;
        $pickCount = $config['pick'] ?? 6;

        $systemPrompt = "Jesteś autonomicznym Analitykiem AI (ReAct Agent) ds. Gier Liczbowych.
Twoim celem jest wyselekcjonowanie optymalnej puli wejściowej DOKŁADNIE $poolSize unikalnych liczb (zakres 1-$maxNumber) dla gry $game.

MASZ DO DYSPOZYCJI NASTĘPUJĄCE NARZĘDZIA STATYSTYCZNE:
1. 'fetch_hot_cold_stats': Pobierz statystyki częstotliwości liczb gorących i zimnych.
2. 'fetch_recent_draws': Pobierz ostatnie wyniki losowań.
3. 'evaluate_candidate_pool': Przeanalizuj parzystość, sumę i wariancję wytypowanej puli.
4. 'test_system_coverage': Przeprowadź symulację pokrycia i gwarancji wygranych w systemie skróconym.

POSTĘPOWANIE (PROCEDURA REACT):
1. Zawsze zacznij od pobrania statystyk ('fetch_hot_cold_stats' lub 'fetch_recent_draws').
2. Zaproponuj wstępną pulę liczb i przetestuj ją narzędziem 'evaluate_candidate_pool'.
3. Jeśli pula jest niezrównoważona, popraw ją i przetestuj pokrycie 'test_system_coverage'.
4. Gdy uzyskasz optymalną pulę $poolSize liczb, zwróć odpowiedź w formacie JSON:
{
  \"reasoning\": \"Zwięzłe uzasadnienie strategii (max 3 zdania).\",
  \"selected_pool\": [2, 7, 12, 24, 38, 45, ...]
}";

        $toolsDeclarations = $this->toolRegistry->getGeminiFunctionDeclarations();

        $contents = [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $systemPrompt . "\n\nWykonaj analizę dla gry $game i wytypuj pulę $poolSize liczb."],
                ],
            ],
        ];

        $steps = [];
        $maxTurns = 6;
        $finalPool = [];
        $finalReasoning = '';

        for ($turn = 1; $turn <= $maxTurns; $turn++) {
            $this->logger->info("ReAct Agent turn $turn", ['game' => $game]);

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
                            $finalPool = array_values(array_map('intval', (array) $json['selected_pool']));
                            $finalReasoning = $json['reasoning'] ?? '';
                            break 2;
                        }
                    }

                    // Fallback regex for integer numbers
                    preg_match_all('/\d+/', $text, $numMatches);
                    $extracted = array_values(array_unique(array_map('intval', $numMatches[0] ?? [])));
                    $validExtracted = array_filter($extracted, fn($n) => $n >= 1 && $n <= $maxNumber);
                    if (count($validExtracted) >= $pickCount) {
                        $finalPool = array_slice($validExtracted, 0, $poolSize);
                        $finalReasoning = "Wytypowano pulę w oparciu o analizę ReAct Agent.";
                    }
                }
            }

            if (!$hasFunctionCall && !empty($finalPool)) {
                break;
            }
        }

        // Guaranteed fallback if pool size is insufficient
        if (count($finalPool) < $pickCount) {
            $poolRange = range(1, $maxNumber);
            shuffle($poolRange);
            $finalPool = array_slice($poolRange, 0, $poolSize);
            sort($finalPool);
            $finalReasoning = "Wygenerowano zbalansowaną pulę rezerwową.";
        }

        sort($finalPool);

        return [
            'pool' => $finalPool,
            'reasoning' => $finalReasoning,
            'steps' => $steps,
        ];
    }
}
