<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AgentTools\FetchNeighboursAnalysisTool;
use App\Service\AgentTools\FetchOverdueStatsTool;
use App\Service\AgentTools\FetchPairCoOccurrenceTool;
use App\Service\AgentTools\FetchRecentDrawsTool;
use App\Service\DrawHistoryProvider;
use App\Service\GameRegistryService;
use App\Service\HistoricalDataProvider;
use App\Service\LottoApiClient;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Testy regresyjne dla obsługi parametrów CLI (--sessions, --months, --with-neighbours)
 * oraz przycinania historii losowań do zadanego okna czasowego.
 */
class ParameterHandlingAndHistorySliceTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/lotto-params-' . uniqid('', true);
        mkdir($this->storageDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storageDir);
    }

    public function testEstimateSessionsForMonthsReflectsDrawSchedule(): void
    {
        $registry = new GameRegistryService();

        // MiniLotto: 7 dni w tygodniu -> round(1 * 4.345 * 7) = 30
        self::assertSame(30, $registry->estimateSessionsForMonths('MiniLotto', 1));
        // Lotto: 3 dni w tygodniu -> round(2 * 4.345 * 3) = 26
        self::assertSame(26, $registry->estimateSessionsForMonths('Lotto', 2));
        // EuroJackpot: 2 dni w tygodniu -> round(1 * 4.345 * 2) = 9
        self::assertSame(9, $registry->estimateSessionsForMonths('EuroJackpot', 1));
    }

    /**
     * Regresja główna z raportu PDF: gdy w pliku var/draw-history/MiniLotto.json
     * są 44 losowania, a użytkownik prosi o limit=5 (--sessions=5), getHistory()
     * musi zwrócić DOKŁADNIE 5 najnowszych losowań, nawet jeśli dzisiejsza data
     * w cache jest jeszcze pusta ([] - przed wieczornym losowaniem).
     */
    public function testDrawHistoryProviderSlicesCachedStoreToExactLimitEvenWhenTodayIsEmpty(): void
    {
        $store = [];
        $today = new \DateTimeImmutable('today');
        // Dzisiejszy dzień sprawdzony przed losowaniem wieczornym -> pusta tablica
        $store[$today->format('Y-m-d')] = [];

        // 44 historyczne losowania dzień po dniu wstecz
        for ($i = 1; $i <= 44; $i++) {
            $date = $today->modify("-{$i} days")->format('Y-m-d');
            $store[$date] = [
                ['main' => [1, 2, 3, 4, min(42, $i)], 'special' => [], 'id' => 5000 - $i],
            ];
        }

        file_put_contents(
            $this->storageDir . '/MiniLotto.json',
            json_encode($store, JSON_PRETTY_PRINT)
        );

        $apiClient = $this->createMock(LottoApiClient::class);
        $apiClient->expects(self::never())->method('requestDrawsForDate');

        $provider = new DrawHistoryProvider(
            $apiClient,
            new GameRegistryService(),
            new NullLogger(),
            $this->storageDir,
            0
        );

        $history = $provider->getHistory('MiniLotto', 5, 0);

        self::assertCount(5, $history['draws'], 'getHistory(MiniLotto, 5) musi zwrócić dokładnie 5 losowań, a nie 4 ani 44');
        // Najnowsze losowanie to wczorajsze ($i = 1 -> piąta liczba = 1), kolejne $i = 2..5
        self::assertSame([1, 2, 3, 4, 1], $history['draws'][0]['main']);
        self::assertSame([1, 2, 3, 4, 5], $history['draws'][4]['main']);
    }

    public function testDrawHistoryProviderCapsMultiDrawPerDayGamesToExactSessionLimit(): void
    {
        $store = [];
        $today = new \DateTimeImmutable('today');

        // MultiMulti ma 2 losowania dziennie (14:00 i 22:00)
        for ($i = 0; $i < 10; $i++) {
            $date = $today->modify("-{$i} days")->format('Y-m-d');
            $store[$date] = [
                ['main' => [1, 2, 3, 4, 5], 'special' => [1], 'id' => 1000 - ($i * 2)],
                ['main' => [6, 7, 8, 9, 10], 'special' => [2], 'id' => 999 - ($i * 2)],
            ];
        }

        file_put_contents(
            $this->storageDir . '/MultiMulti.json',
            json_encode($store, JSON_PRETTY_PRINT)
        );

        $apiClient = $this->createMock(LottoApiClient::class);
        $provider = new DrawHistoryProvider(
            $apiClient,
            new GameRegistryService(),
            new NullLogger(),
            $this->storageDir,
            0
        );

        $history = $provider->getHistory('MultiMulti', 5, 0);

        self::assertCount(5, $history['draws'], 'Dla gier z wieloma losowaniami dziennie limit=5 musi obciąć wynik do 5 sesji');
    }

    /**
     * Regresja: HistoricalDataProvider::fetch() wcześniej wywoływał
     * getHistory($gameType, max($sessions, 50)), co całkowicie ignorowało --sessions=5.
     */
    public function testHistoricalDataProviderRespectsExplicitSmallSessionsParameter(): void
    {
        $apiClient = $this->createMock(LottoApiClient::class);
        $apiClient->expects(self::once())
            ->method('getHotColdNumbers')
            ->with('MiniLotto', self::anything())
            ->willReturn([
                'sorted_by_freq_desc' => [4 => 3, 17 => 2, 23 => 2, 27 => 2, 41 => 2],
                'draws_analyzed' => 5,
            ]);

        $drawHistoryProvider = $this->createMock(DrawHistoryProvider::class);
        $drawHistoryProvider->expects(self::once())
            ->method('getHistory')
            ->with('MiniLotto', 5)
            ->willReturn([
                'draws' => [
                    ['main' => [4, 17, 23, 27, 41], 'special' => []],
                    ['main' => [2, 12, 19, 31, 37], 'special' => []],
                    ['main' => [1, 10, 20, 30, 40], 'special' => []],
                    ['main' => [5, 15, 25, 35, 42], 'special' => []],
                    ['main' => [3, 13, 23, 33, 39], 'special' => []],
                ],
                'from_cache' => 5,
                'fetched' => 0,
                'rate_limited' => false,
                'missing' => 0,
            ]);

        $provider = new HistoricalDataProvider(
            $apiClient,
            new GameRegistryService(),
            $drawHistoryProvider
        );

        $data = $provider->fetch('MiniLotto', 5, null);

        self::assertTrue($data['ok']);
        self::assertCount(5, $data['draws']);
        self::assertNull($data['warning'], 'Dla 5 losowań (>= MIN_DRAWS_FOR_CO_OCCURRENCE=5) nie powinno być ostrzeżenia o degradacji macierzy');
    }

    public function testHistoricalDataProviderUsesMonthsEstimationWhenSessionsIsNull(): void
    {
        $apiClient = $this->createMock(LottoApiClient::class);
        $apiClient->method('getHotColdNumbers')->willReturn([
            'sorted_by_freq_desc' => [1 => 5, 2 => 4, 3 => 3],
            'draws_analyzed' => 26,
        ]);

        $registry = new GameRegistryService();
        $expectedLimit = $registry->estimateSessionsForMonths('Lotto', 2);

        $drawHistoryProvider = $this->createMock(DrawHistoryProvider::class);
        $drawHistoryProvider->expects(self::once())
            ->method('getHistory')
            ->with('Lotto', $expectedLimit)
            ->willReturn([
                'draws' => array_fill(0, $expectedLimit, ['main' => [1, 2, 3, 4, 5, 6], 'special' => []]),
                'from_cache' => $expectedLimit,
                'fetched' => 0,
                'rate_limited' => false,
                'missing' => 0,
            ]);

        $provider = new HistoricalDataProvider($apiClient, $registry, $drawHistoryProvider);
        $data = $provider->fetch('Lotto', null, 2);

        self::assertTrue($data['ok']);
        self::assertCount($expectedLimit, $data['draws']);
    }

    /**
     * Regresja w Mode 8 (optimizeBetsWithFullCoverage): Faza 1 miała zahardkodowane
     * `$adjacentPairs > 1` zamiast `$adjacentPairs > $maxAdjacentPairs`, przez co
     * flaga `with_neighbours => true` była ignorowana przy budowie kuponów bazowych.
     */
    public function testMode8FullCoveragePropagatesWithNeighboursFlag(): void
    {
        $optimizer = new StatisticalOptimizerService(new GameRegistryService(), new NullLogger());
        // Pula z silnymi parami sąsiedzkimi: 4-5, 14-15, 23-24, 32-33, 40-41
        $pool = [4, 5, 10, 14, 15, 20, 23, 24, 30, 32, 33, 40, 41];
        $frequencies = array_fill_keys($pool, 10);
        $draws = [
            [4, 5, 14, 15, 23],
            [4, 5, 14, 15, 32],
            [4, 5, 23, 24, 40],
            [14, 15, 23, 24, 41],
            [4, 5, 32, 33, 40],
        ];

        $resWithout = $optimizer->optimizeBetsWithFullCoverage(
            $pool,
            5,
            6,
            $frequencies,
            42,
            ['with_neighbours' => false, 'draws' => $draws]
        );

        $resWith = $optimizer->optimizeBetsWithFullCoverage(
            $pool,
            5,
            6,
            $frequencies,
            42,
            ['with_neighbours' => true, 'draws' => $draws]
        );

        self::assertCount(6, $resWithout['bets']);
        self::assertCount(6, $resWith['bets']);
        self::assertSame(100.0, $resWith['report']['pool_coverage_pct']);

        // Z with_neighbours=true najwyżej oceniany kupon nie jest karany -100 za 2 pary sąsiadów
        $topScoreWith = $resWith['report']['ranked_bets'][0]['fitness']['total_score'];
        $topScoreWithout = $resWithout['report']['ranked_bets'][0]['fitness']['total_score'];
        self::assertGreaterThanOrEqual($topScoreWithout, $topScoreWith);
    }

    public function testAgentToolsRespectSessionsAndMonthsParameters(): void
    {
        $registry = new GameRegistryService();
        $apiMock = $this->createMock(LottoApiClient::class);

        // 1. FetchPairCoOccurrenceTool musi przekazać sessions=5 (a nie 100) i policzyć pary przy MIN_DRAWS=5
        $historyMock1 = $this->createMock(DrawHistoryProvider::class);
        $historyMock1->expects(self::once())
            ->method('getHistory')
            ->with('MiniLotto', 5)
            ->willReturn([
                'draws' => [
                    ['main' => [4, 17, 23, 27, 41], 'special' => []],
                    ['main' => [4, 17, 10, 20, 30], 'special' => []],
                    ['main' => [4, 17, 11, 21, 31], 'special' => []],
                    ['main' => [4, 17, 12, 22, 32], 'special' => []],
                    ['main' => [4, 17, 13, 24, 33], 'special' => []],
                ],
                'from_cache' => 5,
                'fetched' => 0,
                'rate_limited' => false,
                'missing' => 0,
            ]);

        $pairTool = new FetchPairCoOccurrenceTool($apiMock, $registry, $historyMock1);
        $pairResult = json_decode($pairTool->execute(['game' => 'MiniLotto', 'sessions' => 5]), true);

        self::assertIsArray($pairResult);
        self::assertArrayNotHasKey('error', $pairResult);
        self::assertSame(5, $pairResult['draws_analyzed']);
        self::assertSame([4, 17], $pairResult['top_pairs'][0]['pair']);

        // 2. FetchNeighboursAnalysisTool musi przekazać dokładną liczbę sesji (np. 5, bez podbijania do 20)
        $historyMock2 = $this->createMock(DrawHistoryProvider::class);
        $historyMock2->expects(self::once())
            ->method('getHistory')
            ->with('MiniLotto', 5)
            ->willReturn([
                'draws' => [
                    ['main' => [4, 17, 23, 27, 41], 'special' => []],
                    ['main' => [3, 18, 22, 28, 40], 'special' => []],
                    ['main' => [5, 16, 24, 26, 42], 'special' => []],
                ],
                'from_cache' => 3,
                'fetched' => 0,
                'rate_limited' => false,
                'missing' => 0,
            ]);

        $neighboursTool = new FetchNeighboursAnalysisTool($apiMock, $registry, $historyMock2);
        $neighboursResult = json_decode($neighboursTool->execute(['game' => 'MiniLotto', 'sessions' => 5]), true);

        self::assertIsArray($neighboursResult);
        self::assertArrayNotHasKey('error', $neighboursResult);
        self::assertSame(3, $neighboursResult['draws_analyzed']);

        // 3. FetchOverdueStatsTool obsługuje sessions=5 (bez klamry min 10)
        $historyMock3 = $this->createMock(DrawHistoryProvider::class);
        $historyMock3->expects(self::once())
            ->method('getHistory')
            ->with('MiniLotto', 5)
            ->willReturn([
                'draws' => [
                    ['main' => [1, 2, 3, 4, 5], 'special' => []],
                    ['main' => [6, 7, 8, 9, 10], 'special' => []],
                ],
                'from_cache' => 2,
                'fetched' => 0,
                'rate_limited' => false,
                'missing' => 0,
            ]);

        $overdueTool = new FetchOverdueStatsTool($apiMock, $registry, $historyMock3);
        $overdueResult = json_decode($overdueTool->execute(['game' => 'MiniLotto', 'sessions' => 5]), true);

        self::assertIsArray($overdueResult);
        self::assertArrayNotHasKey('error', $overdueResult);
        self::assertSame(2, $overdueResult['total_draws_analyzed']);

        // 4. FetchRecentDrawsTool obsługuje alias 'sessions' zamiast 'count'
        $historyMock4 = $this->createMock(DrawHistoryProvider::class);
        $historyMock4->expects(self::once())
            ->method('getHistory')
            ->with('MiniLotto', 5)
            ->willReturn([
                'draws' => [
                    ['main' => [4, 17, 23, 27, 41], 'special' => []],
                ],
                'from_cache' => 1,
                'fetched' => 0,
                'rate_limited' => false,
                'missing' => 0,
            ]);

        $recentTool = new FetchRecentDrawsTool($apiMock, $registry, $historyMock4);
        $recentResult = json_decode($recentTool->execute(['game' => 'MiniLotto', 'sessions' => 5]), true);

        self::assertIsArray($recentResult);
        self::assertSame(1, $recentResult['fetched_draws_count']);
    }
}
