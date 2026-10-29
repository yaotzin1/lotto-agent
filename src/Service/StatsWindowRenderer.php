<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renderowanie pakietu kuponów i "Okna Statystycznego".
 *
 * Wcześniej ten sam kod istniał w dwóch identycznych kopiach — w
 * LottoGeneratorCommand i LottoTuiCommand (79 linii, zero różnic).
 * Ostatnia pozycja z E1: po wydzieleniu BetPipelineService zostało już tylko
 * renderowanie.
 *
 * Uwaga metodologiczna dotycząca benchmarku jest tu drukowana ZAWSZE, nie tylko
 * w app:lotto-stats — wcześniej dwie z trzech komend pokazywały "przewagę"
 * bez informacji, że to miara samozwrotna.
 */
class StatsWindowRenderer
{
    /**
     * Domyślnie pokazujemy WSZYSTKIE kupony.
     *
     * Wcześniej tabela była cicho przycinana do 30 wierszy — przy `--bets=50`
     * użytkownik widział 30 kuponów, mimo że zapłaciłby za 50 i musi je
     * przepisać na blankiet. Przycięcie jest teraz świadomym wyborem
     * (`--max-rows`), a nie domyślnym zachowaniem.
     */
    public const SHOW_ALL = 0;

    /**
     * Tabela wygenerowanych kuponów — z rankingiem synergii, jeśli jest dostępny.
     *
     * @param array<int, array<int>> $bets
     * @param array<int, array<int>> $extraSets Liczby dodatkowe, wyrównane indeksem do $bets
     * @param array<string, mixed>|null $statsReport
     */
    public function renderBets(
        SymfonyStyle $io,
        array $bets,
        array $extraSets = [],
        ?array $statsReport = null,
        int $maxRows = self::SHOW_ALL
    ): void {
        $hasExtra = $extraSets !== [];

        if ($statsReport !== null && isset($statsReport['ranked_bets'])) {
            $this->renderRankedBets($io, $statsReport['ranked_bets'], $extraSets, $maxRows);

            return;
        }

        $rows = [];
        foreach ($bets as $i => $bet) {
            $row = ['Zakład ' . ($i + 1), implode(', ', $bet)];
            if ($hasExtra) {
                $row[] = implode(', ', $extraSets[$i] ?? []);
            }
            $rows[] = $row;
        }

        $io->table($hasExtra ? ['L.p.', 'Liczby', 'Dodatkowe'] : ['L.p.', 'Liczby'], $rows);
    }

    /**
     * @param array<int, array{bet: array<int>, fitness: array<string, mixed>}> $rankedBets
     * @param array<int, array<int>> $extraSets
     */
    private function renderRankedBets(SymfonyStyle $io, array $rankedBets, array $extraSets, int $maxRows): void
    {
        $hasExtra = $extraSets !== [];
        $rows = [];

        foreach ($rankedBets as $i => $item) {
            $fit = $item['fitness'];

            $status = isset($fit['tier_summary'])
                ? $this->tieredStatusTag($i, $fit)
                : $this->statusTag($i, (bool) $fit['is_gaussian_optimal']);

            $scoreLabel = sprintf('%.1f', $fit['total_score']);
            if (isset($fit['tier_summary'])) {
                $scoreLabel .= sprintf(' (%s)', $fit['tier_summary']);
            }

            $row = [
                '#' . ($i + 1),
                implode(', ', array_map(static fn(int $n): string => sprintf('%2d', $n), $item['bet'])),
                $fit['sum'],
                $fit['parity_ratio'],
                $scoreLabel,
                $status,
            ];

            if ($hasExtra) {
                array_splice($row, 2, 0, [implode(', ', $extraSets[$i] ?? [])]);
            }

            $rows[] = $row;
        }

        $headers = ['Ranking', 'Liczby', 'Suma', 'Parz (N:P)', 'Synergy Score', 'Profil / Status'];
        if ($hasExtra) {
            array_splice($headers, 2, 0, ['Dodatkowe']);
        }

        $visible = ($maxRows > 0 && count($rows) > $maxRows)
            ? array_slice($rows, 0, $maxRows)
            : $rows;

        $io->table($headers, $visible);

        if (count($visible) < count($rows)) {
            $io->note(sprintf(
                'Wyświetlono %d z %d zakładów (limit --max-rows). Pozostałe %d są częścią pakietu, '
                . 'ale nie widać ich powyżej — uruchom bez --max-rows, aby zobaczyć wszystkie.',
                count($visible),
                count($rows),
                count($rows) - count($visible)
            ));
        }
    }

