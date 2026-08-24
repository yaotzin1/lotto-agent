<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BetGeneratorService;
use PHPUnit\Framework\TestCase;

class BetGeneratorServiceTest extends TestCase
{
    private BetGeneratorService $service;

    protected function setUp(): void
    {
        $this->service = new BetGeneratorService();
    }

    public function testGenerateCombinations(): void
    {
        $elements = [1, 2, 3, 4];
        $combinations = iterator_to_array($this->service->generateCombinations($elements, 2));

        $this->assertCount(6, $combinations); // 4 choose 2 = 6
        $this->assertSame([1, 2], $combinations[0]);
        $this->assertSame([3, 4], $combinations[5]);
    }

    public function testGenerateOverlappingBlocks(): void
    {
        $pool = range(1, 20);
        $blocks = $this->service->generateOverlappingBlocks($pool, 4, 10);

        $this->assertCount(4, $blocks);
        foreach ($blocks as $block) {
            $this->assertCount(10, $block);
        }
    }

    public function testGenerateSmartUniqueBlocks(): void
    {
        $pool = range(1, 15);
        $blocks = $this->service->generateSmartUniqueBlocks($pool, 6, 3);

        $this->assertCount(3, $blocks);
        foreach ($blocks as $block) {
            $this->assertCount(6, $block);
        }
    }

    public function testGenerateBalancedShorthand(): void
    {
        $pool = range(1, 15);
        $bets = $this->service->generateBalancedShorthand($pool, 6, 5);

        $this->assertCount(5, $bets);
        foreach ($bets as $bet) {
            $this->assertCount(6, $bet);
        }
    }

    public function testCalculateCoverage(): void
    {
        $pool = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $bets = [
            [1, 2, 3, 4, 5, 6],
            [5, 6, 7, 8, 9, 10],
        ];

        $coverage = $this->service->calculateCoverage($pool, $bets, 6);

        $this->assertSame(210, $coverage['total_draws']); // 10 choose 6 = 210
        $this->assertGreaterThan(0, $coverage['guarantees'][3]);
    }

    public function testGenerateStatisticalOptimizedBets(): void
    {
        $pool = range(1, 30);
        $frequencies = array_fill_keys($pool, 5);

        $result = $this->service->generateStatisticalOptimizedBets($pool, 6, 10, $frequencies, 49);

        $this->assertArrayHasKey('bets', $result);
        $this->assertArrayHasKey('report', $result);
        $this->assertCount(10, $result['bets']);
        foreach ($result['bets'] as $bet) {
            $this->assertCount(6, $bet);
        }
    }
}
