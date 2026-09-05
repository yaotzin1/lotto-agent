<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\StrideBacktestService;
use PHPUnit\Framework\TestCase;

class StrideBacktestServiceTest extends TestCase
{
    private StrideBacktestService $service;

    protected function setUp(): void
    {
        $this->service = new StrideBacktestService();
    }

    public function testBuildStrideNeighbourPoolReturnsUniqueSortedNumbersOfRequestedSize(): void
    {
        $history = [
            0 => ['date' => '2020-01-01', 'numbers' => [5, 10, 15, 20, 25, 30]],
            1 => ['date' => '2020-01-03', 'numbers' => [1, 2, 3, 4, 5, 6]],
        ];

        $pool = $this->service->buildStrideNeighbourPool($history, 1, 1, 12);

        $this->assertCount(12, $pool);
        $this->assertSame($pool, array_unique($pool));
        foreach ($pool as $num) {
            $this->assertGreaterThanOrEqual(1, $num);
            $this->assertLessThanOrEqual(49, $num);
        }
        // Anchors must be included
        foreach ($history[0]['numbers'] as $anchor) {
            $this->assertContains($anchor, $pool);
        }
    }

    public function testBuildMultiAnchorStridePoolReturnsUniqueNumbers(): void
    {
        $history = [
            0 => ['date' => '2020-01-01', 'numbers' => [1, 3, 5, 7, 9, 11]],
            1 => ['date' => '2020-01-03', 'numbers' => [13, 15, 17, 19, 21, 23]],
            2 => ['date' => '2020-01-05', 'numbers' => [2, 4, 6, 8, 10, 12]],
        ];

        $pool = $this->service->buildMultiAnchorStridePool($history, 2, 1, 12);

        $this->assertCount(12, $pool);
        $this->assertSame($pool, array_unique($pool));
        // Should contain numbers from both past draws
        $this->assertContains(13, $pool);
        $this->assertContains(1, $pool);
    }

    public function testBuildRandomPool(): void
    {
        $pool = $this->service->buildRandomPool(12);

        $this->assertCount(12, $pool);
        $this->assertSame($pool, array_unique($pool));
    }

    public function testGetStridePoolInfoReturnsConfiguredAnchorCount(): void
    {
        $info = $this->service->getStridePoolInfo(
            stride: 257,
            poolSize: 15,
            strategy: 'multi_anchor',
            anchorCount: 3
        );

        $this->assertCount(15, $info['pool']);
        $this->assertCount(3, $info['anchor_draws']);
        $this->assertSame(3, $info['anchor_count']);
        $this->assertSame(257, $info['anchor_draws'][0]['stride_back']);
        $this->assertSame(514, $info['anchor_draws'][1]['stride_back']);
        $this->assertSame(771, $info['anchor_draws'][2]['stride_back']);
    }
}
