<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DrawHistoryProvider;
use App\Service\GameRegistryService;
use App\Service\LottoApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Pobieranie losowań z LOTTO OpenAPI: rozbicie odpowiedzi `by-date` na gry
 * oraz zachowanie wobec HTTP 429.
 */
class DrawFetchingTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/lotto-fetch-' . uniqid('', true);
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
     * Jedno zapytanie `by-date` niesie losowania wszystkich gier z danego dnia,
     * a grupa "Lotto" zawiera dodatkowo wynik LottoPlus.
     */
    public function testExtractDrawsByGameSplitsOneDateAcrossGames(): void
    {
        $client = $this->apiClient();

        $byGame = $client->extractDrawsByGame([
            [
                'drawSystemId' => 7401,
                'drawDate' => '2026-09-05T20:00:00Z',
                'gameType' => 'Lotto',
                'results' => [
                    ['gameType' => 'Lotto', 'drawSystemId' => 7401, 'resultsJson' => [47, 14, 31, 46, 13, 32], 'specialResults' => []],
                    ['gameType' => 'LottoPlus', 'drawSystemId' => 7401, 'resultsJson' => [11, 23, 19, 30, 38, 21], 'specialResults' => []],
                ],
            ],
            [
                'drawSystemId' => 5100,
                'drawDate' => '2026-09-05T21:40:00Z',
                'gameType' => 'MiniLotto',
                'results' => [
                    ['gameType' => 'MiniLotto', 'drawSystemId' => 5100, 'resultsJson' => [3, 9, 14, 22, 41], 'specialResults' => []],
                ],
            ],
        ]);

        $this->assertSame(['Lotto', 'LottoPlus', 'MiniLotto'], array_keys($byGame));
        $this->assertSame([13, 14, 31, 32, 46, 47], $byGame['Lotto'][0]['main']);
        $this->assertSame([11, 19, 21, 23, 30, 38], $byGame['LottoPlus'][0]['main']);
        $this->assertSame([3, 9, 14, 22, 41], $byGame['MiniLotto'][0]['main']);
        $this->assertSame(5100, $byGame['MiniLotto'][0]['id']);
    }

    public function testExtractDrawsByGameDeduplicatesRepeatedDrawIds(): void
    {
        $client = $this->apiClient();

        $byGame = $client->extractDrawsByGame([
            [
                'gameType' => 'MultiMulti',
                'results' => [
                    ['gameType' => 'MultiMulti', 'drawSystemId' => 900, 'resultsJson' => [1, 2, 3], 'specialResults' => []],
                    ['gameType' => 'MultiMulti', 'drawSystemId' => 900, 'resultsJson' => [1, 2, 3], 'specialResults' => []],
                    ['gameType' => 'MultiMulti', 'drawSystemId' => 901, 'resultsJson' => [4, 5, 6], 'specialResults' => []],
                ],
            ],
        ]);

        $this->assertCount(2, $byGame['MultiMulti']);
    }

    /**
     * Regresja: HTTP 429 przerywał CAŁY przebieg, więc głęboka historia nigdy
     * nie powstawała. Teraz data wraca do kolejki i jest ponawiana.
     */
    public function testRateLimitedDateIsRetriedInsteadOfAbortingTheRun(): void
    {
        $apiClient = $this->createMock(LottoApiClient::class);
        $apiClient->method('requestDrawsForDate')
            ->willReturnCallback(fn(): ResponseInterface => $this->createMock(ResponseInterface::class));

        $call = 0;
        $apiClient->method('resolveDrawsResponse')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                // Co drugie zapytanie odbija się od limitu.
                if ($call % 2 === 1) {
                    return ['ok' => false, 'rate_limited' => true, 'draws' => []];
                }

                return [
                    'ok' => true,
                    'rate_limited' => false,
                    'draws' => [['main' => [1, 2, 3, 4, 5, 6], 'special' => [], 'id' => $call]],
                ];
            }
        );

        $provider = new DrawHistoryProvider(
            $apiClient,
            new GameRegistryService(),
            new NullLogger(),
            $this->storageDir,
            0
        );

        $result = $provider->getHistory('Lotto', 4, 4);

        $this->assertFalse($result['rate_limited']);
        $this->assertSame(4, $result['fetched']);
        $this->assertSame(0, $result['missing']);
        $this->assertCount(4, $result['draws']);
    }

    public function testGivesUpAfterRepeatedRateLimitsAndKeepsWhatItGot(): void
    {
        $apiClient = $this->createMock(LottoApiClient::class);
        $apiClient->method('requestDrawsForDate')
            ->willReturnCallback(fn(): ResponseInterface => $this->createMock(ResponseInterface::class));
        $apiClient->method('resolveDrawsResponse')
            ->willReturn(['ok' => false, 'rate_limited' => true, 'draws' => []]);

        $provider = new DrawHistoryProvider(
            $apiClient,
            new GameRegistryService(),
            new NullLogger(),
            $this->storageDir,
            0
        );

        $result = $provider->getHistory('Lotto', 5, 5);

        $this->assertTrue($result['rate_limited']);
        $this->assertSame(0, $result['fetched']);
    }

    /**
     * Budżet zapytań ogranicza tylko liczbę NOWYCH dat; reszta ma zostać
     * zgłoszona jako brakująca, a nie po cichu pominięta.
     */
    public function testRespectsPerRunBudgetAndReportsTheRest(): void
    {
        $apiClient = $this->createMock(LottoApiClient::class);
        $apiClient->method('requestDrawsForDate')
            ->willReturnCallback(fn(): ResponseInterface => $this->createMock(ResponseInterface::class));
        $apiClient->method('resolveDrawsResponse')->willReturn([
            'ok' => true,
            'rate_limited' => false,
            'draws' => [['main' => [1, 2, 3, 4, 5, 6], 'special' => [], 'id' => 1]],
        ]);

        $provider = new DrawHistoryProvider(
            $apiClient,
            new GameRegistryService(),
            new NullLogger(),
            $this->storageDir,
            0
        );

        $result = $provider->getHistory('Lotto', 20, 3);

        $this->assertSame(3, $result['fetched']);
        $this->assertSame(17, $result['missing']);
    }

    private function apiClient(): LottoApiClient
    {
        return new LottoApiClient(
            $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            new GameRegistryService(),
            'test-key'
        );
    }
}
