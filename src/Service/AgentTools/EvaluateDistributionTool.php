<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;

class EvaluateDistributionTool implements LottoToolInterface
{
    public function __construct(
        private readonly GameRegistryService $gameRegistryService
    ) {
    }

    public function getName(): string
    {
        return 'evaluate_distribution';
    }

    public function getDescription(): string
    {
        return 'Przeprowadza szczegółową analizę rozkładu dekadowego (zakresy 1-10, 11-20 itd.), wariancji i pozycji sumy na krzywej Gaussa dla proponowanej puli wejściowej.';
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
                'pool' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'INTEGER'],
                    'description' => 'Proponowana pula liczb do analizy rozkładu',
                ],
            ],
            'required' => ['game', 'pool'],
        ];
    }

    public function execute(array $args): string
    {
        $game = $args['game'] ?? 'Lotto';
        $pool = $args['pool'] ?? [];

        if (!is_array($pool) || empty($pool)) {
            return json_encode(['error' => 'Podana pula liczb jest pusta lub nieprawidłowa']);
        }

        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);

        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNum = $config['from'] ?? 49;
        $pickCount = $config['pick'] ?? 6;

        // Decade breakdown (1-10, 11-20, 21-30, 31-40, 41+)
        $decades = [];
        foreach ($pool as $num) {
            $decadeIndex = (int) floor(($num - 1) / 10);
            $start = $decadeIndex * 10 + 1;
            $end = min(($decadeIndex + 1) * 10, $maxNum);
            $key = sprintf('%d-%d', $start, $end);
            $decades[$key] = ($decades[$key] ?? 0) + 1;
        }

        // Calculate expected sum range for pick numbers
        // Expected average number = (1 + maxNum) / 2
        $expectedAverage = ($maxNum + 1) / 2.0;
        $expectedSumCenter = $pickCount * $expectedAverage;
        $sumMargin = $pickCount * 6; // Standard deviation margin
        $minOptimalSum = (int) round($expectedSumCenter - $sumMargin);
        $maxOptimalSum = (int) round($expectedSumCenter + $sumMargin);

        // Average sum of candidate subsets
        $poolSum = array_sum($pool);
        $poolAvg = count($pool) > 0 ? $poolSum / count($pool) : 0;
        $subsetSumEst = (int) round($poolAvg * $pickCount);

        $isSumOptimal = ($subsetSumEst >= $minOptimalSum && $subsetSumEst <= $maxOptimalSum);

        return json_encode([
            'game' => $game,
            'pool_size' => count($pool),
            'decade_distribution' => $decades,
            'subset_estimated_sum' => $subsetSumEst,
            'optimal_sum_range' => sprintf('%d - %d', $minOptimalSum, $maxOptimalSum),
            'is_sum_within_gaussian_bell' => $isSumOptimal,
            'spread_coverage' => sprintf('%.1f%%', (count($decades) / max(1, ceil($maxNum / 10))) * 100),
        ]);
    }
}
