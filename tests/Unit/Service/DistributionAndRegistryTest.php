<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AgentTools\EvaluateDistributionTool;
use App\Service\GameRegistryService;
use App\Service\StatisticalOptimizerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regresje dla ustaleń A7 i C9 z docs/REVIEW.md.
 */
class DistributionAndRegistryTest extends TestCase
{
    private GameRegistryService $registry;
    private EvaluateDistributionTool $tool;

    protected function setUp(): void
    {
        $this->registry = new GameRegistryService();
        $this->tool = new EvaluateDistributionTool(
            $this->registry,
            new StatisticalOptimizerService($this->registry, new NullLogger())
        );
    }

    private function evaluate(string $game, array $pool): array
    {
        return json_decode($this->tool->execute(['game' => $game, 'pool' => $pool]), true);
    }

    // ---------------- A7 ----------------

    /**
     * Sedno A7: pula skrajna (1-8 + 42-49) ma średnią DOKŁADNIE równą średniej gry,
     * więc stara wersja narzędzia uznawała ją za "optymalną". Realnie jest gorsza
     * od pełnego bębna, bo ma znacznie większy rozrzut.
     */
    public function testExtremePoolIsNotReportedAsOptimalDespiteMatchingMean(): void
    {
        $full = $this->evaluate('Lotto', range(1, 49));
        $extremes = $this->evaluate('Lotto', array_merge(range(1, 8), range(42, 49)));

        // Obie pule mają tę samą średnią sumy...
        self::assertSame(
            $full['pool_subset_sum_profile']['mean'],
            $extremes['pool_subset_sum_profile']['mean']
        );

        // ...ale skrajna ma większy rozrzut i MNIEJ zakładów w normie.
        self::assertGreaterThan(
            $full['pool_subset_sum_profile']['std_dev'],
            $extremes['pool_subset_sum_profile']['std_dev']
        );
        self::assertLessThan(
            $full['pct_of_random_bets_in_plausible_range'],
            $extremes['pct_of_random_bets_in_plausible_range'],
            'Pula skrajna musi wypadać gorzej niż pełny bęben mimo tej samej średniej'
        );
    }

    public function testFullWheelMatchesTheGameBaseline(): void
    {
        $d = $this->evaluate('Lotto', range(1, 49));

        self::assertEqualsWithDelta(
            $d['baseline_pct_for_full_wheel'],
            $d['pct_of_random_bets_in_plausible_range'],
            0.5,
            'Pełny bęben musi dawać dokładnie baseline gry'
        );
        self::assertEqualsWithDelta(82.0, $d['baseline_pct_for_full_wheel'], 1.5);
    }

    public function testSkewedPoolIsFlaggedWithDirectionOfShift(): void
    {
        $low = $this->evaluate('Lotto', range(1, 20));
        $high = $this->evaluate('Lotto', range(30, 49));

        self::assertLessThan(-1.0, $low['pool_subset_sum_profile']['shift_in_sigmas']);
        self::assertGreaterThan(1.0, $high['pool_subset_sum_profile']['shift_in_sigmas']);
        self::assertStringContainsString('niskie', $low['verdict']);
        self::assertStringContainsString('wysokie', $high['verdict']);
    }

    public function testReportsEmptyDecades(): void
    {
        $d = $this->evaluate('Lotto', range(30, 49));

        self::assertContains('1-10', $d['empty_decades']);
        self::assertContains('11-20', $d['empty_decades']);
    }

    public function testUsesTheSameGaussianRangeAsTheOptimizer(): void
    {
        $d = $this->evaluate('Lotto', range(1, 49));

        // Nie wpisane na sztywno, tylko z calculateGaussianParameters.
        self::assertSame('106 - 194', $d['game_sum_profile']['plausible_range']);
        self::assertSame(150, $d['game_sum_profile']['expected_sum']);
    }

    public function testRejectsPoolSmallerThanPick(): void
    {
        $d = $this->evaluate('Lotto', [1, 2, 3]);

        self::assertArrayHasKey('error', $d);
    }

    // ---------------- C9 ----------------

    public function testVariablePickGamesAreRecognised(): void
    {
        self::assertTrue($this->registry->isVariablePick('MultiMulti'));
        self::assertTrue($this->registry->isVariablePick('Keno'));

        foreach (['Lotto', 'MiniLotto', 'EuroJackpot', 'Kaskada', 'Szybkie600'] as $game) {
            self::assertFalse($this->registry->isVariablePick($game), "$game ma stałą liczbę skreśleń");
        }
    }

    public function testResolvePickClampsToTheAllowedRange(): void
    {
        self::assertSame(6, $this->registry->resolvePick('MultiMulti', 6));
        self::assertSame(1, $this->registry->resolvePick('MultiMulti', 0));
        self::assertSame(10, $this->registry->resolvePick('MultiMulti', 99));
        self::assertSame(10, $this->registry->resolvePick('MultiMulti', null));
    }

    public function testResolvePickIgnoresRequestForFixedPickGames(): void
    {
        // --pick=3 dla Lotto nie moze zmienic zasad gry.
        self::assertSame(6, $this->registry->resolvePick('Lotto', 3));
        self::assertSame(5, $this->registry->resolvePick('MiniLotto', 12));
        self::assertSame(12, $this->registry->resolvePick('Kaskada', 2));
    }

    /**
     * Parametry potwierdzone wobec LOTTO OpenAPI (zakres liczb w numbers-frequency).
     */
    public function testRegistryMatchesVerifiedGameParameters(): void
    {
        $expected = [
            'MiniLotto' => [5, 42, 0], 'Lotto' => [6, 49, 0], 'LottoPlus' => [6, 49, 0],
            'EuroJackpot' => [5, 50, 12], 'MultiMulti' => [10, 80, 0],
            'EkstraPensja' => [5, 35, 4], 'EkstraPremia' => [5, 35, 4],
            'Kaskada' => [12, 24, 0], 'Keno' => [10, 70, 0], 'Szybkie600' => [6, 32, 0],
        ];

        foreach ($expected as $game => [$pick, $from, $extraFrom]) {
            $c = $this->registry->getGameConfig($game);
            self::assertSame($pick, $c['pick'], "$game: pick");
            self::assertSame($from, $c['from'], "$game: from");
            self::assertSame($extraFrom, $c['extra_from'], "$game: extra_from");
        }
    }

    public function testEveryGameHasAConsistentPickRange(): void
    {
        foreach ($this->registry->getGameNames() as $game) {
            $r = $this->registry->getPickRange($game);

            self::assertGreaterThanOrEqual(1, $r['min'], "$game: min");
            self::assertLessThanOrEqual($r['max'], $r['min'], "$game: min <= max");
            self::assertGreaterThanOrEqual($r['min'], $r['default'], "$game: default >= min");
            self::assertLessThanOrEqual($r['max'], $r['default'], "$game: default <= max");
            self::assertLessThanOrEqual(
                $this->registry->getGameConfig($game)['from'],
                $r['max'],
                "$game: nie da się skreślić więcej liczb, niż jest na bębnie"
            );
        }
    }
}
