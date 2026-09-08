<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GameRegistryService;
use App\Service\StrideBacktestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lotto-backtest',
    description: 'Backtest hipotezy kroczeń (stride sampling N) i sąsiadów na pełnej historii losowań wybranej gry',
)]
class LottoBacktestCommand extends Command
{
    public function __construct(
        private readonly StrideBacktestService $backtestService,
        private readonly GameRegistryService $gameRegistryService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'Typ gry (np. Lotto, EuroJackpot, MultiMulti)', 'Lotto');
        $this->addOption('pool-size', 'p', InputOption::VALUE_REQUIRED, 'Rozmiar testowanej puli liczb (np. 12)', '12');
        $this->addOption('strides', 's', InputOption::VALUE_REQUIRED, 'Kroczenia do przetestowania, po przecinku (np. "1,2,7,30,50,127,257,500")', '1,2,7,30,50,127,257,500');
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
        $poolSize = max((int) $game['pick'], min((int) $game['from'] - 1, (int) $input->getOption('pool-size')));
        $stridesStr = (string) $input->getOption('strides');
        $strides = array_values(array_filter(array_map('intval', explode(',', $stridesStr)), static fn(int $s): bool => $s > 0));

        if ($strides === []) {
            $strides = [1, 127, 257];
        }

        if (!$isJson) {
            $io->title("=======================================================================\n||   BACKTESTING HIPOTEZY KROCZEŃ (STRIDE SAMPLING) & SĄSIADÓW       ||\n=======================================================================");
            $io->text("Testowanie hipotezy: czy pobieranie liczb co N losowań (np. N=127 lub N=257)");
            $io->text("oraz rozszerzanie ich o sąsiadów (+1/-1) daje przewagę nad losowością lub N=1.\n");
        }

        try {
            $report = $this->backtestService->runBacktest($poolSize, $strides, $gameType);
        } catch (\Throwable $e) {
            $io->error('Błąd podczas wykonywania backtestu: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($isJson) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->section(sprintf(
            'Parametry testu: Gra %s (%d z %d) | Pula %d liczb | Przetestowano %d losowań (od %s do %s)',
            $report['game'],
            $report['drawn_per_draw'],
            $report['numbers_from'],
            $report['pool_size'],
            $report['draws_evaluated'],
            $report['date_from'],
            $report['date_to']
        ));

        // Tabela 1: Rozkład trafień
        // Liczba kolumn zależy od gry: Lotto losuje 6 liczb, Multi Multi 20.
        $drawn = $report['drawn_per_draw'];
        $headers = ['Strategia'];
        for ($k = 0; $k <= $drawn; $k++) {
            $headers[] = $k === $drawn ? sprintf('%d Traf. (Jackpot)', $k) : sprintf('%d Traf.', $k);
        }
        $headers[] = 'Średnia trafień';

        $io->section('1. Rozkład trafień liczb z puli w losowaniu docelowym');
        $table = new Table($output);
        $table->setHeaders($headers);

        $theo = $report['theoretical'];
        $theoRow = ['<info>TEORIA (Czysty RND)</info>'];
        for ($k = 0; $k <= $drawn; $k++) {
            $theoRow[] = $theo['matches'][$k] . '%';
        }
        $theoRow[] = sprintf('%.4f', $theo['mean']);
        $table->addRow($theoRow);
        $table->addRow(array_fill(0, count($headers), '-------'));

        foreach ($report['results'] as $name => $data) {
            $row = [$name];
            for ($k = 0; $k <= $drawn; $k++) {
                $row[] = $data['match_pct'][$k] . '%';
            }
            $row[] = sprintf('%.4f', $data['avg_match']);
            $table->addRow($row);
        }
        $table->render();

        // Tabela 2: Odstępy i periodyczność (Odstępy między trafieniami >= 3 liczb)
        $io->section(sprintf('2. Analiza periodyczności i odstępów (dla trafień >= %d liczb w puli)', $report['hit_threshold']));
        $gapTable = new Table($output);
        $gapTable->setHeaders(['Strategia', 'Średni odstęp (Mean Gap)', 'Odchylenie stand. (StdDev)', 'Maks. posucha (Max Drought)', sprintf('Trafienia %d/%d (Jackpot)', $drawn, $drawn)]);

        foreach ($report['results'] as $name => $data) {
            $gapTable->addRow([
                $name,
                sprintf('%.2f losowań', $data['gaps_ge3_mean']),
                sprintf('%.2f losowań', $data['gaps_ge3_stddev']),
                sprintf('%d losowań', $data['gaps_ge3_max']),
                sprintf('%d hit(s)', $data['jackpot_hits']),
            ]);
        }
        $gapTable->render();

        $io->success('Backtest zakończony sukcesem!');
        return Command::SUCCESS;
    }
}
