<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\DrawHistoryProvider;
use App\Service\LottoApiClient;

class FetchRecentDrawsTool implements LottoToolInterface
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly DrawHistoryProvider $drawHistoryProvider
    ) {
    }

    public function getName(): string
    {
        return 'fetch_recent_draws';
    }

    public function getDescription(): string
    {
        return 'Pobiera RZECZYWISTE wyniki ostatnich losowan (liczby wygrane), od najnowszego do najstarszego. Pole draws_ago = 0 oznacza ostatnie losowanie.';
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
            return json_encode(['error' => "Nieprawidlowa nazwa gry: $game"]);
        }

        $count = isset($args['count']) ? max(1, min((int) $args['count'], 50)) : 10;

        // Prawdziwe wyniki losowan, od najnowszego do najstarszego.
        $draws = $this->drawHistoryProvider->getHistory($game, $count)['draws'];

        if ($draws === []) {
            return json_encode([
                'error' => 'Brak wynikow losowan z LOTTO OpenAPI',
                'hint' => 'Endpoint historii losowan moze byc chwilowo niedostepny. '
                    . 'Uzyj fetch_hot_cold_stats, aby otrzymac statystyki czestotliwosci.',
            ]);
        }

        $draws = array_slice($draws, 0, $count);
        $config = $this->gameRegistryService->getGameConfig($game);
        $hasSpecial = ($config['extra'] ?? 0) > 0;

        $formatted = [];
        foreach ($draws as $i => $draw) {
            $entry = [
                'draws_ago' => $i,
                'numbers' => $draw['main'],
            ];
            if ($hasSpecial && $draw['special'] !== []) {
                $entry['special_numbers'] = $draw['special'];
            }
            $formatted[] = $entry;
        }

        return json_encode([
            'game' => $game,
            'source' => 'draw_history',
            'fetched_draws_count' => count($formatted),
            'most_recent_draw' => $formatted[0]['numbers'] ?? [],
            'draws' => $formatted,
        ]);
    }
}
