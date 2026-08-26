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
        $this->assertLessThanOrEqual(115, $gauss['optimal_min']);
        $this->assertGreaterThanOrEqual(185, $gauss['optimal_max']);
    }

    public function testBuildPairAffinityMatrixSymmetryAndClusterBonus(): void
    {
        $pool = [5, 6, 12, 20];
        $frequencies = [5 => 10, 6 => 15, 12 => 8, 20 => 5];

        $matrix = $this->service->buildPairAffinityMatrix($pool, $frequencies);

        $this->assertSame(0, $matrix[5][5]);
        $this->assertSame($matrix[5][6], $matrix[6][5]);
        $this->assertGreaterThan($matrix[5][12], $matrix[5][6]); // 5 and 6 are direct neighbours -> +35 cluster bonus
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
}
