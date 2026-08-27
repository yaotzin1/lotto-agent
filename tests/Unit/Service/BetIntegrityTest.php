<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BetGeneratorService;
use App\Service\GameRegistryService;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regresje dla błędów kosztujących pieniądze (grupa B z docs/REVIEW.md).
 *
 * Każdy test odpowiada jednemu konkretnemu defektowi, który powodował,
 * że gracz płacił za kupony, których nie zamawiał albo których nie da się grać.
 */
class BetIntegrityTest extends TestCase
{
    private BetGeneratorService $generator;
    private StatisticalOptimizerService $optimizer;

    protected function setUp(): void
    {
        $this->optimizer = new StatisticalOptimizerService(new GameRegistryService(), new NullLogger());
        $this->generator = new BetGeneratorService($this->optimizer);
    }

    // --- B2: pula 6 liczb przy grze 6-liczbowej dawała 30 IDENTYCZNYCH kuponów ---

    public function testRejectsMoreBetsThanDistinctCombinations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tylko 1 różnych kuponów/');

        $pool = [3, 17, 28, 44, 55, 71];
        $this->optimizer->optimizeBetsWithFullCoverage($pool, 6, 30, array_fill_keys($pool, 10), 80);
    }

    public function testAllowsExactlyTheMaximumNumberOfCombinations(): void
    {
        $pool = [1, 2, 3, 4, 5, 6, 7];           // C(7,6) = 7
        $result = $this->optimizer->optimizeBetsWithFullCoverage($pool, 6, 7, array_fill_keys($pool, 10), 49);

        $keys = array_map(static fn(array $b): string => implode('-', $b), $result['bets']);
        self::assertCount(7, $result['bets']);
        self::assertCount(7, array_unique($keys), 'Wszystkie kupony muszą być różne');
    }

    public function testGeneratedPacketNeverContainsDuplicateCoupons(): void
    {
        $pool = range(1, 20);
        $result = $this->optimizer->optimizeBetsWithFullCoverage($pool, 6, 40, array_fill_keys($pool, 10), 49);

        $keys = array_map(static fn(array $b): string => implode('-', $b), $result['bets']);
        self::assertSame(count($keys), count(array_unique($keys)), 'Pakiet nie może zawierać powtórzonych kuponów');
    }

    // --- B4: pula mniejsza niż liczba skreśleń zwracała niegrywalny kupon ---

    public function testRejectsPoolSmallerThanPick(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->generateBalancedShorthand([4, 9, 17], 6, 5);
    }

    public function testEveryGeneratedBetHasExactlyPickNumbers(): void
    {
        $bets = $this->generator->generateBalancedShorthand(range(1, 20), 6, 25);

        self::assertNotEmpty($bets);
        foreach ($bets as $bet) {
            self::assertCount(6, $bet);
            self::assertSame($bet, array_values(array_unique($bet)));
        }
    }

    // --- B5: blok większy niż pula zawijał się i dublował liczby w jednym kuponie ---

    public function testRejectsBlockLargerThanPool(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->generateOverlappingBlocks([1, 2, 3, 4, 5], 2, 8);
    }

    public function testOverlappingBlocksNeverRepeatANumberWithinABlock(): void
    {
        foreach ($this->generator->generateOverlappingBlocks(range(1, 18), 4, 12) as $block) {
            self::assertSame(
                count($block),
                count(array_unique($block)),
                'Blok nie może zawierać powtórzonej liczby'
            );
        }
    }

    // --- B6: progi parzystości / dekad były zakodowane pod pick = 5..6 ---

    public function testParityRuleForLottoIsUnchanged(): void
    {
        // Dla 6 skreśleń nadal akceptujemy dokładnie 2:4, 3:3, 4:2.
        self::assertTrue($this->optimizer->isParityBalanced(3, 3, 6));
        self::assertTrue($this->optimizer->isParityBalanced(4, 2, 6));
        self::assertTrue($this->optimizer->isParityBalanced(2, 4, 6));
        self::assertFalse($this->optimizer->isParityBalanced(5, 1, 6));
        self::assertFalse($this->optimizer->isParityBalanced(6, 0, 6));
    }

    public function testParityRuleAcceptsFiveFiveForMultiMulti(): void
    {
        // To jest najbardziej prawdopodobny podział dla pick = 10,
        // a stara reguła (odds <= 4) odrzucała go i nagradzała 4:6.
        self::assertTrue($this->optimizer->isParityBalanced(5, 5, 10));
        self::assertTrue($this->optimizer->isParityBalanced(6, 4, 10));
        self::assertFalse($this->optimizer->isParityBalanced(10, 0, 10));
    }

    public function testParityRuleIsSymmetric(): void
    {
        foreach ([[4, 1, 5], [1, 4, 5], [7, 3, 10], [3, 7, 10]] as [$odds, $evens, $pick]) {
            self::assertSame(
                $this->optimizer->isParityBalanced($odds, $evens, $pick),
                $this->optimizer->isParityBalanced($evens, $odds, $pick),
                "Reguła parzystości musi być symetryczna dla $odds:$evens"
            );
        }
    }

    public function testDecadeLimitIsReachableForKaskada(): void
    {
        // Kaskada to 12 z 24 -> istnieją tylko 3 dekady.
        // Stary sztywny limit 2 na dekadę czynił KAŻDY kupon nielegalnym.
        $maxPerDecade = $this->optimizer->maxPerDecade(12, 24);
        $decadesAvailable = 3;

        self::assertGreaterThanOrEqual(
            12,
            $maxPerDecade * $decadesAvailable,
            'Musi istnieć jakikolwiek legalny kupon dla Kaskady'
        );
        self::assertLessThanOrEqual(3, $this->optimizer->targetDecadeSpread(12, 24));
    }

    public function testDecadeRulesForLottoAreUnchanged(): void
    {
        self::assertSame(2, $this->optimizer->maxPerDecade(6, 49));
        self::assertSame(4, $this->optimizer->targetDecadeSpread(6, 49));
    }

    // --- C1: dokumentacja rozjeżdżała się z kodem; kod jest źródłem prawdy ---

    public function testGaussianRangeMatchesFinitePopulationFormula(): void
    {
        $g = $this->optimizer->calculateGaussianParameters(49, 6);

        self::assertSame(150, $g['expected_sum']);
        self::assertEqualsWithDelta(32.79, $g['std_dev'], 0.01);
        self::assertSame(106, $g['optimal_min']);
        self::assertSame(194, $g['optimal_max']);
    }

    public function testBenchmarkDeclaresItselfSelfReferential(): void
    {
        $pool = range(1, 20);
        $result = $this->optimizer->optimizeBetsWithFullCoverage($pool, 6, 10, array_fill_keys($pool, 10), 49);

        self::assertTrue($result['report']['benchmark']['metric_is_self_referential']);
        self::assertNotEmpty($result['report']['benchmark']['disclaimer']);
    }
}
