<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DrawArchiveService;
use App\Service\DrawHistoryProvider;
use App\Service\GameRegistryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DrawArchiveServiceTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lotto-archive-' . uniqid('', true);
        mkdir($this->dataDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dataDir);
    }

    public function testProjectsApiCacheOntoChronologyAndAppendsToLocalFile(): void
    {
        $this->writeArchive('Lotto', [
            ['date' => '2026-08-29', 'numbers' => [1, 2, 3, 4, 5, 6]],
        ]);

        $service = $this->service('Lotto', [
            '2026-09-05' => [['main' => [11, 12, 13, 14, 15, 16], 'special' => [], 'id' => 7002]],
            '2026-09-01' => [['main' => [7, 8, 9, 10, 11, 12], 'special' => [], 'id' => 7001]],
        ]);

        $chronology = $service->getChronology('Lotto');

        $this->assertCount(3, $chronology);
        $this->assertSame(['2026-08-29', '2026-09-01', '2026-09-05'], array_column($chronology, 'date'));

        // Nowe losowania z API muszą wylądować w pliku, żeby kolejny przebieg
        // startował z kompletu bez ponownego odpytywania API.
        $onDisk = json_decode((string) file_get_contents($this->dataDir . '/lotto_draws.json'), true);
        $this->assertCount(3, $onDisk);
        $this->assertSame([11, 12, 13, 14, 15, 16], $onDisk[2]['numbers']);
    }

    public function testDoesNotDuplicateDrawsAlreadyPresentInArchive(): void
    {
        $this->writeArchive('Lotto', [
            ['date' => '2026-09-01', 'numbers' => [3, 16, 18, 28, 40, 44]],
        ]);

        $service = $this->service('Lotto', [
            '2026-09-01' => [['main' => [3, 16, 18, 28, 40, 44], 'special' => [], 'id' => 7001]],
        ]);

        $this->assertCount(1, $service->getChronology('Lotto'));
    }

    public function testOrdersSameDayDrawsByDrawSystemId(): void
    {
        // Multi Multi ma kilkanaście losowań dziennie, a API zwraca je malejąco.
        $service = $this->service('MultiMulti', [
            '2026-09-05' => [
                ['main' => [21, 22, 23], 'special' => [], 'id' => 300],
                ['main' => [11, 12, 13], 'special' => [], 'id' => 100],
                ['main' => [31, 32, 33], 'special' => [], 'id' => 200],
            ],
        ]);

        $numbers = array_column($service->getChronology('MultiMulti'), 'numbers');

        $this->assertSame([[11, 12, 13], [31, 32, 33], [21, 22, 23]], $numbers);
    }

    public function testDropsNumbersOutsideGameRange(): void
    {
        $service = $this->service('EuroJackpot', [
            '2026-09-04' => [['main' => [5, 17, 33, 48, 50, 77], 'special' => [3, 9], 'id' => 42]],
        ]);

        $chronology = $service->getChronology('EuroJackpot');

        $this->assertSame([5, 17, 33, 48, 50], $chronology[0]['numbers']);
    }

    public function testFreshnessCountsMissingDrawDays(): void
    {
        $lotto = new GameRegistryService();
        $this->assertSame([2, 4, 6], $lotto->getGameConfig('Lotto')['draw_days']);

        // Ostatnie losowanie w archiwum: 21 dni temu. Lotto losuje wt/czw/sob,
        // więc od tego czasu wypadło 9 dni losowań (nie licząc dzisiejszego).
        $last = (new \DateTimeImmutable('yesterday'))->modify('-21 days');
        while (!in_array((int) $last->format('N'), [2, 4, 6], true)) {
            $last = $last->modify('-1 day');
        }

        $this->writeArchive('Lotto', [
            ['date' => $last->format('Y-m-d'), 'numbers' => [1, 2, 3, 4, 5, 6]],
        ]);

        $service = $this->service('Lotto', []);
        $freshness = $service->freshness('Lotto');

        $this->assertTrue($freshness['stale']);
        $this->assertSame($last->format('Y-m-d'), $freshness['last_date']);
        $this->assertGreaterThanOrEqual(8, $freshness['missing']);
        $this->assertSame(1, $freshness['total']);
    }

    public function testArchiveUpToDateIsNotReportedAsStale(): void
    {
        $expected = new \DateTimeImmutable('yesterday');
        while (!in_array((int) $expected->format('N'), [2, 4, 6], true)) {
            $expected = $expected->modify('-1 day');
        }

        $this->writeArchive('Lotto', [
            ['date' => $expected->format('Y-m-d'), 'numbers' => [1, 2, 3, 4, 5, 6]],
        ]);

        $freshness = $this->service('Lotto', [])->freshness('Lotto');

        $this->assertFalse($freshness['stale']);
        $this->assertSame(0, $freshness['missing']);
    }

    public function testRefreshSurvivesApiFailureAndReportsWarning(): void
    {
        $this->writeArchive('Lotto', [
            ['date' => '2026-09-01', 'numbers' => [1, 2, 3, 4, 5, 6]],
        ]);

        $historyProvider = $this->createMock(DrawHistoryProvider::class);
        $historyProvider->method('getHistory')->willThrowException(new \RuntimeException('Brak LOTTO_API_KEY'));
        $historyProvider->method('getDatedStore')->willReturn([]);

        $service = new DrawArchiveService(
            $historyProvider,
            new GameRegistryService(),
            new NullLogger(),
            $this->dataDir
        );

        $result = $service->refresh('Lotto');

        $this->assertSame(0, $result['added']);
        $this->assertNotNull($result['warning']);
        $this->assertStringContainsString('Brak LOTTO_API_KEY', $result['warning']);
        $this->assertCount(1, $service->getChronology('Lotto'));
    }

    public function testRefreshReportsRateLimit(): void
    {
        $historyProvider = $this->createMock(DrawHistoryProvider::class);
        $historyProvider->method('getHistory')->willReturn([
            'draws' => [],
            'from_cache' => 4,
            'fetched' => 8,
            'rate_limited' => true,
            'missing' => 12,
        ]);
        $historyProvider->method('getDatedStore')->willReturn([]);

        $service = new DrawArchiveService(
            $historyProvider,
            new GameRegistryService(),
            new NullLogger(),
            $this->dataDir
        );

        $result = $service->refresh('Lotto');

        $this->assertTrue($result['rate_limited']);
        $this->assertSame(8, $result['fetched']);
        $this->assertStringContainsString('429', (string) $result['warning']);
    }

    public function testNonLottoGamesUseTheirOwnArchiveFile(): void
    {
        $service = $this->service('EuroJackpot', []);

        $this->assertStringEndsWith('/lotto_draws.json', $service->fileFor('Lotto'));
        $this->assertStringEndsWith('/draws/EuroJackpot.json', $service->fileFor('EuroJackpot'));
    }

    /**
     * @param array<string, array<int, array{main: list<int>, special: list<int>, id?: ?int}>> $store
     */
    private function service(string $gameType, array $store): DrawArchiveService
    {
        $historyProvider = $this->createMock(DrawHistoryProvider::class);
        $historyProvider->method('getDatedStore')->willReturnCallback(
            static fn(string $game): array => $game === $gameType ? $store : []
        );

        return new DrawArchiveService(
            $historyProvider,
            new GameRegistryService(),
            new NullLogger(),
            $this->dataDir
        );
    }

    /**
     * @param list<array{date: string, numbers: list<int>}> $draws
     */
    private function writeArchive(string $gameType, array $draws): void
    {
        $file = $gameType === 'Lotto'
            ? $this->dataDir . '/lotto_draws.json'
            : $this->dataDir . '/draws/' . $gameType . '.json';

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, json_encode($draws));
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
