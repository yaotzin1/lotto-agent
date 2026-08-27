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

    // === SILNIK REDUKCJI: STRICT BUCKET BALANCE ===
    public function generateBalancedShorthand(array $pool, int $pick, int $limit): array
    {
        $pool = array_values(array_unique($pool));

        if ($pick < 1) {
            throw new \InvalidArgumentException(
                sprintf('Liczba skreśleń musi być dodatnia, otrzymano %d.', $pick)
            );
        }

        // Wcześniej zwracana była tu pula "jak leci", co dawało kupon krótszy niż
        // wymaga gra (np. 3 liczby dla Lotto 6/49) — czyli kupon niegrywalny.
        if (count($pool) < $pick) {
            throw new \InvalidArgumentException(sprintf(
                'Pula (%d liczb) jest mniejsza niż wymagana ilość skreśleń (%d).',
                count($pool),
                $pick
            ));
        }

        if (count($pool) === $pick) {
            return [$pool];
        }

        $results = [];
        $usageCounts = array_fill_keys($pool, 0);
        $pairCounts = [];
        foreach ($pool as $n1) {
            foreach ($pool as $n2) {
                $pairCounts[$n1][$n2] = 0;
            }
        }
        $attempts = 0;

        while (count($results) < $limit) {
            $attempts++;
            if ($attempts > 20000) break;

            $bet = [];
            $available = $pool;
            shuffle($available);

            while (count($bet) < $pick) {
                $bestCandidate = null;
                $lowestCost = PHP_INT_MAX;

                foreach ($available as $num) {
                    if (in_array($num, $bet, true)) {
                        continue;
                    }

                    $uCost = $usageCounts[$num] * 10;
                    $pCost = 0;
                    foreach ($bet as $selectedNum) {
                        $pCost += $pairCounts[$num][$selectedNum] * 20;
                    }

                    $totalCost = $uCost + $pCost;
                    if ($totalCost < $lowestCost) {
                        $lowestCost = $totalCost;
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

            $isDup = false;
            foreach ($results as $r) {
                if ($r === $bet) {
                    $isDup = true;
                    break;
                }
            }

            if (!$isDup) {
                $results[] = $bet;
                foreach ($bet as $i => $num1) {
                    $usageCounts[$num1]++;
                    foreach ($bet as $j => $num2) {
                        if ($i !== $j) {
                            $pairCounts[$num1][$num2]++;
                        }
                    }
                }
            } else {
                if ($attempts % 10 === 0) {
                    $backupPool = $pool;
                    shuffle($backupPool);
                    $randomBet = array_slice($backupPool, 0, $pick);
                    sort($randomBet);

                    $isRandDup = false;
                    foreach ($results as $r) {
                        if ($r === $randomBet) {
                            $isRandDup = true;
                            break;
                        }
                    }

                    if (!$isRandDup) {
                        $results[] = $randomBet;
                        foreach ($randomBet as $i => $num1) {
                            $usageCounts[$num1]++;
                            foreach ($randomBet as $j => $num2) {
                                if ($i !== $j) {
                                    $pairCounts[$num1][$num2]++;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $results;
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