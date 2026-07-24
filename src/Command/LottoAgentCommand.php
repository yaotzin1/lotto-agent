<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GameRegistryService;
use App\Service\GeminiApiClient;
use App\Service\LottoApiClient;
use App\Service\ReActAgentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lotto-agent',
    description: 'Zaawansowany ReAct Agent AI wykorzystujący narzędzia i statystyki LOTTO OpenAPI',
)]
class LottoAgentCommand extends Command
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GeminiApiClient $geminiApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly ReActAgentService $reActAgentService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'Typ gry do analizy');
        $this->addOption('pick', 'p', InputOption::VALUE_REQUIRED, 'Ile liczb ma wytypować agent (np. dla systemu)?');
        $this->addOption('bets', 'b', InputOption::VALUE_REQUIRED, 'Ile osobnych zakładów ma wygenerować agent?');
        $this->addOption('months', 'm', InputOption::VALUE_REQUIRED, 'Z ilu ostatnich miesięcy pobrać statystyki?');
        $this->addOption('neighbours', null, InputOption::VALUE_NONE, 'Czy uwzględniać w analizie liczby sąsiadujące?');
        $this->addOption('sessions', 's', InputOption::VALUE_REQUIRED, 'Ilość ostatnich losowań do analizy (zamiast miesięcy)');
        $this->addOption('json-output', 'j', InputOption::VALUE_NONE, 'Zwróć odpowiedź w formacie JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $gameType = $input->getOption('game');
        if (!$gameType || !$this->gameRegistryService->isValidGame($gameType)) {
            $gameType = $io->choice(
                'Wybierz grę do analizy',
                $this->gameRegistryService->getGameNames(),
                'Lotto'
            );
        }

        $pickCount = $input->getOption('pick');
        if (!$pickCount) {
            $pickCount = $io->ask('Ile liczb ma wytypować agent w jednym zakładzie?', '6');
        }
        $pickCount = (int) $pickCount;

        $betsCount = $input->getOption('bets');
        if (!$betsCount) {
            $betsCount = $io->ask('Ile osobnych zakładów wygenerować?', '1');
        }
        $betsCount = (int) $betsCount;

        $months = $input->getOption('months');
        $sessions = $input->getOption('sessions');
        $isJson = (bool) $input->getOption('json-output');

        if (!$months && !$sessions) {
            $months = $io->ask('Z ilu ostatnich miesięcy pobrać statystyki?', '6');
        }
        $months = (int) $months;
        if ($sessions) {
            $sessions = (int) $sessions;
        }

        $includeNeighbours = (bool) $input->getOption('neighbours');
        if (!$input->hasParameterOption('--neighbours') && $input->isInteractive()) {
            $includeNeighbours = $io->confirm('Czy uwzględniać w analizie liczby sąsiadujące (np. +1/-1)?', false);
        }

        $this->logger->info('Uruchomienie Agenta Lotto', [
            'game' => $gameType,
            'months' => $months,
            'sessions' => $sessions,
            'pickCount' => $pickCount,
            'betsCount' => $betsCount,
            'neighbours' => $includeNeighbours,
            'json' => $isJson,
        ]);

        if (!$isJson) {
            $io->title("Agent Lotto - Analiza gry: $gameType");
        }

        try {
            if (!$isJson) {
                $io->section("Inicjalizacja ReAct Agent AI (Reasoning + Acting)...");
            }

            $onStepCallback = function (string $type, array $data) use ($io, $isJson): void {
                if ($isJson) {
                    return;
                }

                if ($type === 'tool_call') {
                    $io->text(sprintf(
                        "🤖 <fg=cyan>[Agent Thought/Action]</fg=cyan> Uruchamiam narzędzie <fg=yellow>%s</fg=yellow> z parametrami: %s",
                        $data['tool'],
                        json_encode($data['args'])
                    ));
                } elseif ($type === 'tool_result') {
                    $io->text(sprintf(
                        "📊 <fg=green>[Observation]</fg=green> Wynik narzędzia <fg=yellow>%s</fg=yellow>: %s",
                        $data['tool'],
                        mb_strimwidth(json_encode($data['result']), 0, 120, '...')
                    ));
                } elseif ($type === 'thought') {
                    $io->text(sprintf("🧠 <fg=magenta>[Agent Thinking]</fg=magenta> %s", mb_strimwidth($data['text'], 0, 150, '...')));
                }
            };

            $poolSize = max($pickCount + 4, 12);
            $reactResult = $this->reActAgentService->runAgentLoop($gameType, $poolSize, $onStepCallback);

            $pool = $reactResult['pool'];
            $reasoning = $reactResult['reasoning'];

            if ($isJson) {
                $output->writeln(json_encode([
                    'game' => $gameType,
                    'reasoning' => $reasoning,
                    'candidate_pool' => $pool,
                    'agent_steps_count' => count($reactResult['steps']),
                ], JSON_PRETTY_PRINT));
            } else {
                $io->success('Analiza ReAct Agent zakończona pomyślnie.');
                $io->definitionList(
                    ['Gra' => $gameType],
                    ['Uzasadnienie Strategii' => $reasoning],
                    ['Pula Wejściowa' => implode(', ', $pool)],
                    ['Wykonane Kroków Tool Call' => count($reactResult['steps'])]
                );
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('Błąd krytyczny komendy', ['msg' => $e->getMessage()]);
            $io->error('Wystąpił błąd: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}