<?php

declare(strict_types=1);

namespace App\Service;

class BetGeneratorService
{
    public function __construct(
        private readonly ?StatisticalOptimizerService $statisticalOptimizer = null
    ) {
    }

    // === SILNIK OPTYMALIZACJI STATYSTYCZNEJ DLA ROZWODNIENIA ===
    public function generateStatisticalOptimizedBets(
        array $pool,
        int $pick,
        int $limit,
        array $frequencies,
        int $maxNumber,
        array $options = []
    ): array {
        $optimizer = $this->statisticalOptimizer ?? new StatisticalOptimizerService(new GameRegistryService(), new \Psr\Log\NullLogger());
        return $optimizer->optimizeBetsForDilution($pool, $pick, $limit, $frequencies, $maxNumber, $options);
    }

    // === SILNIK RANKINGOWEGO PEŁNEGO POKRYCIA (ZERO-DROP GUARANTEE) ===
    public function generateRankedFullCoverageBets(
        array $pool,
        int $pick,
        int $limit,
        array $frequencies,
        int $maxNumber,
        array $options = []
    ): array {
        $optimizer = $this->statisticalOptimizer ?? new StatisticalOptimizerService(new GameRegistryService(), new \Psr\Log\NullLogger());
        return $optimizer->optimizeBetsWithFullCoverage($pool, $pick, $limit, $frequencies, $maxNumber, $options);
    }

    // === SILNIK KASKADOWEGO SYSTEMU SĄSIADÓW (TIERED NEIGHBOUR CASCADE) ===
    public function generateTieredNeighbourBets(
        array $pool,
        int $pick,
        int $limit,
        array $frequencies,
        int $maxNumber,
        array $latestDraw = [],
        array $options = []
    ): array {
        $optimizer = $this->statisticalOptimizer ?? new StatisticalOptimizerService(new GameRegistryService(), new \Psr\Log\NullLogger());
        return $optimizer->optimizeTieredNeighbourBets($pool, $pick, $limit, $frequencies, $maxNumber, $latestDraw, $options);
    }

    /**
     * Zwraca krok faktycznie względnie pierwszy z rozmiarem puli.
     *
     * Poprzednia wersja zgadywała (3, potem 5, potem 7) i dla puli podzielnej
     * przez 3, 5 i 7 (np. 105) zwracała krok NIE będący względnie pierwszym.
     */
    private function coprimeStride(int $poolCount): int
    {
        if ($poolCount <= 2) {
            return 1;
        }

        foreach ([3, 5, 7, 11, 13, 17, 19, 23] as $candidate) {
            if ($candidate < $poolCount && $this->gcd($candidate, $poolCount) === 1) {
                return $candidate;
            }
        }

        return 1;
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return abs($a);
    }

    public function generateOverlappingBlocks(array $pool, int $numBlocks, int $blockSize): array
    {
        $pool = array_values(array_unique($pool));
        $poolCount = count($pool);

        if ($poolCount === 0) {
            throw new \InvalidArgumentException('Pula wejściowa bloku jest pusta.');
        }

        // Blok nie może być większy niż pula — inaczej indeksowanie modulo zawijało
        // się i produkowało blok z powtórzonymi liczbami (kupon niegrywalny).
        if ($blockSize > $poolCount) {
            throw new \InvalidArgumentException(sprintf(
                'Rozmiar bloku (%d) nie może przekraczać wielkości puli (%d).',
                $blockSize,
                $poolCount
            ));
        }

        if ($blockSize < 1 || $numBlocks < 1) {
            throw new \InvalidArgumentException('Rozmiar bloku i liczba bloków muszą być dodatnie.');
        }

        // Geometric Interleaving: wybieramy krok (stride) względnie pierwszy z wielkością puli
        $stride = $this->coprimeStride($poolCount);

        $interleavedPool = [];
        $visited = array_fill(0, $poolCount, false);
        $currentIndex = 0;

        for ($i = 0; $i < $poolCount; $i++) {
            $interleavedPool[] = $pool[$currentIndex];
            $visited[$currentIndex] = true;

            $nextIndex = ($currentIndex + $stride) % $poolCount;
            while ($i < $poolCount - 1 && $visited[$nextIndex]) {
                $nextIndex = ($nextIndex + 1) % $poolCount;
            }
            $currentIndex = $nextIndex;
        }

        $pool = $interleavedPool;

        $blocks = [];
        $step = $poolCount / $numBlocks;

        for ($i = 0; $i < $numBlocks; $i++) {
            $startIdx = (int)($i * $step);
            $block = [];
            for ($j = 0; $j < $blockSize; $j++) {
                $block[] = $pool[($startIdx + $j) % $poolCount];
            }
            sort($block);
            $blocks[] = $block;
        }
        return $blocks;
    }

