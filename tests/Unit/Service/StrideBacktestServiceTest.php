<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DrawArchiveService;
use App\Service\DrawHistoryProvider;
use App\Service\GameRegistryService;
use App\Service\StrideBacktestService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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

    /**
     * Regresja: zawijanie sąsiadów było wpisane na sztywno na 49, więc dla gier
     * o innym zakresie (EuroJackpot 1-50, Multi Multi 1-80) sąsiad liczby
     * granicznej wypadał poza planszę albo w środek zakresu.
     */
    public function testNeighbourWrapAroundFollowsGameRangeNotHardcoded49(): void
    {
        $history = [
            0 => ['date' => '2020-01-01', 'numbers' => [1, 50]],
            1 => ['date' => '2020-01-03', 'numbers' => [7, 8]],
        ];

        $pool = $this->service->buildStrideNeighbourPool($history, 1, 1, 4, 50);

        // Sąsiedzi 1 to 50 i 2; sąsiedzi 50 to 49 i 1.
        $this->assertContains(2, $pool);
        $this->assertContains(49, $pool);
        $this->assertNotContains(51, $pool);
    }

    public function testPoolNeverExceedsGameRange(): void
    {
        $history = [
            0 => ['date' => '2020-01-01', 'numbers' => [1, 2, 3]],
            1 => ['date' => '2020-01-03', 'numbers' => [4, 5, 6]],
        ];

        foreach ([[42, 20], [50, 30], [80, 40]] as [$maxNumber, $poolSize]) {
            $pool = $this->service->buildStrideNeighbourPool($history, 1, 1, $poolSize, $maxNumber);

            $this->assertCount($poolSize, $pool);
            $this->assertLessThanOrEqual($maxNumber, max($pool));
            $this->assertGreaterThanOrEqual(1, min($pool));
        }
    }

    public function testRandomPoolCoversWholeGameRange(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            foreach ($this->service->buildRandomPool(10, 80) as $n) {
                $seen[$n] = true;
                $this->assertLessThanOrEqual(80, $n);
            }
        }

        // Losowa linia bazowa dla Multi Multi musi sięgać powyżej 49.
        $this->assertNotEmpty(array_filter(array_keys($seen), static fn(int $n): bool => $n > 49));
    }

    /**
     * Automatyczna liczba kotwic dzieliła pulę przez wpisaną na sztywno 6
     * (liczba skreśleń w Lotto) niezależnie od wybranej gry.
     */
    public function testAutoAnchorCountUsesGamePick(): void
    {
        $this->assertSame(3, $this->service->autoAnchorCount(18, 'Lotto'));
        $this->assertSame(4, $this->service->autoAnchorCount(18, 'EuroJackpot'));
        $this->assertSame(2, $this->service->autoAnchorCount(18, 'MultiMulti'));
    }

    public function testGetStridePoolInfoUsesSelectedGameArchive(): void
    {
        $dataDir = sys_get_temp_dir() . '/lotto-stride-' . uniqid('', true);
        mkdir($dataDir . '/draws', 0775, true);

        $draws = [];
        for ($i = 0; $i < 20; $i++) {
            $draws[] = [
                'date' => sprintf('2024-01-%02d', $i + 1),
                'numbers' => [1 + $i, 6 + $i, 11 + $i, 16 + $i, 21 + $i],
            ];
        }
        file_put_contents($dataDir . '/draws/EuroJackpot.json', json_encode($draws));

        try {
            $service = new StrideBacktestService($this->archiveFor($dataDir), new GameRegistryService());

            $info = $service->getStridePoolInfo(
                stride: 2,
                poolSize: 10,
                strategy: 'anchor_neighbours',
                gameType: 'EuroJackpot'
            );

            $this->assertSame('EuroJackpot', $info['game']);
            $this->assertSame(50, $info['numbers_from']);
            $this->assertSame(20, $info['total_draws']);
            $this->assertCount(10, $info['pool']);
            $this->assertSame('2024-01-18', $info['anchor_draws'][0]['date']);

            // Kotwicą jest losowanie EuroJackpota, a nie ostatni wiersz historii Lotto.
            foreach ($draws[17]['numbers'] as $anchor) {
                $this->assertContains($anchor, $info['pool']);
            }
        } finally {
            $this->removeDir($dataDir);
        }
    }

    public function testGetStridePoolInfoFailsLoudlyWhenGameHasNoArchive(): void
    {
        $dataDir = sys_get_temp_dir() . '/lotto-stride-' . uniqid('', true);
        mkdir($dataDir, 0775, true);

        try {
            $service = new StrideBacktestService($this->archiveFor($dataDir), new GameRegistryService());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/MultiMulti/');

            $service->getStridePoolInfo(stride: 5, gameType: 'MultiMulti');
        } finally {
            $this->removeDir($dataDir);
        }
    }

    private function archiveFor(string $dataDir): DrawArchiveService
    {
        $historyProvider = $this->createMock(DrawHistoryProvider::class);
        $historyProvider->method('getDatedStore')->willReturn([]);

        return new DrawArchiveService(
            $historyProvider,
            new GameRegistryService(),
            new NullLogger(),
            $dataDir
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
