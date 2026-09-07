<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BetPipelineRequest;
use App\Service\BetPipelineService;
use App\Service\GameRegistryService;
use App\Service\HistoricalDataProvider;
use App\Service\StatsWindowRenderer;
use App\Service\StrideBacktestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lotto-stride',
    description: 'Generator zakładów oparty o analizę kroczeń historycznych (Stride N) i sąsiadów',
)]
class LottoStrideCommand extends Command
{
    public function __construct(
        private readonly StrideBacktestService $strideService,
        private readonly GameRegistryService $gameRegistryService,
        private readonly HistoricalDataProvider $historicalDataProvider,
        private readonly BetPipelineService $betPipeline,
        private readonly StatsWindowRenderer $statsWindowRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'Typ gry (np. Lotto)', 'Lotto');
        $this->addOption('stride', 's', InputOption::VALUE_REQUIRED, 'Krok kroczenia wstecz w losowaniach (np. 257 lub 127)', '257');
        $this->addOption('anchors', 'a', InputOption::VALUE_REQUIRED, 'Liczba losowań kotwicznych wstecz (np. 1, 2, 3, 4, domyślnie: auto)');
        $this->addOption('pool-size', 'p', InputOption::VALUE_REQUIRED, 'Rozmiar puli kandydującej (np. 12)', '12');
        $this->addOption('strategy', 'st', InputOption::VALUE_REQUIRED, 'Strategia selekcji (anchor_neighbours lub multi_anchor)', 'anchor_neighbours');
        $this->addOption('bets', 'b', InputOption::VALUE_REQUIRED, 'Liczba zakładów do wygenerowania', '6');
        $this->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Tryb generatora redukcyjnego (1-8)', '8');
        $this->addOption('sessions', null, InputOption::VALUE_REQUIRED, 'Liczba sesji do macierzy współwystępowania', '50');
        $this->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Nie dociągaj nowych losowań z LOTTO OpenAPI do archiwum');
        $this->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Maksymalna liczba wierszy tabeli kuponów do wyświetlenia');
        $this->addOption('json-output', 'j', InputOption::VALUE_NONE, 'Zwróć wynik w formacie JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isJson = (bool) $input->getOption('json-output');

        $gameType = (string) $input->getOption('game');
        if (!$this->gameRegistryService->isValidGame($gameType)) {
            $io->error("Nieobsługiwany typ gry: $gameType");
            return Command::FAILURE;
        }

        $game = $this->gameRegistryService->getGameConfig($gameType);
        $stride = max(1, (int) $input->getOption('stride'));
        $anchorsOpt = $input->getOption('anchors');
        $anchorCount = ($anchorsOpt !== null && is_numeric($anchorsOpt)) ? max(1, (int) $anchorsOpt) : null;
        // Górną granicą jest zakres liczb TEJ gry, nie wpisane wcześniej na sztywno 30.
        $poolSize = max($game['pick'], min((int) $game['from'], (int) $input->getOption('pool-size')));
        $strategy = in_array($input->getOption('strategy'), ['multi_anchor', 'anchor_neighbours'], true)
            ? (string) $input->getOption('strategy')
            : 'anchor_neighbours';
        $betsTotal = max(1, (int) $input->getOption('bets'));
        $mode = (string) ($input->getOption('mode') ?: '8');

        if (!$isJson) {
            $io->title("=======================================================================\n||   GENERATOR STROBOSKOPOWY / STRIDE SAMPLER (KROCZENIE CO N)       ||\n=======================================================================");
            $io->text(sprintf(
                "Konfiguracja: Gra <info>%s</info> (%d z %d) | Krok kroczenia <info>N=%d</info> (kotwice: %s) | Pula <info>%d liczb</info> | Strategia: <comment>%s</comment>",
                $gameType,
                $game['pick'],
                $game['from'],
                $stride,
                $anchorCount !== null ? (string) $anchorCount : 'auto',
                $poolSize,
                $strategy
            ));
        }

        $archiveWarnings = [];

        // Archiwum kroczenia domykamy z oficjalnego LOTTO OpenAPI PRZED zbudowaniem
        // puli: kotwice adresują losowania pozycją (T-N, T-2N...), więc każde
        // brakujące losowanie przesuwałoby je wszystkie.
        if (!$input->getOption('no-refresh')) {
            $refresh = $this->strideService->refreshArchive($gameType);
            if ($refresh !== null) {
                if ($refresh['warning'] !== null) {
                    $archiveWarnings[] = $refresh['warning'];
                }
                if (!$isJson && $refresh['added'] > 0) {
                    $io->text(sprintf(
                        ' <fg=green>↻</> Archiwum %s uzupełnione o %d nowych losowań z LOTTO OpenAPI.',
                        $gameType,
                        $refresh['added']
                    ));
                }
            }
        }

        try {
            $strideInfo = $this->strideService->getStridePoolInfo($stride, $poolSize, $strategy, null, $anchorCount, $gameType);
        } catch (\Throwable $e) {
            $io->error('Błąd pobierania puli kroczenia: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $pool = $strideInfo['pool'];

        $freshness = $strideInfo['freshness'];
        if ($freshness !== null && $freshness['stale']) {
            $archiveWarnings[] = sprintf(
                "Archiwum losowań %s jest nieaktualne: ostatnie losowanie w bazie to %s, "
                . "a od tego czasu odbyło się jeszcze %d losowań (ostatnie oczekiwane: %s).\n"
                . 'Kotwice T-N liczone są od ostatniego wiersza archiwum, więc WSZYSTKIE są przesunięte o tyle samo losowań.',
                $gameType,
                $freshness['last_date'] ?? 'brak',
                $freshness['missing'],
                $freshness['expected_date'] ?? 'brak'
            );
        }

        // Pobieramy dane historyczne dla macierzy współwystępowania i dzwonu Gaussa
        $sessions = (int) ($input->getOption('sessions') ?: 50);
        $history = $this->historicalDataProvider->fetch($gameType, $sessions);

        if (!$history['ok']) {
            $archiveWarnings[] = ($history['warning'] ?? 'Brak danych historycznych z LOTTO OpenAPI.')
                . "\nMacierz współwystępowania i dzwon Gaussa liczą się na danych zastępczych.";
        } elseif ($history['warning'] !== null) {
            $archiveWarnings[] = $history['warning'];
        }

        // Ostrzeżenia o danych muszą paść PRZED raportem, a nie po nim —
        // inaczej użytkownik najpierw czyta tabele policzone na danych
        // zastępczych, a dopiero potem dowiaduje się, że były zastępcze.
        if (!$isJson) {
            foreach ($archiveWarnings as $w) {
                $io->warning($w);
            }
        }

        $gameForPipeline = $game;
        $gameForPipeline['_special_frequencies'] = $history['special_frequencies'] ?? [];
        $gameForPipeline['_special_draws'] = $history['special_draws'] ?? [];

        $request = new BetPipelineRequest(
            game: $gameForPipeline,
            pool: $pool,
            mode: $mode,
            betsTotal: $betsTotal,
            frequencies: $history['frequencies'],
            draws: $history['draws'],
        );

        try {
            $pipelineResult = $this->betPipeline->run($request);
        } catch (\InvalidArgumentException $e) {
            $io->error('Błąd generatora: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($isJson) {
            $response = [
                'game' => $gameType,
                'numbers_from' => $strideInfo['numbers_from'],
                'stride' => $stride,
                'strategy' => $strategy,
                'pool_size' => $poolSize,
                'archive_freshness' => $strideInfo['freshness'],
                'anchor_draws' => $strideInfo['anchor_draws'],
                'anchors' => $strideInfo['anchors'],
                'neighbours' => $strideInfo['neighbours'],
                'pool' => $pool,
                'bets' => $pipelineResult->bets,
                'stats_report' => $pipelineResult->statsReport,
                'warnings' => array_merge($archiveWarnings, $pipelineResult->warnings),
            ];
            $output->writeln((string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        // Prezentacja losowań kotwicznych
        $io->section('1. Próbkowane losowania historyczne (Kotwice Stride)');
        if ($freshness !== null) {
            $io->text(sprintf(
                ' Archiwum %s: <info>%d losowań</info>, ostatnie z dnia <info>%s</info>.',
                $gameType,
                $freshness['total'],
                $freshness['last_date'] ?? 'brak'
            ));
        }
        $anchorTable = new Table($output);
        $anchorTable->setHeaders(['Pozycja', 'Data losowania', 'Wstecz o (Stride)', 'Wylosowane liczby']);
        foreach ($strideInfo['anchor_draws'] as $ad) {
            $anchorTable->addRow([
                'Losowanie #' . $ad['index'],
                $ad['date'],
                $ad['stride_back'] . ' losowań wstecz',
                implode(', ', $ad['numbers']),
            ]);
        }
        $anchorTable->render();

        // Prezentacja Puli
        $io->section(sprintf('2. Skonstruowana Pula Kandydująca (%d liczb)', count($pool)));
        $io->writeln(sprintf(" <fg=yellow>★ Kotwice (%d):</>   %s", count($strideInfo['anchors']), implode(', ', $strideInfo['anchors'])));
        $io->writeln(sprintf(" <fg=cyan>⚡ Sąsiedzi ±1 (%d):</> %s", count($strideInfo['neighbours']), implode(', ', $strideInfo['neighbours'])));
        $io->writeln(sprintf(" <fg=green>✔ Pełna pula (%d):</>  %s", count($pool), implode(', ', $pool)));

        // Statystyka makro puli
        $sum = array_sum($pool);
        $evens = count(array_filter($pool, static fn(int $n): bool => $n % 2 === 0));
        $odds = count($pool) - $evens;
        $io->text(sprintf("\n Profil puli: Parzyste/Nieparzyste: %d/%d | Suma: %d", $evens, $odds, $sum));

        // Prezentacja kuponów
        $io->section(sprintf('3. Wygenerowane Zakłady (Tryb %s, %d kuponów)', $mode, count($pipelineResult->bets)));
        $this->statsWindowRenderer->renderBets(
            $io,
            $pipelineResult->bets,
            $pipelineResult->extraSets,
            $pipelineResult->statsReport,
            (int) ($input->getOption('max-rows') ?: StatsWindowRenderer::SHOW_ALL)
        );

        if ($pipelineResult->statsReport !== null) {
            $this->statsWindowRenderer->renderStatsWindow($io, $pipelineResult->statsReport, count($pool));
        }

        foreach ($pipelineResult->warnings as $w) {
            $io->warning($w);
        }

        $io->success(sprintf('Gotowe! Pomyślnie wygenerowano %d zakładów ze strategii Stride-%d.', count($pipelineResult->bets), $stride));
        return Command::SUCCESS;
    }
}
