<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\DrawHistoryProvider;
use App\Service\LottoApiClient;

class FetchPairCoOccurrenceTool implements LottoToolInterface
{
    /** Ponizej tylu losowan statystyka par jest zbyt rzadka. */
    private const MIN_DRAWS = 20;

    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly DrawHistoryProvider $drawHistoryProvider
    ) {
    }

    public function getName(): string
    {
        return 'fetch_pair_co_occurrence';
    }

    public function getDescription(): string
    {
        return 'Oblicza RZECZYWISTE wspolwystepowanie par: ile razy dwie liczby padly w tym samym losowaniu. Liczone z historii losowan. Przy zbyt krotkiej historii zwraca heurystyke i oznacza to polem is_estimate.';
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
            return json_encode(['error' => "Nieprawidlowa nazwa gry: $game"]);
        }

        $targetNumbers = isset($args['target_numbers']) && is_array($args['target_numbers'])
            ? array_values(array_unique(array_map('intval', $args['target_numbers'])))
            : [];

        $history = $this->drawHistoryProvider->getHistory($game, 100);
        $draws = array_map(static fn(array $d): array => $d['main'], $history['draws']);

        if (count($draws) >= self::MIN_DRAWS) {
            return json_encode($this->fromDrawHistory($game, $draws, $targetNumbers));
        }

        return json_encode($this->fromFrequencyFallback($game, $targetNumbers, count($draws)));
    }

    /**
     * Prawdziwe wspolwystepowanie: ile razy para (A, B) padla w tym samym losowaniu.
     *
     * @param array<int, array<int>> $draws
     * @param array<int> $targetNumbers
     */
    private function fromDrawHistory(string $game, array $draws, array $targetNumbers): array
    {
        $counts = [];
        $weightedCounts = [];
        $tripletCounts = [];
        $tripletWeighted = [];

        foreach (array_values($draws) as $drawIdx => $draw) {
            $sortedDraw = array_values(array_unique(array_map('intval', $draw)));
            sort($sortedDraw);
            $n = count($sortedDraw);
            $weight = 1.0 + (0.6 * exp(-max(0, $drawIdx) / 15.0));

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $sortedDraw[$i];
                    $b = $sortedDraw[$j];
                    $counts[$a][$b] = ($counts[$a][$b] ?? 0) + 1;
                    $weightedCounts[$a][$b] = ($weightedCounts[$a][$b] ?? 0.0) + $weight;

                    for ($k = $j + 1; $k < $n; $k++) {
                        $c = $sortedDraw[$k];
                        $tKey = sprintf('%d-%d-%d', $a, $b, $c);
                        $tripletCounts[$tKey] = ($tripletCounts[$tKey] ?? 0) + 1;
                        $tripletWeighted[$tKey] = ($tripletWeighted[$tKey] ?? 0.0) + $weight;
                    }
                }
            }
        }

        $pairs = [];
        foreach ($counts as $a => $partners) {
            foreach ($partners as $b => $c) {
                if ($targetNumbers !== []
                    && !in_array($a, $targetNumbers, true)
                    && !in_array($b, $targetNumbers, true)) {
                    continue;
                }
                $pairs[] = [
                    'pair' => [$a, $b],
                    'times_drawn_together' => $c,
                    'recency_weighted_score' => round($weightedCounts[$a][$b] ?? (float) $c, 2),
                ];
            }
        }

        usort($pairs, static function (array $x, array $y): int {
            $cmp = $y['times_drawn_together'] <=> $x['times_drawn_together'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $y['recency_weighted_score'] <=> $x['recency_weighted_score'];
        });

        $triplets = [];
        foreach ($tripletCounts as $tKey => $c) {
            if ($c < 2 && count($tripletCounts) > 15) {
                continue;
            }
            $nums = array_map('intval', explode('-', $tKey));
            if ($targetNumbers !== [] && array_intersect($nums, $targetNumbers) === []) {
                continue;
            }
            $triplets[] = [
                'triplet' => $nums,
                'times_drawn_together' => $c,
                'recency_weighted_score' => round($tripletWeighted[$tKey] ?? (float) $c, 2),
            ];
        }

        usort($triplets, static function (array $x, array $y): int {
            $cmp = $y['times_drawn_together'] <=> $x['times_drawn_together'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $y['recency_weighted_score'] <=> $x['recency_weighted_score'];
        });

        return [
            'game' => $game,
            'source' => 'draw_history',
            'is_estimate' => false,
            'draws_analyzed' => count($draws),
            'definition' => 'times_drawn_together = liczba losowan, w ktorych liczby padly razem (z waga swiezosci EMA)',
            'top_pairs' => array_slice($pairs, 0, 15),
            'top_triplets' => array_slice($triplets, 0, 10),
            'target_numbers_checked' => $targetNumbers,
        ];
    }

    /**
     * @param array<int> $targetNumbers
     */
    private function fromFrequencyFallback(string $game, array $targetNumbers, int $drawsFound): array
    {
        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($game, 40);
        $stats = $this->lottoApiClient->getHotColdNumbers($game, $dateFrom);

        if (!$stats) {
            return ['error' => 'Brak danych do analizy par'];
        }

        $freqDesc = $stats['sorted_by_freq_desc'] ?? [];
        $topNumbers = array_keys(array_slice($freqDesc, 0, 15, true));
        if ($targetNumbers !== []) {
            $topNumbers = array_values(array_unique(array_merge($targetNumbers, $topNumbers)));
        }

        $pairs = [];
        $count = count($topNumbers);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $n1 = min($topNumbers[$i], $topNumbers[$j]);
                $n2 = max($topNumbers[$i], $topNumbers[$j]);
                $pairs[] = [
                    'pair' => [$n1, $n2],
                    'heuristic_score' => (int) round(sqrt(($freqDesc[$n1] ?? 1) * ($freqDesc[$n2] ?? 1))),
                ];
            }
        }
        usort($pairs, fn($a, $b) => $b['heuristic_score'] <=> $a['heuristic_score']);

        return [
            'game' => $game,
            'source' => 'frequency_fallback',
            'is_estimate' => true,
            'draws_found' => $drawsFound,
            'warning' => 'Za malo historii losowan (' . $drawsFound . ' < ' . self::MIN_DRAWS . '), '
                . 'wiec to NIE jest wspolwystepowanie par, tylko sqrt(f(A)*f(B)). '
                . 'Najwyzej ocenione pary to po prostu dwie najgoretsze liczby.',
            'top_pairs' => array_slice($pairs, 0, 10),
            'target_numbers_checked' => $targetNumbers,
        ];
    }
}
