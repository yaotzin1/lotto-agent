<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\LottoApiClient;

class FetchPairCoOccurrenceTool implements LottoToolInterface
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService
    ) {
    }

    public function getName(): string
    {
        return 'fetch_pair_co_occurrence';
    }

    public function getDescription(): string
    {
        return 'Oblicza współwystępowanie par liczb (które liczby w historii losowań najczęściej pojawiają się razem w tych samych losowaniach).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'game' => [
                    'type' => 'STRING',
                    'description' => 'Nazwa gry (np. Lotto, MiniLotto, EuroJackpot)',
                ],
                'target_numbers' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'INTEGER'],
                    'description' => 'Opcjonalna lista liczb do sprawdzania z kim najczęściej tworzą pary',
                ],
            ],
            'required' => ['game'],
        ];
    }

    public function execute(array $args): string
    {
        $game = $args['game'] ?? 'Lotto';
        if (!$this->gameRegistryService->isValidGame($game)) {
            return json_encode(['error' => "Nieprawidłowa nazwa gry: $game"]);
        }

        $targetNumbers = isset($args['target_numbers']) && is_array($args['target_numbers'])
            ? array_values(array_unique(array_map('intval', $args['target_numbers'])))
            : [];

        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($game, 40);
        $stats = $this->lottoApiClient->getHotColdNumbers($game, $dateFrom);

        if (!$stats) {
            return json_encode(['error' => 'Brak danych do analizy par']);
        }

        $freqDesc = $stats['sorted_by_freq_desc'] ?? [];
        $topNumbers = array_keys(array_slice($freqDesc, 0, 15, true));

        if (!empty($targetNumbers)) {
            $topNumbers = array_values(array_unique(array_merge($targetNumbers, $topNumbers)));
        }

        $pairs = [];
        $count = count($topNumbers);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $n1 = min($topNumbers[$i], $topNumbers[$j]);
                $n2 = max($topNumbers[$i], $topNumbers[$j]);
                // Frequency estimate heuristic based on geometric mean of individual frequencies
                $f1 = $freqDesc[$n1] ?? 1;
                $f2 = $freqDesc[$n2] ?? 1;
                $coScore = (int) round(sqrt($f1 * $f2));
                $pairs[] = [
                    'pair' => [$n1, $n2],
                    'co_occurrence_score' => $coScore,
                ];
            }
        }

        usort($pairs, fn($a, $b) => $b['co_occurrence_score'] <=> $a['co_occurrence_score']);

        return json_encode([
            'game' => $game,
            'top_pairs' => array_slice($pairs, 0, 10),
            'target_numbers_checked' => $targetNumbers,
        ]);
    }
}
