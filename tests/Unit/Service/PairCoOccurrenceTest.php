<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GameRegistryService;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regresje dla macierzy powiązań par (finding A1 z docs/REVIEW.md).
 *
 * Wcześniej "współwystępowanie par" liczone było jako sqrt(f(A) * f(B)),
 * czyli nie zawierało ŻADNEJ informacji o parach.
 */
class PairCoOccurrenceTest extends TestCase
{
    private StatisticalOptimizerService $service;

    protected function setUp(): void
    {
        $this->service = new StatisticalOptimizerService(new GameRegistryService(), new NullLogger());
    }

    /**
     * @return array<int, array<int>>
     */
    private function drawsWithStrongPair(int $count): array
    {
        $draws = [];
        for ($i = 0; $i < $count; $i++) {
            // 3 i 40 padają ZAWSZE razem, mimo że pojedynczo nie są najgorętsze.
            $draws[] = [3, 40, 5 + ($i % 7), 12 + ($i % 5), 22 + ($i % 6), 31 + ($i % 4)];
        }

        return $draws;
    }

    public function testRealCoOccurrenceBeatsIndividualFrequency(): void
    {
        $pool = range(1, 49);
        $draws = $this->drawsWithStrongPair(40);

        // 7 jest najgorętszą liczbą pojedynczo, ale nigdy nie chodzi w parze z 8.
        $frequencies = array_fill_keys($pool, 1);
        $frequencies[7] = 999;
        $frequencies[8] = 999;

        $matrix = $this->service->buildPairAffinityMatrix($pool, $frequencies, $draws);

        self::assertSame(
            StatisticalOptimizerService::AFFINITY_SOURCE_CO_OCCURRENCE,
            $this->service->getLastAffinitySource()
        );
        self::assertSame(40, $this->service->getLastAffinityDrawCount());

        // Para faktycznie współwystępująca musi bić parę "dwóch gorących liczb".
        self::assertGreaterThan(
            $matrix[7][8],
            $matrix[3][40],
            'Para o realnym współwystępowaniu powinna mieć wyższe affinity niż dwie gorące, ale niepowiązane liczby'
        );
    }

    public function testMatrixIsSymmetric(): void
    {
        $pool = range(1, 49);
        $matrix = $this->service->buildPairAffinityMatrix($pool, array_fill_keys($pool, 5), $this->drawsWithStrongPair(30));

        foreach ([[3, 40], [5, 12], [7, 31]] as [$a, $b]) {
            self::assertSame($matrix[$a][$b], $matrix[$b][$a]);
        }
    }

    public function testFallsBackToFrequencyHeuristicWhenHistoryIsTooShort(): void
    {
        $pool = range(1, 49);
        $matrix = $this->service->buildPairAffinityMatrix($pool, array_fill_keys($pool, 5), $this->drawsWithStrongPair(3));

        self::assertSame(
            StatisticalOptimizerService::AFFINITY_SOURCE_FREQUENCY_HEURISTIC,
            $this->service->getLastAffinitySource()
        );
        self::assertSame(0, $this->service->getLastAffinityDrawCount());
        self::assertNotEmpty($matrix);
    }

    public function testFallsBackWhenNoDrawsSupplied(): void
    {
        $pool = range(1, 42);
        $this->service->buildPairAffinityMatrix($pool, array_fill_keys($pool, 5));

        self::assertSame(
            StatisticalOptimizerService::AFFINITY_SOURCE_FREQUENCY_HEURISTIC,
            $this->service->getLastAffinitySource()
        );
    }

    /**
     * Finding A5: sąsiad (+1/-1) musi być premiowany MOCNIEJ niż liczba
     * oddalona o 3-12, bo cała strategia "syndykatu klastrowego" opiera się
     * na sąsiadach. Wcześniej było odwrotnie (+8 vs +12).
     */
    public function testNeighbourBonusOutranksSpreadBonus(): void
    {
        $pool = range(10, 24);
        $flat = array_fill_keys($pool, 10);

        $matrix = $this->service->buildPairAffinityMatrix($pool, $flat);

        self::assertGreaterThan(
            $matrix[15][18],
            $matrix[15][16],
            'Bezpośredni sąsiad powinien mieć wyższe affinity niż liczba oddalona o 3'
        );
    }

    public function testRecentCoOccurrenceOutranksStaleCoOccurrence(): void
    {
        $pool = range(1, 30);
        $draws = [];

        // Pierwsze 5 (najnowszych) losowań zawiera parę (4, 14)
        for ($i = 0; $i < 5; $i++) {
            $draws[] = [4, 14, 1 + $i, 10 + $i, 20 + $i];
        }
        // Środkowe 15 losowań zawiera różne tło
        for ($i = 0; $i < 15; $i++) {
            $draws[] = [1 + ($i % 3), 8 + ($i % 4), 16 + ($i % 4), 22 + ($i % 4), 27 + ($i % 3)];
        }
        // Ostatnie 5 (najstarszych) losowań zawiera parę (5, 15) dokładnie tyle samo razy (5x)
        for ($i = 0; $i < 5; $i++) {
            $draws[] = [5, 15, 2 + $i, 11 + $i, 21 + $i];
        }

        $matrix = $this->service->buildPairAffinityMatrix($pool, array_fill_keys($pool, 5), $draws);

        self::assertGreaterThan(
            $matrix[5][15],
            $matrix[4][14],
            'Para z najnowszych losowań (4,14) musi mieć wyższe affinity (EMA decay) niż para (5,15) sprzed 20 sesji'
        );
    }

    public function testTripletAffinityMapCapturesThreeCliquesAndScoresBet(): void
    {
        $pool = range(1, 30);
        $draws = [];
        for ($i = 0; $i < 25; $i++) {
            // Trójka (7, 17, 27) pada razem w 10 losowaniach
            if ($i < 10) {
                $draws[] = [7, 17, 27, 1 + ($i % 5), 11 + ($i % 5), 21 + ($i % 5)];
            } else {
                $draws[] = [2 + ($i % 5), 8 + ($i % 5), 14 + ($i % 5), 19 + ($i % 5), 24 + ($i % 5), 29];
            }
        }

        $tripletMap = $this->service->buildTripletAffinityMap($pool, $draws);

        self::assertNotEmpty($tripletMap);
        self::assertArrayHasKey('7-17-27', $tripletMap);
        self::assertSame(60.0, $tripletMap['7-17-27']);

        $scoreWithClique = $this->service->calculateTripletSynergyScore([3, 7, 12, 17, 22, 27], $tripletMap);
        $scoreWithoutClique = $this->service->calculateTripletSynergyScore([1, 6, 13, 18, 23, 30], $tripletMap);

        self::assertGreaterThan($scoreWithoutClique, $scoreWithClique);
    }
}
