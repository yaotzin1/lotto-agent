<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

class StatisticalOptimizerService
{
    /** Poniżej tylu losowań statystyka par jest zbyt rzadka, by cokolwiek znaczyć. */
    public const MIN_DRAWS_FOR_CO_OCCURRENCE = 20;

    public function __construct(
        private readonly GameRegistryService $gameRegistryService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Oblicza wskaźniki kombinatoryczne i rozwodnienie dla danej puli i liczby zakładów.
     */
    public function calculateDilutionMetrics(array $pool, int $pick, int $numBets, int $maxNumber): array
    {
        $pool = array_values(array_unique(array_map('intval', $pool)));
        $poolSize = count($pool);

        $totalSpace = $this->calculateCombinationsCount($poolSize, $pick);
        $fullLotterySpace = $this->calculateCombinationsCount($maxNumber, $pick);

        $dilutionFactor = ($totalSpace > 0) ? ($numBets / $totalSpace) : 1.0;
        $globalDilutionFactor = ($fullLotterySpace > 0) ? ($numBets / $fullLotterySpace) : 1.0;

        $totalSlots = $numBets * $pick;
        $avgRepeatsPerNumber = $poolSize > 0 ? round($totalSlots / $poolSize, 2) : 0.0;

        $totalPossiblePairsInPool = $this->calculateCombinationsCount($poolSize, 2);
        $totalPairsInBets = $numBets * $this->calculateCombinationsCount($pick, 2);

        return [
            'pool_size' => $poolSize,
            'pick' => $pick,
            'num_bets' => $numBets,
            'max_number' => $maxNumber,
            'pool_combinations_total' => $totalSpace,
            'full_lottery_combinations' => $fullLotterySpace,
            'dilution_factor_pct' => round($dilutionFactor * 100, 6),
            'global_dilution_factor_pct' => round($globalDilutionFactor * 100, 8),
            'dilution_ratio_str' => sprintf('1 : %s', number_format($totalSpace > 0 ? (int)round($totalSpace / max(1, $numBets)) : 1, 0, ',', ' ')),
            'avg_repeats_per_number' => $avgRepeatsPerNumber,
            'total_possible_pairs_in_pool' => (int)$totalPossiblePairsInPool,
            'total_pairs_capacity_in_bets' => (int)$totalPairsInBets,
        ];
    }

    /**
     * Oblicza parametry rozkładu Gaussa (średnia, odchylenie standardowe, optymalny przedział sumy).
     */
    public function calculateGaussianParameters(int $maxNumber, int $pick): array
    {
        // Średnia pojedynczej liczby w losowaniu jednostajnym bez zwracania
        $meanSingle = ($maxNumber + 1) / 2.0;
        // Średnia sumy k liczb
        $expectedSum = $pick * $meanSingle;

        // Wariancja sumy k liczb z populacji M bez zwracania: Var = k * ((M^2 - 1)/12) * ((M - k)/(M - 1)) = k*(M+1)*(M-k)/12
        $variance = ($pick * ($maxNumber + 1) * ($maxNumber - $pick)) / 12.0;
        $stdDev = sqrt($variance);

        // Przedział 80% pewności (ok. 1.35 sigma)
        $optimalMin = (int)round($expectedSum - (1.35 * $stdDev));
        $optimalMax = (int)round($expectedSum + (1.35 * $stdDev));

        return [
            'expected_sum' => (int)round($expectedSum),
            'std_dev' => round($stdDev, 2),
            'optimal_min' => $optimalMin,
            'optimal_max' => $optimalMax,
            'optimal_range_str' => sprintf('%d - %d', $optimalMin, $optimalMax),
        ];
    }

    /** Macierz zbudowana z rzeczywistej historii losowań (prawdziwe współwystępowanie par). */
    public const AFFINITY_SOURCE_CO_OCCURRENCE = 'historical_co_occurrence';

    /** Macierz zastępcza, wyliczona wyłącznie z częstotliwości pojedynczych liczb. */
    public const AFFINITY_SOURCE_FREQUENCY_HEURISTIC = 'frequency_heuristic';

    /** Tryb użyty przy ostatnim wywołaniu buildPairAffinityMatrix(). */
    private string $lastAffinitySource = self::AFFINITY_SOURCE_FREQUENCY_HEURISTIC;

    /** Liczba losowań, z których policzono ostatnią macierz (0 = brak historii). */
    private int $lastAffinityDrawCount = 0;

    public function getLastAffinitySource(): string
    {
        return $this->lastAffinitySource;
    }

    public function getLastAffinityDrawCount(): int
    {
        return $this->lastAffinityDrawCount;
    }

    /**
     * Największa możliwa liczba RÓŻNYCH kuponów z danej puli.
     */
    public function maxDistinctBets(int $poolSize, int $pick): float
    {
        return $this->calculateCombinationsCount($poolSize, $pick);
    }

    /**
     * Pilnuje, by nie dało się zamówić więcej kuponów, niż istnieje kombinacji.
     *
     * Bez tego np. pula 6 liczb przy grze 6-liczbowej i --bets=30 dawała
     * 30 IDENTYCZNYCH kuponów, a raport i tak chwalił się "100% pokrycia puli".
     */
    private function assertBetCountIsAchievable(int $poolSize, int $pick, int $numBets): void
    {
        if ($numBets < 1) {
            throw new \InvalidArgumentException(
                sprintf('Liczba zakładów musi być dodatnia, otrzymano %d.', $numBets)
            );
        }

        $maxDistinct = $this->maxDistinctBets($poolSize, $pick);

        if ($numBets > $maxDistinct) {
            throw new \InvalidArgumentException(sprintf(
                'Z puli %d liczb przy %d skreśleniach istnieje tylko %s różnych kuponów, '
                . 'a zamówiono %d. Zwiększ pulę albo zmniejsz liczbę zakładów.',
                $poolSize,
                $pick,
                number_format($maxDistinct, 0, ',', ' '),
                $numBets
            ));
        }
    }

    /**
     * Losuje kombinację, której NIE ma jeszcze w pakiecie.
     *
     * Poprzednio ścieżka awaryjna dokładała losowy kupon bez sprawdzania
     * unikalności, przez co użytkownik płacił za powtórzone kupony.
     *
     * @param array<int> $pool
     * @param array<int, array<int>> $existing
     * @return array<int>|null
     */
    private function findUnusedCombination(array $pool, int $pick, array $existing): ?array
    {
        $seen = [];
        foreach ($existing as $bet) {
            $seen[implode('-', $bet)] = true;
        }

        for ($i = 0; $i < 2000; $i++) {
            $backup = $pool;
            shuffle($backup);
            $candidate = array_slice($backup, 0, $pick);
            sort($candidate);

            if (!isset($seen[implode('-', $candidate)])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Buduje macierz powiązań (affinity) par liczb.
     *
     * Są DWA różne tryby i różnią się one fundamentalnie wartością informacyjną:
     *
     *  1. AFFINITY_SOURCE_CO_OCCURRENCE — gdy podano historię losowań ($draws).
     *     Liczone jest PRAWDZIWE współwystępowanie: ile razy para (A, B) padła
     *     w tym samym losowaniu. To jest realna statystyka par.
     *
     *  2. AFFINITY_SOURCE_FREQUENCY_HEURISTIC — gdy historii brak.
     *     Wynik to średnia geometryczna częstotliwości POJEDYNCZYCH liczb.
     *     UWAGA: taka wartość nie zawiera ŻADNEJ informacji o parach — jest
     *     monotoniczna względem f(A) i f(B), więc "najsilniejsza para" to po
     *     prostu "dwie najgorętsze liczby". Tryb ten jest zachowany wyłącznie
     *     jako awaryjny i MUSI być raportowany użytkownikowi jako heurystyka.
     *
     * @param array<int> $pool
     * @param array<int, int> $frequencies Mapa: [numer => liczba wystąpień]
     * @param array<int, array<int>> $draws Historia losowań (każde jako lista liczb)
     * @return array<int, array<int, int>>
     */
    public function buildPairAffinityMatrix(array $pool, array $frequencies, array $draws = []): array
    {
        $pool = array_values($pool);
        $count = count($pool);
        $matrix = [];

        $pairCounts = $this->countPairCoOccurrences($pool, $draws);
        $useCoOccurrence = $pairCounts !== null;

        $this->lastAffinitySource = $useCoOccurrence
            ? self::AFFINITY_SOURCE_CO_OCCURRENCE
            : self::AFFINITY_SOURCE_FREQUENCY_HEURISTIC;
        $this->lastAffinityDrawCount = $useCoOccurrence ? count($draws) : 0;

        // Skala normalizująca: utrzymuje rząd wielkości obu trybów porównywalny,
        // żeby wagi w funkcji dopasowania nie wymagały przestrajania.
        $scale = 1.0;
        if ($useCoOccurrence) {
            $maxPair = 0;
            foreach ($pairCounts as $partners) {
                foreach ($partners as $c) {
                    $maxPair = max($maxPair, $c);
                }
            }
            $scale = $maxPair > 0 ? (100.0 / $maxPair) : 1.0;
        }

        for ($i = 0; $i < $count; $i++) {
            $n1 = $pool[$i];
            for ($j = 0; $j < $count; $j++) {
                $n2 = $pool[$j];
                if ($n1 === $n2) {
                    $matrix[$n1][$n2] = 0;
                    continue;
                }

                if ($useCoOccurrence) {
                    // Prawdziwe współwystępowanie: |{losowania zawierające A i B}|
                    $baseScore = (int) round(($pairCounts[$n1][$n2] ?? 0) * $scale);
                } else {
                    $f1 = $frequencies[$n1] ?? 1;
                    $f2 = $frequencies[$n2] ?? 1;
                    // Heurystyka awaryjna — NIE jest to statystyka par (patrz docblock).
                    $baseScore = (int) round(sqrt($f1 * $f2) * 8);
                }

                $matrix[$n1][$n2] = $baseScore + $this->adjacencyBonus($n1, $n2);
            }
        }

        return $matrix;
    }

    /**
     * Zlicza rzeczywiste współwystąpienia par w historii losowań.
     *
     * @param array<int> $pool
     * @param array<int, array<int>> $draws
     * @return array<int, array<int, int>>|null null, gdy historia jest bezużyteczna
     */
    private function countPairCoOccurrences(array $pool, array $draws): ?array
    {
        if (count($draws) < self::MIN_DRAWS_FOR_CO_OCCURRENCE) {
            return null;
        }

        $inPool = array_fill_keys($pool, true);
        $counts = [];
        $observedPairs = 0;

        foreach ($draws as $draw) {
            if (!is_array($draw)) {
                continue;
            }

            $relevant = [];
            foreach ($draw as $n) {
                $n = (int) $n;
                if (isset($inPool[$n])) {
                    $relevant[] = $n;
                }
            }
            $relevant = array_values(array_unique($relevant));

            $len = count($relevant);
            for ($a = 0; $a < $len; $a++) {
                for ($b = $a + 1; $b < $len; $b++) {
                    $n1 = $relevant[$a];
                    $n2 = $relevant[$b];
                    $counts[$n1][$n2] = ($counts[$n1][$n2] ?? 0) + 1;
                    $counts[$n2][$n1] = ($counts[$n2][$n1] ?? 0) + 1;
                    $observedPairs++;
                }
            }
        }

        // Historia bez ani jednej pary z puli nie niesie informacji.
        return $observedPairs > 0 ? $counts : null;
    }

    /**
     * Premia strukturalna niezależna od źródła danych.
     *
     * Wcześniej sąsiad (+1/-1) dostawał +8, a liczba oddalona o 3-12 aż +12,
     * co było sprzeczne ze strategią "syndykatu klastrowego" opisaną w docs/
     * (60% puli to sąsiedzi). Premia sąsiedztwa jest teraz najwyższa.
     */
    private function adjacencyBonus(int $n1, int $n2): int
    {
        $diff = abs($n1 - $n2);

        if ($diff === 1) {
            return 14;
        }

        if ($diff >= 3 && $diff <= 12) {
            return 8;
        }

        return 0;
    }

    /**
     * Generuje zakłady zoptymalizowane statystycznie dla dużej puli przy silnym rozwodnieniu.
     *
     * @param array<int> $pool Pula wejściowa (np. 30 lub 49 liczb)
     * @param int $pick Liczba skreśleń (np. 6)
     * @param int $numBets Docelowa liczba zakładów (np. 100 lub 25)
     * @param array<int, int> $frequencies Częstotliwości wystąpień liczb
     * @param int $maxNumber Maksymalny numer w grze (np. 49)
     * @param array $options Opcje wag i filtrów (np. ['full_coverage' => true])
     * @return array{bets: array<array<int>>, report: array}
     */
    public function optimizeBetsForDilution(
        array $pool,
        int $pick,
        int $numBets,
        array $frequencies,
        int $maxNumber,
        array $options = []
    ): array {
        // Domyślnie FALSE: to jest silnik KONCENTRACJI (Tryb 7).
        // Wcześniej domyślne `true` powodowało, że Tryb 7 natychmiast delegował
        // do Trybu 8 — oba tryby robiły dokładnie to samo, a cała gałąź poniżej
        // była martwym kodem. Pełne pokrycie zamawia się teraz jawnie.
        $fullCoverageRequested = $options['full_coverage'] ?? false;
        if ($fullCoverageRequested) {
            return $this->optimizeBetsWithFullCoverage($pool, $pick, $numBets, $frequencies, $maxNumber, $options);
        }

        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);
        $poolSize = count($pool);

        if ($poolSize < $pick) {
            throw new \InvalidArgumentException("Pula ($poolSize) jest mniejsza niż wymagana ilość do skreślenia ($pick).");
        }

        $this->assertBetCountIsAchievable($poolSize, $pick, $numBets);

        $pairMatrix = $this->buildPairAffinityMatrix($pool, $frequencies, $options['draws'] ?? []);
        $gaussParams = $this->calculateGaussianParameters($maxNumber, $pick);
        $maxPerDecade = $this->maxPerDecade($pick, $maxNumber);

        $usageCounts = array_fill_keys($pool, 0);
        $pairUsageCounts = [];
        foreach ($pool as $n1) {
            foreach ($pool as $n2) {
                $pairUsageCounts[$n1][$n2] = 0;
            }
        }

        $generatedBets = [];
        $maxAttemptsPerBet = 400;
        $wPair = $options['weight_pair'] ?? 1.2;
        $wFreq = $options['weight_freq'] ?? 1.5;
        $penaltyUsage = $options['penalty_usage'] ?? 14.0;
        $penaltyPairUsage = $options['penalty_pair'] ?? 35.0;

        for ($b = 0; $b < $numBets; $b++) {
            $bestCandidateBet = null;
            $bestCandidateFitness = -PHP_FLOAT_MAX;

            // Sortuj pulę pod kątem najmniej używanych liczb (dynamiczna rotacja puli)
            $availablePool = $pool;

            for ($attempt = 0; $attempt < $maxAttemptsPerBet; $attempt++) {
                $currentBet = [];

                // 1. Dobierz pierwszy element (Seed): preferuj liczbę o najniższym dotychczasowym użyciu
                usort($availablePool, function ($a, $b) use ($usageCounts, $frequencies) {
                    $uA = $usageCounts[$a];
                    $uB = $usageCounts[$b];
                    if ($uA !== $uB) {
                        return $uA <=> $uB;
                    }
                    return ($frequencies[$b] ?? 0) <=> ($frequencies[$a] ?? 0);
                });

                $seedCandidates = array_slice($availablePool, 0, max(4, (int)ceil($poolSize * 0.3)));
                $seed = $seedCandidates[array_rand($seedCandidates)];
                $currentBet[] = $seed;

                // 2. Dobieraj kolejne liczby z puli, dbając o realizm, rozproszenie i brak ciągów
                while (count($currentBet) < $pick) {
                    $bestNext = null;
                    $bestNextScore = -PHP_FLOAT_MAX;

                    $remainingCandidates = array_diff($pool, $currentBet);
                    shuffle($remainingCandidates);

                    foreach ($remainingCandidates as $candidate) {
                        // --- FILTR 1: BLOKADA CIĄGÓW (Max 2 liczby pod rząd, np. 14, 15 - NIGDY 14, 15, 16) ---
                        $tempNums = array_merge($currentBet, [$candidate]);
                        sort($tempNums);

                        $maxConsecutive = 1;
                        $curConsecutive = 1;
                        $adjacentPairsCount = 0;

                        for ($k = 0; $k < count($tempNums) - 1; $k++) {
                            if ($tempNums[$k + 1] === $tempNums[$k] + 1) {
                                $curConsecutive++;
                                $adjacentPairsCount++;
                                if ($curConsecutive > $maxConsecutive) {
                                    $maxConsecutive = $curConsecutive;
                                }
                            } else {
                                $curConsecutive = 1;
                            }
                        }

                        // Jeśli dodanie kandydata tworzy 3 liczby pod rząd lub więcej niż 1 parę sąsiadów -> ODRZUĆ
                        if ($maxConsecutive >= 3 || $adjacentPairsCount > 1) {
                            continue;
                        }

                        // --- FILTR 2: ROZPROSZENIE DEKADOWE (Maks. 2 liczby w tej samej dziesiątce) ---
                        $candDecade = (int)floor(($candidate - 1) / 10);
                        $decadeCount = 0;
                        foreach ($currentBet as $cb) {
                            if ((int)floor(($cb - 1) / 10) === $candDecade) {
                                $decadeCount++;
                            }
                        }
                        if ($decadeCount >= $maxPerDecade) {
                            continue; // Limit zagęszczenia dekadowego (skalowany do gry)
                        }

                        // --- FILTR 3: PROBABILITY & SYNERGY SCORING ---
                        $fScore = ($frequencies[$candidate] ?? 1) * $wFreq;

                        $pairAffinitySum = 0;
                        $pairUsagePenaltySum = 0;
                        foreach ($currentBet as $selected) {
                            $pairAffinitySum += ($pairMatrix[$candidate][$selected] ?? 0);
                            $pairUsagePenaltySum += ($pairUsageCounts[$candidate][$selected] ?? 0) * $penaltyPairUsage;
                        }

                        $usagePenalty = $usageCounts[$candidate] * $penaltyUsage;

                        $score = ($fScore * 3.0) + ($pairAffinitySum * $wPair) - $usagePenalty - $pairUsagePenaltySum;

                        // Preferencja dla balansu parzyste / nieparzyste
                        $currentOdds = count(array_filter($currentBet, fn($n) => $n % 2 !== 0));
                        $isCandOdd = ($candidate % 2 !== 0);
                        if (count($currentBet) >= ($pick - 2)) {
                            if ($currentOdds >= ceil($pick / 2) && $isCandOdd) {
                                $score -= 40;
                            } elseif ($currentOdds <= 1 && !$isCandOdd) {
                                $score -= 40;
                            }
                        }

                        // Kontrola orientacyjnej sumy cząstkowej
                        $tempSum = array_sum($tempNums);
                        $slotsRemaining = $pick - count($tempNums);
                        $minProjectedSum = $tempSum + ($slotsRemaining * 1);
                        $maxProjectedSum = $tempSum + ($slotsRemaining * $maxNumber);

                        if ($minProjectedSum > $gaussParams['optimal_max'] || $maxProjectedSum < $gaussParams['optimal_min']) {
                            $score -= 60;
                        }

                        if ($score > $bestNextScore) {
                            $bestNextScore = $score;
                            $bestNext = $candidate;
                        }
                    }

                    if ($bestNext !== null) {
                        $currentBet[] = $bestNext;
                    } else {
                        // Jeśli brak idealnego kandydata, weź bezpiecznego z innej dekady
                        $remainingCandidates = array_diff($pool, $currentBet);
                        shuffle($remainingCandidates);
                        foreach ($remainingCandidates as $rc) {
                            $tempNums = array_merge($currentBet, [$rc]);
                            sort($tempNums);
                            $maxC = 1;
                            $curC = 1;
                            for ($k = 0; $k < count($tempNums) - 1; $k++) {
                                if ($tempNums[$k + 1] === $tempNums[$k] + 1) {
                                    $curC++;
                                    if ($curC > $maxC) $maxC = $curC;
                                } else {
                                    $curC = 1;
                                }
                            }
                            if ($maxC <= 2) {
                                $currentBet[] = $rc;
                                break;
                            }
                        }
                        if (count($currentBet) < $pick && !empty($remainingCandidates)) {
                            $currentBet[] = reset($remainingCandidates);
                        }
                    }
                }

                sort($currentBet);

                if (count($currentBet) < $pick) {
                    continue;
                }

                // Sprawdź czy kupon jest unikalny
                $isDuplicate = false;
                foreach ($generatedBets as $existing) {
                    if ($existing === $currentBet) {
                        $isDuplicate = true;
                        break;
                    }
                }

                if ($isDuplicate) {
                    continue;
                }

                // 3. Ocena całkowita kuponu (Fitness Score)
                $fitness = $this->calculateBetFitness($currentBet, $pairMatrix, $frequencies, $gaussParams, $maxNumber);

                if ($fitness['total_score'] > $bestCandidateFitness) {
                    $bestCandidateFitness = $fitness['total_score'];
                    $bestCandidateBet = $currentBet;

                    if ($fitness['is_gaussian_optimal'] && $fitness['is_parity_balanced'] && $fitness['max_consecutive'] <= 2 && $attempt > 10) {
                        break;
                    }
                }
            }

            // Jeśli po próbach nie znaleziono unikalnego, wygeneruj awaryjny realistyczny kupon
            if ($bestCandidateBet === null) {
                $bestCandidateBet = $this->findUnusedCombination($pool, $pick, $generatedBets);
            }

            // Brak jakiejkolwiek nowej kombinacji — kończymy zamiast dokładać duplikaty.
            if ($bestCandidateBet === null) {
                $this->logger->warning('Przerwano generowanie: brak dalszych unikalnych kombinacji.', [
                    'generated' => count($generatedBets),
                    'requested' => $numBets,
                ]);
                break;
            }

            $generatedBets[] = $bestCandidateBet;

            // Zaktualizuj liczniki użyć (Dynamic Marginal Decay)
            foreach ($bestCandidateBet as $i => $n1) {
                $usageCounts[$n1]++;
                foreach ($bestCandidateBet as $j => $n2) {
                    if ($i !== $j) {
                        $pairUsageCounts[$n1][$n2]++;
                    }
                }
            }
        }

        // Sortuj zakłady według rankingu Fitness Score (malejąco)
        $betsWithFitness = [];
        foreach ($generatedBets as $bet) {
            $fit = $this->calculateBetFitness($bet, $pairMatrix, $frequencies, $gaussParams, $maxNumber);
            $betsWithFitness[] = [
                'bet' => $bet,
                'fitness' => $fit,
            ];
        }
        usort($betsWithFitness, fn($a, $b) => $b['fitness']['total_score'] <=> $a['fitness']['total_score']);
        $sortedBets = array_map(fn($item) => $item['bet'], $betsWithFitness);

        // Przelicz użycia
        $finalUsageCounts = array_fill_keys($pool, 0);
        $finalPairUsageCounts = [];
        foreach ($pool as $n1) {
            foreach ($pool as $n2) {
                $finalPairUsageCounts[$n1][$n2] = 0;
            }
        }
        foreach ($sortedBets as $b) {
            foreach ($b as $i => $n1) {
                $finalUsageCounts[$n1]++;
                foreach ($b as $j => $n2) {
                    if ($i !== $j) $finalPairUsageCounts[$n1][$n2]++;
                }
            }
        }

        // Przygotowanie szczegółowego raportu do Okna Statystycznego
        $report = $this->generateStatisticalReport(
            $pool,
            $sortedBets,
            $pairMatrix,
            $frequencies,
            $gaussParams,
            $maxNumber,
            $finalUsageCounts,
            $finalPairUsageCounts,
            $options['draws'] ?? []
        );
        $report['ranked_bets'] = $betsWithFitness;

        return [
            'bets' => $sortedBets,
            'report' => $report,
        ];
    }

    /**
     * Generuje zakłady z gwarancją pełnego pokrycia puli liczb (Zero-Drop) i rankingiem synergii.
     *
     * @param array<int> $pool Pula wejściowa (np. 42 lub 49 liczb)
     * @param int $pick Liczba skreśleń (np. 5 lub 6)
     * @param int $numBets Docelowa liczba zakładów (np. 15 lub 25)
     * @param array<int, int> $frequencies Częstotliwości wystąpień liczb
     * @param int $maxNumber Maksymalny numer w grze (np. 42 lub 49)
     * @param array $options Opcje wag i filtrów
     * @return array{bets: array<array<int>>, report: array}
     */
    public function optimizeBetsWithFullCoverage(
        array $pool,
        int $pick,
        int $numBets,
        array $frequencies,
        int $maxNumber,
        array $options = []
    ): array {
        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);
        $poolSize = count($pool);

        if ($poolSize < $pick) {
            throw new \InvalidArgumentException("Pula ($poolSize) jest mniejsza niż wymagana ilość do skreślenia ($pick).");
        }

        $this->assertBetCountIsAchievable($poolSize, $pick, $numBets);

        $pairMatrix = $this->buildPairAffinityMatrix($pool, $frequencies, $options['draws'] ?? []);
        $gaussParams = $this->calculateGaussianParameters($maxNumber, $pick);
        $maxPerDecade = $this->maxPerDecade($pick, $maxNumber);

        // Liczba zakładów bazowych potrzebna do jednokrotnego pokrycia całej puli
        $baseBetsNeeded = (int)ceil($poolSize / $pick);
        $baseBetsCount = min($numBets, $baseBetsNeeded);

        // FAZA 1: Partycjonowanie puli w celu zapewnienia maksymalnego pokrycia i synergii par
        $bestPartitionBets = [];
        $bestPartitionFitness = -PHP_FLOAT_MAX;

        $partitionAttempts = 300;
        for ($p = 0; $p < $partitionAttempts; $p++) {
            $unassigned = $pool;
            shuffle($unassigned);
            $currentPartition = [];

            for ($b = 0; $b < $baseBetsCount; $b++) {
                $currentBet = [];

                if (!empty($unassigned)) {
                    usort($unassigned, fn($n1, $n2) => ($frequencies[$n2] ?? 0) <=> ($frequencies[$n1] ?? 0));
                    $seedIdx = ($p % 2 === 0 && count($unassigned) > 3) ? array_rand(array_slice($unassigned, 0, 3, true)) : 0;
                    $currentBet[] = $unassigned[$seedIdx];
                    unset($unassigned[$seedIdx]);
                    $unassigned = array_values($unassigned);
                }

                while (count($currentBet) < $pick && !empty($unassigned)) {
                    $bestCandIdx = null;
                    $bestCandScore = -PHP_FLOAT_MAX;

                    foreach ($unassigned as $uIdx => $cand) {
                        $tempNums = array_merge($currentBet, [$cand]);
                        sort($tempNums);
                        $maxConsecutive = 1;
                        $curConsecutive = 1;
                        $adjacentPairs = 0;
                        for ($k = 0; $k < count($tempNums) - 1; $k++) {
                            if ($tempNums[$k + 1] === $tempNums[$k] + 1) {
                                $curConsecutive++;
                                $adjacentPairs++;
                                if ($curConsecutive > $maxConsecutive) {
                                    $maxConsecutive = $curConsecutive;
                                }
                            } else {
                                $curConsecutive = 1;
                            }
                        }
                        if ($maxConsecutive >= 3 || $adjacentPairs > 1) {
                            continue;
                        }

                        $candDecade = (int)floor(($cand - 1) / 10);
                        $decCount = 0;
                        foreach ($currentBet as $cb) {
                            if ((int)floor(($cb - 1) / 10) === $candDecade) {
                                $decCount++;
                            }
                        }
                        if ($decCount >= $maxPerDecade) {
                            continue;
                        }

                        $aff = 0;
                        foreach ($currentBet as $cb) {
                            $aff += ($pairMatrix[$cand][$cb] ?? 0);
                        }
                        $freq = ($frequencies[$cand] ?? 1);
                        $score = ($aff * 1.5) + ($freq * 2.0);

                        if ($score > $bestCandScore) {
                            $bestCandScore = $score;
                            $bestCandIdx = $uIdx;
                        }
                    }

                    if ($bestCandIdx !== null) {
                        $currentBet[] = $unassigned[$bestCandIdx];
                        unset($unassigned[$bestCandIdx]);
                        $unassigned = array_values($unassigned);
                    } else {
                        $currentBet[] = array_shift($unassigned);
                    }
                }

                // Jeśli unassigned się skończyło, a zakład ma mniej niż $pick liczb (resztka),
                // dopełnij z pełnej puli liczbami o najwyższym affinity do tego zakładu
                if (count($currentBet) < $pick) {
                    $availSpare = array_diff($pool, $currentBet);
                    while (count($currentBet) < $pick && !empty($availSpare)) {
                        $bestSpare = null;
                        $bestSpareScore = -PHP_FLOAT_MAX;
                        foreach ($availSpare as $spare) {
                            $aff = 0;
                            foreach ($currentBet as $cb) {
                                $aff += ($pairMatrix[$spare][$cb] ?? 0);
                            }
                            $f = ($frequencies[$spare] ?? 1);
                            $sc = ($aff * 1.5) + ($f * 2.0);
                            if ($sc > $bestSpareScore) {
                                $bestSpareScore = $sc;
                                $bestSpare = $spare;
                            }
                        }
                        if ($bestSpare !== null) {
                            $currentBet[] = $bestSpare;
                            $availSpare = array_diff($availSpare, [$bestSpare]);
                        } else {
                            $currentBet[] = array_shift($availSpare);
                        }
                    }
                }

                sort($currentBet);
                $currentPartition[] = $currentBet;
            }

            $partFitness = 0.0;
            foreach ($currentPartition as $pb) {
                $f = $this->calculateBetFitness($pb, $pairMatrix, $frequencies, $gaussParams, $maxNumber);
                $partFitness += $f['total_score'];
            }

            if ($partFitness > $bestPartitionFitness) {
                $bestPartitionFitness = $partFitness;
                $bestPartitionBets = $currentPartition;
            }
        }

        $generatedBets = $bestPartitionBets;

        $usageCounts = array_fill_keys($pool, 0);
        $pairUsageCounts = [];
        foreach ($pool as $n1) {
            foreach ($pool as $n2) {
                $pairUsageCounts[$n1][$n2] = 0;
            }
        }
        foreach ($generatedBets as $b) {
            foreach ($b as $i => $n1) {
                $usageCounts[$n1]++;
                foreach ($b as $j => $n2) {
                    if ($i !== $j) $pairUsageCounts[$n1][$n2]++;
                }
            }
        }

        // FAZA 2: Dopełnienie do żądanej liczby zakładów (jeśli numBets > baseBetsCount)
        $wPair = $options['weight_pair'] ?? 1.2;
        $wFreq = $options['weight_freq'] ?? 1.5;
        $penaltyUsage = $options['penalty_usage'] ?? 10.0;
        $penaltyPairUsage = $options['penalty_pair'] ?? 25.0;

        while (count($generatedBets) < $numBets) {
            $bestCandidateBet = null;
            $bestCandidateFitness = -PHP_FLOAT_MAX;

            for ($attempt = 0; $attempt < 300; $attempt++) {
                $currentBet = [];
                $avail = $pool;
                usort($avail, function ($a, $b) use ($usageCounts, $frequencies) {
                    $uA = $usageCounts[$a];
                    $uB = $usageCounts[$b];
                    if ($uA !== $uB) return $uA <=> $uB;
                    return ($frequencies[$b] ?? 0) <=> ($frequencies[$a] ?? 0);
                });

                $seedPool = array_slice($avail, 0, max(5, (int)ceil($poolSize * 0.3)));
                $currentBet[] = $seedPool[array_rand($seedPool)];

                while (count($currentBet) < $pick) {
                    $bestNext = null;
                    $bestNextScore = -PHP_FLOAT_MAX;
                    $remaining = array_diff($pool, $currentBet);
                    shuffle($remaining);

                    foreach ($remaining as $candidate) {
                        $tempNums = array_merge($currentBet, [$candidate]);
                        sort($tempNums);
                        $maxC = 1; $curC = 1; $adj = 0;
                        for ($k = 0; $k < count($tempNums) - 1; $k++) {
                            if ($tempNums[$k + 1] === $tempNums[$k] + 1) {
                                $curC++; $adj++;
                                if ($curC > $maxC) $maxC = $curC;
                            } else {
                                $curC = 1;
                            }
                        }
                        if ($maxC >= 3 || $adj > 1) continue;

                        $candDecade = (int)floor(($candidate - 1) / 10);
                        $decC = 0;
                        foreach ($currentBet as $cb) {
                            if ((int)floor(($cb - 1) / 10) === $candDecade) $decC++;
                        }
                        if ($decC >= $maxPerDecade) continue;

                        $fScore = ($frequencies[$candidate] ?? 1) * $wFreq;
                        $pairAff = 0;
                        $pairPen = 0;
                        foreach ($currentBet as $sel) {
                            $pairAff += ($pairMatrix[$candidate][$sel] ?? 0);
                            $pairPen += ($pairUsageCounts[$candidate][$sel] ?? 0) * $penaltyPairUsage;
                        }
                        $uPen = $usageCounts[$candidate] * $penaltyUsage;

                        $score = ($fScore * 3.0) + ($pairAff * $wPair) - $uPen - $pairPen;

                        $odds = count(array_filter($currentBet, fn($n) => $n % 2 !== 0));
                        $isOdd = ($candidate % 2 !== 0);
                        if (count($currentBet) >= ($pick - 2)) {
                            if ($odds >= ceil($pick / 2) && $isOdd) $score -= 35;
                            elseif ($odds <= 1 && !$isOdd) $score -= 35;
                        }

                        $tempSum = array_sum($tempNums);
                        $remSlots = $pick - count($tempNums);
                        $minP = $tempSum + $remSlots;
                        $maxP = $tempSum + ($remSlots * $maxNumber);
                        if ($minP > $gaussParams['optimal_max'] || $maxP < $gaussParams['optimal_min']) {
                            $score -= 50;
                        }

                        if ($score > $bestNextScore) {
                            $bestNextScore = $score;
                            $bestNext = $candidate;
                        }
                    }

                    if ($bestNext !== null) {
                        $currentBet[] = $bestNext;
                    } else {
                        $rem = array_diff($pool, $currentBet);
                        if (!empty($rem)) {
                            $currentBet[] = reset($rem);
                        } else {
                            break;
                        }
                    }
                }

                sort($currentBet);
                if (count($currentBet) < $pick) continue;

                $dup = false;
                foreach ($generatedBets as $ex) {
                    if ($ex === $currentBet) { $dup = true; break; }
                }
                if ($dup) continue;

                $fit = $this->calculateBetFitness($currentBet, $pairMatrix, $frequencies, $gaussParams, $maxNumber);
                if ($fit['total_score'] > $bestCandidateFitness) {
                    $bestCandidateFitness = $fit['total_score'];
                    $bestCandidateBet = $currentBet;
                    if ($fit['is_gaussian_optimal'] && $fit['is_parity_balanced'] && $fit['max_consecutive'] <= 2 && $attempt > 15) {
                        break;
                    }
                }
            }

            if ($bestCandidateBet === null) {
                $bestCandidateBet = $this->findUnusedCombination($pool, $pick, $generatedBets);
            }

            if ($bestCandidateBet === null) {
                $this->logger->warning('Przerwano dopełnianie: brak dalszych unikalnych kombinacji.', [
                    'generated' => count($generatedBets),
                    'requested' => $numBets,
                ]);
                break;
            }

            $generatedBets[] = $bestCandidateBet;
            foreach ($bestCandidateBet as $i => $n1) {
                $usageCounts[$n1]++;
                foreach ($bestCandidateBet as $j => $n2) {
                    if ($i !== $j) $pairUsageCounts[$n1][$n2]++;
                }
            }
        }

        // FAZA 3: Ranking i sortowanie zakładów według Fitness Score
        $betsWithFitness = [];
        foreach ($generatedBets as $bet) {
            $fit = $this->calculateBetFitness($bet, $pairMatrix, $frequencies, $gaussParams, $maxNumber);
            $betsWithFitness[] = [
                'bet' => $bet,
                'fitness' => $fit,
            ];
        }

        usort($betsWithFitness, function ($a, $b) {
            return $b['fitness']['total_score'] <=> $a['fitness']['total_score'];
        });

        $finalSortedBets = array_map(fn($item) => $item['bet'], $betsWithFitness);

        // Przelicz statystyki dla raportu
        $finalUsageCounts = array_fill_keys($pool, 0);
        $finalPairUsageCounts = [];
        foreach ($pool as $n1) {
            foreach ($pool as $n2) {
                $finalPairUsageCounts[$n1][$n2] = 0;
            }
        }
        foreach ($finalSortedBets as $b) {
            foreach ($b as $i => $n1) {
                $finalUsageCounts[$n1]++;
                foreach ($b as $j => $n2) {
                    if ($i !== $j) $finalPairUsageCounts[$n1][$n2]++;
                }
            }
        }

        $report = $this->generateStatisticalReport(
            $pool,
            $finalSortedBets,
            $pairMatrix,
            $frequencies,
            $gaussParams,
            $maxNumber,
            $finalUsageCounts,
            $finalPairUsageCounts,
            $options['draws'] ?? []
        );

        $usedUniqueCount = count(array_filter($finalUsageCounts, fn($cnt) => $cnt > 0));
        $report['unique_numbers_used'] = $usedUniqueCount;
        $report['pool_size'] = $poolSize;
        $report['pool_coverage_pct'] = round(($usedUniqueCount / $poolSize) * 100, 1);
        $report['is_full_coverage_guaranteed'] = ($usedUniqueCount === $poolSize);
        $report['base_bets_needed'] = $baseBetsNeeded;
        $report['ranked_bets'] = $betsWithFitness;

        return [
            'bets' => $finalSortedBets,
            'report' => $report,
        ];
    }

    /**
     * Czy podział parzyste/nieparzyste jest "naturalny" dla TEJ gry.
     *
     * Poprzedni warunek ($odds >= 2 && $evens >= 1 && $odds <= 4) był zapisany
     * na sztywno pod pick = 5..6. Dla Multi Multi (pick = 10) odrzucał podział
     * 5:5 — czyli najbardziej prawdopodobny — a nagradzał 4:6. Był też
     * asymetryczny (dopuszczał 4:1, ale nie 1:4).
     *
     * Dla pick = 6 wynik jest identyczny jak wcześniej: {2:4, 3:3, 4:2}.
     */
    public function isParityBalanced(int $odds, int $evens, int $pick): bool
    {
        if ($pick < 2) {
            return true;
        }

        if ($odds < 1 || $evens < 1) {
            return false;
        }

        $tolerance = max(2, (int) ceil($pick / 3));

        return abs($odds - $evens) <= $tolerance;
    }

    /**
     * Maksymalna dopuszczalna liczba liczb w jednej dekadzie.
     *
     * Skalowana do gry: dla Kaskady (12 z 24) istnieją tylko 3 dekady, więc
     * sztywny limit 2 czynił KAŻDY możliwy kupon nielegalnym.
     */
    public function maxPerDecade(int $pick, int $maxNumber): int
    {
        $decadesAvailable = max(1, (int) ceil($maxNumber / 10));

        return max(2, (int) ceil($pick / $decadesAvailable));
    }

    /**
     * Docelowe rozproszenie dekadowe — nigdy więcej niż liczba istniejących dekad.
     */
    public function targetDecadeSpread(int $pick, int $maxNumber): int
    {
        $decadesAvailable = max(1, (int) ceil($maxNumber / 10));

        return min($decadesAvailable, $pick >= 6 ? 4 : 3);
    }

    /**
     * Ocenia jakość pojedynczego zakładu z uwzględnieniem realizmu stochastycznego.
     */
    public function calculateBetFitness(
        array $bet,
        array $pairMatrix,
        array $frequencies,
        array $gaussParams,
        int $maxNumber
    ): array {
        $pick = count($bet);
        $sum = array_sum($bet);

        // 1. Weryfikacja ciągów kolejnych liczb (Consecutive runs)
        $maxConsecutive = 1;
        $curConsecutive = 1;
        $adjacentPairs = 0;
        for ($i = 0; $i < $pick - 1; $i++) {
            if ($bet[$i + 1] === $bet[$i] + 1) {
                $curConsecutive++;
                $adjacentPairs++;
                if ($curConsecutive > $maxConsecutive) {
                    $maxConsecutive = $curConsecutive;
                }
            } else {
                $curConsecutive = 1;
            }
        }

        // Kary za nierealistyczne ciągi liczb
        $consecutivePenalty = 0;
        if ($maxConsecutive >= 3) {
            $consecutivePenalty = ($maxConsecutive >= 4) ? -500 : -200;
        } elseif ($adjacentPairs > 1) {
            $consecutivePenalty = -100;
        }

        // 2. Suma powiązań par
        $pairAffinityTotal = 0;
        $pairCount = 0;
        for ($i = 0; $i < $pick; $i++) {
            for ($j = $i + 1; $j < $pick; $j++) {
                $pairAffinityTotal += ($pairMatrix[$bet[$i]][$bet[$j]] ?? 0);
                $pairCount++;
            }
        }
        $avgPairAffinity = $pairCount > 0 ? $pairAffinityTotal / $pairCount : 0;

        // 3. Suma frekwencji liczb
        $freqTotal = 0;
        foreach ($bet as $num) {
            $freqTotal += ($frequencies[$num] ?? 1);
        }

        // 4. Parzyste / Nieparzyste (progi skalowane do liczby skreśleń)
        $odds = count(array_filter($bet, fn($n) => $n % 2 !== 0));
        $evens = $pick - $odds;
        $isParityBalanced = $this->isParityBalanced($odds, $evens, $pick);
        $parityBonus = $isParityBalanced ? 30 : -40;

        // 5. Dzwon Gaussa sumy
        $isGaussianOptimal = ($sum >= $gaussParams['optimal_min'] && $sum <= $gaussParams['optimal_max']);
        $diffFromExpected = abs($sum - $gaussParams['expected_sum']);
        $gaussianBonus = $isGaussianOptimal ? 40 : max(-60, (int)round(40 - ($diffFromExpected * 2.0)));

        // 6. Rozkład dekadowy (zagęszczenie)
        $decades = [];
        foreach ($bet as $n) {
            $decades[(int)floor(($n - 1) / 10)] = ($decades[(int)floor(($n - 1) / 10)] ?? 0) + 1;
        }
        $decadeSpread = count($decades);
        $maxInSingleDecade = !empty($decades) ? max($decades) : 0;

        $decadeBonus = 0;
        if ($maxInSingleDecade > $this->maxPerDecade($pick, $maxNumber)) {
            $decadeBonus = -150; // kara za stłoczenie liczb w jednej dekadzie
        } elseif ($decadeSpread >= $this->targetDecadeSpread($pick, $maxNumber)) {
            $decadeBonus = 30; // bonus za naturalne rozproszenie
        }

        $totalScore = ($pairAffinityTotal * 1.0) + ($freqTotal * 2.5) + $parityBonus + $gaussianBonus + $decadeBonus + $consecutivePenalty;

        return [
            'total_score' => round($totalScore, 1),
            'sum' => $sum,
            'is_gaussian_optimal' => $isGaussianOptimal,
            'is_parity_balanced' => $isParityBalanced,
            'parity_ratio' => sprintf('%d:%d', $odds, $evens),
            'decade_spread' => $decadeSpread,
            'max_consecutive' => $maxConsecutive,
            'pair_affinity_total' => $pairAffinityTotal,
            'avg_pair_affinity' => round($avgPairAffinity, 2),
            'freq_total' => $freqTotal,
        ];
    }

    /**
     * Benchmark porównawczy: wygenerowany zestaw vs losowy baseline Monte Carlo.
     */
    public function benchmarkAgainstRandom(
        array $pool,
        int $pick,
        array $optimizedBets,
        array $frequencies,
        int $maxNumber,
        int $simulations = 50,
        array $draws = []
    ): array {
        $gaussParams = $this->calculateGaussianParameters($maxNumber, $pick);
        // Zachowaj informację o źródle macierzy — benchmark nie może jej nadpisać.
        $sourceBefore = $this->lastAffinitySource;
        $drawsBefore = $this->lastAffinityDrawCount;
        $pairMatrix = $this->buildPairAffinityMatrix($pool, $frequencies, $draws);
        $this->lastAffinitySource = $sourceBefore;
        $this->lastAffinityDrawCount = $drawsBefore;

        $optCount = count($optimizedBets);

        // Oblicz metryki dla zoptymalizowanego zestawu
        $optScores = [];
        $optGaussHits = 0;
        $optParityHits = 0;

        foreach ($optimizedBets as $bet) {
            $fit = $this->calculateBetFitness($bet, $pairMatrix, $frequencies, $gaussParams, $maxNumber);
            $optScores[] = $fit['total_score'];
            if ($fit['is_gaussian_optimal']) {
                $optGaussHits++;
            }
            if ($fit['is_parity_balanced']) {
                $optParityHits++;
            }
        }

        $optAvgScore = count($optScores) > 0 ? array_sum($optScores) / count($optScores) : 0.0;
        $optGaussPct = $optCount > 0 ? ($optGaussHits / $optCount) * 100 : 0.0;
        $optParityPct = $optCount > 0 ? ($optParityHits / $optCount) * 100 : 0.0;

        // Symulacja losowego baseline
        $randScoresTotal = 0;
        $randGaussTotalHits = 0;
        $randParityTotalHits = 0;

        for ($s = 0; $s < $simulations; $s++) {
            for ($b = 0; $b < $optCount; $b++) {
                $rPool = $pool;
                shuffle($rPool);
                $randBet = array_slice($rPool, 0, $pick);
                sort($randBet);

                $rFit = $this->calculateBetFitness($randBet, $pairMatrix, $frequencies, $gaussParams, $maxNumber);
                $randScoresTotal += $rFit['total_score'];
                if ($rFit['is_gaussian_optimal']) {
                    $randGaussTotalHits++;
                }
                if ($rFit['is_parity_balanced']) {
                    $randParityTotalHits++;
                }
            }
        }

        $totalRandBetsEvaluated = $simulations * $optCount;
        $randAvgScore = $totalRandBetsEvaluated > 0 ? $randScoresTotal / $totalRandBetsEvaluated : 0.0;
        $randGaussPct = $totalRandBetsEvaluated > 0 ? ($randGaussTotalHits / $totalRandBetsEvaluated) * 100 : 0.0;
        $randParityPct = $totalRandBetsEvaluated > 0 ? ($randParityTotalHits / $totalRandBetsEvaluated) * 100 : 0.0;

        $advantagePct = $randAvgScore > 0 ? round((($optAvgScore - $randAvgScore) / $randAvgScore) * 100, 1) : 0.0;

        return [
            // UWAGA METODOLOGICZNA: baseline losowy jest oceniany TĄ SAMĄ funkcją
            // celu, którą optymalizator maksymalizuje. Wynik mówi więc wyłącznie
            // "optymalizator zoptymalizował własną funkcję celu" i NIE jest
            // miarą szansy na wygraną ani oczekiwanego zwrotu.
            'metric_is_self_referential' => true,
            'disclaimer' => 'Wskaźnik porównuje zestawy tą samą funkcją oceny, którą optymalizator '
                . 'maksymalizuje. Nie jest to miara prawdopodobieństwa wygranej ani zwrotu z gry.',
            'optimized_avg_synergy_score' => round($optAvgScore, 1),
            'random_baseline_avg_score' => round($randAvgScore, 1),
            'synergy_advantage_percent' => $advantagePct,
            'optimized_gaussian_adherence_pct' => round($optGaussPct, 1),
            'random_gaussian_adherence_pct' => round($randGaussPct, 1),
            'optimized_parity_balance_pct' => round($optParityPct, 1),
            'random_parity_balance_pct' => round($randParityPct, 1),
        ];
    }

    /**
     * Generuje wykres słupkowy ASCII dla rozkładu sumy zakładów.
     */
    public function generateAsciiGaussianHistogram(array $bets, array $gaussParams, int $maxNumber): array
    {
        $sums = array_map('array_sum', $bets);
        sort($sums);

        $pick = count($bets[0] ?? []);
        $minPossibleSum = ($pick * ($pick + 1)) / 2;
        $maxPossibleSum = 0;
        for ($i = 0; $i < $pick; $i++) {
            $maxPossibleSum += ($maxNumber - $i);
        }

        // Przedziały co 20 jednostek
        $bucketSize = 20;
        $startBucket = (int)floor($minPossibleSum / $bucketSize) * $bucketSize;
        $endBucket = (int)ceil($maxPossibleSum / $bucketSize) * $bucketSize;

        $buckets = [];
        for ($b = $startBucket; $b < $endBucket; $b += $bucketSize) {
            $key = sprintf('%d-%d', $b, $b + $bucketSize - 1);
            $buckets[$key] = [
                'start' => $b,
                'end' => $b + $bucketSize - 1,
                'count' => 0,
                'is_optimal' => ($b + $bucketSize > $gaussParams['optimal_min'] && $b < $gaussParams['optimal_max']),
            ];
        }

        foreach ($sums as $s) {
            foreach ($buckets as &$bData) {
                if ($s >= $bData['start'] && $s <= $bData['end']) {
                    $bData['count']++;
                    break;
                }
            }
        }

        $maxCount = 0;
        foreach ($buckets as $bData) {
            if ($bData['count'] > $maxCount) {
                $maxCount = $bData['count'];
            }
        }

        $lines = [];
        foreach ($buckets as $range => $data) {
            if ($data['count'] === 0 && !$data['is_optimal']) {
                continue;
            }
            $barLength = ($maxCount > 0) ? (int)round(($data['count'] / $maxCount) * 25) : 0;
            $bar = str_repeat('█', $barLength);
            $tag = $data['is_optimal'] ? '[Optimum Gaussa]' : '';
            $lines[] = [
                'range' => $range,
                'count' => $data['count'],
                'bar' => $bar,
                'tag' => $tag,
            ];
        }

        return [
            'min_sum' => min($sums),
            'max_sum' => max($sums),
            'avg_sum' => round(array_sum($sums) / count($sums), 1),
            'expected_sum' => $gaussParams['expected_sum'],
            'optimal_range' => $gaussParams['optimal_range_str'],
            'histogram_rows' => $lines,
        ];
    }

    /**
     * Generuje pełen raport analityczny do Okna Statystycznego.
     */
    private function generateStatisticalReport(
        array $pool,
        array $bets,
        array $pairMatrix,
        array $frequencies,
        array $gaussParams,
        int $maxNumber,
        array $usageCounts,
        array $pairUsageCounts,
        array $draws = []
    ): array {
        $pick = count($bets[0] ?? []);
        $numBets = count($bets);

        $dilutionMetrics = $this->calculateDilutionMetrics($pool, $pick, $numBets, $maxNumber);
        $gaussianHistogram = $this->generateAsciiGaussianHistogram($bets, $gaussParams, $maxNumber);
        $benchmark = $this->benchmarkAgainstRandom($pool, $pick, $bets, $frequencies, $maxNumber, 50, $draws);

        // Top 10 par o najwyższym Affinity w wygenerowanych kuponach
        $topPairs = [];
        $poolCount = count($pool);
        for ($i = 0; $i < $poolCount; $i++) {
            for ($j = $i + 1; $j < $poolCount; $j++) {
                $n1 = $pool[$i];
                $n2 = $pool[$j];
                $topPairs[] = [
                    'pair' => [$n1, $n2],
                    'affinity' => $pairMatrix[$n1][$n2] ?? 0,
                    'bets_included' => $pairUsageCounts[$n1][$n2] ?? 0,
                ];
            }
        }

        usort($topPairs, fn($a, $b) => $b['affinity'] <=> $a['affinity']);
        $top10Pairs = array_slice($topPairs, 0, 10);

        // Analiza parzystości
        $paritySummary = [];
        foreach ($bets as $b) {
            $odds = count(array_filter($b, fn($n) => $n % 2 !== 0));
            $evens = $pick - $odds;
            $ratioKey = sprintf('%d:%d (N:P)', $odds, $evens);
            $paritySummary[$ratioKey] = ($paritySummary[$ratioKey] ?? 0) + 1;
        }
        arsort($paritySummary);

        // Wyliczenie unikalnych par pokrytych w kuponach
        $uniquePairsCovered = 0;
        foreach ($pairUsageCounts as $n1 => $partners) {
            foreach ($partners as $n2 => $cnt) {
                if ($n1 < $n2 && $cnt > 0) {
                    $uniquePairsCovered++;
                }
            }
        }

        $uniqueUsedCount = count(array_filter($usageCounts, fn($cnt) => $cnt > 0));
        $poolTotal = count($pool);

        return [
            'dilution_metrics' => $dilutionMetrics,
            'gaussian_analysis' => $gaussianHistogram,
            'benchmark' => $benchmark,
            'affinity_source' => $this->lastAffinitySource,
            'affinity_draws_used' => $this->lastAffinityDrawCount,
            'affinity_is_real_co_occurrence' => $this->lastAffinitySource === self::AFFINITY_SOURCE_CO_OCCURRENCE,
            'top_pairs' => $top10Pairs,
            'parity_summary' => $paritySummary,
            'unique_pairs_covered' => $uniquePairsCovered,
            'unique_pairs_total_in_pool' => $dilutionMetrics['total_possible_pairs_in_pool'],
            'pairs_coverage_pct' => $dilutionMetrics['total_possible_pairs_in_pool'] > 0
                ? round(($uniquePairsCovered / $dilutionMetrics['total_possible_pairs_in_pool']) * 100, 1)
                : 100.0,
            'min_number_usage' => !empty($usageCounts) ? min($usageCounts) : 0,
            'max_number_usage' => !empty($usageCounts) ? max($usageCounts) : 0,
            'unique_numbers_used' => $uniqueUsedCount,
            'pool_size' => $poolTotal,
            'pool_coverage_pct' => $poolTotal > 0 ? round(($uniqueUsedCount / $poolTotal) * 100, 1) : 100.0,
            'is_full_coverage_guaranteed' => ($uniqueUsedCount === $poolTotal),
        ];
    }

    /**
     * Pomocnicza metoda do obliczania symbolu Newtona C(n, k).
     */
    private function calculateCombinationsCount(int $n, int $k): float
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
