<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Generator liczb dodatkowych ("drugiego bębna").
 *
 * Dotyczy gier, które mają `extra > 0` w GameRegistryService:
 *  - EuroJackpot      : 2 z 12 (EuroNumbers)
 *  - EkstraPensja/Premia : 1 z 4
 *
 * Do tej pory pola `extra` / `extra_from` były zdefiniowane, ale NIGDZIE nie
 * używane — aplikacja generowała wyłącznie liczby główne, więc kupon
 * EuroJackpot był niegrywalny.
 *
 * Zestawy dobierane są tak, by:
 *  1. nie powtarzać tego samego zestawu, dopóki są jeszcze wolne kombinacje,
 *  2. preferować pary o realnym współwystępowaniu (gdy jest historia losowań),
 *  3. równomiernie zużywać cały bęben liczb dodatkowych.
 */
class ExtraNumbersGenerator
{
    /**
     * @param array<int, int> $frequencies Mapa [liczba => wystąpienia] dla bębna dodatkowego
     * @param array<int, array<int>> $specialDraws Historyczne zestawy liczb dodatkowych
     * @return array{sets: array<int, array<int>>, source: string, distinct_sets: int, total_possible: int}
     */
    public function generate(
        int $extraCount,
        int $extraFrom,
        int $numBets,
        array $frequencies = [],
        array $specialDraws = []
    ): array {
        if ($extraCount < 1 || $extraFrom < $extraCount || $numBets < 1) {
            return ['sets' => [], 'source' => 'not_applicable', 'distinct_sets' => 0, 'total_possible' => 0];
        }

        $pool = range(1, $extraFrom);
        $pairCounts = $this->countPairs($pool, $specialDraws);
        $usesRealHistory = $pairCounts !== null;

        $usage = array_fill_keys($pool, 0);
        $sets = [];
        $seen = [];
        $totalPossible = (int) $this->combinations($extraFrom, $extraCount);

        for ($b = 0; $b < $numBets; $b++) {
            $best = null;
            $bestScore = -INF;

            // Kilka prób, żeby przy remisach nie produkować zawsze tego samego.
            for ($attempt = 0; $attempt < 40; $attempt++) {
                $candidate = $this->buildSet($pool, $extraCount, $frequencies, $pairCounts, $usage, $attempt);
                $key = implode('-', $candidate);

                // Dopóki są wolne kombinacje, nie powtarzamy zestawu.
                if (isset($seen[$key]) && count($seen) < $totalPossible) {
                    continue;
                }

                $score = $this->scoreSet($candidate, $frequencies, $pairCounts, $usage);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }
            }

            if ($best === null) {
                $shuffled = $pool;
                shuffle($shuffled);
                $best = array_slice($shuffled, 0, $extraCount);
                sort($best);
            }

            $sets[] = $best;
            $seen[implode('-', $best)] = true;
            foreach ($best as $n) {
                $usage[$n]++;
            }
        }

        return [
            'sets' => $sets,
            'source' => $usesRealHistory ? 'historical_co_occurrence' : 'frequency_heuristic',
            'distinct_sets' => count($seen),
            'total_possible' => $totalPossible,
        ];
    }

    /**
     * @param array<int> $pool
     * @param array<int, int> $frequencies
     * @param array<int, array<int, int>>|null $pairCounts
     * @param array<int, int> $usage
     * @return array<int>
     */
    private function buildSet(
        array $pool,
        int $extraCount,
        array $frequencies,
        ?array $pairCounts,
        array $usage,
        int $attempt
    ): array {
        $candidates = $pool;
        shuffle($candidates);

        // Start: najmniej dotąd użyta liczba (z lekką losowością przy remisach).
        usort($candidates, static function (int $a, int $b) use ($usage, $frequencies): int {
            if ($usage[$a] !== $usage[$b]) {
                return $usage[$a] <=> $usage[$b];
            }

            return ($frequencies[$b] ?? 0) <=> ($frequencies[$a] ?? 0);
        });

        $seedWindow = max(1, min(count($candidates), 1 + ($attempt % 3)));
        $set = [$candidates[random_int(0, $seedWindow - 1)]];

        while (count($set) < $extraCount) {
            $bestNext = null;
            $bestNextScore = -INF;

            foreach ($pool as $n) {
                if (in_array($n, $set, true)) {
                    continue;
                }

                $score = ($frequencies[$n] ?? 1) * 1.0 - $usage[$n] * 6.0;
                foreach ($set as $chosen) {
                    $score += $pairCounts !== null
                        ? ($pairCounts[$n][$chosen] ?? 0) * 3.0
                        : 0.0;
                }

                if ($score > $bestNextScore) {
                    $bestNextScore = $score;
                    $bestNext = $n;
                }
            }

            if ($bestNext === null) {
                break;
            }
            $set[] = $bestNext;
        }

        sort($set);

        return $set;
    }

    /**
     * @param array<int> $set
     * @param array<int, int> $frequencies
     * @param array<int, array<int, int>>|null $pairCounts
     * @param array<int, int> $usage
     */
    private function scoreSet(array $set, array $frequencies, ?array $pairCounts, array $usage): float
    {
        $score = 0.0;

        foreach ($set as $n) {
            $score += ($frequencies[$n] ?? 1) * 1.0;
            $score -= $usage[$n] * 6.0;
        }

        if ($pairCounts !== null) {
            $count = count($set);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $score += ($pairCounts[$set[$i]][$set[$j]] ?? 0) * 3.0;
                }
            }
        }

        return $score;
    }

    /**
     * Rzeczywiste współwystępowanie liczb dodatkowych.
     *
     * @param array<int> $pool
     * @param array<int, array<int>> $draws
     * @return array<int, array<int, int>>|null
     */
    private function countPairs(array $pool, array $draws): ?array
    {
        if (count($draws) < 10) {
            return null;
        }

        $inPool = array_fill_keys($pool, true);
        $counts = [];
        $observed = 0;

        foreach ($draws as $draw) {
            $relevant = [];
            foreach ($draw as $n) {
                $n = (int) $n;
                if (isset($inPool[$n])) {
                    $relevant[] = $n;
                }
            }
            $relevant = array_values(array_unique($relevant));

            $len = count($relevant);
            for ($i = 0; $i < $len; $i++) {
                for ($j = $i + 1; $j < $len; $j++) {
                    $a = $relevant[$i];
                    $b = $relevant[$j];
                    $counts[$a][$b] = ($counts[$a][$b] ?? 0) + 1;
                    $counts[$b][$a] = ($counts[$b][$a] ?? 0) + 1;
                    $observed++;
                }
            }
        }

        return $observed > 0 ? $counts : null;
    }

    private function combinations(int $n, int $k): float
    {
        if ($k < 0 || $k > $n) {
            return 0.0;
        }
        if ($k === 0 || $k === $n) {
            return 1.0;
        }
        if ($k > $n / 2) {
            $k = $n - $k;
        }

        $result = 1.0;
        for ($i = 1; $i <= $k; $i++) {
            $result = $result * ($n - $k + $i) / $i;
        }

        return round($result);
    }
}
