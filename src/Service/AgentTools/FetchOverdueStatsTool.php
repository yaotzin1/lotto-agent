<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\LottoApiClient;

class FetchOverdueStatsTool implements LottoToolInterface
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService
    ) {
    }

    public function getName(): string
    {
        return 'fetch_overdue_stats';
    }

    public function getDescription(): string
    {
        return 'Analizuje historię losowań i oblicza opóźnienie (liczbę losowań od ostatniego wystąpienia) dla liczb w grze (statystyka powrotu do średniej).';
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
                'sessions' => [
                    'type' => 'INTEGER',
                    'description' => 'Liczba ostatnich losowań do przeanalizowania opóźnień (domyślnie 50)',
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

        $sessions = isset($args['sessions']) ? max(10, min((int) $args['sessions'], 200)) : 50;
        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($game, $sessions);

        $stats = $this->lottoApiClient->getHotColdNumbers($game, $dateFrom);
        if (!$stats) {
            return json_encode(['error' => 'Brak danych do analizy opóźnień']);
        }

        $freqDesc = $stats['sorted_by_freq_desc'] ?? [];
        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNum = $config['from'] ?? 49;
        $totalDraws = $stats['draws_analyzed'] ?? $sessions;

        $overdueStats = [];
        for ($num = 1; $num <= $maxNum; $num++) {
            $occurrences = $freqDesc[$num] ?? 0;
            $estimatedDrawGap = $occurrences > 0 ? (int) round($totalDraws / $occurrences) : $totalDraws;
            $overdueStats[$num] = [
                'number' => $num,
                'occurrences' => $occurrences,
                'estimated_draw_gap' => $estimatedDrawGap,
            ];
        }

        usort($overdueStats, fn($a, $b) => $a['occurrences'] <=> $b['occurrences']);

        $mostOverdue = array_slice($overdueStats, 0, 12);
        $mostRecent = array_reverse(array_slice($overdueStats, -12));

        return json_encode([
            'game' => $game,
            'total_draws_analyzed' => $totalDraws,
            'most_overdue_numbers' => array_column($mostOverdue, 'number'),
            'most_overdue_details' => $mostOverdue,
            'most_frequent_numbers' => array_column($mostRecent, 'number'),
        ]);
    }
}
