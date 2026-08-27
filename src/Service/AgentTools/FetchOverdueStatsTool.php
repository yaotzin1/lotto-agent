<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\DrawHistoryProvider;
use App\Service\LottoApiClient;

class FetchOverdueStatsTool implements LottoToolInterface
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly DrawHistoryProvider $drawHistoryProvider
    ) {
    }

    public function getName(): string
    {
        return 'fetch_overdue_stats';
    }

    public function getDescription(): string
    {
        return 'Oblicza RZECZYWISTE zaległości liczb: ile losowań minęło od ostatniego wystąpienia '
            . 'każdej liczby (0 = padła w ostatnim losowaniu). Liczone z historii losowań. '
            . 'Gdy historia jest niedostępna, zwraca oszacowanie z częstotliwości i oznacza to polem "is_estimate".';
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
                    'description' => 'Liczba ostatnich losowań do przeanalizowania (domyślnie 50)',
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
        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNum = $config['from'] ?? 49;

        // Historia wraca od NAJNOWSZEGO losowania, więc indeks pierwszego
        // trafienia == liczba losowań, które minęły od wystąpienia.
        $history = $this->drawHistoryProvider->getHistory($game, $sessions);
        $draws = array_map(static fn(array $d): array => $d['main'], $history['draws']);

        if ($draws !== []) {
            $out = $this->fromDrawHistory($game, $draws, $maxNum);
            $out['cache'] = ['from_cache' => $history['from_cache'], 'fetched' => $history['fetched'], 'rate_limited' => $history['rate_limited']];

            return json_encode($out);
        }

        return json_encode($this->fromFrequencyFallback($game, $sessions, $maxNum));
    }

    /**
     * @param array<int, array<int>> $draws
     */
    private function fromDrawHistory(string $game, array $draws, int $maxNum): array
    {
        $totalDraws = count($draws);
        $stats = [];

        for ($num = 1; $num <= $maxNum; $num++) {
            $drawsSinceLast = null;
            $occurrences = 0;

            foreach ($draws as $index => $draw) {
                if (in_array($num, $draw, true)) {
                    $occurrences++;
                    if ($drawsSinceLast === null) {
                        $drawsSinceLast = $index;
                    }
                }
            }

            $stats[] = [
                'number' => $num,
                // null => nie padła w oknie; traktujemy jako "co najmniej $totalDraws"
                'draws_since_last_seen' => $drawsSinceLast ?? $totalDraws,
                'never_seen_in_window' => $drawsSinceLast === null,
                'occurrences' => $occurrences,
            ];
        }

        $byOverdue = $stats;
        usort($byOverdue, fn($a, $b) => $b['draws_since_last_seen'] <=> $a['draws_since_last_seen']);

        $byRecent = $stats;
        usort($byRecent, fn($a, $b) => $a['draws_since_last_seen'] <=> $b['draws_since_last_seen']);

        return [
            'game' => $game,
            'source' => 'draw_history',
            'is_estimate' => false,
            'total_draws_analyzed' => $totalDraws,
            'definition' => 'draws_since_last_seen = liczba losowań od ostatniego wystąpienia (0 = ostatnie losowanie)',
            'most_overdue_numbers' => array_column(array_slice($byOverdue, 0, 12), 'number'),
            'most_overdue_details' => array_slice($byOverdue, 0, 12),
            'most_recently_seen_numbers' => array_column(array_slice($byRecent, 0, 12), 'number'),
        ];
    }

    /**
     * Ścieżka awaryjna: bez historii da się policzyć wyłącznie ŚREDNI odstęp,
     * który jest monotoniczny względem częstotliwości — czyli ranking pokrywa
     * się z listą liczb zimnych. Musi być wyraźnie oznaczony.
     */
    private function fromFrequencyFallback(string $game, int $sessions, int $maxNum): array
    {
        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($game, $sessions);
        $stats = $this->lottoApiClient->getHotColdNumbers($game, $dateFrom);

        if (!$stats) {
            return ['error' => 'Brak danych do analizy zaległości'];
        }

        $freqDesc = $stats['sorted_by_freq_desc'] ?? [];
        $totalDraws = $stats['draws_analyzed'] ?? $sessions;

        $overdueStats = [];
        for ($num = 1; $num <= $maxNum; $num++) {
            $occurrences = $freqDesc[$num] ?? 0;
            $overdueStats[] = [
                'number' => $num,
                'occurrences' => $occurrences,
                'average_gap_between_draws' => $occurrences > 0
                    ? (int) round($totalDraws / $occurrences)
                    : $totalDraws,
            ];
        }

        usort($overdueStats, fn($a, $b) => $a['occurrences'] <=> $b['occurrences']);

        return [
            'game' => $game,
            'source' => 'frequency_fallback',
            'is_estimate' => true,
            'warning' => 'Historia losowań niedostępna. Poniższe wartości to ŚREDNI odstęp '
                . '(totalDraws / occurrences), a NIE czas od ostatniego wystąpienia. '
                . 'Ten ranking jest równoważny liście liczb zimnych.',
            'total_draws_analyzed' => $totalDraws,
            'coldest_numbers' => array_column(array_slice($overdueStats, 0, 12), 'number'),
            'coldest_details' => array_slice($overdueStats, 0, 12),
        ];
    }
}
