<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Jedno miejsce, w którym tryb generatora (1-8) zamienia się w pakiet kuponów.
 *
 * Wcześniej ta sama logika istniała w DWÓCH kopiach — w LottoGeneratorCommand
 * i LottoTuiCommand (ok. 345 identycznych linii). Kopie zdążyły się rozjechać:
 * `--mode=8` działało tylko w jednej z nich, `--bankers` tylko w drugiej,
 * a każdą poprawkę trzeba było nanosić dwa razy.
 *
 * Klasa nie robi żadnego I/O i nie zadaje pytań — dostaje gotowy
 * BetPipelineRequest i zwraca BetPipelineResult wraz z ostrzeżeniami.
 */
class BetPipelineService
{
    public function __construct(
        private readonly BetGeneratorService $generatorService,
        private readonly StatisticalOptimizerService $statisticalOptimizer,
        private readonly ExtraNumbersGenerator $extraNumbersGenerator,
    ) {
    }

    /**
     * @throws \InvalidArgumentException gdy konfiguracja jest niewykonalna
     */
    public function run(BetPipelineRequest $request): BetPipelineResult
    {
        if ($request->betsTotal < 1) {
            throw new \InvalidArgumentException('Liczba zakładów musi być dodatnia.');
        }

        if (count($request->pool) < $request->pick()) {
            throw new \InvalidArgumentException(sprintf(
                'Pula (%d) jest mniejsza niż wymagana ilość do skreślenia (%d).',
                count($request->pool),
                $request->pick()
            ));
        }

        $warnings = [];
        $statsReport = null;

        [$blocks, $statsReport] = $this->buildBlocks($request);

        if ($blocks === []) {
            throw new \InvalidArgumentException('Brak danych do wygenerowania kuponów.');
        }

        $bets = $this->materialise($request, $blocks);

        if (count($bets) < $request->betsTotal) {
            $warnings[] = sprintf(
                "Zamówiono %d zakładów, a wygenerowano tylko %d.\n"
                . "Przyczyna: dostępna przestrzeń kombinacji dla tej puli/bloków jest mniejsza niż żądanie.\n"
                . 'Zwiększ pulę wejściową albo zmniejsz liczbę zakładów.',
                $request->betsTotal,
                count($bets)
            );
        }

        [$extraSets, $extraInfo] = $this->buildExtraNumbers($request, count($bets));

        return new BetPipelineResult($bets, $extraSets, $statsReport, $extraInfo, $warnings);
    }

    /**
     * @return array{0: array<int, mixed>, 1: array<string, mixed>|null}
     */
    private function buildBlocks(BetPipelineRequest $request): array
    {
        $pool = $request->pool;
        $pick = $request->pick();
        $maxNumber = $request->maxNumber();
        $options = ['draws' => $request->draws];

        switch ($request->mode) {
            case '8':
                $result = $this->statisticalOptimizer->optimizeBetsWithFullCoverage(
                    $pool,
                    $pick,
                    $request->betsTotal,
                    $request->frequencies,
                    $maxNumber,
                    $options
                );

                return [[['type' => 'precalc', 'bets' => $result['bets']]], $result['report']];

            case '7':
                // full_coverage domyślnie false => realny tryb koncentracji.
                $result = $this->statisticalOptimizer->optimizeBetsForDilution(
                    $pool,
                    $pick,
                    $request->betsTotal,
                    $request->frequencies,
                    $maxNumber,
                    $options
                );

                return [[['type' => 'precalc', 'bets' => $result['bets']]], $result['report']];

            case '6':
                return [[$this->rotatingBankers($request)], null];

            case '5':
                return [$this->fractalBlocks($request), null];

            case '4':
                return [[$this->fixedBankers($request)], null];

            case '3':
                return [[$this->weightedPool($request)], null];

            case '2':
                return [
                    $this->generatorService->generateSmartUniqueBlocks(
                        $pool,
                        min($request->blockSize, count($pool)),
                        max(1, $request->blockCount)
                    ),
                    null,
                ];

            default:
                return [[$pool], null];
        }
    }

    /**
     * @return array{type: string, bets: array<int, array<int>>}
     */
    private function rotatingBankers(BetPipelineRequest $request): array
    {
        $pick = $request->pick();
        $bankers = array_values(array_intersect($request->bankers, $request->pool));
        $perBet = $request->bankersPerBet;

        if ($perBet < 1 || $perBet >= $pick) {
            throw new \InvalidArgumentException(sprintf(
                'Liczba bankierów na kupon musi mieścić się w zakresie 1..%d.',
                $pick - 1
            ));
        }

        if (count($bankers) < $perBet) {
            throw new \InvalidArgumentException(sprintf(
                'Podano %d bankierów z puli, a na kupon potrzeba %d.',
                count($bankers),
                $perBet
            ));
        }

        $vars = array_values(array_diff($request->pool, $bankers));
        $slotsForVars = $pick - $perBet;

        if (count($vars) < $slotsForVars) {
            throw new \InvalidArgumentException('Zbyt mała pula zmiennych względem liczby skreśleń.');
        }

        sort($bankers);
        sort($vars);

        $bankerBets = $this->generatorService->generateBalancedShorthand($bankers, $perBet, $request->betsTotal);
        $varBets = $this->generatorService->generateBalancedShorthand($vars, $slotsForVars, $request->betsTotal);
        shuffle($varBets);

        // Zestawów bankierskich jest tylko C(bankierzy, perBet). Gdy podano
        // dokładnie tylu bankierów, ilu ma trafić na kupon, kombinacja jest
        // jedna. Wcześniejsze `min(count(bankerBets), count(varBets))` ucinało
        // wtedy cały pakiet do 1 zakładu, mimo że liczb zmiennych starczało na
        // komplet. Bankierów cyklujemy, a zmienne zużywamy pojedynczo.
        $bets = [];
        $bankerCount = count($bankerBets);
        $limit = min($request->betsTotal, count($varBets));

        for ($i = 0; $i < $limit; $i++) {
            $bet = array_merge($bankerBets[$i % $bankerCount], $varBets[$i]);
            sort($bet);
            if (count(array_unique($bet)) === $pick) {
                $bets[] = $bet;
            }
        }

        return ['type' => 'precalc', 'bets' => $bets];
    }