    private function tieredStatusTag(int $index, array $fit): string
    {
        $profile = $fit['tier_profile'] ?? 'PROFIL';
        $t1 = $fit['tier1_count'] ?? 0;

        if ($t1 >= 3) {
            return sprintf('<fg=yellow;options=bold>[★ %s]</>', $profile);
        }

        if ($t1 === 2) {
            return sprintf('<fg=cyan>[⚡ %s]</>', $profile);
        }

        if ($t1 === 1) {
            return sprintf('<fg=green>[🌱 %s]</>', $profile);
        }

        return sprintf('<fg=gray>[❄️ %s]</>', $profile);
    }

    private function statusTag(int $index, bool $isGaussianOptimal): string
    {
        if ($index === 0) {
            return '<fg=yellow;options=bold>[★ TOP SYNERGIA]</>';
        }

        if ($index < 3) {
            return '<fg=cyan>[Mocna Synergia]</>';
        }

        return $isGaussianOptimal
            ? '<fg=green>[Optimum Gaussa]</>'
            : '<fg=gray>[Pokrycie Puli]</>';
    }

    /**
     * Okno Statystyczne: rozwodnienie + benchmark jakości.
     *
     * @param array<string, mixed> $statsReport
     */
    public function renderStatsWindow(SymfonyStyle $io, array $statsReport, int $poolSize): void
    {
        $io->section('📊 OKNO STATYSTYCZNE: PROFIL ZESTAWU I ROZWODNIENIE');

        if (isset($statsReport['tiers'])) {
            $this->renderTierCascade($io, $statsReport['tiers']);
        }

        $this->renderAffinitySource($io, $statsReport);
        $this->renderDilution($io, $statsReport, $poolSize);
        $this->renderBenchmark($io, $statsReport);
    }

    /**
     * @param array<string, mixed> $tiers
     */
    private function renderTierCascade(SymfonyStyle $io, array $tiers): void
    {
        $t1 = $tiers['tier1'] ?? [];
        $t2 = $tiers['tier2'] ?? [];
        $t3 = $tiers['tier3'] ?? [];
        $anchors = $tiers['anchors'] ?? [];
        $nbrs = $tiers['neighbours'] ?? [];

        $io->text(sprintf(
            '🔥 <fg=yellow;options=bold>TIER 1: Pula Silna (%d liczb)</> -> [%s]',
            count($t1),
            implode(', ', $t1)
        ));
        if (!empty($anchors)) {
            $io->text(sprintf('   • Kotwice (ostatnie losowanie): [%s]', implode(', ', $anchors)));
        }
        if (!empty($nbrs)) {
            $io->text(sprintf('   • Bezpośredni sąsiedzi ±1: [%s]', implode(', ', $nbrs)));
        }

        $io->text(sprintf(
            '⚡ <fg=cyan;options=bold>TIER 2: Pula Średnia / Bufor (%d liczb)</> -> [%s]',
            count($t2),
            implode(', ', $t2)
        ));

        if (!empty($t3)) {
            $io->text(sprintf(
                '❄️ <fg=gray;options=bold>TIER 3: Domknięcie Bębna / Uśpione (%d liczb)</> -> [%s]',
                count($t3),
                implode(', ', $t3)
            ));
        }

        if (isset($tiers['avg_tier1'], $tiers['avg_tier2'], $tiers['avg_tier3'])) {
            $io->text(sprintf(
                'Średni skład kuponu: <fg=yellow>Tier 1: %.1f</> | <fg=cyan>Tier 2: %.1f</> | <fg=gray>Tier 3: %.1f</>',
                $tiers['avg_tier1'],
                $tiers['avg_tier2'],
                $tiers['avg_tier3']
            ));
        }
        $io->newLine();
    }

