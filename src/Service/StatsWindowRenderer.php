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

            $row = [
                '#' . ($i + 1),
                implode(', ', array_map(static fn(int $n): string => sprintf('%2d', $n), $item['bet'])),
                $fit['sum'],
                $fit['parity_ratio'],
                sprintf('%.1f', $fit['total_score']),
                $this->statusTag($i, (bool) $fit['is_gaussian_optimal']),
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

        $this->renderAffinitySource($io, $statsReport);
        $this->renderDilution($io, $statsReport, $poolSize);
        $this->renderBenchmark($io, $statsReport);
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
}