    /**
     * @return array{type: string, bankers: array<int>, vars: array<int>}
     */
    private function fixedBankers(BetPipelineRequest $request): array
    {
        $pick = $request->pick();
        $bankers = array_values(array_intersect($request->bankers, $request->pool));

        if ($bankers === []) {
            throw new \InvalidArgumentException('Nie podano żadnego bankiera należącego do puli wejściowej.');
        }

        if (count($bankers) >= $pick) {
            throw new \InvalidArgumentException(sprintf(
                'Podano %d bankierów, a gra przewiduje tylko %d skreśleń. Zostaw miejsce na liczby zmienne.',
                count($bankers),
                $pick
            ));
        }

        $vars = array_values(array_diff($request->pool, $bankers));
        if (count($vars) < ($pick - count($bankers))) {
            throw new \InvalidArgumentException('Zbyt mała pula zmiennych, aby uzupełnić kupony.');
        }

        sort($bankers);
        sort($vars);

        return ['type' => 'hybrid', 'bankers' => $bankers, 'vars' => $vars];
    }

    /**
     * @return array<int, array<int>>
     */
    private function fractalBlocks(BetPipelineRequest $request): array
    {
        $poolCount = count($request->pool);

        if ($request->l1Size > $poolCount) {
            throw new \InvalidArgumentException(sprintf(
                'Blok L1 (%d) jest większy niż pula (%d).',
                $request->l1Size,
                $poolCount
            ));
        }

        if ($request->l2Size > $request->l1Size) {
            throw new \InvalidArgumentException(sprintf(
                'Blok L2 (%d) jest większy niż blok L1 (%d).',
                $request->l2Size,
                $request->l1Size
            ));
        }

        $blocks = [];
        foreach ($this->generatorService->generateOverlappingBlocks($request->pool, $request->l1Count, $request->l1Size) as $parent) {
            foreach ($this->generatorService->generateOverlappingBlocks($parent, $request->l2Count, $request->l2Size) as $sub) {
                $blocks[] = $sub;
            }
        }

        return $blocks;
    }

    /**
     * @return array<int>
     */
    private function weightedPool(BetPipelineRequest $request): array
    {
        $weight = max(1, min(10, $request->weight));
        $urn = [];

        foreach ($request->pool as $n) {
            $repeat = in_array($n, $request->hotNumbers, true) ? $weight : 1;
            for ($k = 0; $k < $repeat; $k++) {
                $urn[] = $n;
            }
        }

        $selected = [];
        $target = count($request->pool);
        $guard = 0;

        while (count($selected) < $target && $guard++ < 100000) {
            shuffle($urn);
            $x = $urn[0];
            if (!in_array($x, $selected, true)) {
                $selected[] = $x;
            }
        }

        sort($selected);

        return $selected;
    }

    /**
     * Zamienia bloki robocze na finalny, ZDEDUPLIKOWANY pakiet kuponów.
     *
     * `betsTotal` oznacza tu zawsze liczbę ŁĄCZNĄ — limit jest dzielony między
     * bloki, a nie stosowany do każdego z osobna.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, array<int>>
     */
    private function materialise(BetPipelineRequest $request, array $blocks): array
    {
        $first = $blocks[0];
        $isPrecalc = is_array($first) && ($first['type'] ?? null) === 'precalc';
        $blockCount = max(1, count($blocks));
        $perBlock = $isPrecalc ? $request->betsTotal : (int) ceil($request->betsTotal / $blockCount);

        $bets = [];
        $seen = [];

        foreach ($blocks as $block) {
            $produced = $this->betsForBlock($request, $block, $perBlock);

            foreach ($produced as $bet) {
                $key = implode('-', $bet);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $bets[] = $bet;

                if (count($bets) >= $request->betsTotal) {
                    return $bets;
                }
            }
        }

        return $bets;
    }

    /**
     * @param mixed $block
     * @return array<int, array<int>>
     */
    private function betsForBlock(BetPipelineRequest $request, mixed $block, int $limit): array
    {
        if (is_array($block) && ($block['type'] ?? null) === 'precalc') {
            return $block['bets'];
        }

        if (is_array($block) && ($block['type'] ?? null) === 'hybrid') {
            $bankers = $block['bankers'];
            $needed = $request->pick() - count($bankers);
            $bets = [];

            foreach ($this->generatorService->generateBalancedShorthand($block['vars'], $needed, $limit) as $sub) {
                $merged = array_merge($bankers, $sub);
                sort($merged);
                $bets[] = $merged;
            }

            return $bets;
        }

        return $this->generatorService->generateBalancedShorthand($block, $request->pick(), $limit);
    }

    /**
     * @return array{0: array<int, array<int>>, 1: array<string, mixed>|null}
     */
    private function buildExtraNumbers(BetPipelineRequest $request, int $betCount): array
    {
        if ($request->extraCount() < 1 || $betCount < 1) {
            return [[], null];
        }

        $info = $this->extraNumbersGenerator->generate(
            $request->extraCount(),
            $request->extraFrom(),
            $betCount,
            $request->game['_special_frequencies'] ?? [],
            $request->game['_special_draws'] ?? []
        );

        return [$info['sets'], $info];
    }
}
