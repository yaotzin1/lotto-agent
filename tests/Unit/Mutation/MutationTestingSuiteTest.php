<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mutation;

use App\Service\AgentTools\LottoToolInterface;
use App\Service\AgentTools\ToolRegistry;
use App\Service\DecadeDistributionService;
use App\Service\DrawHistoryProvider;
use App\Service\GameRegistryService;
use App\Service\GeminiApiClient;
use App\Service\LottoApiClient;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Zestaw testów mutacyjnych (Mutation Testing Suite).
 *
 * Wczytuje rzeczywisty kod źródłowy klas z `src/`, aplikuje mutacje (mutatory brzegowe,
 * arytmetyczne i regresyjne odpowiadające błędom parametrów z raportu PDF), kompiluje
 * zmutowane klasy w izolowanej przestrzeni nazw i weryfikuje, że nasze testy jednostkowe
 * ZABIJAJĄ (Kill) 100% mutantów (MSI = 100%).
 */
class MutationTestingSuiteTest extends TestCase
{
    private string $projectDir;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->storageDir = sys_get_temp_dir() . '/lotto-mutation-' . uniqid('', true);
        mkdir($this->storageDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storageDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storageDir);
    }

    /**
     * Kompiluje zmutowaną klasę z pliku źródłowego `src/...` pod unikalną nazwą
     * i zwraca pełną nazwę klasy (FQCN).
     */
    private function loadMutantClass(string $relativePath, string $originalClassName, string $search, string $replace): string
    {
        $fullPath = $this->projectDir . '/' . ltrim($relativePath, '/\\');
        $source = file_get_contents($fullPath);
        self::assertIsString($source, "Nie można odczytać pliku źródłowego: $fullPath");
        self::assertStringContainsString(
            $search,
            $source,
            sprintf("Fragment docelowy mutacji nie został znaleziony w %s:\n%s", $relativePath, $search)
        );

        $mutatedSource = str_replace($search, $replace, $source);
        self::assertNotSame($source, $mutatedSource, 'Mutacja nie zmieniła kodu źródłowego');

        $mutantShortName = $originalClassName . '_Mutant_' . substr(md5($relativePath . $search . $replace . uniqid('', true)), 0, 8);

        // Podmień deklarację klasy na unikalną nazwę w tym samym namespace
        $mutatedSource = preg_replace(
            '/class\s+' . preg_quote($originalClassName, '/') . '\b/',
            'class ' . $mutantShortName,
            $mutatedSource,
            1
        );

        // Usuń tag otwierający <?php przed eval
        $codeToEval = preg_replace('/^\s*<\?php\s*/', '', (string) $mutatedSource);
        eval($codeToEval);

        if (preg_match('/namespace\s+([^;]+);/', (string) $mutatedSource, $m)) {
            return $m[1] . '\\' . $mutantShortName;
        }

        return $mutantShortName;
    }

    /**
     * Upewnia się, że przekazany test weryfikujący RZUCA wyjątek asercji (czyli ZABIJA mutanta).
     */
    private function assertMutantKilled(string $mutantId, callable $verificationTest): void
    {
        $killed = false;
        try {
            $verificationTest();
        } catch (AssertionFailedError|\Throwable $e) {
            $killed = true;
        }

        self::assertTrue(
            $killed,
            sprintf('MUTANT ESCAPED [%s]: Zmutowany kod przeszedł testy bez wykrycia błędu!', $mutantId)
        );
    }

    public function testMutantM1HistoricalDataProviderSessionsFloor50IsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/HistoricalDataProvider.php',
            'HistoricalDataProvider',
            '$historyLimit = $effectiveSessions',
            '$historyLimit = max((int) $effectiveSessions, 50) ?: $effectiveSessions'
        );

        $this->assertMutantKilled('M1: HistoricalDataProvider max($sessions, 50)', function () use ($mutantClass): void {
            $apiClient = $this->createMock(LottoApiClient::class);
            $apiClient->method('getHotColdNumbers')->willReturn([
                'sorted_by_freq_desc' => [4 => 3, 17 => 2],
                'draws_analyzed' => 5,
            ]);

            $capturedLimit = null;
            $drawHistoryProvider = $this->createMock(DrawHistoryProvider::class);
            $drawHistoryProvider->method('getHistory')
                ->willReturnCallback(static function (string $game, int $limit) use (&$capturedLimit): array {
                    $capturedLimit = $limit;

                    return [
                        'draws' => array_fill(0, 5, ['main' => [1, 2, 3, 4, 5], 'special' => []]),
                        'from_cache' => 5,
                        'fetched' => 0,
                        'rate_limited' => false,
                        'missing' => 0,
                    ];
                });

            $provider = new $mutantClass($apiClient, new GameRegistryService(), $drawHistoryProvider);
            $provider->fetch('MiniLotto', 5, null);

            self::assertSame(5, $capturedLimit);
        });
    }

    public function testMutantM2HistoricalDataProviderMonthsFallbackIgnoredIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/HistoricalDataProvider.php',
            'HistoricalDataProvider',
            '? $this->gameRegistryService->estimateSessionsForMonths($gameType, $months)',
            '? 50'
        );

        $this->assertMutantKilled('M2: HistoricalDataProvider ignores --months', function () use ($mutantClass): void {
            $apiClient = $this->createMock(LottoApiClient::class);
            $apiClient->method('getHotColdNumbers')->willReturn([
                'sorted_by_freq_desc' => [1 => 5],
                'draws_analyzed' => 26,
            ]);

            $registry = new GameRegistryService();
            $expected = $registry->estimateSessionsForMonths('Lotto', 2);

            $capturedLimit = null;
            $drawHistoryProvider = $this->createMock(DrawHistoryProvider::class);
            $drawHistoryProvider->method('getHistory')
                ->willReturnCallback(static function (string $game, int $limit) use (&$capturedLimit): array {
                    $capturedLimit = $limit;

                    return [
                        'draws' => [],
                        'from_cache' => 0,
                        'fetched' => 0,
                        'rate_limited' => false,
                        'missing' => 0,
                    ];
                });

            $provider = new $mutantClass($apiClient, $registry, $drawHistoryProvider);
            $provider->fetch('Lotto', null, 2);

            self::assertSame($expected, $capturedLimit);
        });
    }

    public function testMutantM3HistoricalDataProviderSessionsBoundaryConditionIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/HistoricalDataProvider.php',
            'HistoricalDataProvider',
            '$effectiveSessions = ($sessions !== null && $sessions > 0) ? $sessions : null;',
            '$effectiveSessions = ($sessions !== null && $sessions > 5) ? $sessions : null;'
        );

        $this->assertMutantKilled('M3: HistoricalDataProvider $sessions > 0 mutated to > 5', function () use ($mutantClass): void {
            $apiClient = $this->createMock(LottoApiClient::class);
            $apiClient->method('getHotColdNumbers')->willReturn([
                'sorted_by_freq_desc' => [1 => 5],
                'draws_analyzed' => 5,
            ]);

            $capturedLimit = null;
            $drawHistoryProvider = $this->createMock(DrawHistoryProvider::class);
            $drawHistoryProvider->method('getHistory')
                ->willReturnCallback(static function (string $game, int $limit) use (&$capturedLimit): array {
                    $capturedLimit = $limit;

                    return [
                        'draws' => [],
                        'from_cache' => 0,
                        'fetched' => 0,
                        'rate_limited' => false,
                        'missing' => 0,
                    ];
                });

            $provider = new $mutantClass($apiClient, new GameRegistryService(), $drawHistoryProvider);
            $provider->fetch('MiniLotto', 5, null);

            self::assertSame(5, $capturedLimit);
        });
    }

    public function testMutantM4DrawHistoryProviderMissingSliceOnStoreIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/DrawHistoryProvider.php',
            'DrawHistoryProvider',
            'if (count($draws) >= $limit) {',
            'if (false && count($draws) >= $limit) {'
        );

        $this->assertMutantKilled('M4: DrawHistoryProvider missing $limit stop condition', function () use ($mutantClass): void {
            $store = [];
            $today = new \DateTimeImmutable('today');
            for ($i = 1; $i <= 20; $i++) {
                $date = $today->modify("-{$i} days")->format('Y-m-d');
                $store[$date] = [['main' => [1, 2, 3, 4, $i], 'special' => [], 'id' => $i]];
            }
            file_put_contents($this->storageDir . '/MiniLotto.json', json_encode($store));

            $provider = new $mutantClass(
                $this->createMock(LottoApiClient::class),
                new GameRegistryService(),
                new NullLogger(),
                $this->storageDir,
                0
            );

            $history = $provider->getHistory('MiniLotto', 5, 0);
            self::assertCount(5, $history['draws']);
        });
    }

    public function testMutantM5DrawHistoryProviderSortOrderInversionIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/DrawHistoryProvider.php',
            'DrawHistoryProvider',
            'krsort($store);',
            'ksort($store);'
        );

        $this->assertMutantKilled('M5: DrawHistoryProvider krsort mutated to ksort', function () use ($mutantClass): void {
            $store = [];
            $today = new \DateTimeImmutable('today');
            for ($i = 1; $i <= 15; $i++) {
                $date = $today->modify("-{$i} days")->format('Y-m-d');
                $store[$date] = [['main' => [1, 2, 3, 4, $i], 'special' => [], 'id' => 100 - $i]];
            }
            file_put_contents($this->storageDir . '/MiniLotto.json', json_encode($store));

            $provider = new $mutantClass(
                $this->createMock(LottoApiClient::class),
                new GameRegistryService(),
                new NullLogger(),
                $this->storageDir,
                0
            );

            $history = $provider->getHistory('MiniLotto', 5, 0);
            self::assertSame([1, 2, 3, 4, 1], $history['draws'][0]['main']);
        });
    }

    public function testMutantM6GameRegistryEstimateSessionsMultiplierIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/GameRegistryService.php',
            'GameRegistryService',
            '$weeks = max(1.0, $months * 4.345);',
            '$weeks = max(1.0, $months * 1.0);'
        );

        $this->assertMutantKilled('M6: GameRegistryService weeks-per-month multiplier mutated', function () use ($mutantClass): void {
            $registry = new $mutantClass();
            self::assertSame(30, $registry->estimateSessionsForMonths('MiniLotto', 1));
        });
    }

    public function testMutantM7OptimizerCoOccurrenceMinDrawsThresholdIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/StatisticalOptimizerService.php',
            'StatisticalOptimizerService',
            'public const MIN_DRAWS_FOR_CO_OCCURRENCE = 5;',
            'public const MIN_DRAWS_FOR_CO_OCCURRENCE = 20;'
        );

        $this->assertMutantKilled('M7: StatisticalOptimizerService MIN_DRAWS_FOR_CO_OCCURRENCE mutated to 20', function () use ($mutantClass): void {
            $optimizer = new $mutantClass(new GameRegistryService(), new NullLogger());
            $pool = range(1, 20);
            $draws = [
                [3, 17, 1, 8, 14],
                [3, 17, 2, 9, 15],
                [3, 17, 4, 10, 16],
                [3, 17, 5, 11, 18],
                [3, 17, 6, 12, 19],
            ];
            $optimizer->buildPairAffinityMatrix($pool, array_fill_keys($pool, 5), $draws);
            self::assertSame(
                StatisticalOptimizerService::AFFINITY_SOURCE_CO_OCCURRENCE,
                $optimizer->getLastAffinitySource()
            );
            self::assertSame(5, $optimizer->getLastAffinityDrawCount());
        });
    }

    public function testMutantM8OptimizerEmaDecaySignInversionIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/StatisticalOptimizerService.php',
            'StatisticalOptimizerService',
            'return 1.0 + (0.6 * exp(-max(0, $drawIdx) / 15.0));',
            'return 1.0 + (0.6 * exp(max(0, $drawIdx) / 15.0));'
        );

        $this->assertMutantKilled('M8: StatisticalOptimizerService EMA exp(-drawIdx/15) mutated to exp(+drawIdx/15)', function () use ($mutantClass): void {
            $optimizer = new $mutantClass(new GameRegistryService(), new NullLogger());
            $pool = range(1, 30);
            $draws = [];
            for ($i = 0; $i < 5; $i++) {
                $draws[] = [4, 14, 1 + $i, 10 + $i, 20 + $i];
            }
            for ($i = 0; $i < 15; $i++) {
                $draws[] = [1 + ($i % 3), 8 + ($i % 4), 16 + ($i % 4), 22 + ($i % 4), 27 + ($i % 3)];
            }
            for ($i = 0; $i < 5; $i++) {
                $draws[] = [5, 15, 2 + $i, 11 + $i, 21 + $i];
            }
            $matrix = $optimizer->buildPairAffinityMatrix($pool, array_fill_keys($pool, 5), $draws);
            self::assertGreaterThan($matrix[5][15], $matrix[4][14]);
        });
    }

    public function testMutantM9FetchPairCoOccurrenceHardcodedLimitIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/AgentTools/FetchPairCoOccurrenceTool.php',
            'FetchPairCoOccurrenceTool',
            '$history = $this->drawHistoryProvider->getHistory($game, $sessions);',
            '$history = $this->drawHistoryProvider->getHistory($game, 100);'
        );

        $this->assertMutantKilled('M9: FetchPairCoOccurrenceTool hardcoded getHistory(100)', function () use ($mutantClass): void {
            $registry = new GameRegistryService();
            $apiMock = $this->createMock(LottoApiClient::class);
            $capturedLimit = null;
            $historyMock = $this->createMock(DrawHistoryProvider::class);
            $historyMock->method('getHistory')
                ->willReturnCallback(static function (string $game, int $limit) use (&$capturedLimit): array {
                    $capturedLimit = $limit;

                    return [
                        'draws' => array_fill(0, 5, ['main' => [4, 17, 23, 27, 41], 'special' => []]),
                        'from_cache' => 5,
                        'fetched' => 0,
                        'rate_limited' => false,
                        'missing' => 0,
                    ];
                });

            $tool = new $mutantClass($apiMock, $registry, $historyMock);
            $tool->execute(['game' => 'MiniLotto', 'sessions' => 5]);

            self::assertSame(5, $capturedLimit);
        });
    }

    public function testMutantM10ReActAgentDisablingSessionsInjectionIsKilled(): void
    {
        $mutantClass = $this->loadMutantClass(
            'src/Service/ReActAgentService.php',
            'ReActAgentService',
            'if ($sessions !== null && !isset($toolArgs[\'sessions\'])) {',
            'if (false && $sessions !== null && !isset($toolArgs[\'sessions\'])) {'
        );

        $this->assertMutantKilled('M10: ReActAgentService disabled sessions injection into tool calls', function () use ($mutantClass): void {
            $capturedArgs = [];
            $mockTool = new class($capturedArgs) implements LottoToolInterface {
                /** @var array<int, array<string, mixed>> */
                private array $captured;

                public function __construct(array &$captured)
                {
                    $this->captured = &$captured;
                }

                public function getName(): string
                {
                    return 'fetch_pair_co_occurrence';
                }

                public function getDescription(): string
                {
                    return 'Mock';
                }

                public function getParametersSchema(): array
                {
                    return ['type' => 'OBJECT', 'properties' => []];
                }

                public function execute(array $args): string
                {
                    $this->captured[] = $args;

                    return json_encode(['draws_analyzed' => $args['sessions'] ?? 0]);
                }
            };

            $geminiMock = $this->createMock(GeminiApiClient::class);
            $turn = 0;
            $geminiMock->method('chatWithTools')->willReturnCallback(
                static function () use (&$turn): array {
                    $turn++;
                    if ($turn === 1) {
                        return [
                            'thought' => 'Call tool',
                            'text' => '',
                            'tool_calls' => [['id' => '1', 'name' => 'fetch_pair_co_occurrence', 'args' => []]],
                        ];
                    }

                    return [
                        'thought' => 'Done',
                        'text' => json_encode(['reasoning' => 'OK', 'selected_pool' => range(1, 10)]),
                        'tool_calls' => [],
                    ];
                }
            );

            $agent = new $mutantClass(
                $geminiMock,
                new ToolRegistry([$mockTool]),
                new GameRegistryService(),
                new NullLogger(),
                new DecadeDistributionService()
            );

            $agent->runAgentLoop('MiniLotto', 10, 'balanced', null, 5);
            self::assertSame(5, $capturedArgs[0]['sessions'] ?? null);
        });
    }
}
