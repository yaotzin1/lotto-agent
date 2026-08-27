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
        return 'Symuluje gwarancje trafień dla podanej puli i zakładów. WAŻNE: wynik jest WARUNKOWY - zakłada, że wszystkie wylosowane liczby leżą w podanej puli.';
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

        $config = $this->gameRegistryService->getGameConfig($game);
        $pick = $config['pick'] ?? 6;

        if (empty($pool) || count($pool) < $pick) {
            return json_encode(['error' => sprintf('Pula musi zawierać co najmniej %d liczb', $pick)]);
        }

        $bets = $this->betGeneratorService->generateBalancedShorthand($pool, $pick, $betsCount);
        $coverage = $this->betGeneratorService->calculateCoverage($pool, $bets, $pick);

        return json_encode([
            'game' => $game,
            'pool_size' => count($pool),
            'bets_generated' => count($bets),
            'sample_bets' => array_slice($bets, 0, 3),
            'guarantees' => $coverage['guarantees'] ?? [],
            // Bez tego pola model raportował gwarancje jako bezwarunkowe.
            'assumption' => sprintf(
                'UWAGA: gwarancje są WARUNKOWE. Zakładają, że wszystkie %d wylosowanych liczb '
                . 'znajduje się w podanej puli %d liczb. Bezwarunkowa szansa jest znacznie niższa.',
                $pick,
                count($pool)
            ),
        ]);
    }
}
