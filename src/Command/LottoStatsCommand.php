<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GameRegistryService;
use App\Service\GeminiApiClient;
use App\Service\LottoApiClient;
use App\Service\HistoricalDataProvider;
use App\Service\StatisticalOptimizerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lotto-stats',
    description: 'Okno Statystyczne i Optymalizator Synergii dla Dużych Pul (Rozwodnienie) zintegrowany z LOTTO OpenAPI i AI',
)]
class LottoStatsCommand extends Command
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly StatisticalOptimizerService $optimizerService,
        private readonly HistoricalDataProvider $historicalDataProvider,
        private readonly GeminiApiClient $geminiApiClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'Typ gry (np. MiniLotto, Lotto, EuroJackpot, MultiMulti)');
        $this->addOption('pool', 'p', InputOption::VALUE_REQUIRED, 'Pula liczb (np. "1-42", "all", "1,5,10,15,20,25,30")');
        $this->addOption('bets', 'b', InputOption::VALUE_REQUIRED, 'Liczba zakładów do wygenerowania (np. 100 lub 25)');
        $this->addOption('sessions', 's', InputOption::VALUE_REQUIRED, 'Liczba ostatnich losowań do pobrania statystyk', '50');
        $this->addOption('months', 'mo', InputOption::VALUE_REQUIRED, 'Liczba miesięcy do pobrania statystyk');
        $this->addOption('full-coverage', null, InputOption::VALUE_OPTIONAL, 'Wymuś 100% pokrycie puli wejściowej (true/false)', 'true');
        $this->addOption('ai', null, InputOption::VALUE_NONE, 'Dołącz strategiczną analizę i komentarz AI (Google Gemini)');
        $this->addOption('json-output', 'j', InputOption::VALUE_NONE, 'Zwróć wynik w formacie JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isJson = (bool)$input->getOption('json-output');

        if (!$isJson) {
            $io->title("========================================================\n||   OKNO STATYSTYCZNE & OPTYMALIZATOR ROZWODNIENIA   ||\n========================================================");
        }

        // 1. Wybór gry
        $gameType = $input->getOption('game');
        if (!$gameType || !$this->gameRegistryService->isValidGame($gameType)) {
            $gameType = $io->choice(
                'Wybierz grę do analizy statystycznej',
                $this->gameRegistryService->getGameNames(),
                'MiniLotto'
            );
        }

        $gameConfig = $this->gameRegistryService->getGameConfig($gameType);
        $maxNumber = $gameConfig['from'] ?? 49;
        $pick = $gameConfig['pick'] ?? 6;

        if ($gameType === 'MultiMulti' && !$input->getOption('pool')) {
            $pickSize = (int)$io->choice('Ile liczb chcesz skreślać na jednym zakładzie (Multi Multi)?', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'], '10');
            $pick = $pickSize;
        }

        $sessions = (int)($input->getOption('sessions') ?: 50);
        $monthsOpt = $input->getOption('months');
        $months = $monthsOpt !== null ? (int)$monthsOpt : null;

        $dateFrom = $this->gameRegistryService->calculateDateFromForSessions($gameType, $sessions, $months ?: 6);

        if (!$isJson) {
            $io->text(sprintf("📡 Łączenie z LOTTO OpenAPI (developers.lotto.pl) dla gry <fg=yellow;options=bold>%s</>...", $gameType));
        }

        $history = $this->historicalDataProvider->fetch($gameType, $sessions, $months);
        $stats = [
            'date_from' => $history['date_from'],
            'date_to' => $history['date_to'],
            'sorted_by_freq_desc' => $history['frequencies'],
        ];
        $frequencies = $history['frequencies'];
        $draws = $history['draws'];
        $totalDrawsAnalyzed = $history['draws_analyzed'] ?: $sessions;

        if (!$history['ok'] && !$isJson) {
            $io->warning($history['warning']);
            $io->note(
                "Raport zostanie wygenerowany, ale WSZYSTKIE wskaźniki oparte na historii "
                . "(hot/cold, affinity, ranking synergii) są w tym stanie bezwartościowe."
            );
        } elseif ($history['warning'] !== null && !$isJson) {
            $io->note($history['warning']);
        }

        // 2. Określenie puli
        $poolOpt = $input->getOption('pool');
        $fullPool = [];

        if ($poolOpt) {
            if (strtolower(trim($poolOpt)) === 'all' || trim($poolOpt) === "1-{$maxNumber}") {
                $fullPool = range(1, $maxNumber);
            } else {
                preg_match_all('/\d+/', $poolOpt, $matches);
                $fullPool = array_unique(array_map('intval', $matches[0] ?? []));
                $fullPool = array_values(array_filter($fullPool, fn($n) => $n >= 1 && $n <= $maxNumber));
            }
        } elseif ($input->isInteractive() && !$isJson) {
            $poolChoice = $io->choice(
                "Jaką pulę liczb dla gry {$gameType} (losujemy {$pick} z {$maxNumber}) chcesz przeanalizować?",
                [
                    'all' => "Pełna pula (Wszystkie {$maxNumber} liczb: zakres 1..{$maxNumber})",
                    'custom' => "Własna pula (np. 20, 25 lub 30 liczb)",
                ],
                'all'
            );

            if ($poolChoice === 'all') {
                $fullPool = range(1, $maxNumber);
            } else {
                $poolStr = $io->ask(sprintf('Podaj liczby z zakresu 1-%d oddzielone spacją lub przecinkiem:', $maxNumber));
                preg_match_all('/\d+/', (string)$poolStr, $matches);
                $fullPool = array_unique(array_map('intval', $matches[0] ?? []));
                $fullPool = array_values(array_filter($fullPool, fn($n) => $n >= 1 && $n <= $maxNumber));
            }
        } else {
            $fullPool = range(1, $maxNumber);
        }

        sort($fullPool);

        if (count($fullPool) < $pick) {
            if ($isJson) {
                $output->writeln(json_encode(['error' => "Pula wejściowa jest mniejsza niż $pick liczb."]));
            } else {
                $io->error("Pula wejściowa (" . count($fullPool) . " liczb) jest mniejsza niż wymagana ilość do skreślenia ($pick)!");
            }
            return Command::FAILURE;
        }

        $defaultBets = count($fullPool) >= 40 ? 100 : 25;
        $betsCount = $input->getOption('bets') ? (int)$input->getOption('bets') : null;
        if ($betsCount === null) {
            if ($input->isInteractive() && !$isJson) {
                $betsCount = (int)$io->ask("Ile zakładów chcesz wygenerować z puli " . count($fullPool) . " liczb?", (string)$defaultBets);
            } else {
                $betsCount = $defaultBets;
            }
        }

        $useAi = (bool)$input->getOption('ai');
        if (!$input->hasParameterOption('--ai') && $input->isInteractive() && !$isJson) {
            $useAi = $io->confirm('Czy chcesz dołączyć analizę i komentarz strategiczny Analityka AI (Google Gemini)?', true);
        }

        if (!$isJson) {
            $io->text("Przetwarzanie statystyk, macierzy współwystępowania oraz optymalizacja heurystyczna...");
        }

        $fullCoverageOpt = $input->getOption('full-coverage');
        $fullCoverage = ($fullCoverageOpt === null || filter_var($fullCoverageOpt, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false);

        $optimizationResult = $this->optimizerService->optimizeBetsForDilution(
            $fullPool,
            $pick,
            $betsCount,
            $frequencies,
            $maxNumber,
            ['full_coverage' => $fullCoverage, 'draws' => $draws]
        );

        $bets = $optimizationResult['bets'];
        $report = $optimizationResult['report'];

        // Komentarz AI (opcjonalny)
        $aiAnalysis = '';
        if ($useAi) {
            if (!$isJson) {
                $io->text("🧠 Generowanie komentarza strategicznego przez Google Gemini...");
            }
            try {
                $hotTop = array_slice($frequencies, 0, 5, true);
                $coldTop = array_slice(array_reverse($frequencies, true), 0, 5, true);
                $hotStr = implode(', ', array_map(fn($num, $cnt) => "$num ({$cnt}x)", array_keys($hotTop), $hotTop));
                $coldStr = implode(', ', array_map(fn($num, $cnt) => "$num ({$cnt}x)", array_keys($coldTop), $coldTop));

                $aiPrompt = sprintf(
                    "Jesteś Głównym Analitykiem Gier Liczbowych. Przygotuj zwięzłą (3-4 zdania) analizę statystyczną i rekomendację dla gry %s (%d z %d).\n" .
                    "DANE Z LOTTO OPENAPI (ostatnie %d losowań):\n" .
                    "- Liczby najczęstsze (Hot): %s\n" .
                    "- Liczby najrzadsze (Cold): %s\n" .
                    "- Zastosowano optymalizację rozwodnienia: %d liczb rozłożono na %d zakładów.\n" .
                    "Przedstaw ocenę ryzyka, zalecenie dotyczące balansu parzystości i sumy Gaussa oraz podsumowanie synergii par.",
                    $gameType,
                    $pick,
                    $maxNumber,
                    $totalDrawsAnalyzed,
                    $hotStr,
                    $coldStr,
                    count($fullPool),
                    $betsCount
                );

                $payload = [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $aiPrompt]]]],
                    'generationConfig' => ['temperature' => 0.4],
                ];

                $aiAnalysis = $this->geminiApiClient->generateContent($payload, 30);
            } catch (\Throwable $e) {
                $this->logger->warning('Błąd generowania komentarza AI: ' . $e->getMessage());
                $aiAnalysis = "Nie udało się połączyć z API Gemini: " . $e->getMessage();
            }
        }

        if ($isJson) {
            $output->writeln(json_encode([
                'game' => $gameType,
                'game_config' => ['pick' => $pick, 'from' => $maxNumber],
                'data_source' => [
                    'provider' => 'developers.lotto.pl (LOTTO OpenAPI)',
                    'ok' => $history['ok'],
                    'warning' => $history['warning'],
                    'affinity_source' => $report['affinity_source'] ?? null,
                    'draws_used_for_co_occurrence' => $report['affinity_draws_used'] ?? 0,
                    'draws_analyzed' => $totalDrawsAnalyzed,
                    'date_from' => $stats['date_from'] ?? $dateFrom,
                    'date_to' => $stats['date_to'] ?? (new \DateTime())->format('Y-m-d\TH:i:s\Z'),
                ],
                'pool' => $fullPool,
                'bets' => $bets,
                'report' => $report,
                'ai_analysis' => $aiAnalysis,
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        // ==========================================
        // PREZENTACJA OKNA STATYSTYCZNEGO
        // ==========================================
        $io->title("📊 OKNO STATYSTYCZNE: RAPORT OPTYMALIZACJI PRZY ROZWODNIENIU");

        // 0. Banner Źródła Danych
        $io->section("📡 Źródło Danych i Statystyki Bazowe");
        $hotTop = array_slice($frequencies, 0, 8, true);
        $coldTop = array_slice(array_reverse($frequencies, true), 0, 8, true);
        $hotList = implode(', ', array_map(fn($num, $cnt) => "<fg=yellow;options=bold>$num</> ({$cnt}x)", array_keys($hotTop), $hotTop));
        $coldList = implode(', ', array_map(fn($num, $cnt) => "<fg=cyan;options=bold>$num</> ({$cnt}x)", array_keys($coldTop), $coldTop));

        $io->definitionList(
            ['API Dostawca' => 'Oficjalne Totalizator Sportowy LOTTO OpenAPI (developers.lotto.pl)'],
            ['Gra i Zasady' => sprintf('%s (losujemy %d z %d)', $gameType, $pick, $maxNumber)],
            ['Okres Analizy' => sprintf('%s do %s (%d losowań)', substr($stats['date_from'] ?? $dateFrom, 0, 10), substr($stats['date_to'] ?? date('Y-m-d'), 0, 10), $totalDrawsAnalyzed)],
            ['Top Gorące (Hot)' => $hotList ?: 'Brak danych'],
            ['Top Zimne (Cold)' => $coldList ?: 'Brak danych']
        );

        // 1. Metryki Rozwodnienia
        $dm = $report['dilution_metrics'];
        $covStatus = ($report['is_full_coverage_guaranteed'] ?? false)
            ? '<fg=green;options=bold>[100% POKRYCIA PULI (Zero Drop)]</>'
            : sprintf('<fg=yellow>[CZĘŚCIOWE: %d/%d liczb]</>', $report['unique_numbers_used'] ?? count($fullPool), count($fullPool));

        $io->section("1. Analiza Rozwodnienia i Przestrzeni Kombinatorycznej");
        $io->table(
            ['Metryka', 'Wartość'],
            [
                ['Liczba liczb w Puli (N)', count($fullPool) . " (zakres 1-$maxNumber)"],
                ['Liczba generowanych zakładów', $betsCount],
                ['Pokrycie Puli Wejściowej', sprintf('%d z %d liczb (%.1f%%) %s', $report['unique_numbers_used'] ?? count($fullPool), count($fullPool), $report['pool_coverage_pct'] ?? 100.0, $covStatus)],
                ['Kombinacje w wybranej puli C(N, k)', number_format($dm['pool_combinations_total'], 0, ',', ' ')],
                ['Kombinacje w całej grze C(Max, k)', number_format($dm['full_lottery_combinations'], 0, ',', ' ')],
                ['Współczynnik Rozwodnienia', sprintf('%s (%s%%)', $dm['dilution_ratio_str'], $dm['dilution_factor_pct'])],
                ['Średnie użycie liczby w pakiecie', sprintf('%.2f razy (min: %d, max: %d)', $dm['avg_repeats_per_number'], $report['min_number_usage'], $report['max_number_usage'])],
                ['Pokrycie unikalnych par w pakiecie', sprintf('%d z %d możliwych (%.1f%%)', $report['unique_pairs_covered'], $report['unique_pairs_total_in_pool'], $report['pairs_coverage_pct'])],
            ]
        );

        // 2. Macierz Najmocniejszych Par
        $isRealCoOcc = ($report['affinity_source'] ?? '') === StatisticalOptimizerService::AFFINITY_SOURCE_CO_OCCURRENCE;
        $io->section("2. Macierz Najmocniejszych Powiązań (Top Pary o Najwyższym Affinity)");
        if ($isRealCoOcc) {
            $io->text(sprintf(
                "<fg=green>Źródło: RZECZYWISTE współwystępowanie par z %d losowań.</>",
                $report['affinity_draws_used'] ?? 0
            ));
        } else {
            $io->text(
                "<fg=yellow>Źródło: heurystyka częstotliwościowa (brak historii losowań).</>
"
                . "<fg=yellow>To NIE jest statystyka par — wartość zależy wyłącznie od częstotliwości</>
"
                . "<fg=yellow>pojedynczych liczb, więc 'najsilniejsza para' to po prostu dwie najgorętsze liczby.</>"
            );
        }
        $topPairsData = [];
        foreach ($report['top_pairs'] as $idx => $pairInfo) {
            $topPairsData[] = [
                '#' . ($idx + 1),
                sprintf('[%d, %d]', $pairInfo['pair'][0], $pairInfo['pair'][1]),
                $pairInfo['affinity'],
                sprintf('%d kuponów', $pairInfo['bets_included']),
            ];
        }
        $io->table(['L.p.', 'Para Liczb', 'Wskaźnik Synergii (Affinity)', 'Obecność w Pakiecie'], $topPairsData);

        // 3. Histogram Sum i Krzywa Gaussa
        $gauss = $report['gaussian_analysis'];
        $io->section("3. Rozkład Sum Zakładów (Krzywa Dzwonowa Gaussa)");
        $io->text(sprintf(
            "Oczekiwana średnia teoretyczna: <fg=white;options=bold>%d</> | Średnia wygenerowanych kuponów: <fg=white;options=bold>%.1f</> (Min: %d, Max: %d) | Przedział optymalny (80%% masy): <fg=green;options=bold>%s</>",
            $gauss['expected_sum'],
            $gauss['avg_sum'],
            $gauss['min_sum'],
            $gauss['max_sum'],
            $gauss['optimal_range']
        ));

        $chartData = [];
        foreach ($gauss['histogram_rows'] as $row) {
            $chartData[] = [
                $row['range'],
                $row['count'],
                $row['bar'] . ' ' . $row['tag'],
            ];
        }
        $io->table(['Przedział Sumy', 'Kupony', 'Wykres Rozkładu'], $chartData);

        // 4. Parzystość
        $io->section("4. Struktura Parzystości (Nieparzyste : Parzyste)");
        $parityRows = [];
        foreach ($report['parity_summary'] as $ratio => $count) {
            $pct = count($bets) > 0 ? round(($count / count($bets)) * 100, 1) : 0.0;
            $parityRows[] = [$ratio, $count, sprintf('%.1f%%', $pct)];
        }
        $io->table(['Stosunek (N:P)', 'Liczba Kuponów', 'Udział %'], $parityRows);

        // 5. Benchmark Jakości (Optimizer vs Random Baseline)
        $bench = $report['benchmark'];
        $io->section("5. Benchmark Jakości: Optymalizator Synergii vs Losowy Baseline");
        $io->text(
            "<fg=yellow>UWAGA METODOLOGICZNA:</> baseline losowy jest oceniany TĄ SAMĄ funkcją celu,
"
            . "którą optymalizator maksymalizuje. Poniższa 'przewaga' mówi więc wyłącznie, że
"
            . "optymalizator zoptymalizował własną funkcję oceny. <fg=yellow>Nie jest to miara szansy
"
            . "na wygraną ani oczekiwanego zwrotu z gry.</>"
        );
        $io->table(
            ['Wskaźnik Analityczny', 'Optymalizator Statystyczny', 'Losowy Baseline (Monte Carlo)', 'Zysk / Przewaga'],
            [
                [
                    'Średni Wskaźnik Synergii (Fitness Score)',
                    sprintf('<fg=green;options=bold>%.1f</>', $bench['optimized_avg_synergy_score']),
                    sprintf('%.1f', $bench['random_baseline_avg_score']),
                    sprintf('<fg=green;options=bold>+%s%%</>', $bench['synergy_advantage_percent']),
                ],
                [
                    'Zgodność z Optimum Gaussa (Sumy w normie)',
                    sprintf('%.1f%%', $bench['optimized_gaussian_adherence_pct']),
                    sprintf('%.1f%%', $bench['random_gaussian_adherence_pct']),
                    sprintf('+%.1f pp', $bench['optimized_gaussian_adherence_pct'] - $bench['random_gaussian_adherence_pct']),
                ],
                [
                    'Balans Parzystości (Brak skrajności)',
                    sprintf('%.1f%%', $bench['optimized_parity_balance_pct']),
                    sprintf('%.1f%%', $bench['random_parity_balance_pct']),
                    sprintf('+%.1f pp', $bench['optimized_parity_balance_pct'] - $bench['random_parity_balance_pct']),
                ],
            ]
        );

        // 6. Komentarz AI (jeśli wygenerowano)
        if (!empty($aiAnalysis)) {
            $io->section("🤖 6. Ekspertyza Analityka AI (Google Gemini)");
            $io->block($aiAnalysis, null, 'fg=white;bg=blue', ' ', true);
        }

        // 7. Lista Wygenerowanych Zakładów (Ranking Synergii)
        $io->section(sprintf("7. Wygenerowany Pakiet (%d Zakładów - Ranking Synergii)", count($bets)));
        $tableData = [];
        $pairMatrix = $this->optimizerService->buildPairAffinityMatrix($fullPool, $frequencies, $draws);
        $gaussParams = $this->optimizerService->calculateGaussianParameters($maxNumber, $pick);

        foreach ($bets as $i => $bet) {
            $fitness = $this->optimizerService->calculateBetFitness($bet, $pairMatrix, $frequencies, $gaussParams, $maxNumber);
            $rankStr = '#' . ($i + 1);
            if ($i === 0) {
                $statusTag = '<fg=yellow;options=bold>[★ TOP SYNERGIA]</>';
            } elseif ($i < 3) {
                $statusTag = '<fg=cyan>[Mocna Synergia]</>';
            } elseif ($fitness['is_gaussian_optimal']) {
                $statusTag = '<fg=green>[Optimum Gaussa]</>';
            } else {
                $statusTag = '<fg=gray>[Pokrycie Puli]</>';
            }

            $tableData[] = [
                $rankStr,
                implode(', ', array_map(fn($n) => sprintf('%2d', $n), $bet)),
                $fitness['sum'],
                $fitness['parity_ratio'],
                sprintf('%.1f', $fitness['total_score']),
                $statusTag,
            ];
        }

        $io->table(['Ranking', 'Liczby', 'Suma', 'Parz (N:P)', 'Synergy Score', 'Profil / Status'], array_slice($tableData, 0, 30));
        if (count($tableData) > 30) {
            $io->note(sprintf("Wyświetlono pierwszych 30 z %d zakładów. Wszystkie zakłady zostały pomyślnie zoptymalizowane.", count($tableData)));
        }

        $io->success(sprintf(
            "Wygenerowano pakiet %d zakładów dla gry %s (pula: %d liczb).",
            count($bets),
            $gameType,
            count($fullPool)
        ));
        $io->text(sprintf(
            "Wewnętrzny wskaźnik dopasowania: %+.1f%% względem losowego doboru z tej samej puli "
            . "(miara samozwrotna — patrz uwaga w sekcji 5).",
            $bench['synergy_advantage_percent']
        ));

        return Command::SUCCESS;
    }
}
