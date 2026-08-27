<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BetGeneratorService;
use App\Service\BetPipelineRequest;
use App\Service\BetPipelineService;
use App\Service\ExtraNumbersGenerator;
use App\Service\GameRegistryService;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Testy dyspozytora trybów (finding E1 z docs/REVIEW.md).
 *
 * Przed wydzieleniem BetPipelineService logika trybów żyła w dwóch kopiach
 * wewnątrz komend CLI i była nietestowalna bez udawania terminala.
 */
class BetPipelineServiceTest extends TestCase
{
    private BetPipelineService $pipeline;
    private array $lotto;
    private array $euro;

    protected function setUp(): void
    {
        $registry = new GameRegistryService();
        $optimizer = new StatisticalOptimizerService($registry, new NullLogger());

        $this->pipeline = new BetPipelineService(
            new BetGeneratorService($optimizer),
            $optimizer,
            new ExtraNumbersGenerator()
        );

        $this->lotto = $registry->getGameConfig('Lotto');
        $this->euro = $registry->getGameConfig('EuroJackpot');
    }

    private function request(string $mode, int $bets, array $overrides = []): BetPipelineRequest
    {
        $pool = $overrides['pool'] ?? range(1, 30);

        return new BetPipelineRequest(
            game: $overrides['game'] ?? $this->lotto,
            pool: $pool,
            mode: $mode,
            betsTotal: $bets,
            frequencies: array_fill_keys($pool, 10),
            draws: $overrides['draws'] ?? [],
            bankers: $overrides['bankers'] ?? [],
            bankersPerBet: $overrides['bankersPerBet'] ?? 3,
            l1Size: $overrides['l1Size'] ?? 12,
            l1Count: $overrides['l1Count'] ?? 4,
            l2Size: $overrides['l2Size'] ?? 8,
            l2Count: $overrides['l2Count'] ?? 2,
            blockSize: $overrides['blockSize'] ?? 12,
            blockCount: $overrides['blockCount'] ?? 5,
            hotNumbers: $overrides['hotNumbers'] ?? [],
            weight: $overrides['weight'] ?? 5,
        );
    }

    /**
     * B1: --bets to liczba ŁĄCZNA w KAŻDYM trybie, także blokowym.
     */
    public function testEveryModeRespectsTheTotalBetCount(): void
    {
        foreach (['1', '2', '3', '5', '7', '8'] as $mode) {
            $result = $this->pipeline->run($this->request($mode, 12));

            self::assertLessThanOrEqual(
                12,
                $result->count(),
                "Tryb $mode wygenerował więcej kuponów niż zamówiono"
            );
            self::assertGreaterThan(0, $result->count(), "Tryb $mode nie wygenerował nic");
        }
    }

    public function testFractalModeNoLongerMultipliesBetsByBlockCount(): void
    {
        // Wcześniej: 4 bloki L1 x 2 podbloki L2 x 12 kuponow = 96 zamiast 12.
        $result = $this->pipeline->run($this->request('5', 12, ['pool' => range(1, 18)]));

        self::assertLessThanOrEqual(12, $result->count());
    }

    public function testPacketNeverContainsDuplicateCoupons(): void
    {
        foreach (['2', '5', '8'] as $mode) {
            $result = $this->pipeline->run($this->request($mode, 20));
            $keys = array_map(static fn(array $b): string => implode('-', $b), $result->bets);

            self::assertSame(count($keys), count(array_unique($keys)), "Tryb $mode zwrócił duplikaty");
        }
    }

    public function testEveryCouponHasExactlyPickNumbers(): void
    {
        foreach (['1', '4', '6', '7', '8'] as $mode) {
            $result = $this->pipeline->run($this->request($mode, 8, [
                'bankers' => [3, 7, 11, 19, 23],
                'bankersPerBet' => 2,
            ]));

            foreach ($result->bets as $bet) {
                self::assertCount(6, $bet, "Tryb $mode zwrócił kupon o złej długości");
                self::assertSame($bet, array_values(array_unique($bet)));
            }
        }
    }

    // --- walidacja, która wcześniej po cichu przepuszczała złe konfiguracje ---

    public function testRejectsMoreBankersThanPicks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tylko 6 skreśleń/u');

        $this->pipeline->run($this->request('4', 10, ['bankers' => [1, 2, 3, 4, 5, 6, 7]]));
    }

    public function testRejectsTooManyBankersPerBet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pipeline->run($this->request('6', 10, ['bankers' => [1, 2, 3, 4], 'bankersPerBet' => 6]));
    }

    public function testRejectsL1BlockLargerThanPool(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pipeline->run($this->request('5', 10, ['pool' => range(1, 10), 'l1Size' => 20]));
    }

    public function testRejectsPoolSmallerThanPick(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pipeline->run($this->request('8', 5, ['pool' => [1, 2, 3]]));
    }

    public function testReportsShortfallInsteadOfSilentlyReturningFewer(): void
    {
        // 8 bankierów po 3 na kupon => C(8,3) = 56 to twardy sufit.
        $result = $this->pipeline->run($this->request('6', 200, [
            'pool' => range(1, 40),
            'bankers' => [3, 8, 14, 19, 25, 31, 38, 40],
            'bankersPerBet' => 3,
        ]));

        self::assertLessThan(200, $result->count());
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('Zamówiono 200', $result->warnings[0]);
    }

    // --- C4: liczby dodatkowe ---

    public function testEuroJackpotProducesExtraNumbers(): void
    {
        $result = $this->pipeline->run($this->request('8', 10, [
            'game' => $this->euro,
            'pool' => range(1, 50),
        ]));

        self::assertTrue($result->hasExtraNumbers());
        self::assertCount($result->count(), $result->extraSets);

        foreach ($result->extraSets as $set) {
            self::assertCount(2, $set, 'EuroJackpot wymaga dokładnie 2 liczb dodatkowych');
            foreach ($set as $n) {
                self::assertGreaterThanOrEqual(1, $n);
                self::assertLessThanOrEqual(12, $n);
            }
        }
    }

    public function testLottoHasNoExtraNumbers(): void
    {
        $result = $this->pipeline->run($this->request('8', 5));

        self::assertFalse($result->hasExtraNumbers());
        self::assertNull($result->extraInfo);
    }

    public function testModeSevenAndEightDifferInCoverage(): void
    {
        $pool = range(1, 49);
        $concentration = $this->pipeline->run($this->request('7', 9, ['pool' => $pool]));
        $fullCoverage = $this->pipeline->run($this->request('8', 9, ['pool' => $pool]));

        $used = static function (array $bets): int {
            $seen = [];
            foreach ($bets as $b) {
                foreach ($b as $n) {
                    $seen[$n] = true;
                }
            }

            return count($seen);
        };

        self::assertGreaterThan(
            $used($concentration->bets),
            $used($fullCoverage->bets),
            'Tryb 8 (pełne pokrycie) musi obejmować więcej liczb niż tryb 7 (koncentracja)'
        );
    }
}
