<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;

class EvaluatePoolTool implements LottoToolInterface
{
    public function __construct(
        private readonly GameRegistryService $gameRegistryService
    ) {
    }

    public function getName(): string
    {
        return 'evaluate_candidate_pool';
    }

    public function getDescription(): string
    {
        return 'Ewaluuje proponowaną pulę liczb pod kątem parzystości/nieparzystości, sumy oraz rozkładu niska/wysoka.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'game' => [
                    'type' => 'STRING',
                    'description' => 'Nazwa gry (np. Lotto, MiniLotto)',
                ],
                'numbers' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'INTEGER',
                    ],
                    'description' => 'Tablica proponowanych liczb puli do analizy',
                ],
            ],
            'required' => ['game', 'numbers'],
        ];
    }

    public function execute(array $args): string
    {
        $game = $args['game'] ?? 'Lotto';
        $numbers = $args['numbers'] ?? [];

        if (!is_array($numbers) || empty($numbers)) {
            return json_encode(['error' => 'Podana pula liczb jest pusta lub nieprawidłowa']);
        }

        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers);

        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNum = $config['from'] ?? 49;
        $midPoint = (int) ceil($maxNum / 2);

        $oddCount = 0;
        $evenCount = 0;
        $lowCount = 0;
        $highCount = 0;
        $sum = array_sum($numbers);

        foreach ($numbers as $num) {
            if ($num % 2 === 0) {
                $evenCount++;
            } else {
                $oddCount++;
            }

            if ($num <= $midPoint) {
                $lowCount++;
            } else {
                $highCount++;
            }
        }

        $consecutiveCount = 0;
        for ($i = 0; $i < count($numbers) - 1; $i++) {
            if ($numbers[$i + 1] === $numbers[$i] + 1) {
                $consecutiveCount++;
            }
        }

        return json_encode([
            'game' => $game,
            'numbers_count' => count($numbers),
            'numbers' => $numbers,
            'sum_total' => $sum,
            'odd_count' => $oddCount,
            'even_count' => $evenCount,
            'odd_even_ratio' => sprintf('%d:%d', $oddCount, $evenCount),
            'low_count' => $lowCount,
            'high_count' => $highCount,
            'consecutive_pairs' => $consecutiveCount,
            'is_balanced' => ($oddCount > 0 && $evenCount > 0 && abs($oddCount - $evenCount) <= 3),
        ]);
    }
}
