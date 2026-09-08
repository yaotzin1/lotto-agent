<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kroczenie (stride sampling): pula budowana z losowań oddalonych o stały krok N
 * wstecz (T-N, T-2N...) i ich sąsiadów ±1.
 *
 * Serwis jest ŚWIADOMY GRY. Wcześniej nie był: 49 było wpisane na sztywno
 * w zawijaniu sąsiadów, w dobijaniu puli, w losowej linii bazowej i w rozkładzie
 * hipergeometrycznym, a historia zawsze pochodziła z pliku Lotto. Wybranie
 * EuroJackpota czy Multi Multi dawało więc pulę zbudowaną z losowań Lotto,
 * w której liczby powyżej 49 nie mogły się pojawić.
 *
 * Losowania pochodzą z DrawArchiveService, który scala ręczne archiwum
 * z cache'em oficjalnego LOTTO OpenAPI.
 */
class StrideBacktestService
{
    /** Minimalna głębokość historii, przy której backtest ma sens statystyczny. */
    private const MIN_DRAWS_FOR_BACKTEST = 600;

    private string $legacyDataFile;

    public function __construct(
        private readonly ?DrawArchiveService $drawArchiveService = null,
        private readonly GameRegistryService $gameRegistryService = new GameRegistryService(),
        #[Autowire('%kernel.project_dir%')]
        string $projectDir = ''
    ) {
        $baseDir = $projectDir !== '' ? $projectDir : dirname(__DIR__, 2);
        $this->legacyDataFile = $baseDir . '/data/lotto_draws.json';
    }