    /**
     * @param array<string, mixed> $statsReport
     */
    private function renderAffinitySource(SymfonyStyle $io, array $statsReport): void
    {
        $source = $statsReport['affinity_source'] ?? null;
        if ($source === null) {
            return;
        }

        if ($source === StatisticalOptimizerService::AFFINITY_SOURCE_CO_OCCURRENCE) {
            $io->text(sprintf(
                '<fg=green>Macierz par: RZECZYWISTE współwystępowanie z %d losowań.</>',
                $statsReport['affinity_draws_used'] ?? 0
            ));

            return;
        }

        $io->text(
            "<fg=yellow>Macierz par: heurystyka częstotliwościowa (brak historii losowań).</>\n"
            . '<fg=yellow>To NIE jest statystyka par — "najsilniejsza para" to dwie najgorętsze liczby.</>'
        );
    }

    /**
     * @param array<string, mixed> $statsReport
     */
    private function renderDilution(SymfonyStyle $io, array $statsReport, int $poolSize): void
    {
        $dm = $statsReport['dilution_metrics'];
        $used = $statsReport['unique_numbers_used'] ?? $poolSize;

        $covStatus = ($statsReport['is_full_coverage_guaranteed'] ?? false)
            ? '<fg=green;options=bold>[100% POKRYCIA PULI (Zero Drop)]</>'
            : sprintf('<fg=yellow>[CZĘŚCIOWE: %d/%d liczb]</>', $used, $poolSize);

        $io->table(
            ['Metryka', 'Wartość'],
            [
                ['Liczba liczb w Puli', $poolSize],
                [
                    'Pokrycie Puli Wejściowej',
                    sprintf('%d z %d liczb (%.1f%%) %s', $used, $poolSize, $statsReport['pool_coverage_pct'] ?? 100.0, $covStatus),
                ],
                ['Kombinacje w wybranej puli C(N, k)', number_format($dm['pool_combinations_total'], 0, ',', ' ')],
                ['Współczynnik Rozwodnienia', sprintf('%s (%s%%)', $dm['dilution_ratio_str'], $dm['dilution_factor_pct'])],
                ['Średnie użycie liczby', sprintf('%.2f razy', $dm['avg_repeats_per_number'])],
                [
                    'Pokrycie unikalnych par',
                    sprintf('%d z %d (%.1f%%)', $statsReport['unique_pairs_covered'], $statsReport['unique_pairs_total_in_pool'], $statsReport['pairs_coverage_pct']),
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $statsReport
     */
    private function renderBenchmark(SymfonyStyle $io, array $statsReport): void
    {
        $bench = $statsReport['benchmark'];

        if ($bench['metric_is_self_referential'] ?? false) {
            $io->text(
                "<fg=yellow>UWAGA:</> baseline losowy jest oceniany TĄ SAMĄ funkcją celu, którą\n"
                . "optymalizator maksymalizuje. Poniższa \"przewaga\" nie jest miarą szansy\n"
                . 'na wygraną ani oczekiwanego zwrotu z gry.'
            );
        }

        $io->table(
            ['Wskaźnik', 'Optymalizator', 'Losowy Baseline', 'Przewaga'],
            [
                [
                    'Średni Synergy Score',
                    sprintf('%.1f', $bench['optimized_avg_synergy_score']),
                    sprintf('%.1f', $bench['random_baseline_avg_score']),
                    sprintf('%+.1f%%', $bench['synergy_advantage_percent']),
                ],
                [
                    'Zgodność z Gaussa (Sumy)',
                    sprintf('%.1f%%', $bench['optimized_gaussian_adherence_pct']),
                    sprintf('%.1f%%', $bench['random_gaussian_adherence_pct']),
                    sprintf('%+.1f pp', $bench['optimized_gaussian_adherence_pct'] - $bench['random_gaussian_adherence_pct']),
                ],
                [
                    'Balans Parzystości',
                    sprintf('%.1f%%', $bench['optimized_parity_balance_pct']),
                    sprintf('%.1f%%', $bench['random_parity_balance_pct']),
                    sprintf('%+.1f pp', $bench['optimized_parity_balance_pct'] - $bench['random_parity_balance_pct']),
                ],
            ]
        );
    }

    /**
     * Renderuje wizualną mapę cieplną bębna (1..maxNumber) z oznaczeniem kotwic,
     * liczb w puli oraz krotności ich użycia w wygenerowanych kuponach.
     *
     * @param array<int, array<int>> $bets
     * @param array<int> $pool
     * @param array<int> $anchors
     */
    public function renderDrumHeatmap(
        SymfonyStyle $io,
        array $bets,
        array $pool,
        int $maxNumber,
        array $anchors = []
    ): void {
        if ($maxNumber < 1 || $bets === []) {
            return;
        }

        $io->section(sprintf('🎯 WIZUALNA MAPA BĘBNA (1..%d) — POKRYCIE I KOTWICE', $maxNumber));

        $inPool = array_fill_keys(array_map('intval', $pool), true);
        $isAnchor = array_fill_keys(array_map('intval', $anchors), true);
        $usage = array_fill(1, $maxNumber, 0);

        foreach ($bets as $bet) {
            foreach ($bet as $n) {
                $n = (int) $n;
                if ($n >= 1 && $n <= $maxNumber) {
                    $usage[$n]++;
                }
            }
        }

        $maxUsage = max($usage);
        $highThreshold = max(2, (int) ceil($maxUsage * 0.65));

        $rows = [];
        $columnsPerRow = 10;
        $totalRows = (int) ceil($maxNumber / $columnsPerRow);

        for ($r = 0; $r < $totalRows; $r++) {
            $startNum = ($r * $columnsPerRow) + 1;
            $endNum = min($maxNumber, ($r + 1) * $columnsPerRow);
            $decadeLabel = sprintf('%02d-%02d', $startNum, $endNum);
            $cells = [$decadeLabel];

            for ($col = 0; $col < $columnsPerRow; $col++) {
                $num = $startNum + $col;
                if ($num > $maxNumber) {
                    $cells[] = '';
                    continue;
                }

                $cnt = $usage[$num];
                $anchorMark = isset($isAnchor[$num]) ? '★' : ' ';

                if (!isset($inPool[$num]) && $cnt === 0) {
                    $cells[] = sprintf('<fg=gray> ·%02d·  </>', $num);
                } elseif ($cnt === 0) {
                    $cells[] = sprintf('<fg=red>%s%02d(0x)</>', $anchorMark, $num);
                } elseif (isset($isAnchor[$num])) {
                    $cells[] = sprintf('<fg=yellow;options=bold>★%02d(%dx)</>', $num, $cnt);
                } elseif ($cnt >= $highThreshold) {
                    $cells[] = sprintf('<fg=cyan;options=bold> %02d(%dx)</>', $num, $cnt);
                } else {
                    $cells[] = sprintf('<fg=green> %02d(%dx)</>', $num, $cnt);
                }
            }

            $rows[] = $cells;
        }

        $headers = ['Dekada', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
        $io->table($headers, $rows);
        $io->text(
            'Legenda: <fg=yellow;options=bold>★XX(Nx)</> Kotwica ostatniego losowania | '
            . '<fg=cyan;options=bold>XX(Nx)</> Wysoka koncentracja | '
            . '<fg=green>XX(Nx)</> W grze | '
            . '<fg=gray>·XX·</> Poza pulą'
        );
        $io->newLine();
    }

    /**
     * Renderuje pogłębioną analizę statystyczną: rozkład Gaussa (histogram ASCII),
     * Top 10 Par, Top 10 Trójek (3-Cliques) oraz pokrycie podzbiorów t-Design.
     *
     * @param array<string, mixed>|null $statsReport
     * @param array<string, mixed>|null $subsetsCoverage
     */
    public function renderDeepSynergyAndGaussianDetails(
        SymfonyStyle $io,
        ?array $statsReport,
        ?array $subsetsCoverage = null
    ): void {
        $io->section('📈 SZCZEGÓŁOWA ANALIZA GAUSSA, PAR, TRÓJEK (3-CLIQUES) I T-DESIGN');

        if ($subsetsCoverage !== null) {
            $io->table(
                ['Podzbiory Kombinatoryczne (Wheeling t-Design)', 'Pokryte w Kuponach', 'Wszystkie w Puli', 'Pokrycie %'],
                [
                    [
                        'Pary (2-subsets, t=2)',
                        $subsetsCoverage['pairs_covered'],
                        $subsetsCoverage['pairs_total'],
                        sprintf('%.2f%%', $subsetsCoverage['pairs_coverage_pct']),
                    ],
                    [
                        'Trójki (3-subsets, t=3 — baza gwarancji 3/k)',
                        $subsetsCoverage['triplets_covered'],
                        $subsetsCoverage['triplets_total'],
                        sprintf('%.2f%%', $subsetsCoverage['triplets_coverage_pct']),
                    ],
                ]
            );
        }

        if ($statsReport === null) {
            return;
        }

        if (isset($statsReport['gaussian_analysis']['histogram_rows'])) {
            $ga = $statsReport['gaussian_analysis'];
            $io->text(sprintf(
                'Rozkład sum kuponów: min=<fg=cyan>%d</>, max=<fg=cyan>%d</>, średnia=<fg=yellow;options=bold>%.1f</> (oczekiwana Gaussa: <fg=green>%d</>, przedział optymalny: <fg=green>%s</>)',
                $ga['min_sum'],
                $ga['max_sum'],
                $ga['avg_sum'],
                $ga['expected_sum'],
                $ga['optimal_range']
            ));

            $histRows = [];
            foreach ($ga['histogram_rows'] as $row) {
                $histRows[] = [
                    $row['range'],
                    $row['count'],
                    $row['bar'],
                    $row['tag'],
                ];
            }
            if ($histRows !== []) {
                $io->table(['Przedział Sumy', 'Kupony', 'Histogram', 'Strefa'], $histRows);
            }
        }

        if (!empty($statsReport['top_pairs'])) {
            $pairRows = [];
            foreach ($statsReport['top_pairs'] as $idx => $p) {
                $pairRows[] = [
                    '#' . ($idx + 1),
                    implode(' + ', $p['pair']),
                    $p['affinity'],
                    $p['bets_included'] . 'x',
                ];
            }
            $io->table(['Rank', 'Para Liczb', 'Affinity Score (EMA)', 'Wystąpienia w pakiecie'], $pairRows);
        }

        if (!empty($statsReport['top_triplets'])) {
            $tripletRows = [];
            foreach ($statsReport['top_triplets'] as $idx => $t) {
                $tripletRows[] = [
                    '#' . ($idx + 1),
                    implode(' + ', $t['triplet']),
                    sprintf('%.1f', $t['synergy']),
                    $t['bets_included'] . 'x',
                ];
            }
            $io->table(['Rank', 'Trójka Liczb (3-Clique)', 'Synergia Trójki (EMA)', 'Wystąpienia w pakiecie'], $tripletRows);
        }
    }
}
