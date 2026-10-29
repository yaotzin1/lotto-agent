<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\DrawHistoryProvider;
use App\Service\GameRegistryService;
use App\Service\LottoApiClient;

class FetchNeighboursAnalysisTool implements LottoToolInterface
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly ?DrawHistoryProvider $drawHistoryProvider = null
    ) {
    }

    public function getName(): string
    {
        return 'fetch_neighbours_analysis';
    }

    public function getDescription(): string
    {
        return 'Przeprowadza zaawansowaną analizę klastrową syndykatu: wyznacza matematycznych sąsiadów (+1/-1) ostatnich liczb wygranych (60%), bezpośrednie powtórki wygranych (20%) oraz uśpione liczby izolowane z odległych stref bębna (20%).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'game' => [
                    'type' => 'STRING',
                    'description' => 'Nazwa gry (np. Lotto, MiniLotto, EuroJackpot, MultiMulti)',
                ],
                'sessions' => [
                    'type' => 'INTEGER',
                    'description' => 'Liczba ostatnich losowań do analizy (domyślnie 15)',
                ],
                'months' => [
                    'type' => 'INTEGER',
                    'description' => 'Liczba ostatnich miesięcy do analizy (alternatywa dla sessions)',
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

        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNum = $config['from'] ?? 49;
        $pickCount = $config['pick'] ?? 6;

        $sessions = isset($args['sessions']) ? max(5, min((int) $args['sessions'], 100)) : 15;
        $months = isset($args['months']) ? (int) $args['months'] : 6;

        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($game, $sessions, $months);
        $stats = $this->lottoApiClient->getHotColdNumbers($game, $dateFrom);
        $history = $this->drawHistoryProvider?->getHistory($game, max($sessions, 20));
        $draws = isset($history['draws']) && is_array($history['draws'])
            ? array_values(array_filter(array_map(static fn(array $d): array => $d['main'] ?? [], $history['draws'])))
            : [];

        if (!$stats && $draws === []) {
            return json_encode(['error' => 'Brak danych statystycznych do analizy sąsiedztwa i klastrów']);
        }

        $freqDesc = $stats['sorted_by_freq_desc'] ?? [];
        $freqAsc = $stats['sorted_by_freq_asc'] ?? [];

        if ($freqDesc === [] && $draws !== []) {
            $counts = array_fill(1, $maxNum, 0);
            foreach ($draws as $draw) {
                foreach ($draw as $n) {
                    if ($n >= 1 && $n <= $maxNum) {
                        $counts[$n]++;
                    }
                }
            }
            $freqDesc = $counts;
            arsort($freqDesc);
            $freqAsc = $counts;
            asort($freqAsc);
        }

        $hasRealDraws = $draws !== [];
        $latestDraw = $hasRealDraws ? $draws[0] : [];

        // 1. Wygrane kotwice (Anchors): z rzeczywistego ostatniego losowania (jeśli dostępne) + najsilniejsze z okna
        if ($hasRealDraws) {
            $anchorTarget = max($pickCount + 2, 8);
            $winningAnchors = $latestDraw;
            foreach (array_keys($freqDesc) as $hotNum) {
                if (count($winningAnchors) >= $anchorTarget) {
                    break;
                }
                if (!in_array((int) $hotNum, $winningAnchors, true)) {
                    $winningAnchors[] = (int) $hotNum;
                }
            }
        } else {
            $winningAnchors = array_keys(array_slice($freqDesc, 0, max($pickCount + 2, 8), true));
        }

        // 2. Bezpośrednie powtórki (20% udziału) - priorytet dla liczb z ostatniego losowania
        $repeatCandidates = [];
        $repeatSource = $hasRealDraws ? $latestDraw : array_slice($winningAnchors, 0, $pickCount);
        foreach ($repeatSource as $num) {
            $repeatCandidates[] = [
                'number' => $num,
                'occurrences' => $freqDesc[$num] ?? 0,
                'in_latest_draw' => $hasRealDraws,
                'role' => 'direct_repeat',
            ];
        }
        usort($repeatCandidates, static fn(array $a, array $b): int => $b['occurrences'] <=> $a['occurrences']);

        // 3. Sąsiedzi (+1 / -1) wokół wygranych kotwic (60% udziału)
        $neighbourCandidates = [];
        $neighbourMap = [];

        foreach ($winningAnchors as $anchor) {
            $isLatestAnchor = in_array($anchor, $latestDraw, true);
            $left = $anchor - 1;
            $right = $anchor + 1;

            if ($left >= 1) {
                $neighbourMap[$left]['anchors'][] = $anchor;
                $neighbourMap[$left]['latest_anchors_count'] = ($neighbourMap[$left]['latest_anchors_count'] ?? 0) + ($isLatestAnchor ? 1 : 0);
                $neighbourMap[$left]['occurrences'] = $freqDesc[$left] ?? 0;
            }
            if ($right <= $maxNum) {
                $neighbourMap[$right]['anchors'][] = $anchor;
                $neighbourMap[$right]['latest_anchors_count'] = ($neighbourMap[$right]['latest_anchors_count'] ?? 0) + ($isLatestAnchor ? 1 : 0);
                $neighbourMap[$right]['occurrences'] = $freqDesc[$right] ?? 0;
            }
        }

        foreach ($neighbourMap as $num => $data) {
            $anchorCount = count(array_unique($data['anchors']));
            $latestAnchorBonus = ($data['latest_anchors_count'] ?? 0) * 6;
            $occurrences = $data['occurrences'];
            // Wynik klastrowy: liczba przyległych wygranych * 10 + premia za ostatnie losowanie + częstotliwość
            $clusterScore = ($anchorCount * 10) + $latestAnchorBonus + $occurrences;

            $neighbourCandidates[] = [
                'number' => $num,
                'adjacent_to_anchors' => array_values(array_unique($data['anchors'])),
                'occurrences' => $occurrences,
                'cluster_strength' => $clusterScore,
                'is_itself_winning_anchor' => in_array($num, $winningAnchors, true),
            ];
        }

        usort($neighbourCandidates, fn($a, $b) => $b['cluster_strength'] <=> $a['cluster_strength']);

        // 4. Uśpione liczby izolowane (20% udziału) - odległość > 2 od wszystkich kotwic wygranych
        $isolatedColdCandidates = [];
        $coldOrder = array_keys($freqAsc);
        if ($coldOrder === []) {
            $coldOrder = range(1, $maxNum);
        }
        foreach ($coldOrder as $num) {
            $num = (int) $num;
            $isNearAnchor = false;
            foreach ($winningAnchors as $anchor) {
                if (abs($num - $anchor) <= 2) {
                    $isNearAnchor = true;
                    break;
                }
            }

            if (!$isNearAnchor) {
                $drawsSinceLast = null;
                if ($hasRealDraws) {
                    foreach ($draws as $idx => $d) {
                        if (in_array($num, $d, true)) {
                            $drawsSinceLast = $idx;
                            break;
                        }
                    }
                    $drawsSinceLast ??= count($draws);
                }

                $entry = [
                    'number' => $num,
                    'occurrences' => $freqAsc[$num] ?? ($freqDesc[$num] ?? 0),
                    'role' => 'isolated_cold_outlier',
                    'zone' => sprintf('Strefa %d-%d', (int)floor(($num - 1) / 10) * 10 + 1, min(((int)floor(($num - 1) / 10) + 1) * 10, $maxNum)),
                ];
                if ($drawsSinceLast !== null) {
                    $entry['draws_since_last_seen'] = $drawsSinceLast;
                }
                $isolatedColdCandidates[] = $entry;
            }

            if (count($isolatedColdCandidates) >= 8) {
                break;
            }
        }

        return json_encode([
            'game' => $game,
            'source' => $hasRealDraws ? 'draw_history' : 'frequency_fallback',
            'draws_analyzed' => $stats['draws_analyzed'] ?? ($hasRealDraws ? count($draws) : $sessions),
            'most_recent_draw' => $latestDraw,
            'winning_anchors' => $winningAnchors,
            'syndicate_strategy_formula' => [
                'neighbours_quota' => '60%',
                'repeats_quota' => '20%',
                'isolated_cold_quota' => '20%',
            ],
            'neighbours_60_percent' => array_slice($neighbourCandidates, 0, 12),
            'direct_repeats_20_percent' => $repeatCandidates,
            'isolated_cold_20_percent' => $isolatedColdCandidates,
        ]);
    }
}
