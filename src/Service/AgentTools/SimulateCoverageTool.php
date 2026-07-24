<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\BetGeneratorService;
use App\Service\GameRegistryService;

class SimulateCoverageTool implements LottoToolInterface
{
    public function __construct(
        private readonly BetGeneratorService $betGeneratorService,
        private readonly GameRegistryService $gameRegistryService
    ) {
    }

    public function getName(): string
    {
        return 'test_system_coverage';
    }

    public function getDescription(): string
    {
        return 'Symuluje i oblicza gwarancje trafień (system skrócony / pełny) dla podanej puli liczb i zakładów.';
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
                'pool' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'INTEGER'],
                    'description' => 'Pula wytypowanych liczb',
                ],
                'bets_count' => [
                    'type' => 'INTEGER',
                    'description' => 'Liczba generowanych zakładów',
                ],
            ],
            'required' => ['game', 'pool', 'bets_count'],
        ];
    }

    public function execute(array $args): string
    {
        $game = $args['game'] ?? 'Lotto';
        $pool = $args['pool'] ?? [];
        $betsCount = isset($args['bets_count']) ? (int) $args['bets_count'] : 5;

        if (empty($pool) || count($pool) < 6) {
            return json_encode(['error' => 'Pula musi zawierać co najmniej 6 liczb']);
        }

        $config = $this->gameRegistryService->getGameConfig($game);
        $pick = $config['pick'] ?? 6;

        $bets = $this->betGeneratorService->generateBalancedShorthand($pool, $pick, $betsCount);
        $coverage = $this->betGeneratorService->calculateCoverage($pool, $bets, $pick);

        return json_encode([
            'game' => $game,
            'pool_size' => count($pool),
            'bets_generated' => count($bets),
            'sample_bets' => array_slice($bets, 0, 3),
            'guarantees' => $coverage['guarantees'] ?? [],
        ]);
    }
}