    /**
     * @return array<int, array{date: string, numbers: list<int>}>
     */
    public function loadDraws(string $gameType = 'Lotto'): array
    {
        if ($this->drawArchiveService !== null) {
            return $this->drawArchiveService->getChronology($gameType);
        }

        // Awaryjna ścieżka bez kontenera (np. testy jednostkowe): tylko Lotto,
        // tylko ręczne archiwum, bez domknięcia z API.
        if ($gameType !== 'Lotto' || !is_readable($this->legacyDataFile)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->legacyDataFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Świeżość archiwum danej gry. Null, gdy serwis działa bez archiwum.
     *
     * @return array{last_date: ?string, expected_date: ?string, missing: int, stale: bool, total: int}|null
     */
    public function freshness(string $gameType = 'Lotto'): ?array
    {
        return $this->drawArchiveService?->freshness($gameType);
    }

    /**
     * Dociąga brakujące losowania z oficjalnego API i dopisuje je do archiwum.
     *
     * @return array{added: int, fetched: int, from_cache: int, rate_limited: bool, warning: ?string}|null
     */
    public function refreshArchive(string $gameType = 'Lotto', ?int $sessions = null): ?array
    {
        return $this->drawArchiveService?->refresh($gameType, $sessions);
    }

    /**
     * @param list<int> $strides
     * @return array{
     *     game: string,
     *     numbers_from: int,
     *     drawn_per_draw: int,
     *     hit_threshold: int,
     *     total_draws: int,
     *     draws_evaluated: int,
     *     draws_skipped: int,
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
    public function runBacktest(
        int $poolSize = 12,
        array $strides = [1, 2, 7, 30, 50, 127, 257, 500],
        string $gameType = 'Lotto'
    ): array {
        $game = $this->gameRegistryService->getGameConfig($gameType);
        $maxNumber = (int) $game['from'];
        $poolSize = max(2, min($poolSize, $maxNumber - 1));

        $draws = $this->loadDraws($gameType);
        $totalDraws = count($draws);

        if ($totalDraws < self::MIN_DRAWS_FOR_BACKTEST) {
            throw new \RuntimeException(sprintf(
                'Niewystarczająca liczba losowań w archiwum gry %s (%d < %d) do przeprowadzenia rzetelnego backtestu kroczeń.',
                $gameType,
                $totalDraws,
                self::MIN_DRAWS_FOR_BACKTEST
            ));
        }

        // Ile liczb pada w jednym losowaniu tej gry. Nie jest to `pick`:
        // w Multi Multi skreślamy do 10 liczb, ale losowanych jest 20.
        $drawn = $this->modalDrawSize($draws, (int) $game['pick']);
        $hitThreshold = min(3, $drawn);
        $autoAnchors = $this->autoAnchorCount($poolSize, $gameType);

        $maxStride = max($strides);
        $warmup = $maxStride + 10;

        // Rozkład hipergeometryczny dla TEJ gry: ile z $drawn wylosowanych liczb
        // wpada do puli $poolSize wybranej z $maxNumber.
        $totalCombs = $this->binomial($maxNumber, $drawn);
        $theoMatches = [];
        for ($k = 0; $k <= $drawn; $k++) {
            $theoMatches[$k] = $totalCombs > 0.0
                ? ($this->binomial($poolSize, $k) * $this->binomial($maxNumber - $poolSize, $drawn - $k)) / $totalCombs
                : 0.0;
        }
        $theoMean = $poolSize * ($drawn / $maxNumber);

        $strategies = [];
        foreach ($strides as $s) {
            $strategies["Stride-$s (Anchor+Nbr)"] = ['type' => 'stride_nbr', 'stride' => $s];
            $strategies["Stride-$s (2xAnchor)"] = ['type' => 'multi_anchor', 'stride' => $s];
        }
        $strategies['Random Baseline'] = ['type' => 'random', 'stride' => 0];

        $rawResults = [];
        foreach (array_keys($strategies) as $name) {
            $rawResults[$name] = [
                'match_counts' => array_fill(0, $drawn + 1, 0),
                'total_matches' => 0,
                'gaps_ge3' => [],
                'last_hit_ge3' => null,
            ];
        }

        $evalCount = 0;
        $skipped = 0;

        for ($t = $warmup; $t < $totalDraws; $t++) {
            $targetDraw = $draws[$t]['numbers'];

            // Losowania o nietypowej liczbie liczb (np. skrócony wpis w archiwum)
            // rozjeżdżałyby rozkład trafień, więc nie wchodzą do statystyki.
            if (count($targetDraw) !== $drawn) {
                $skipped++;
                continue;
            }

            $evalCount++;

            foreach ($strategies as $name => $strat) {
                if ($strat['type'] === 'stride_nbr') {
                    $pool = $this->buildStrideNeighbourPool($draws, $t, $strat['stride'], $poolSize, $maxNumber);
                } elseif ($strat['type'] === 'multi_anchor') {
                    $pool = $this->buildMultiAnchorStridePool($draws, $t, $strat['stride'], $poolSize, $autoAnchors, $maxNumber);
                } else {
                    $pool = $this->buildRandomPool($poolSize, $maxNumber);
                }

                $hits = count(array_intersect($targetDraw, $pool));
                $rawResults[$name]['match_counts'][$hits]++;
                $rawResults[$name]['total_matches'] += $hits;

                if ($hits >= $hitThreshold) {
                    if ($rawResults[$name]['last_hit_ge3'] !== null) {
                        $rawResults[$name]['gaps_ge3'][] = $t - $rawResults[$name]['last_hit_ge3'];
                    }
                    $rawResults[$name]['last_hit_ge3'] = $t;
                }
            }
        }

        if ($evalCount === 0) {
            throw new \RuntimeException(sprintf(
                'Archiwum gry %s nie zawiera losowań o oczekiwanej liczbie %d liczb.',
                $gameType,
                $drawn
            ));
        }

        $results = [];
        foreach ($rawResults as $name => $res) {
            $matchPct = [];
            for ($k = 0; $k <= $drawn; $k++) {
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
                'jackpot_hits' => $res['match_counts'][$drawn],
                'gaps_ge3_mean' => $gapMean,
                'gaps_ge3_stddev' => $gapStdDev,
                'gaps_ge3_max' => $gapMax,
            ];
        }

        return [
            'game' => $gameType,
            'numbers_from' => $maxNumber,
            'drawn_per_draw' => $drawn,
            'hit_threshold' => $hitThreshold,
            'total_draws' => $totalDraws,
            'draws_evaluated' => $evalCount,
            'draws_skipped' => $skipped,
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
    public function buildStrideNeighbourPool(
        array $history,
        int $targetIdx,
        int $stride,
        int $targetPoolSize,
        int $maxNumber = 49
    ): array {
        $targetPoolSize = max(1, min($targetPoolSize, $maxNumber));
        $anchorIdx = $targetIdx - $stride;

        if ($anchorIdx < 0 || !isset($history[$anchorIdx])) {
            return range(1, $targetPoolSize);
        }

        $anchors = array_values(array_filter(
            $history[$anchorIdx]['numbers'],
            static fn(int $n): bool => $n >= 1 && $n <= $maxNumber
        ));

        $neighbourCounts = [];
        foreach ($anchors as $num) {
            foreach ($this->neighboursOf($num, $maxNumber) as $neighbour) {
                if (!in_array($neighbour, $anchors, true)) {
                    $neighbourCounts[$neighbour] = ($neighbourCounts[$neighbour] ?? 0) + 1;
                }
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

        return $this->padPool($pool, $targetPoolSize, $maxNumber);
    }

    /**
     * Gdy $maxAnchors jest null, liczba kotwic jest liczona dla Lotto —
     * wywołania świadome gry (runBacktest, getStridePoolInfo) podają ją wprost
     * przez autoAnchorCount($poolSize, $gameType).
     *
     * @param array<int, array{date: string, numbers: list<int>}> $history
     * @return list<int>
     */
    public function buildMultiAnchorStridePool(
        array $history,
        int $targetIdx,
        int $stride,
        int $targetPoolSize,
        ?int $maxAnchors = null,
        int $maxNumber = 49
    ): array {
        $targetPoolSize = max(1, min($targetPoolSize, $maxNumber));
        $anchorLimit = $maxAnchors ?? $this->autoAnchorCount($targetPoolSize, 'Lotto');
        $numberOccurrences = [];
        $firstSeen = [];

        for ($k = 1; $k <= $anchorLimit; $k++) {
            $aIdx = $targetIdx - ($k * $stride);
            if ($aIdx < 0 || !isset($history[$aIdx])) {
                break;
            }
            foreach ($history[$aIdx]['numbers'] as $num) {
                if ($num < 1 || $num > $maxNumber) {
                    continue;
                }
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

        $pool = array_map('intval', array_slice(array_keys($numberOccurrences), 0, $targetPoolSize));

        if (count($pool) < $targetPoolSize) {
            $neighbourCounts = [];
            foreach (array_keys($numberOccurrences) as $num) {
                foreach ($this->neighboursOf((int) $num, $maxNumber) as $neighbour) {
                    if (!in_array($neighbour, $pool, true)) {
                        $neighbourCounts[$neighbour] = ($neighbourCounts[$neighbour] ?? 0) + 1;
                    }
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

        return $this->padPool($pool, $targetPoolSize, $maxNumber);
    }

    /**
     * @return list<int>
     */
    public function buildRandomPool(int $targetPoolSize, int $maxNumber = 49): array
    {
        $targetPoolSize = max(1, min($targetPoolSize, $maxNumber));
        $numbers = range(1, $maxNumber);
        shuffle($numbers);
        $pool = array_slice($numbers, 0, $targetPoolSize);
        sort($pool);

        return $pool;
    }

    /**
     * Sąsiedzi ±1 na okręgu 1..$maxNumber.
     *
     * @return list<int>
     */
    private function neighboursOf(int $number, int $maxNumber): array
    {
        if ($maxNumber < 2) {
            return [];
        }

        return [
            $number === 1 ? $maxNumber : $number - 1,
            $number === $maxNumber ? 1 : $number + 1,
        ];
    }

    /**
     * Domyka pulę do żądanego rozmiaru, usuwa duplikaty i sortuje.
     *
     * @param list<int> $pool
     * @return list<int>
     */
    private function padPool(array $pool, int $targetPoolSize, int $maxNumber): array
    {
        $pool = array_values(array_unique(array_map('intval', $pool)));

        $candidate = 1;
        while (count($pool) < $targetPoolSize && $candidate <= $maxNumber) {
            if (!in_array($candidate, $pool, true)) {
                $pool[] = $candidate;
            }
            $candidate++;
        }

        $pool = array_slice($pool, 0, $targetPoolSize);
        sort($pool);

        return $pool;
    }

    /**
     * Ile kotwic próbkować, gdy użytkownik nie poda liczby wprost.
     *
     * Dzielnikiem jest liczba skreśleń W TEJ GRZE, a nie wpisana wcześniej
     * na sztywno szóstka z Lotto.
     */
    public function autoAnchorCount(int $poolSize, string $gameType = 'Lotto'): int
    {
        try {
            $pick = max(1, (int) $this->gameRegistryService->getGameConfig($gameType)['pick']);
        } catch (\Throwable) {
            $pick = 6;
        }

        return max(2, (int) ceil($poolSize / $pick));
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
     * Najczęstsza liczba liczb w losowaniu — dla Lotto 6, dla Multi Multi 20.
     *
     * @param array<int, array{date: string, numbers: list<int>}> $draws
     */
    private function modalDrawSize(array $draws, int $fallback): int
    {
        $counts = [];
        foreach ($draws as $draw) {
            $size = count($draw['numbers']);
            $counts[$size] = ($counts[$size] ?? 0) + 1;
        }

        if ($counts === []) {
            return max(1, $fallback);
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * @return array{
     *     game: string,
     *     numbers_from: int,
     *     target_draw: array{index: int, date: string, numbers: list<int>}|null,
     *     anchor_draws: list<array{index: int, date: string, numbers: list<int>, stride_back: int}>,
     *     anchors: list<int>,
     *     neighbours: list<int>,
     *     pool: list<int>,
     *     strategy: string,
     *     stride: int,
     *     pool_size: int,
     *     anchor_count: int,
     *     total_draws: int,
     *     freshness: array{last_date: ?string, expected_date: ?string, missing: int, stale: bool, total: int}|null
     * }
     */
    public function getStridePoolInfo(
        int $stride,
        int $poolSize = 12,
        string $strategy = 'anchor_neighbours',
        ?int $targetIdx = null,
        ?int $anchorCount = null,
        string $gameType = 'Lotto'
    ): array {
        $game = $this->gameRegistryService->getGameConfig($gameType);
        $maxNumber = (int) $game['from'];
        $poolSize = max(1, min($poolSize, $maxNumber));

        $draws = $this->loadDraws($gameType);
        $totalDraws = count($draws);

        if ($totalDraws === 0) {
            throw new \RuntimeException(sprintf(
                'Brak archiwum losowań dla gry %s (oczekiwany plik: %s). '
                . 'Uzupełnij je z LOTTO OpenAPI (wymaga LOTTO_API_KEY) albo przez scripts/parse_history.php.',
                $gameType,
                $this->drawArchiveService?->fileFor($gameType) ?? $this->legacyDataFile
            ));
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
            $kLimit = ($strategy === 'multi_anchor') ? $this->autoAnchorCount($poolSize, $gameType) : 1;
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
            $pool = $this->buildMultiAnchorStridePool($draws, $idx, $stride, $poolSize, $kLimit, $maxNumber);
        } else {
            $pool = $this->buildStrideNeighbourPool($draws, $idx, $stride, $poolSize, $maxNumber);
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
            'game' => $gameType,
            'numbers_from' => $maxNumber,
            'target_draw' => $targetDraw,
            'anchor_draws' => $anchorDraws,
            'anchors' => $allAnchors,
            'neighbours' => $neighbours,
            'pool' => $pool,
            'strategy' => $strategy,
            'stride' => $stride,
            'pool_size' => $poolSize,
            'anchor_count' => count($anchorDraws),
            'total_draws' => $totalDraws,
            'freshness' => $this->freshness($gameType),
        ];
    }
}
