<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StrideBacktestService
{
    private string $dataFile;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir = ''
    ) {
        $baseDir = $projectDir !== '' ? $projectDir : dirname(__DIR__, 2);
        $this->dataFile = $baseDir . '/data/lotto_draws.json';
    }

    /**
     * @return array<int, array{date: string, numbers: list<int>}>
     */
    public function loadDraws(): array
    {
        if (!file_exists($this->dataFile)) {
            return [];
        }

        $content = (string) file_get_contents($this->dataFile);
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<int> $strides
     * @return array{
     *     total_draws: int,
     *     draws_evaluated: int,
     *     pool_size: int,
     *     date_from: string,
     *     date_to: string,
     *     theoretical: array{matches: array<int, float>, mean: float},
     *     results: array<string, array{
     *         match_counts: array<int, int>,
     *         match_pct: array<int, float>,
     *         avg_match: float,
     *         jackpot_hits: int,
     *         gaps_ge3_mean: float,
     *         gaps_ge3_stddev: float,
     *         gaps_ge3_max: int
     *     }>
     * }
     */
    public function runBacktest(int $poolSize = 12, array $strides = [1, 2, 7, 30, 50, 127, 257, 500]): array
    {
        $draws = $this->loadDraws();
        $totalDraws = count($draws);

        if ($totalDraws < 600) {
            throw new \RuntimeException("Niewystarczająca liczba losowań w bazie ($totalDraws < 600) do przeprowadzenia rzetelnego backtestu kroczeń.");
        }

        $maxStride = max($strides);
        $warmup = $maxStride + 10;
        $evalCount = $totalDraws - $warmup;

        // Theoretical hypergeometric distribution for poolSize out of 49, 6 drawn
        $totalCombs = 13983816.0; // C(49, 6)
        $theoMatches = [];
        for ($k = 0; $k <= 6; $k++) {
            $c_pool_k = $this->binomial($poolSize, $k);
            $c_rem_k = $this->binomial(49 - $poolSize, 6 - $k);
            $theoMatches[$k] = ($c_pool_k * $c_rem_k) / $totalCombs;
        }
        $theoMean = $poolSize * (6.0 / 49.0);

        // Setup strategies
        $strategies = [];
        foreach ($strides as $s) {
            $strategies["Stride-$s (Anchor+Nbr)"] = ['type' => 'stride_nbr', 'stride' => $s];
            $strategies["Stride-$s (2xAnchor)"] = ['type' => 'multi_anchor', 'stride' => $s];
        }
        $strategies['Random Baseline'] = ['type' => 'random', 'stride' => 0];

        $rawResults = [];
        foreach (array_keys($strategies) as $name) {
            $rawResults[$name] = [
                'match_counts' => array_fill(0, 7, 0),
                'total_matches' => 0,
                'gaps_ge3' => [],
                'last_hit_ge3' => null,
            ];
        }

        for ($t = $warmup; $t < $totalDraws; $t++) {
            $targetDraw = $draws[$t]['numbers'];

            foreach ($strategies as $name => $strat) {
                if ($strat['type'] === 'stride_nbr') {
                    $pool = $this->buildStrideNeighbourPool($draws, $t, $strat['stride'], $poolSize);
                } elseif ($strat['type'] === 'multi_anchor') {
                    $pool = $this->buildMultiAnchorStridePool($draws, $t, $strat['stride'], $poolSize);
                } else {
                    $pool = $this->buildRandomPool($poolSize);
                }

                $hits = count(array_intersect($targetDraw, $pool));
                $rawResults[$name]['match_counts'][$hits]++;
                $rawResults[$name]['total_matches'] += $hits;

                if ($hits >= 3) {
                    if ($rawResults[$name]['last_hit_ge3'] !== null) {
                        $rawResults[$name]['gaps_ge3'][] = $t - $rawResults[$name]['last_hit_ge3'];
                    }
                    $rawResults[$name]['last_hit_ge3'] = $t;
                }
            }
        }

        $results = [];
        foreach ($rawResults as $name => $res) {
            $matchPct = [];
            for ($k = 0; $k <= 6; $k++) {
                $matchPct[$k] = round(($res['match_counts'][$k] / $evalCount) * 100, 2);
            }

            $gaps = $res['gaps_ge3'];
            $gapMean = 0.0;
            $gapStdDev = 0.0;
            $gapMax = 0;

            if (count($gaps) > 1) {
                $gapMean = round(array_sum($gaps) / count($gaps), 2);
                $sqDiffs = array_map(static fn(int $g): float => ($g - $gapMean) ** 2, $gaps);
                $gapStdDev = round(sqrt(array_sum($sqDiffs) / (count($gaps) - 1)), 2);
                $gapMax = max($gaps);
            }

            $results[$name] = [
                'match_counts' => $res['match_counts'],
                'match_pct' => $matchPct,
                'avg_match' => round($res['total_matches'] / $evalCount, 4),
                'jackpot_hits' => $res['match_counts'][6],
                'gaps_ge3_mean' => $gapMean,
                'gaps_ge3_stddev' => $gapStdDev,
                'gaps_ge3_max' => $gapMax,
            ];
        }

        return [
            'total_draws' => $totalDraws,
            'draws_evaluated' => $evalCount,
            'pool_size' => $poolSize,
            'date_from' => $draws[$warmup]['date'],
            'date_to' => $draws[$totalDraws - 1]['date'],
            'theoretical' => [
                'matches' => array_map(static fn(float $p): float => round($p * 100, 2), $theoMatches),
                'mean' => round($theoMean, 4),
            ],
            'results' => $results,
        ];
    }

    /**
     * @param array<int, array{date: string, numbers: list<int>}> $history
     * @return list<int>
     */
    public function buildStrideNeighbourPool(array $history, int $targetIdx, int $stride, int $targetPoolSize): array
    {
        $anchorIdx = $targetIdx - $stride;
        if ($anchorIdx < 0) {
            return range(1, $targetPoolSize);
        }

        $anchors = $history[$anchorIdx]['numbers'];
        $neighbourCounts = [];

        foreach ($anchors as $num) {
            $left = $num === 1 ? 49 : $num - 1;
            $right = $num === 49 ? 1 : $num + 1;

            if (!in_array($left, $anchors, true)) {
                $neighbourCounts[$left] = ($neighbourCounts[$left] ?? 0) + 1;
            }
            if (!in_array($right, $anchors, true)) {
                $neighbourCounts[$right] = ($neighbourCounts[$right] ?? 0) + 1;
            }
        }

        arsort($neighbourCounts);

        $pool = $anchors;
        foreach (array_keys($neighbourCounts) as $n) {
            if (count($pool) >= $targetPoolSize) {
                break;
            }
            $pool[] = (int) $n;
        }

        $candidate = 1;
        while (count($pool) < $targetPoolSize && $candidate <= 49) {
            if (!in_array($candidate, $pool, true)) {
                $pool[] = $candidate;
            }
            $candidate++;
        }

        sort($pool);
        return $pool;
    }

    /**
     * @param array<int, array{date: string, numbers: list<int>}> $history
     * @return list<int>
     */
    /**
     * @param array<int, array{date: string, numbers: list<int>}> $history
     * @return list<int>
     */
    public function buildMultiAnchorStridePool(
        array $history,
        int $targetIdx,
        int $stride,
        int $targetPoolSize,
        ?int $maxAnchors = null
    ): array {
        $anchorLimit = $maxAnchors ?? max(2, (int) ceil($targetPoolSize / 6));
        $numberOccurrences = [];
        $firstSeen = [];

        for ($k = 1; $k <= $anchorLimit; $k++) {
            $aIdx = $targetIdx - ($k * $stride);
            if ($aIdx < 0 || !isset($history[$aIdx])) {
                break;
            }
            foreach ($history[$aIdx]['numbers'] as $num) {
                $numberOccurrences[$num] = ($numberOccurrences[$num] ?? 0) + 1;
                if (!isset($firstSeen[$num])) {
                    $firstSeen[$num] = $k;
                }
            }
        }

        uksort($numberOccurrences, static function ($a, $b) use ($numberOccurrences, $firstSeen) {
            if ($numberOccurrences[$a] !== $numberOccurrences[$b]) {
                return $numberOccurrences[$b] <=> $numberOccurrences[$a];
            }
            return $firstSeen[$a] <=> $firstSeen[$b];
        });

        $pool = array_slice(array_keys($numberOccurrences), 0, $targetPoolSize);

        if (count($pool) < $targetPoolSize) {
            $neighbourCounts = [];
            foreach (array_keys($numberOccurrences) as $num) {
                $left = $num === 1 ? 49 : $num - 1;
                $right = $num === 49 ? 1 : $num + 1;
                if (!in_array($left, $pool, true)) {
                    $neighbourCounts[$left] = ($neighbourCounts[$left] ?? 0) + 1;
                }
                if (!in_array($right, $pool, true)) {
                    $neighbourCounts[$right] = ($neighbourCounts[$right] ?? 0) + 1;
                }
            }
            arsort($neighbourCounts);
            foreach (array_keys($neighbourCounts) as $nbr) {
                $pool[] = (int) $nbr;
                if (count($pool) >= $targetPoolSize) {
                    break;
                }
            }
        }

        $candidate = 1;
        while (count($pool) < $targetPoolSize && $candidate <= 49) {
            if (!in_array($candidate, $pool, true)) {
                $pool[] = $candidate;
            }
            $candidate++;
        }

        sort($pool);
        return $pool;
    }

    /**
     * @return list<int>
     */
    public function buildRandomPool(int $targetPoolSize): array
    {
        $numbers = range(1, 49);
        shuffle($numbers);
        $pool = array_slice($numbers, 0, $targetPoolSize);
        sort($pool);

        return $pool;
    }

    private function binomial(int $n, int $k): float
    {
        if ($k < 0 || $k > $n) {
            return 0.0;
        }
        if ($k === 0 || $k === $n) {
            return 1.0;
        }
        $k = min($k, $n - $k);
        $res = 1.0;
        for ($i = 1; $i <= $k; $i++) {
            $res = $res * ($n - $k + $i) / $i;
        }

        return round($res);
    }

    /**
     * @return array{
     *     target_draw: array{index: int, date: string, numbers: list<int>}|null,
     *     anchor_draws: list<array{index: int, date: string, numbers: list<int>, stride_back: int}>,
     *     anchors: list<int>,
     *     neighbours: list<int>,
     *     pool: list<int>,
     *     strategy: string,
     *     stride: int,
     *     pool_size: int,
     *     anchor_count: int
     * }
     */
    public function getStridePoolInfo(
        int $stride,
        int $poolSize = 12,
        string $strategy = 'anchor_neighbours',
        ?int $targetIdx = null,
        ?int $anchorCount = null
    ): array {
        $draws = $this->loadDraws();
        $totalDraws = count($draws);

        if ($totalDraws === 0) {
            throw new \RuntimeException('Brak danych historycznych w data/lotto_draws.json.');
        }

        $idx = $targetIdx ?? ($totalDraws - 1);
        $targetDraw = isset($draws[$idx]) ? [
            'index' => $idx + 1,
            'date' => $draws[$idx]['date'],
            'numbers' => $draws[$idx]['numbers'],
        ] : null;

        $anchorDraws = [];
        if ($anchorCount !== null && $anchorCount > 0) {
            $kLimit = $anchorCount;
        } else {
            $kLimit = ($strategy === 'multi_anchor') ? max(2, (int) ceil($poolSize / 6)) : 1;
        }

        for ($k = 1; $k <= $kLimit; $k++) {
            $aIdx = $idx - ($k * $stride);
            if ($aIdx >= 0 && isset($draws[$aIdx])) {
                $anchorDraws[] = [
                    'index' => $aIdx + 1,
                    'date' => $draws[$aIdx]['date'],
                    'numbers' => $draws[$aIdx]['numbers'],
                    'stride_back' => $k * $stride,
                ];
            }
        }

        if ($strategy === 'multi_anchor') {
            $pool = $this->buildMultiAnchorStridePool($draws, $idx, $stride, $poolSize, $kLimit);
        } else {
            $pool = $this->buildStrideNeighbourPool($draws, $idx, $stride, $poolSize);
        }

        $allAnchors = [];
        foreach ($anchorDraws as $ad) {
            foreach ($ad['numbers'] as $n) {
                if (!in_array($n, $allAnchors, true)) {
                    $allAnchors[] = $n;
                }
            }
        }
        sort($allAnchors);

        $neighbours = array_values(array_diff($pool, $allAnchors));
        sort($neighbours);

        return [
            'target_draw' => $targetDraw,
            'anchor_draws' => $anchorDraws,
            'anchors' => $allAnchors,
            'neighbours' => $neighbours,
            'pool' => $pool,
            'strategy' => $strategy,
            'stride' => $stride,
            'pool_size' => $poolSize,
            'anchor_count' => count($anchorDraws),
        ];
    }
}


