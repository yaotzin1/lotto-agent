<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\LottoApiClient;

class FetchHotColdStatsTool implements LottoToolInterface
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService
    ) {
    }

    public function getName(): string
    {
        return 'fetch_hot_cold_stats';
    }

    public function getDescription(): string
    {
        return 'Pobiera statystyki najczęściej i najrzadziej losowanych liczb (hot/cold) z Lotto OpenAPI dla wybranej gry.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'game' => [
                    'type' => 'STRING',
                    'description' => 'Nazwa gry (np. Lotto, MiniLotto, EuroJackpot, MultiMulti, EkstraPensja)',
                ],
                'months' => [
                    'type' => 'INTEGER',
                    'description' => 'Liczba ostatnich miesięcy do analizy (np. 6 lub 12)',
                ],
                'sessions' => [
                    'type' => 'INTEGER',
                    'description' => 'Ilość ostatnich losowań do analizy (opcjonalne, alternatywa dla miesięcy)',
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

        $sessions = isset($args['sessions']) ? (int) $args['sessions'] : null;
        $months = isset($args['months']) ? (int) $args['months'] : 6;

        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($game, $sessions, $months);
        $stats = $this->lottoApiClient->getHotColdNumbers($game, $dateFrom);

        if (!$stats) {
            return json_encode(['error' => 'Brak danych statystycznych z Lotto OpenAPI']);
        }

        return json_encode([
            'game' => $game,
            'date_from' => $stats['date_from'],
            'total_draws_analyzed' => $stats['draws_analyzed'],
            'hot_numbers' => array_slice($stats['sorted_by_freq_desc'], 0, 10, true),
            'cold_numbers' => array_slice($stats['sorted_by_freq_asc'], 0, 10, true),
        ]);
    }
}
