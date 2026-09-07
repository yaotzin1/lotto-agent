<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DrawArchiveService;
use App\Service\GameRegistryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Budowanie i przegląd archiwum losowań.
 *
 * Kroczenie (app:lotto-stride) adresuje losowania pozycją, więc potrzebuje
 * ciągłej historii — a LOTTO OpenAPI nie ma endpointu z zakresem dat. Generator
 * dociąga tylko kilka ostatnich dat, żeby nie kazać użytkownikowi czekać;
 * zbudowanie historii na setki losowań wstecz jest zadaniem tej komendy.
 */
#[AsCommand(
    name: 'app:lotto-archive',
    description: 'Buduje i pokazuje archiwum losowań (historia dla trybu Stride i statystyk)',
)]
class LottoArchiveCommand extends Command
{
    /** Gry pobierane, gdy użytkownik nie zawęzi listy przy --all-games. */
    private const DEFAULT_GAMES = ['Lotto', 'LottoPlus', 'MiniLotto', 'EuroJackpot', 'MultiMulti'];

    public function __construct(
        private readonly DrawArchiveService $drawArchiveService,
        private readonly GameRegistryService $gameRegistryService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'Typ gry (np. MiniLotto)', 'Lotto');
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Ile dni kalendarza wstecz domknąć', '120');
        $this->addOption(
            'all-games',
            'a',
            InputOption::VALUE_NONE,
            'Buduj archiwa wielu gier naraz (jedno zapytanie na dzień zasila wszystkie)'
        );
        $this->addOption(
            'games',
            null,
            InputOption::VALUE_REQUIRED,
            'Lista gier po przecinku dla --all-games (domyślnie: ' . implode(',', self::DEFAULT_GAMES) . ')'
        );
        $this->addOption('status', 's', InputOption::VALUE_NONE, 'Tylko pokaż stan archiwów, bez pobierania');
        $this->addOption('json-output', 'j', InputOption::VALUE_NONE, 'Zwróć wynik w formacie JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isJson = (bool) $input->getOption('json-output');
        $days = max(1, (int) $input->getOption('days'));

        $allGames = (bool) $input->getOption('all-games');
        $games = $allGames ? $this->resolveGames($input) : [(string) $input->getOption('game')];

        foreach ($games as $game) {
            if (!$this->gameRegistryService->isValidGame($game)) {
                $io->error("Nieobsługiwany typ gry: $game");
                return Command::FAILURE;
            }
        }

        if ($input->getOption('status')) {
            return $this->showStatus($io, $output, $games, $isJson);
        }

        if (!$isJson) {
            $io->title('ARCHIWUM LOSOWAŃ — DOMYKANIE HISTORII Z LOTTO OpenAPI');
            $io->text(sprintf(
                'Gry: <info>%s</info> | Zakres: <info>%d dni wstecz</info>',
                implode(', ', $games),
                $days
            ));
            $io->text(
                'API udostępnia wyniki tylko dla POJEDYNCZEJ daty, więc jest to jedno zapytanie na dzień. '
                . 'Przebieg można przerwać i powtórzyć — pobrane daty zostają w pamięci podręcznej.'
            );
            $io->newLine();
        }

        $progress = null;
        if (!$isJson && $output->isVerbose() === false) {
            $bar = $io->createProgressBar();
            $bar->start();
            $progress = static function (int $done, int $total) use ($bar): void {
                $bar->setMaxSteps(max($total, $done));
                $bar->setProgress($done);
            };
        }

        if ($allGames) {
            $result = $this->drawArchiveService->backfillAllGames(
                $games,
                $days,
                $progress === null ? null : static fn(int $d, int $t, string $date) => $progress($d, $t)
            );
            $rateLimited = $result['rate_limited'];
            $fetched = $result['dates_fetched'];
            $added = $result['added'];
        } else {
            $game = $games[0];
            $single = $this->drawArchiveService->backfill($game, $days, $progress);
            $rateLimited = $single['rate_limited'];
            $fetched = $single['fetched'];
            $added = [$game => $single['added']];
        }

        if (isset($bar)) {
            $bar->finish();
            $io->newLine(2);
        }

        if ($isJson) {
            $output->writeln((string) json_encode([
                'games' => $games,
                'days' => $days,
                'dates_fetched' => $fetched,
                'added' => $added,
                'rate_limited' => $rateLimited,
                'archives' => $this->statusRows($games),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->text(sprintf('Sprawdzonych dat: <info>%d</info>', $fetched));
        foreach ($added as $game => $count) {
            $io->text(sprintf(' • %s: <info>+%d</info> losowań', $game, $count));
        }

        $this->renderStatusTable($output, $games);

        if ($rateLimited) {
            $io->warning(
                "LOTTO OpenAPI ograniczyło tempo zapytań (HTTP 429) i nie wszystkie daty zostały pobrane.\n"
                . 'Uruchom komendę ponownie — pobrane daty są już w pamięci podręcznej i nie będą pobierane drugi raz.'
            );

            return Command::SUCCESS;
        }

        $io->success('Archiwum zaktualizowane.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $games
     */
    private function showStatus(SymfonyStyle $io, OutputInterface $output, array $games, bool $isJson): int
    {
        if ($isJson) {
            $output->writeln((string) json_encode($this->statusRows($games), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->title('STAN ARCHIWÓW LOSOWAŃ');
        $this->renderStatusTable($output, $games);

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $games
     * @return array<string, array{last_date: ?string, expected_date: ?string, missing: int, stale: bool, total: int}>
     */
    private function statusRows(array $games): array
    {
        $rows = [];
        foreach ($games as $game) {
            $rows[$game] = $this->drawArchiveService->freshness($game);
        }

        return $rows;
    }

    /**
     * @param list<string> $games
     */
    private function renderStatusTable(OutputInterface $output, array $games): void
    {
        $table = new Table($output);
        $table->setHeaders(['Gra', 'Losowań w archiwum', 'Ostatnie losowanie', 'Oczekiwane', 'Brakuje', 'Plik']);

        foreach ($this->statusRows($games) as $game => $row) {
            if ($row['total'] === 0) {
                $missing = '<comment>brak archiwum</comment>';
            } elseif ($row['stale']) {
                $missing = sprintf('<comment>%d</comment>', $row['missing']);
            } else {
                $missing = '0';
            }

            $table->addRow([
                $game,
                $row['total'],
                $row['last_date'] ?? '—',
                $row['expected_date'] ?? '—',
                $missing,
                $this->drawArchiveService->fileFor($game),
            ]);
        }

        $table->render();
    }

    /**
     * @return list<string>
     */
    private function resolveGames(InputInterface $input): array
    {
        $raw = $input->getOption('games');
        if (!is_string($raw) || trim($raw) === '') {
            return self::DEFAULT_GAMES;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