    // === SILNIK BLOKÓW: INTELIGENTNY KRUPIER ===
    public function generateSmartUniqueBlocks(array $fullPool, int $blockSize, int $numBlocks): array
    {
        $poolSize = count($fullPool);
        $totalSlots = $blockSize * $numBlocks;
        $baseRepeats = (int)floor($totalSlots / $poolSize);
        $remainder = $totalSlots % $poolSize;

        $frequencies = array_fill_keys($fullPool, $baseRepeats);

        if ($remainder > 0) {
            $rPool = $fullPool;
            shuffle($rPool);
            foreach (array_slice($rPool, 0, $remainder) as $num) {
                $frequencies[$num]++;
            }
        }

        arsort($frequencies);
        $deck = [];
        foreach ($frequencies as $num => $count) {
            for ($i = 0; $i < $count; $i++) {
                $deck[] = $num;
            }
        }

        $blocks = array_fill(0, $numBlocks, []);
        $success = $this->backtrackBlocks($deck, 0, $blocks, $blockSize, $numBlocks);

        if ($success) {
            foreach ($blocks as &$b) {
                sort($b);
            }
            return $blocks;
        }

        throw new \RuntimeException("BŁĄD: Nie udało się rozłożyć liczb unikalnie w trybie Krupiera przy użyciu algorytmu Backtrackingu.");
    }

    private function backtrackBlocks(array &$deck, int $deckIndex, array &$blocks, int $blockSize, int $numBlocks): bool
    {
        if ($deckIndex >= count($deck)) {
            return true;
        }

        $num = $deck[$deckIndex];

        for ($b = 0; $b < $numBlocks; $b++) {
            if (count($blocks[$b]) < $blockSize && !in_array($num, $blocks[$b], true)) {
                $blocks[$b][] = $num;

                if ($this->backtrackBlocks($deck, $deckIndex + 1, $blocks, $blockSize, $numBlocks)) {
                    return true;
                }

                array_pop($blocks[$b]);
            }
        }

        return false;
    }

    // === SILNIK REDUKCJI: ABBREVIATED WHEELING (t-DESIGN SET-COVER + BUCKET BALANCE) ===
    /**
     * Generuje skrócony system kombinatoryczny (Abbreviated Wheel) metodą
     * zachłannego pokrycia zbiorów (Greedy t-Design Set-Cover dla t=3 i t=2)
     * połączonego ze ścisłym bilansem koszykowym pojedynczych liczb (Bucket Balance)
     * oraz lokalnym przeszukiwaniem 1-opt (Kempe-style swap).
     *
     * Dla każdego nowego kuponu algorytm przeprowadza wielostartowy turniej kandydatów
     * i wybiera ten kupon, który pokrywa najwięcej NOWYCH (dotąd niepokrytych)
     * trójek C(pool, 3) oraz par C(pool, 2), maksymalizując gwarancję trafienia
     * min. 3/k oraz 4/k przy trafieniu wygranych liczb do puli.
     *
     * @param array<int> $pool
     * @return array<int, array<int>>
     */
    public function generateBalancedShorthand(array $pool, int $pick, int $limit): array
    {
        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);
        $poolSize = count($pool);

        if ($pick < 1) {
            throw new \InvalidArgumentException(
                sprintf('Liczba skreśleń musi być dodatnia, otrzymano %d.', $pick)
            );
        }

        // Wcześniej zwracana była tu pula "jak leci", co dawało kupon krótszy niż
        // wymaga gra (np. 3 liczby dla Lotto 6/49) — czyli kupon niegrywalny.
        if ($poolSize < $pick) {
            throw new \InvalidArgumentException(sprintf(
                'Pula (%d liczb) jest mniejsza niż wymagana ilość skreśleń (%d).',
                $poolSize,
                $pick
            ));
        }

        if ($poolSize === $pick) {
            return [$pool];
        }

        $maxPossible = (int) $this->countCombinations($poolSize, $pick);
        $targetLimit = min($limit, $maxPossible);

        $results = [];
        $seenBets = [];
        $usageCounts = array_fill_keys($pool, 0);
        $pairCounts = [];
        foreach ($pool as $n1) {
            foreach ($pool as $n2) {
                $pairCounts[$n1][$n2] = 0;
            }
        }
        /** @var array<string, int> $tripletCounts */
        $tripletCounts = [];

