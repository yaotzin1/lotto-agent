<?php

declare(strict_types=1);

namespace App\Command;

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
    description: 'Backtest hipotezy kroczeń (stride sampling N) i sąsiadów na pełnej historii losowań Lotto',
)]
class LottoBacktestCommand extends Command
{
    public function __construct(
        private readonly StrideBacktestService $backtestService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('pool-size', 'p', InputOption::VALUE_REQUIRED, 'Rozmiar testowanej puli liczb (np. 12)', '12');
        $this->addOption('strides', 's', InputOption::VALUE_REQUIRED, 'Kroczenia do przetestowania, po przecinku (np. "1,2,7,30,50,127,257,500")', '1,2,7,30,50,127,257,500');
        $this->addOption('json-output', 'j', InputOption::VALUE_NONE, 'Zwróć wynik w formacie JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isJson = (bool) $input->getOption('json-output');

        $poolSize = max(6, min(24, (int) $input->getOption('pool-size')));
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
            $report = $this->backtestService->runBacktest($poolSize, $strides);
        } catch (\Throwable $e) {
            $io->error('Błąd podczas wykonywania backtestu: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($isJson) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->section(sprintf(
            'Parametry testu: Pula %d liczb | Przetestowano %d losowań (od %s do %s)',
            $report['pool_size'],
            $report['draws_evaluated'],
            $report['date_from'],
            $report['date_to']
        ));

        // Tabela 1: Rozkład trafień
        $io->section('1. Rozkład trafień liczb z puli w losowaniu docelowym');
        $table = new Table($output);
        $table->setHeaders(['Strategia', '0 Trafień', '1 Trafienie', '2 Trafienia', '3 Trafienia', '4 Trafienia', '5 Trafień', '6 Trafień (Jackpot)', 'Średnia trafień']);

        $theo = $report['theoretical'];
        $table->addRow([
            '<info>TEORIA (Czysty RND)</info>',
            $theo['matches'][0] . '%',
            $theo['matches'][1] . '%',
            $theo['matches'][2] . '%',
            $theo['matches'][3] . '%',
            $theo['matches'][4] . '%',
            $theo['matches'][5] . '%',
            $theo['matches'][6] . '%',
            sprintf('%.4f', $theo['mean']),
        ]);
        $table->addRow(['----------------------------', '-------', '-------', '-------', '-------', '-------', '-------', '-------', '-------']);

        foreach ($report['results'] as $name => $data) {
            $table->addRow([
                $name,
                $data['match_pct'][0] . '%',
                $data['match_pct'][1] . '%',
                $data['match_pct'][2] . '%',
                $data['match_pct'][3] . '%',
                $data['match_pct'][4] . '%',
                $data['match_pct'][5] . '%',
                $data['match_pct'][6] . '%',
                sprintf('%.4f', $data['avg_match']),
            ]);
        }
        $table->render();

        // Tabela 2: Odstępy i periodyczność (Odstępy między trafieniami >= 3 liczb)
        $io->section('2. Analiza periodyczności i odstępów (Dla trafień >= 3 liczb w puli)');
        $gapTable = new Table($output);
        $gapTable->setHeaders(['Strategia', 'Średni odstęp (Mean Gap)', 'Odchylenie stand. (StdDev)', 'Maks. posucha (Max Drought)', 'Trafienia 6/6 (Jackpot)']);

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
