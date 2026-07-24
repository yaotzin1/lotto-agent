<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\LottoApiClient;

class FetchRecentDrawsTool implements LottoToolInterface
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService
    ) {
    }

    public function getName(): string
    {
        return 'fetch_recent_draws';
    }

    public function getDescription(): string
    {
        return 'Pobiera wykaz ostatnich wyników losowań (numery wygrane) dla podanej gry.';
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
                'count' => [
                    'type' => 'INTEGER',
                    'description' => 'Liczba losowań do pobrania (domyślnie 10)',
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

        $count = isset($args['count']) ? max(1, min((int) $args['count'], 50)) : 10;
        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($game, $count);

        $draws = $this->lottoApiClient->getDrawResults($game, $dateFrom, $count);

        if (empty($draws)) {
            return json_encode(['error' => 'Brak ostatnich wyników losowań z Lotto OpenAPI']);
        }

        return json_encode([
            'game' => $game,
            'fetched_draws_count' => count($draws),
            'draws' => $draws,
        ]);
    }
}