        $trackTriplets = ($pick >= 3);
        $trialsPerTicket = $poolSize <= 24 ? min(20, max(10, $poolSize)) : 8;
        $enableOneOpt = ($poolSize <= 26 && $pick >= 3);

        for ($ticketIdx = 0; $ticketIdx < $targetLimit; $ticketIdx++) {
            // Posortuj pulę wg najniższego dotychczasowego użycia (oraz najmniejszego nasycenia par)
            $sortedByUsage = $pool;
            usort($sortedByUsage, static function (int $a, int $b) use ($usageCounts, $pairCounts): int {
                $cmp = $usageCounts[$a] <=> $usageCounts[$b];
                if ($cmp !== 0) {
                    return $cmp;
                }
                return array_sum($pairCounts[$a]) <=> array_sum($pairCounts[$b]);
            });

            $bestTicket = null;
            $bestTicketCost = PHP_INT_MAX;

            for ($trial = 0; $trial < $trialsPerTicket; $trial++) {
                // Wybierz ziarno (seed) rotując po najrzadziej użytych liczbach
                $seedPoolSize = max(3, min($poolSize, (int) ceil($poolSize * 0.45)));
                $seedIdx = ($trial < $seedPoolSize) ? $trial : ($trial % $seedPoolSize);
                $bet = [$sortedByUsage[$seedIdx]];

                $available = $pool;
                if ($trial > 0) {
                    shuffle($available);
                }

                while (count($bet) < $pick) {
                    $bestCandidate = null;
                    $lowestStepCost = PHP_INT_MAX;
                    $betLen = count($bet);

                    foreach ($available as $num) {
                        if (in_array($num, $bet, true)) {
                            continue;
                        }

                        // 1-subset cost (Bucket Balance)
                        $uCost = $usageCounts[$num] * 60;

                        // 2-subset cost (Pair coverage)
                        $pCost = 0;
                        for ($i = 0; $i < $betLen; $i++) {
                            $s = $bet[$i];
                            $pc = $pairCounts[$num][$s];
                            // Duża kara za wielokrotne powielanie tej samej pary, zerowy koszt dla nowej pary
                            $pCost += ($pc === 0) ? 0 : ($pc * 220 + 80);
                        }

                        // 3-subset cost (Triplet t=3 Set-Cover Guarantee)
                        $tCost = 0;
                        if ($trackTriplets && $betLen >= 2) {
                            for ($i = 0; $i < $betLen - 1; $i++) {
                                for ($j = $i + 1; $j < $betLen; $j++) {
                                    $tk = $this->tripletKey($num, $bet[$i], $bet[$j]);
                                    $tc = $tripletCounts[$tk] ?? 0;
                                    // Ogromny priorytet dla jeszcze NIEPOKRYTYCH trójek (tc === 0 -> koszt 0)
                                    $tCost += ($tc === 0) ? 0 : ($tc * 950 + 350);
                                }
                            }
                        }

                        $stepCost = $uCost + $pCost + $tCost;
                        if ($stepCost < $lowestStepCost) {
                            $lowestStepCost = $stepCost;
                            $bestCandidate = $num;
                        }
                    }

                    if ($bestCandidate !== null) {
                        $bet[] = $bestCandidate;
                    } else {
                        break;
                    }
                }

                sort($bet);
                if (count($bet) < $pick) {
                    continue;
                }

                // 1-opt Local Search (Kempe-style single element swap) maksymalizujący pokrycie nowych trójek i par
                if ($enableOneOpt) {
                    $bet = $this->refineTicketOneOpt($bet, $pool, $seenBets, $usageCounts, $pairCounts, $tripletCounts);
                }

                $betKey = implode('-', $bet);
                if (isset($seenBets[$betKey])) {
                    continue;
                }

                $ticketCost = $this->evaluateTicketWheelingCost($bet, $usageCounts, $pairCounts, $tripletCounts, $trackTriplets);
                if ($ticketCost < $bestTicketCost) {
                    $bestTicketCost = $ticketCost;
                    $bestTicket = $bet;

                    if ($ticketCost === 0) {
                        break;
                    }
                }
            }

            // Fallback, jeśli wszystkie próby turniejowe trafiły na istniejące już kombinacje
            if ($bestTicket === null) {
                $bestTicket = $this->findUnseenCombination($pool, $pick, $seenBets);
            }

            if ($bestTicket === null) {
                break;
            }

            $betKey = implode('-', $bestTicket);
            $seenBets[$betKey] = true;
            $results[] = $bestTicket;

            $bLen = count($bestTicket);
            for ($i = 0; $i < $bLen; $i++) {
                $n1 = $bestTicket[$i];
                $usageCounts[$n1]++;
                for ($j = $i + 1; $j < $bLen; $j++) {
                    $n2 = $bestTicket[$j];
                    $pairCounts[$n1][$n2]++;
                    $pairCounts[$n2][$n1]++;
                    if ($trackTriplets) {
                        for ($k = $j + 1; $k < $bLen; $k++) {
                            $tk = sprintf('%d-%d-%d', $n1, $n2, $bestTicket[$k]);
                            $tripletCounts[$tk] = ($tripletCounts[$tk] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Oblicza dokładne pokrycie podzbiorów 2-elementowych (par) i 3-elementowych (trójek)
     * dla wygenerowanego systemu skróconego (t-Design Coverage Metrics).
     *
     * @param array<int> $pool
     * @param array<int, array<int>> $bets
     * @return array{
     *     pairs_covered: int,
     *     pairs_total: int,
     *     pairs_coverage_pct: float,
     *     triplets_covered: int,
     *     triplets_total: int,
     *     triplets_coverage_pct: float
     * }
     */
    public function calculateSubsetsCoverage(array $pool, array $bets): array
    {
        $pool = array_values(array_unique(array_map('intval', $pool)));
        $poolSize = count($pool);
        $inPool = array_fill_keys($pool, true);

        $pairsTotal = (int) $this->countCombinations($poolSize, 2);
        $tripletsTotal = (int) $this->countCombinations($poolSize, 3);

        $coveredPairs = [];
        $coveredTriplets = [];

        foreach ($bets as $bet) {
            $valid = [];
            foreach ($bet as $n) {
                $n = (int) $n;
                if (isset($inPool[$n])) {
                    $valid[] = $n;
                }
            }
            $valid = array_values(array_unique($valid));
            sort($valid);
            $len = count($valid);

            for ($i = 0; $i < $len; $i++) {
                for ($j = $i + 1; $j < $len; $j++) {
                    $coveredPairs[sprintf('%d-%d', $valid[$i], $valid[$j])] = true;
                    for ($k = $j + 1; $k < $len; $k++) {
                        $coveredTriplets[sprintf('%d-%d-%d', $valid[$i], $valid[$j], $valid[$k])] = true;
                    }
                }
            }
        }

        $pCov = count($coveredPairs);
        $tCov = count($coveredTriplets);

        return [
            'pairs_covered' => $pCov,
            'pairs_total' => $pairsTotal,
            'pairs_coverage_pct' => $pairsTotal > 0 ? round(($pCov / $pairsTotal) * 100, 2) : 100.0,
            'triplets_covered' => $tCov,
            'triplets_total' => $tripletsTotal,
            'triplets_coverage_pct' => $tripletsTotal > 0 ? round(($tCov / $tripletsTotal) * 100, 2) : 100.0,
        ];
    }

    /**
     * Jednokrokowa wymiana 1-opt (Kempe swap): sprawdza, czy podmiana jednej liczby w kuponie
     * na inną z puli zmniejsza koszt pokrycia (czyli pokrywa więcej dotąd niepokrytych trójek/par).
     *
     * @param array<int> $bet
     * @param array<int> $pool
     * @param array<string, bool> $seenBets
     * @param array<int, int> $usageCounts
     * @param array<int, array<int, int>> $pairCounts
     * @param array<string, int> $tripletCounts
     * @return array<int>
     */
    private function refineTicketOneOpt(
        array $bet,
        array $pool,
        array $seenBets,
        array $usageCounts,
        array $pairCounts,
        array $tripletCounts
    ): array {
        $currentCost = $this->evaluateTicketWheelingCost($bet, $usageCounts, $pairCounts, $tripletCounts, true);
        if ($currentCost === 0) {
            return $bet;
        }

        $outsidePool = array_values(array_diff($pool, $bet));
        $pick = count($bet);
        $bestBet = $bet;
        $bestCost = $currentCost;

        for ($pos = 0; $pos < $pick; $pos++) {
            $removed = $bet[$pos];
            foreach ($outsidePool as $replacement) {
                if ($usageCounts[$replacement] > $usageCounts[$removed] + 1) {
                    continue;
                }
                $candidate = $bet;
                $candidate[$pos] = $replacement;
                sort($candidate);

                $key = implode('-', $candidate);
                if (isset($seenBets[$key])) {
                    continue;
                }

                $cost = $this->evaluateTicketWheelingCost($candidate, $usageCounts, $pairCounts, $tripletCounts, true);
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $bestBet = $candidate;
                }
            }
        }

        return $bestBet;
    }

    /**
     * Całkowita funkcja kosztu pokrycia (im niższa, tym więcej nowych trójek i par pokrywa kupon).
     *
     * @param array<int> $bet
     * @param array<int, int> $usageCounts
     * @param array<int, array<int, int>> $pairCounts
     * @param array<string, int> $tripletCounts
     */
    private function evaluateTicketWheelingCost(
        array $bet,
        array $usageCounts,
        array $pairCounts,
        array $tripletCounts,
        bool $trackTriplets
    ): int {
        $cost = 0;
        $len = count($bet);

        for ($i = 0; $i < $len; $i++) {
            $n1 = $bet[$i];
            $cost += $usageCounts[$n1] * 60;

            for ($j = $i + 1; $j < $len; $j++) {
                $n2 = $bet[$j];
                $pc = $pairCounts[$n1][$n2];
                if ($pc > 0) {
                    $cost += $pc * 220 + 80;
                }

                if ($trackTriplets) {
                    for ($k = $j + 1; $k < $len; $k++) {
                        $tk = sprintf('%d-%d-%d', $n1, $n2, $bet[$k]);
                        $tc = $tripletCounts[$tk] ?? 0;
                        if ($tc > 0) {
                            $cost += $tc * 950 + 350;
                        }
                    }
                }
            }
        }

        return $cost;
    }

    private function tripletKey(int $a, int $b, int $c): string
    {
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        if ($b > $c) {
            [$b, $c] = [$c, $b];
        }
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }

        return sprintf('%d-%d-%d', $a, $b, $c);
    }

    /**
     * @param array<int> $pool
     * @param array<string, bool> $seenBets
     * @return array<int>|null
     */
    private function findUnseenCombination(array $pool, int $pick, array $seenBets): ?array
    {
        for ($i = 0; $i < 1000; $i++) {
            $backup = $pool;
            shuffle($backup);
            $cand = array_slice($backup, 0, $pick);
            sort($cand);
            if (!isset($seenBets[implode('-', $cand)])) {
                return $cand;
            }
        }

        foreach ($this->generateCombinations($pool, $pick) as $comb) {
            sort($comb);
            if (!isset($seenBets[implode('-', $comb)])) {
                return $comb;
            }
        }

        return null;
    }

    private function countCombinations(int $n, int $k): float
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

        $res = 1.0;
        for ($i = 1; $i <= $k; $i++) {
            $res = $res * ($n - $k + $i) / $i;
        }

        return round($res);
    }

    // === WERYFIKATOR POKRYCIA (COVERAGE CHECKER) ===
    public function calculateCoverage(array $pool, array $bets, int $assumeHits): array
    {
        $pool = array_values(array_unique($pool));

        if (count($pool) > 30) {
            throw new \InvalidArgumentException("Pula jest zbyt duża do symulacji pełnego pokrycia w czasie rzeczywistym.");
        }

        $totalDraws = 0;
        $results = [
            'total_draws' => 0,
            'hits' => array_fill(0, $assumeHits + 1, 0),
            'guarantees' => array_fill(0, $assumeHits + 1, 0.0),
        ];

        foreach ($this->generateCombinations($pool, $assumeHits) as $draw) {
            $totalDraws++;
            $maxHitInThisDraw = 0;

            foreach ($bets as $bet) {
                $hitCount = count(array_intersect($draw, $bet));
                if ($hitCount > $maxHitInThisDraw) {
                    $maxHitInThisDraw = $hitCount;
                }

                if ($maxHitInThisDraw === $assumeHits) {
                    break;
                }
            }
            $results['hits'][$maxHitInThisDraw]++;
        }

        $results['total_draws'] = $totalDraws;

        $cumulative = 0;
        for ($i = $assumeHits; $i >= 0; $i--) {
            $cumulative += $results['hits'][$i];
            $results['guarantees'][$i] = ($totalDraws > 0) ? round(($cumulative / $totalDraws) * 100, 2) : 0;
        }

        return $results;
    }

    // Generator kombinacji bez powtórzeń (używa yield do zerowego zużycia pamięci RAM)
    public function generateCombinations(array $elements, int $length): \Generator
    {
        $count = count($elements);
        if ($length < 1 || $length > $count) {
            return;
        }
        if ($length === 1) {
            foreach ($elements as $element) {
                yield [$element];
            }
            return;
        }

        for ($i = 0; $i <= $count - $length; $i++) {
            $first = $elements[$i];
            $remaining = array_slice($elements, $i + 1);
            foreach ($this->generateCombinations($remaining, $length - 1) as $combination) {
                array_unshift($combination, $first);
                yield $combination;
            }
        }
    }
}