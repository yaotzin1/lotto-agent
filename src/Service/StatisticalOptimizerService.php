<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

class StatisticalOptimizerService
{
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

    /**
     * Buduje macierz współwystępowania (affinity matrix) par liczb na podstawie częstości lub historii losowań.
     *
     * @param array<int> $pool
     * @param array<int, int> $frequencies Mapa: [numer => liczba wystąpień]
     * @return array<int, array<int, int>>
     */
    public function buildPairAffinityMatrix(array $pool, array $frequencies): array
    {
        $matrix = [];
        $count = count($pool);

        for ($i = 0; $i < $count; $i++) {
            $n1 = $pool[$i];
            for ($j = 0; $j < $count; $j++) {
                $n2 = $pool[$j];
                if ($n1 === $n2) {
                    $matrix[$n1][$n2] = 0;
                    continue;
                }

                $f1 = $frequencies[$n1] ?? 1;
                $f2 = $frequencies[$n2] ?? 1;

                // Estymacja siły powiązania: średnia geometryczna frekwencji bazowych z historii
                $baseScore = (int)round(sqrt($f1 * $f2) * 8);

                // Umiarkowany bonus dla pojedynczej pary sąsiadów (+1/-1)
                $diff = abs($n1 - $n2);
                if ($diff === 1) {
                    $baseScore += 8;
                } elseif ($diff >= 3 && $diff <= 12) {
                    // Bonus za optymalne rozproszenie harmoniczne (odstęp 3-12 liczb)
                    $baseScore += 12;
                }

                $matrix[$n1][$n2] = $baseScore;
            }
        }

        return $matrix;
    }

    /**
     * Generuje zakłady zoptymalizowane statystycznie dla dużej puli przy silnym rozwodnieniu.
     *
     * @param array<int> $pool Pula wejściowa (np. 30 lub 49 liczb)
     * @param int $pick Liczba skreśleń (np. 6)
     * @param int $numBets Docelowa liczba zakładów (np. 100 lub 25)
     * @param array<int, int> $frequencies Częstotliwości wystąpień liczb
     * @param int $maxNumber Maksymalny numer w grze (np. 49)
     * @param array $options Opcje wag i filtrów
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
        $pool = array_values(array_unique(array_map('intval', $pool)));
        sort($pool);
        $poolSize = count($pool);

        if ($poolSize < $pick) {
            throw new \InvalidArgumentException("Pula ($poolSize) jest mniejsza niż wymagana ilość do skreślenia ($pick).");
        }

        $pairMatrix = $this->buildPairAffinityMatrix($pool, $frequencies);
        $gaussParams = $this->calculateGaussianParameters($maxNumber, $pick);

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
                        if ($decadeCount >= 2) {
                            continue; // Nie więcej niż 2 liczby w tej samej dekadzie!
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
                $backup = $pool;
                shuffle($backup);
                $bestCandidateBet = array_slice($backup, 0, $pick);
                sort($bestCandidateBet);
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

        // Przygotowanie szczegółowego raportu do Okna Statystycznego
        $report = $this->generateStatisticalReport(
            $pool,
            $generatedBets,
            $pairMatrix,
            $frequencies,
            $gaussParams,
            $maxNumber,
            $usageCounts,
            $pairUsageCounts
        );

        return [
            'bets' => $generatedBets,
            'report' => $report,
        ];
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

        // 4. Parzyste / Nieparzyste
        $odds = count(array_filter($bet, fn($n) => $n % 2 !== 0));
        $evens = $pick - $odds;
        $isParityBalanced = ($odds >= 2 && $evens >= 1 && $odds <= 4);
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
        if ($maxInSingleDecade >= 3) {
            $decadeBonus = -150; // kara za stłoczenie 3+ liczb w jednej dekadzie
        } elseif ($decadeSpread >= ($pick >= 6 ? 4 : 3)) {
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
        int $simulations = 50
    ): array {
        $gaussParams = $this->calculateGaussianParameters($maxNumber, $pick);
        $pairMatrix = $this->buildPairAffinityMatrix($pool, $frequencies);

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
        array $pairUsageCounts
    ): array {
        $pick = count($bets[0] ?? []);
        $numBets = count($bets);

        $dilutionMetrics = $this->calculateDilutionMetrics($pool, $pick, $numBets, $maxNumber);
        $gaussianHistogram = $this->generateAsciiGaussianHistogram($bets, $gaussParams, $maxNumber);
        $benchmark = $this->benchmarkAgainstRandom($pool, $pick, $bets, $frequencies, $maxNumber);

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

        return [
            'dilution_metrics' => $dilutionMetrics,
            'gaussian_analysis' => $gaussianHistogram,
            'benchmark' => $benchmark,
            'top_pairs' => $top10Pairs,
            'parity_summary' => $paritySummary,
            'unique_pairs_covered' => $uniquePairsCovered,
            'unique_pairs_total_in_pool' => $dilutionMetrics['total_possible_pairs_in_pool'],
            'pairs_coverage_pct' => $dilutionMetrics['total_possible_pairs_in_pool'] > 0
                ? round(($uniquePairsCovered / $dilutionMetrics['total_possible_pairs_in_pool']) * 100, 1)
                : 100.0,
            'min_number_usage' => !empty($usageCounts) ? min($usageCounts) : 0,
            'max_number_usage' => !empty($usageCounts) ? max($usageCounts) : 0,
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
