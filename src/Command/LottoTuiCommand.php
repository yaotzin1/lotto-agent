<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BetGeneratorService;
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
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

#[AsCommand(
    name: 'app:lotto-tui',
    description: 'Zaawansowany Generator Systemów Hazardzisty v8.1 (Wersja TUI)',
)]
class LottoTuiCommand extends Command
{
    public function __construct(
        private readonly LottoApiClient $lottoApiClient,
        private readonly GeminiApiClient $geminiApiClient,
        private readonly GameRegistryService $gameRegistryService,
        private readonly BetGeneratorService $generatorService,
        private readonly ReActAgentService $reactAgentService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'Typ gry do analizy');
        $this->addOption('sessions', 's', InputOption::VALUE_REQUIRED, 'Ilość ostatnich losowań do pobrania statystyk');
        $this->addOption('bets', 'b', InputOption::VALUE_REQUIRED, 'Ile zakładów ma wygenerować system?');
    }

    private function promptSelect(string $question, array $choices, ?string $default = null): string
    {
        $tui = new Tui();
        $tui->add(new TextWidget($question));

        $isAssoc = array_keys($choices) !== range(0, count($choices) - 1);
        $items = [];

        if ($isAssoc) {
            foreach ($choices as $key => $label) {
                $items[] = ['value' => (string)$key, 'label' => (string)$label];
            }
        } else {
            foreach ($choices as $val) {
                $items[] = ['value' => (string)$val, 'label' => (string)$val];
            }
        }

        $select = new SelectListWidget($items);
        if ($default !== null) {
            foreach ($items as $idx => $item) {
                if ($item['value'] === $default) {
                    $select->setSelectedIndex($idx);
                    break;
                }
            }
        }

        $tui->add($select);
        $tui->setFocus($select);

        $result = null;
        $select->onSelect(function (SelectEvent $event) use ($tui, &$result) {
            $result = $event->getItem()['value'];
            $tui->stop();
        });

        $tui->run();

        return $result ?? $default ?? '';
    }

    private function promptInput(string $question, string $default = ''): string
    {
        $tui = new Tui();
        $tui->add(new TextWidget($question));

        $input = new InputWidget();
        $input->setPrompt('> ');
        if ($default !== '') {
            $input->setValue($default);
        }
        $tui->add($input);
        $tui->setFocus($input);

        $result = null;
        $input->onSubmit(function (SubmitEvent $event) use ($tui, &$result) {
            $result = $event->getValue();
            $tui->stop();
        });

        $tui->run();

        return empty($result) ? $default : $result;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title("========================================================\n||   GENERATOR v8.1 (FRAKTAL & ROZDZIELNY) + AI [TUI] ||\n========================================================");

        $gameType = $input->getOption('game');
        if (!$gameType || !$this->gameRegistryService->isValidGame($gameType)) {
            $gameType = $this->promptSelect(
                'Wybierz grę do analizy',
                $this->gameRegistryService->getGameNames(),
                'Lotto'
            );
        }
        $game = $this->gameRegistryService->getGameConfig($gameType);

        if ($gameType === 'MultiMulti') {
            $pickSize = (int)$this->promptSelect('Ile liczb chcesz skreślać na jednym zakładzie (strategia Multi Multi)?', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'], '10');
            $game['pick'] = $pickSize;
        }

        $poolMode = $this->promptSelect('Jak chcesz wygenerować pulę wejściową liczb?', [
            'AI' => 'AI (Na podstawie statystyk LOTTO API)',
            'Manual' => 'Ręcznie (Wpisz własne liczby)',
        ], 'AI');

        $aiStrategy = 'balanced';
        if ($poolMode === 'AI') {
            $aiStrategy = $this->promptSelect('Wybierz strategię doboru liczb przez AI:', [
                'balanced' => 'Zbalansowana (Klasyczna Hybryda, równowaga)',
                'aggressive' => 'Ostra Gra (Łowca Trendów - maks. ryzyko na klastry i liczby gorące)',
            ], 'aggressive');
        }

        $fullPool = [];

        if ($poolMode === 'AI') {
            $poolSize = (int)$this->promptInput("Ile liczb ma wybrać AI? (dla gry {$gameType} losujemy {$game['pick']} z {$game['from']})", '20');

            $io->text("Uruchamianie ReAct Agent AI (Gemini + Narzędzia Statystyczne OpenAPI)...");

            $onStepCallback = function (string $type, array $data) use ($io): void {
                if ($type === 'tool_call') {
                    $io->text(sprintf(
                        "🤖 <fg=cyan>[Agent Thought/Action]</fg=cyan> Uruchamiam narzędzie <fg=yellow>%s</fg=yellow> z parametrami: %s",
                        $data['tool'],
                        json_encode($data['args'])
                    ));
                } elseif ($type === 'tool_result') {
                    $io->text(sprintf(
                        "📊 <fg=magenta>[Observation]</fg=magenta> Otrzymano wynik z narzędzia %s.",
                        $data['tool']
                    ));
                } elseif ($type === 'thinking') {
                    $io->text(sprintf(
                        "🧠 <fg=gray>[Agent Thinking]</fg=gray> Tura %d: Gemini przetwarza obserwacje...",
                        $data['turn']
                    ));
                }
            };

            $result = $this->reactAgentService->runAgentLoop($gameType, $poolSize, $onStepCallback);
            $fullPool = $result['pool'] ?? $result['selected_pool'] ?? [];
            sort($fullPool);

            if (!empty($result['reasoning'])) {
                $io->text("<comment>Uzasadnienie ReAct Agent:</comment> " . $result['reasoning']);
            }
            $io->success("Pula AI (" . count($fullPool) . " liczb): " . implode(', ', $fullPool));
        } else {
            $poolStr = $this->promptInput('Podaj pulę liczb oddzielonych spacją lub przecinkiem (np. 1 5 12 18):');
            preg_match_all('/\d+/', $poolStr, $matches);
            $fullPool = array_unique(array_map('intval', $matches[0] ?? []));
            sort($fullPool);
            $io->success("Pula ręczna: " . implode(', ', $fullPool));
        }

        if (count($fullPool) < $game['pick']) {
            $io->error("Pula (" . count($fullPool) . ") jest mniejsza niż wymagana ilość do skreślenia (" . $game['pick'] . ")!");
            return Command::FAILURE;
        }

        $io->section("METODA PRACY (Generator)");
        $mode = $this->promptSelect('Wybierz tryb:', [
            '1' => '[1] RĘCZNY (Jeden blok = Pula wejściowa)',
            '2' => '[2] KREATOR BLOKÓW (Inteligentny Krupier)',
            '3' => '[3] GENERATOR WAŻONY (Statystyczny)',
            '4' => '[4] SYSTEM HYBRYDOWY (Stali Bankierzy + Zmienne z Puli)',
            '5' => '[5] SYSTEM FRAKTALNY (Zaawansowane bloki)',
            '6' => '[6] SYSTEM ROZDZIELNY (Bankierzy Rotacyjni)',
        ], '1');

        $blocksToProcess = [];

        if ($mode === '6') {
            $io->text("SYSTEM ROZDZIELNY (BANKIERZY ROTACYJNI)");
            $bankStr = $this->promptInput('Wpisz liczby BANKIERÓW z Puli (np. 8 sztuk):');
            preg_match_all('/\d+/', $bankStr, $matches);
            $bankersPool = array_unique(array_map('intval', $matches[0] ?? []));
            $bankersQty = (int)$this->promptInput('Ile z tych Bankierów ma być na każdym kuponie?', '3');

            $varsPool = array_diff($fullPool, $bankersPool);
            sort($varsPool);
            sort($bankersPool);

            $slotsForVars = $game['pick'] - $bankersQty;
            $betsLimit = (int)($input->getOption('bets') ?: $this->promptInput('Ile zakładów wygenerować?', '30'));

            $bankerBets = $this->generatorService->generateBalancedShorthand($bankersPool, $bankersQty, $betsLimit);
            $varBets = $this->generatorService->generateBalancedShorthand($varsPool, $slotsForVars, $betsLimit);

            shuffle($varBets);
            $generatedHybridBets = [];
            $limit = min(count($bankerBets), count($varBets));

            for ($i = 0; $i < $limit; $i++) {
                $fullBet = array_merge($bankerBets[$i], $varBets[$i]);
                sort($fullBet);
                if (count(array_unique($fullBet)) === $game['pick']) {
                    $generatedHybridBets[] = $fullBet;
                }
            }
            $blocksToProcess[] = ['type' => 'precalc', 'bets' => $generatedHybridBets];

        } elseif ($mode === '5') {
            $io->text("SYSTEM FRAKTALNY");
            $l1Size = (int)$this->promptInput("Rozmiar Bloku L1 (np. 12):", '12');
            $l1Count = (int)$this->promptInput("Ile Bloków L1 (np. 4):", '4');
            $l2Size = (int)$this->promptInput("Rozmiar Bloku L2 (np. 8):", '8');
            $l2Count = (int)$this->promptInput("Ile podziałów na L2 z każdego L1 (np. 2):", '2');

            $l1Blocks = $this->generatorService->generateOverlappingBlocks($fullPool, $l1Count, $l1Size);
            foreach ($l1Blocks as $parentBlock) {
                $subBlocks = $this->generatorService->generateOverlappingBlocks($parentBlock, $l2Count, $l2Size);
                foreach ($subBlocks as $sb) {
                    $blocksToProcess[] = $sb;
                }
            }

        } elseif ($mode === '4') {
            $io->text("SYSTEM HYBRYDOWY (Stali Bankierzy)");
            $bankStr = $this->promptInput('Wpisz stałych Bankierów (będą na każdym kuponie):');
            preg_match_all('/\d+/', $bankStr, $matches);
            $bankers = array_unique(array_map('intval', $matches[0] ?? []));
            $vars = array_diff($fullPool, $bankers);
            sort($bankers);
            sort($vars);
            $blocksToProcess[] = ['type' => 'hybrid', 'bankers' => $bankers, 'vars' => $vars];

        } elseif ($mode === '3') {
            $io->text("GENERATOR WAŻONY");
            $hotStr = $this->promptInput('Wpisz z Puli liczby GORĄCE (dostaną większą wagę):');
            preg_match_all('/\d+/', $hotStr, $matches);
            $hotNums = array_unique(array_map('intval', $matches[0] ?? []));
            $weight = (int)$this->promptInput('Waga (2-10):', '5');

            $urn = [];
            foreach ($fullPool as $n) {
                if (in_array($n, $hotNums, true)) {
                    for ($k = 0; $k < $weight; $k++) {
                        $urn[] = $n;
                    }
                } else {
                    $urn[] = $n;
                }
            }
            $weightedPool = [];
            $targetSize = min(count($fullPool), 15);
            while (count($weightedPool) < $targetSize) {
                shuffle($urn);
                $x = $urn[0];
                if (!in_array($x, $weightedPool, true)) {
                    $weightedPool[] = $x;
                }
            }
            sort($weightedPool);
            $blocksToProcess[] = $weightedPool;

        } elseif ($mode === '2') {
            $io->text("KREATOR BLOKÓW (Inteligentny Krupier)");
            $bs = (int)$this->promptInput("Rozmiar bloku (np. 12):", '12');
            $bn = (int)$this->promptInput("Ile bloków:", '5');
            $blocksToProcess = $this->generatorService->generateSmartUniqueBlocks($fullPool, $bs, $bn);

        } else {
            $blocksToProcess[] = $fullPool;
        }

        if (empty($blocksToProcess)) {
            $io->error("Brak danych do wygenerowania.");
            return Command::FAILURE;
        }

        $firstBlock = $blocksToProcess[0];
        if (isset($firstBlock['type']) && $firstBlock['type'] === 'precalc') {
            $betsLimit = count($firstBlock['bets']);
        } elseif (isset($firstBlock['type']) && $firstBlock['type'] === 'hybrid') {
            $betsLimit = (int)($input->getOption('bets') ?: $this->promptInput('Ile zakładów wygenerować z puli zmiennych?:', '10'));
        } else {
            $sysSize = count($firstBlock);
            $io->text("Rozmiar bloku roboczego: $sysSize liczb.");
            $betsLimit = (int)($input->getOption('bets') ?: $this->promptInput('Ile zakładów wygenerować na JEDEN blok?', '5'));
        }

        $allFinalBets = [];

        foreach ($blocksToProcess as $data) {
            if (isset($data['type']) && $data['type'] === 'precalc') {
                $finalBets = $data['bets'];
            } elseif (isset($data['type']) && $data['type'] === 'hybrid') {
                $bankers = $data['bankers'];
                $vars = $data['vars'];
                $needed = $game['pick'] - count($bankers);
                $subBets = $this->generatorService->generateBalancedShorthand($vars, $needed, $betsLimit);
                $finalBets = [];
                foreach ($subBets as $sb) {
                    $merged = array_merge($bankers, $sb);
                    sort($merged);
                    $finalBets[] = $merged;
                }
            } else {
                $finalBets = $this->generatorService->generateBalancedShorthand($data, $game['pick'], $betsLimit);
            }

            foreach ($finalBets as $bet) {
                $allFinalBets[] = $bet;
            }
        }

        $io->success("Wygenerowano " . count($allFinalBets) . " zakładów!");

        $tableData = [];
        foreach ($allFinalBets as $i => $bet) {
            $tableData[] = ['Zakład ' . ($i + 1), implode(', ', $bet)];
        }
        $io->table(['L.p.', 'Liczby'], $tableData);

        // --- WERYFIKATOR POKRYCIA ---
        $io->section("ANALIZA POKRYCIA (Macierz Gwarancji: Siatka Bezpieczeństwa)");

        if (count($fullPool) <= 30) {
            $io->text("Symulacja możliwych scenariuszy losowania...");

            try {
                $scenarios = [
                    $game['pick'],
                    $game['pick'] - 1,
                    $game['pick'] - 2,
                ];

                foreach ($scenarios as $hitsInPool) {
                    if ($hitsInPool < 3 || $hitsInPool <= 0) {
                        continue;
                    }

                    $coverage = $this->generatorService->calculateCoverage($fullPool, $allFinalBets, $hitsInPool);

                    $io->text("<fg=cyan>Scenariusz:</> W Twojej puli znalazło się dokładnie <fg=white;options=bold>{$hitsInPool}</> z wylosowanych liczb.");
                    $covData = [];
                    for ($i = 3; $i <= $hitsInPool; $i++) {
                        $percent = $coverage['guarantees'][$i];
                        $format = $percent == 100 ? '<fg=green;options=bold>%s%%</>' : ($percent > 50 ? '<fg=yellow>%s%%</>' : '%s%%');
                        $covData[] = [
                            "Gwarancja $i/" . $game['pick'],
                            sprintf($format, $percent),
                        ];
                    }

                    $io->table(['Cel', 'Szansa na przynajmniej 1 taki kupon'], $covData);
                }

                if (isset($scenarios[0])) {
                    $totalCoverage = $this->generatorService->calculateCoverage($fullPool, $allFinalBets, $scenarios[0]);
                    $io->text("<fg=gray>Przeanalizowano " . number_format($totalCoverage['total_draws'], 0, ',', ' ') . " bazowych kombinacji dla pełnego trafienia w pulę.</>");
                }
            } catch (\Exception $e) {
                $io->warning("Nie udało się przeprowadzić symulacji: " . $e->getMessage());
            }
        } else {
            $io->warning("Pula jest zbyt duża (" . count($fullPool) . " liczb) na pełną symulację w pamięci RAM.");
        }

        return Command::SUCCESS;
    }
}