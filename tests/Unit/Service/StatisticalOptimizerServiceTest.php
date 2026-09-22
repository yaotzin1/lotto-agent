<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GameRegistryService;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StatisticalOptimizerServiceTest extends TestCase
{
    private StatisticalOptimizerService $service;

    protected function setUp(): void
    {
        $this->service = new StatisticalOptimizerService(
            new GameRegistryService(),
            new NullLogger()
        );
    }

    public function testCalculateDilutionMetricsFor49Numbers100Bets(): void
    {
        $pool = range(1, 49);
        $metrics = $this->service->calculateDilutionMetrics($pool, 6, 100, 49);

        $this->assertSame(49, $metrics['pool_size']);
        $this->assertSame(6, $metrics['pick']);
        $this->assertSame(100, $metrics['num_bets']);
        $this->assertSame(13983816.0, $metrics['pool_combinations_total']);
        $this->assertSame(13983816.0, $metrics['full_lottery_combinations']);
        $this->assertEquals(12.24, $metrics['avg_repeats_per_number']);
        $this->assertStringContainsString('1 :', $metrics['dilution_ratio_str']);
        $this->assertSame(1176, $metrics['total_possible_pairs_in_pool']); // 49 choose 2 = 1176
        $this->assertSame(1500, $metrics['total_pairs_capacity_in_bets']); // 100 * 15 = 1500
    }

    public function testCalculateDilutionMetricsFor30Numbers25Bets(): void
    {
        $pool = range(1, 30);
        $metrics = $this->service->calculateDilutionMetrics($pool, 6, 25, 49);

        $this->assertSame(30, $metrics['pool_size']);
        $this->assertSame(593775.0, $metrics['pool_combinations_total']); // 30 choose 6 = 593 775
        $this->assertSame(13983816.0, $metrics['full_lottery_combinations']);
        $this->assertEquals(5.0, $metrics['avg_repeats_per_number']);
    }

    public function testCalculateGaussianParameters(): void
    {
        $gauss = $this->service->calculateGaussianParameters(49, 6);

        $this->assertSame(150, $gauss['expected_sum']);
        $this->assertGreaterThan(30.0, $gauss['std_dev']);
        $this->assertLessThan(40.0, $gauss['std_dev']);
        // Dokładne wartości wynikające ze wzoru Var = k(M+1)(M-k)/12.
        // Wcześniejsze luźne granice (<=115 / >=185) przepuszczały rozjazd
        // między kodem a dokumentacją (finding C1).
        $this->assertEqualsWithDelta(32.79, $gauss['std_dev'], 0.01);
        $this->assertSame(106, $gauss['optimal_min']);
        $this->assertSame(194, $gauss['optimal_max']);
        $this->assertSame('106 - 194', $gauss['optimal_range_str']);
    }

    public function testBuildPairAffinityMatrixSymmetryAndClusterBonus(): void
    {
        $pool = [5, 6, 12, 20];
        $frequencies = [5 => 10, 6 => 15, 12 => 8, 20 => 5];

        $matrix = $this->service->buildPairAffinityMatrix($pool, $frequencies);

        $this->assertSame(0, $matrix[5][5]);
        $this->assertSame($matrix[5][6], $matrix[6][5]);

        // Premia sąsiedztwa (+14) musi przebijać premię za rozstaw 3-12 (+8).
        // Wcześniejszy komentarz mówił o "+35 cluster bonus", którego nigdy nie
        // było w kodzie, a test przechodził tylko dlatego, że f(6) > f(12).
        // Teraz sprawdzamy niezmiennik przy RÓWNYCH częstotliwościach.
        $flatPool = [14, 15, 16, 18];
        $flatFreq = array_fill_keys($flatPool, 10);
        $flatMatrix = $this->service->buildPairAffinityMatrix($flatPool, $flatFreq);
        $this->assertGreaterThan(
            $flatMatrix[15][18],
            $flatMatrix[15][16],
            'Bezpośredni sąsiad musi mieć wyższe affinity niż liczba oddalona o 3'
        );
    }

    public function testOptimizeBetsForDilutionGeneratesUniqueValidBets(): void
    {
        $pool = range(1, 49);
        $frequencies = array_fill_keys($pool, 10);
        // Nadaj niektórym liczbom wyższą częstotliwość
        $frequencies[7] = 25;
        $frequencies[13] = 24;
        $frequencies[24] = 22;

        $result = $this->service->optimizeBetsForDilution(
            $pool,
            6,
            100,
            $frequencies,
            49
        );

        $bets = $result['bets'];
        $report = $result['report'];

        $this->assertCount(100, $bets);

        $seenBets = [];
        $numberUsage = array_fill_keys($pool, 0);

        foreach ($bets as $bet) {
            $this->assertCount(6, $bet);
            $this->assertSame($bet, array_values(array_unique($bet)), "Zakład nie powinien zawierać powtórzonych liczb");
            
            // Weryfikacja zakresu
            foreach ($bet as $n) {
                $this->assertGreaterThanOrEqual(1, $n);
                $this->assertLessThanOrEqual(49, $n);
                $numberUsage[$n]++;
            }

            $betKey = implode('-', $bet);
            $this->assertArrayNotHasKey($betKey, $seenBets, "Nie powinno być duplikatów zakładów");
            $seenBets[$betKey] = true;
        }

        // Każda liczba z 49 powinna być użyta przy 100 zakładach (100 * 6 = 600 slotów na 49 liczb -> min usage >= 1)
        foreach ($numberUsage as $num => $count) {
            $this->assertGreaterThan(0, $count, "Liczba $num powinna być wykorzystana przynajmniej raz");
        }

        // Weryfikacja struktury raportu
        $this->assertArrayHasKey('dilution_metrics', $report);
        $this->assertArrayHasKey('gaussian_analysis', $report);
        $this->assertArrayHasKey('benchmark', $report);
        $this->assertArrayHasKey('top_pairs', $report);
        $this->assertArrayHasKey('parity_summary', $report);
        $this->assertGreaterThan(0, $report['unique_pairs_covered']);
    }

    public function testBenchmarkAgainstRandom(): void
    {
        $pool = range(1, 30);
        $frequencies = array_fill_keys($pool, 8);
        $gaussParams = $this->service->calculateGaussianParameters(49, 6);
        $pairMatrix = $this->service->buildPairAffinityMatrix($pool, $frequencies);

        $optBets = [
            [2, 5, 6, 12, 18, 24],
            [3, 7, 8, 15, 21, 29],
            [1, 9, 10, 16, 22, 28],
        ];

        $bench = $this->service->benchmarkAgainstRandom($pool, 6, $optBets, $frequencies, 49, 10);

        $this->assertArrayHasKey('optimized_avg_synergy_score', $bench);
        $this->assertArrayHasKey('random_baseline_avg_score', $bench);
        $this->assertArrayHasKey('synergy_advantage_percent', $bench);
        $this->assertArrayHasKey('optimized_gaussian_adherence_pct', $bench);
        $this->assertArrayHasKey('random_gaussian_adherence_pct', $bench);
    }

    public function testFullCoverageForMiniLotto15BetsUsesAll42Numbers(): void
    {
        $pool = range(1, 42);
        $frequencies = [];
        foreach ($pool as $num) {
            $frequencies[$num] = rand(2, 14);
        }

        $result = $this->service->optimizeBetsWithFullCoverage(
            $pool,
            5,
            15,
            $frequencies,
            42
        );

        $bets = $result['bets'];
        $report = $result['report'];

        $this->assertCount(15, $bets);
        $this->assertSame(42, $report['unique_numbers_used']);
        $this->assertTrue($report['is_full_coverage_guaranteed']);
        $this->assertEquals(100.0, $report['pool_coverage_pct']);

        $usageCounts = array_fill_keys($pool, 0);
        foreach ($bets as $bet) {
            $this->assertCount(5, $bet);
            foreach ($bet as $n) {
                $usageCounts[$n]++;
            }
        }

        foreach ($usageCounts as $num => $count) {
            $this->assertGreaterThan(0, $count, "Liczba $num powinna wystąpić przynajmniej 1 raz w 15 zakładach");
        }
    }

    public function testRankedOrderDescBySynergyScore(): void
    {
        $pool = range(1, 42);
        $frequencies = array_fill_keys($pool, 5);
        $frequencies[10] = 20;
        $frequencies[25] = 18;

        $result = $this->service->optimizeBetsWithFullCoverage(
            $pool,
            5,
            15,
            $frequencies,
            42
        );

        $rankedBets = $result['report']['ranked_bets'] ?? [];
        $this->assertCount(15, $rankedBets);

        for ($i = 0; $i < count($rankedBets) - 1; $i++) {
            $score1 = $rankedBets[$i]['fitness']['total_score'];
            $score2 = $rankedBets[$i + 1]['fitness']['total_score'];
            $this->assertGreaterThanOrEqual($score2, $score1, "Zakłady powinny być posortowane malejąco według Fitness Score");
        }
    }

    public function testFullCoverageForLotto49Numbers25Bets(): void
    {
        $pool = range(1, 49);
        $frequencies = array_fill_keys($pool, 8);
        $frequencies[7] = 25;
        $frequencies[13] = 22;

        $result = $this->service->optimizeBetsWithFullCoverage(
            $pool,
            6,
            25,
            $frequencies,
            49
        );

        $this->assertCount(25, $result['bets']);
        $this->assertSame(49, $result['report']['unique_numbers_used']);
        $this->assertTrue($result['report']['is_full_coverage_guaranteed']);
        $this->assertEquals(100.0, $result['report']['pool_coverage_pct']);
    }

    public function testCalculateBetFitnessWithNeighboursAllowsTwoPairsWithoutPenalty(): void
    {
        $bet = [14, 15, 22, 23, 31, 41]; // 2 pary sąsiadów: 14-15 i 22-23
        $pairMatrix = [];
        $frequencies = array_fill_keys(range(1, 49), 10);
        $gaussParams = $this->service->calculateGaussianParameters(49, 6);

        // Bez withNeighbours (domyślnie false): 2 pary to kara -100
        $fitDefault = $this->service->calculateBetFitness($bet, $pairMatrix, $frequencies, $gaussParams, 49, false);

        // Z withNeighbours = true: 2 pary są dozwolone bez kary
        $fitWithNeighbours = $this->service->calculateBetFitness($bet, $pairMatrix, $frequencies, $gaussParams, 49, true);

        $this->assertEquals($fitDefault['total_score'] + 100, $fitWithNeighbours['total_score']);

        // Ciąg 3 liczb (np. 14, 15, 16) nadal otrzymuje twardą karę nawet z withNeighbours = true
        $bet3 = [14, 15, 16, 22, 31, 41];
        $fit3 = $this->service->calculateBetFitness($bet3, $pairMatrix, $frequencies, $gaussParams, 49, true);
        $this->assertLessThan($fitWithNeighbours['total_score'] - 150, $fit3['total_score']);
    }

    public function testOptimizeTieredNeighbourBetsDecomposesPoolAndGuaranteesZeroDrop(): void
    {
        $pool = range(1, 49);
        $frequencies = array_fill_keys($pool, 10);
        $latestDraw = [2, 3, 19, 23, 42, 49];

        $result = $this->service->optimizeTieredNeighbourBets(
            $pool,
            6,
            25,
            $frequencies,
            49,
            $latestDraw
        );

        $this->assertCount(25, $result['bets']);

        // Weryfikacja dekompozycji na warstwy
        $tiers = $result['tiers'];
        $t1 = $tiers['tier1'];
        $t2 = $tiers['tier2'];
        $t3 = $tiers['tier3'];
        $anchors = $tiers['anchors'];
        $nbrs = $tiers['neighbours'];

        $this->assertEquals([2, 3, 19, 23, 42, 49], $anchors);
        // Sprawdź czy sąsiedzi ±1 są poprawni (1, 4, 18, 20, 22, 24, 41, 43, 48)
        $this->assertContains(1, $nbrs);
        $this->assertContains(4, $nbrs);
        $this->assertContains(18, $nbrs);
        $this->assertContains(20, $nbrs);
        $this->assertContains(22, $nbrs);
        $this->assertContains(24, $nbrs);
        $this->assertContains(41, $nbrs);
        $this->assertContains(43, $nbrs);
        $this->assertContains(48, $nbrs);

        // Rozłączność warstw i pełne pokrycie 49 liczb
        $this->assertEmpty(array_intersect($t1, $t2));
        $this->assertEmpty(array_intersect($t1, $t3));
        $this->assertEmpty(array_intersect($t2, $t3));
        $this->assertSame(49, count($t1) + count($t2) + count($t3));

        // Gwarancja Zero-Drop (każda z 49 liczb w co najmniej jednym zakładzie)
        $this->assertSame(49, $result['report']['unique_numbers_used']);
        $this->assertTrue($result['report']['is_full_coverage_guaranteed']);
        $this->assertEquals(100.0, $result['report']['pool_coverage_pct']);

        // Weryfikacja ścisłego sortowania od najsilniejszego do najsłabszego
        $ranked = $result['report']['ranked_bets'];
        $this->assertCount(25, $ranked);
        for ($i = 0; $i < count($ranked) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $ranked[$i + 1]['fitness']['total_score'],
                $ranked[$i]['fitness']['total_score'],
                "Zakład #$i musi mieć wyższy lub równy score niż zakład #" . ($i + 1)
            );
        }

        // Najsilniejszy zakład ma wyższy lub równy udział Tier 1 niż najsłabszy zakład (domykający)
        $topBetFit = $ranked[0]['fitness'];
        $bottomBetFit = $ranked[count($ranked) - 1]['fitness'];
        $this->assertGreaterThanOrEqual(2, $topBetFit['tier1_count']);
        $this->assertTrue(
            $topBetFit['tier1_count'] >= $bottomBetFit['tier1_count'],
            sprintf('Top bet Tier 1 (%d) musi być >= Bottom bet Tier 1 (%d)', $topBetFit['tier1_count'], $bottomBetFit['tier1_count'])
        );
    }

    public function testOptimizeTieredNeighbourBetsSmallBudgetFocusesOnTier1(): void
    {
        $pool = range(1, 49);
        $frequencies = array_fill_keys($pool, 10);
        $latestDraw = [2, 3, 19, 23, 42, 49];

        // Tylko 5 zakładów (5 * 6 = 30 < 49 liczb)
        $result = $this->service->optimizeTieredNeighbourBets(
            $pool,
            6,
            5,
            $frequencies,
            49,
            $latestDraw
        );

        $this->assertCount(5, $result['bets']);
        $ranked = $result['report']['ranked_bets'];

        // Wszystkie zakłady w małym budżecie powinny być skupione na Tier 1 i Tier 2
        foreach ($ranked as $item) {
            $this->assertGreaterThanOrEqual(1, $item['fitness']['tier1_count']);
            $this->assertNotEmpty($item['fitness']['tier_summary']);
        }
    }
}
