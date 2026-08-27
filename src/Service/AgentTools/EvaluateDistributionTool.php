<?php

declare(strict_types=1);

namespace App\Service\AgentTools;

use App\Service\GameRegistryService;
use App\Service\StatisticalOptimizerService;

class EvaluateDistributionTool implements LottoToolInterface
{
    public function __construct(
        private readonly GameRegistryService $gameRegistryService,
        private readonly StatisticalOptimizerService $optimizerService
    ) {
    }

    public function getName(): string
    {
        return 'evaluate_distribution';
    }

    public function getDescription(): string
    {
        return 'Ocenia, jakie SUMY będą miały losowe zakłady wybrane z podanej puli i jaka ich część '
            . 'trafi w prawdopodobny przedział sumy dla tej gry. Zwraca też rozkład dekadowy i luki w pokryciu bębna. '
            . 'Odpowiada na pytanie "czy z tej puli da się w ogóle ułożyć realistyczne zakłady", '
            . 'a nie tylko "jaka jest średnia puli".';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'game' => [
                    'type' => 'STRING',
                    'description' => 'Nazwa gry (np. Lotto, MiniLotto, EuroJackpot)',
                ],
                'pool' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'INTEGER'],
                    'description' => 'Proponowana pula liczb do analizy rozkładu',
                ],
            ],
            'required' => ['game', 'pool'],
        ];
    }

    public function execute(array $args): string
    {
        $game = $args['game'] ?? 'Lotto';
        $pool = $args['pool'] ?? [];

        if (!$this->gameRegistryService->isValidGame($game)) {
            return json_encode(['error' => "Nieprawidłowa nazwa gry: $game"]);
        }

        if (!is_array($pool) || $pool === []) {
            return json_encode(['error' => 'Podana pula liczb jest pusta lub nieprawidłowa']);
        }

        $config = $this->gameRegistryService->getGameConfig($game);
        $maxNum = $config['from'] ?? 49;
        $pick = $config['pick'] ?? 6;

        $pool = array_values(array_unique(array_map('intval', $pool)));
        $pool = array_values(array_filter($pool, static fn(int $n): bool => $n >= 1 && $n <= $maxNum));
        sort($pool);

        $poolSize = count($pool);
        if ($poolSize < $pick) {
            return json_encode([
                'error' => sprintf('Pula (%d liczb) jest mniejsza niż liczba skreśleń (%d).', $poolSize, $pick),
            ]);
        }

        // Przedział prawdopodobnej sumy dla CAŁEJ gry — jedno źródło prawdy,
        // ten sam wzór, którego używa optymalizator.
        $gauss = $this->optimizerService->calculateGaussianParameters($maxNum, $pick);

        // Rozkład sumy losowego podzbioru wybranego Z TEJ PULI.
        // To jest sedno poprawki: poprzednia wersja liczyła poolAvg * pick, czyli
        // DOKŁADNIE średnią rozkładu, więc dla każdej symetrycznej puli (np. "all")
        // wynik zawsze wypadał "optymalnie". Teraz liczymy też ROZRZUT, dzięki
        // czemu da się powiedzieć, JAKA CZĘŚĆ zakładów z tej puli będzie realistyczna.
        $subset = $this->subsetSumDistribution($pool, $pick);

        $shareInRange = $this->shareWithinRange(
            $subset['mean'],
            $subset['std_dev'],
            (float) $gauss['optimal_min'],
            (float) $gauss['optimal_max']
        );

        // Punkt odniesienia: ile daje gra bez zawężania puli (~82% dla ±1.35σ).
        $baselineShare = $this->shareWithinRange(
            (float) $gauss['expected_sum'],
            (float) $gauss['std_dev'],
            (float) $gauss['optimal_min'],
            (float) $gauss['optimal_max']
        );

        $decades = $this->decadeBreakdown($pool, $maxNum);
        $decadesAvailable = max(1, (int) ceil($maxNum / 10));
        $maxPerDecade = $this->optimizerService->maxPerDecade($pick, $maxNum);

        // Czy limit zagęszczenia dekadowego w ogóle pozwala ułożyć zakład z tej puli.
        $decadeCapacity = 0;
        foreach ($decades as $info) {
            $decadeCapacity += min($info['count'], $maxPerDecade);
        }

        $meanShift = $subset['mean'] - (float) $gauss['expected_sum'];
        $shiftInSigmas = $gauss['std_dev'] > 0 ? $meanShift / (float) $gauss['std_dev'] : 0.0;

        return json_encode([
            'game' => $game,
            'pool_size' => $poolSize,
            'pick' => $pick,

            'game_sum_profile' => [
                'expected_sum' => $gauss['expected_sum'],
                'std_dev' => $gauss['std_dev'],
                'plausible_range' => $gauss['optimal_range_str'],
                'note' => 'Przedział ±1.35σ liczony przez calculateGaussianParameters (bez wartości wpisanych na sztywno).',
            ],

            'pool_subset_sum_profile' => [
                'mean' => round($subset['mean'], 1),
                'std_dev' => round($subset['std_dev'], 2),
                'min_possible' => $subset['min'],
                'max_possible' => $subset['max'],
                'shift_from_game_mean' => round($meanShift, 1),
                'shift_in_sigmas' => round($shiftInSigmas, 2),
            ],

            // To jest metryka, która niesie informację — w przeciwieństwie do
            // poprzedniego "is_sum_within_gaussian_bell", zawsze prawdziwego.
            'pct_of_random_bets_in_plausible_range' => round($shareInRange * 100, 1),
            'baseline_pct_for_full_wheel' => round($baselineShare * 100, 1),
            'verdict' => $this->verdict($shareInRange, $baselineShare, $shiftInSigmas),

            'decade_distribution' => array_map(static fn(array $d): int => $d['count'], $decades),
            'decades_covered' => count(array_filter($decades, static fn(array $d): bool => $d['count'] > 0)),
            'decades_available' => $decadesAvailable,
            'empty_decades' => array_keys(array_filter($decades, static fn(array $d): bool => $d['count'] === 0)),
            'max_numbers_per_decade_allowed' => $maxPerDecade,
            'decade_capacity_vs_pick' => sprintf('%d / %d', $decadeCapacity, $pick),
            'decade_limit_blocks_generation' => $decadeCapacity < $pick,
        ]);
    }

    /**
     * Średnia i odchylenie sumy $pick liczb losowanych BEZ ZWRACANIA z podanej puli.
     *
     * Var = k * σ²_pop(pula) * (N - k) / (N - 1)   — poprawka na populację skończoną.
     *
     * @param array<int> $pool
     * @return array{mean: float, std_dev: float, min: int, max: int}
     */
    private function subsetSumDistribution(array $pool, int $pick): array
    {
        $n = count($pool);
        $mean = array_sum($pool) / $n;

        $variancePop = 0.0;
        foreach ($pool as $value) {
            $variancePop += ($value - $mean) ** 2;
        }
        $variancePop /= $n;

        $subsetVariance = $n > 1
            ? $pick * $variancePop * (($n - $pick) / ($n - 1))
            : 0.0;

        $sorted = $pool;
        sort($sorted);

        return [
            'mean' => $pick * $mean,
            'std_dev' => sqrt(max(0.0, $subsetVariance)),
            'min' => (int) array_sum(array_slice($sorted, 0, $pick)),
            'max' => (int) array_sum(array_slice($sorted, -$pick)),
        ];
    }

    /**
     * Przybliżony udział masy prawdopodobieństwa w przedziale [$lo, $hi]
     * dla rozkładu normalnego o zadanej średniej i odchyleniu.
     */
    private function shareWithinRange(float $mean, float $stdDev, float $lo, float $hi): float
    {
        if ($stdDev <= 0.0) {
            return ($mean >= $lo && $mean <= $hi) ? 1.0 : 0.0;
        }

        return max(0.0, min(1.0, $this->normalCdf(($hi - $mean) / $stdDev) - $this->normalCdf(($lo - $mean) / $stdDev)));
    }

    /**
     * Dystrybuanta rozkładu normalnego standardowego (przez erf).
     */
    private function normalCdf(float $z): float
    {
        return 0.5 * (1.0 + $this->erf($z / M_SQRT2));
    }

    /**
     * Przybliżenie Abramowitza-Steguna 7.1.26 (błąd < 1.5e-7).
     */
    private function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);

        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $poly = 0.254829592 * $t
            - 0.284496736 * $t ** 2
            + 1.421413741 * $t ** 3
            - 1.453152027 * $t ** 4
            + 1.061405429 * $t ** 5;

        return $sign * (1.0 - $poly * exp(-$x * $x));
    }

    private function verdict(float $share, float $baseline, float $shiftInSigmas): string
    {
        if (abs($shiftInSigmas) > 1.0) {
            return sprintf(
                'Pula jest przesunięta o %.2f sigma względem środka gry — losowe zakłady z niej '
                . 'będą systematycznie miały za %s sumy.',
                $shiftInSigmas,
                $shiftInSigmas > 0 ? 'wysokie' : 'niskie'
            );
        }

        if ($share >= $baseline - 0.05) {
            return 'Pula zachowuje się jak pełny bęben: losowy zakład z niej ma normalną szansę '
                . 'trafić w prawdopodobny przedział sumy.';
        }

        if ($share >= 0.5) {
            return 'Pula jest zauważalnie węższa niż bęben — część losowych zakładów wypadnie poza '
                . 'prawdopodobny przedział sumy. Generator będzie musiał je odrzucać.';
        }

        return 'Pula jest mocno zawężona: większość losowych zakładów wypadnie poza prawdopodobny '
            . 'przedział sumy. Rozważ dobranie liczb z brakujących dekad.';
    }

    /**
     * @param array<int> $pool
     * @return array<string, array{count: int}>
     */
    private function decadeBreakdown(array $pool, int $maxNum): array
    {
        $decades = [];
        $decadesAvailable = (int) ceil($maxNum / 10);

        for ($i = 0; $i < $decadesAvailable; $i++) {
            $start = $i * 10 + 1;
            $end = min(($i + 1) * 10, $maxNum);
            $decades[sprintf('%d-%d', $start, $end)] = ['count' => 0];
        }

        foreach ($pool as $num) {
            $i = (int) floor(($num - 1) / 10);
            $start = $i * 10 + 1;
            $end = min(($i + 1) * 10, $maxNum);
            $key = sprintf('%d-%d', $start, $end);
            $decades[$key]['count'] = ($decades[$key]['count'] ?? 0) + 1;
        }

        return $decades;
    }
}
