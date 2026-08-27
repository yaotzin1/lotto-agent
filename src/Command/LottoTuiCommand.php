<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BetGeneratorService;
use App\Service\GameRegistryService;
use App\Service\GeminiApiClient;
use App\Service\LottoApiClient;
use App\Service\ReActAgentService;
use App\Service\BetPipelineRequest;
use App\Service\BetPipelineService;
use App\Service\ExtraNumbersGenerator;
use App\Service\HistoricalDataProvider;
use App\Service\StatisticalOptimizerService;
use App\Service\StatsWindowRenderer;
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
        private readonly StatisticalOptimizerService $statisticalOptimizer,
        private readonly HistoricalDataProvider $historicalDataProvider,
        private readonly ExtraNumbersGenerator $extraNumbersGenerator,
        private readonly BetPipelineService $betPipeline,
        private readonly StatsWindowRenderer $statsWindowRenderer,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this->addOption('game', 'g', InputOption::VALUE_REQUIRED, 'Typ gry do analizy');
        $this->addOption('pick', 'p', InputOption::VALUE_REQUIRED, 'Ile liczb ma wytypować agent w jednym zakładzie');
        $this->addOption('sessions', 's', InputOption::VALUE_REQUIRED, 'Ilość ostatnich losowań do pobrania statystyk');
        $this->addOption('months', 'mo', InputOption::VALUE_REQUIRED, 'Z ilu ostatnich miesięcy pobrać statystyki');
        $this->addOption('bets', 'b', InputOption::VALUE_REQUIRED, 'Ile zakładów ma wygenerować system?');
        $this->addOption('strategy', 'st', InputOption::VALUE_REQUIRED, 'Strategia doboru liczb przez AI (balanced/aggressive)');
        $this->addOption('pool-size', 'ps', InputOption::VALUE_REQUIRED, 'Rozmiar puli liczb wybieranych przez AI');
        $this->addOption('pool-mode', 'pm', InputOption::VALUE_REQUIRED, 'Metoda doboru puli (AI/Manual)');
        $this->addOption('pool', null, InputOption::VALUE_REQUIRED, 'Pula liczb dla trybu Manual (np. "all" albo "1,5,12,18")');
        $this->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Ogranicz liczbę wierszy w tabeli wyników (domyślnie: wszystkie)');
        $this->addOption('bankers-per-bet', null, InputOption::VALUE_REQUIRED, 'Tryb 6: ilu bankierów na jednym kuponie');
        $this->addOption('l1-size', null, InputOption::VALUE_REQUIRED, 'Tryb 5: rozmiar bloku L1');
        $this->addOption('l1-count', null, InputOption::VALUE_REQUIRED, 'Tryb 5: liczba bloków L1');
        $this->addOption('l2-size', null, InputOption::VALUE_REQUIRED, 'Tryb 5: rozmiar bloku L2');
        $this->addOption('l2-count', null, InputOption::VALUE_REQUIRED, 'Tryb 5: liczba podbloków L2');
        $this->addOption('block-size', null, InputOption::VALUE_REQUIRED, 'Tryb 2: rozmiar bloku');
        $this->addOption('block-count', null, InputOption::VALUE_REQUIRED, 'Tryb 2: liczba bloków');
        $this->addOption('hot', null, InputOption::VALUE_REQUIRED, 'Tryb 3: liczby gorące o zwiększonej wadze');
        $this->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Tryb pracy generatora (1-8)');
        $this->addOption('neighbours', 'nb', InputOption::VALUE_NONE, 'Czy uwzględniać w analizie liczby sąsiadujące (+1/-1)?');
        $this->addOption('bankers', 'bk', InputOption::VALUE_REQUIRED, 'Liczby bankierów oddzielone przecinkami/spacją (dla trybów 4 i 6)');
        $this->addOption('weight', 'w', InputOption::VALUE_REQUIRED, 'Waga dla gorących liczb w generatorze ważonym (dla trybu 3)');
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

        // Liczba skreśleń pochodzi z rejestru; --pick działa TYLKO tam, gdzie gra
        // faktycznie na to pozwala (wcześniej przyjmowano dowolną wartość >= 1
        // nawet dla gier o stałej liczbie skreśleń, np. --pick=3 dla Lotto).
        if ($this->gameRegistryService->isVariablePick($gameType)) {
            $range = $this->gameRegistryService->getPickRange($gameType);
            $pickOpt = $input->getOption('pick');

            if ($pickOpt !== null && is_numeric($pickOpt)) {
                $game['pick'] = $this->gameRegistryService->resolvePick($gameType, (int) $pickOpt);
            } elseif ($input->isInteractive()) {
                $choices = array_map('strval', range($range['min'], $range['max']));
                $game['pick'] = (int) $this->promptSelect(
                    sprintf('Ile liczb skreślać na jednym zakładzie (%s: %d-%d)?', $gameType, $range['min'], $range['max']),
                    $choices,
                    (string) $range['default']
                );
            }
        }

        $poolModeOpt = $input->getOption('pool-mode');
        if ($poolModeOpt && in_array(strtolower($poolModeOpt), ['ai', 'manual'], true)) {
            $poolMode = strtolower($poolModeOpt) === 'ai' ? 'AI' : 'Manual';
        } else {
            $poolMode = $this->promptSelect('Jak chcesz wygenerować pulę wejściową liczb?', [
                'AI' => 'AI (Na podstawie statystyk LOTTO API)',
                'Manual' => 'Ręcznie (Wpisz własne liczby)',
            ], 'AI');
        }

        $strategyOpt = $input->getOption('strategy');
        if ($strategyOpt && in_array(strtolower($strategyOpt), ['syndicate', 'balanced', 'aggressive'], true)) {
            $aiStrategy = strtolower($strategyOpt);
        } else {
            $aiStrategy = 'syndicate';
            if ($poolMode === 'AI') {
                $aiStrategy = $this->promptSelect('Wybierz strategię doboru liczb przez AI:', [
                    'syndicate' => 'Syndykat Klastrowy (60% sąsiedzi, 20% powtórki wygranych, 20% uśpione)',
                    'balanced' => 'Zbalansowana (Klasyczna Hybryda, równowaga)',
                    'aggressive' => 'Ostra Gra (Łowca Trendów - maks. ryzyko na klastry i liczby gorące)',
                ], 'syndicate');
            }
        }

        $includeNeighbours = (bool)$input->getOption('neighbours');

        $fullPool = [];

        if ($poolMode === 'AI') {
            $poolSizeOpt = $input->getOption('pool-size');
            if ($poolSizeOpt && is_numeric($poolSizeOpt) && (int)$poolSizeOpt >= $game['pick']) {
                $poolSize = (int)$poolSizeOpt;
            } else {
                $poolSize = (int)$this->promptInput("Ile liczb ma wybrać AI? (dla gry {$gameType} losujemy {$game['pick']} z {$game['from']})", '20');
            }

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

            $sessionsOpt = $input->getOption('sessions');
            $sessions = $sessionsOpt !== null && is_numeric($sessionsOpt) ? (int)$sessionsOpt : null;

            $monthsOpt = $input->getOption('months');
            $months = $monthsOpt !== null && is_numeric($monthsOpt) ? (int)$monthsOpt : null;

            $result = $this->reactAgentService->runAgentLoop($gameType, $poolSize, $aiStrategy, $onStepCallback, $sessions, $months, $includeNeighbours);
            $fullPool = $result['pool'] ?? $result['selected_pool'] ?? [];
            sort($fullPool);

            if ($result['is_fallback'] ?? false) {
                $io->warning(
                    "Agent AI nie zwrócił wyniku analizy — pula pochodzi z trybu awaryjnego.
"
                    . "Nie traktuj jej jako rekomendacji statystycznej."
                );
            }

            if (!empty($result['reasoning'])) {
                $io->text("<comment>Uzasadnienie ReAct Agent:</comment> " . $result['reasoning']);
            }
            $io->success("Pula AI (" . count($fullPool) . " liczb): " . implode(', ', $fullPool));
        } else {
            $poolOpt = $input->getOption('pool');
            $maxNum = $game['from'] ?? 49;

            if ($poolOpt !== null && strtolower(trim((string) $poolOpt)) === 'all') {
                $fullPool = range(1, $maxNum);
            } else {
                $poolStr = ($poolOpt !== null && $poolOpt !== '')
                    ? (string) $poolOpt
                    : (string) $this->promptInput('Podaj pulę liczb oddzielonych spacją lub przecinkiem (np. 1 5 12 18):');
                preg_match_all('/\d+/', $poolStr, $matches);
                $fullPool = array_values(array_unique(array_map('intval', $matches[0] ?? [])));
            }

            $fullPool = array_values(array_filter($fullPool, static fn(int $n): bool => $n >= 1 && $n <= $maxNum));
            sort($fullPool);
            $io->success("Pula ręczna: " . implode(', ', $fullPool));
        }

        if (count($fullPool) < $game['pick']) {
            $io->error("Pula (" . count($fullPool) . ") jest mniejsza niż wymagana ilość do skreślenia (" . $game['pick'] . ")!");
            return Command::FAILURE;
        }

        $modeOpt = $input->getOption('mode');
        if ($modeOpt && in_array((string)$modeOpt, ['1', '2', '3', '4', '5', '6', '7', '8'], true)) {
            $mode = (string)$modeOpt;
        } else {
            $io->section("METODA PRACY (Generator)");
            $mode = $this->promptSelect('Wybierz tryb:', [
                '1' => '[1] RĘCZNY (Jeden blok = Pula wejściowa)',
                '2' => '[2] KREATOR BLOKÓW (Inteligentny Krupier)',
                '3' => '[3] GENERATOR WAŻONY (Statystyczny)',
                '4' => '[4] SYSTEM HYBRYDOWY (Stali Bankierzy + Zmienne z Puli)',
                '5' => '[5] SYSTEM FRAKTALNY (Zaawansowane bloki)',
                '6' => '[6] SYSTEM ROZDZIELNY (Bankierzy Rotacyjni)',
                '7' => '[7] OPTYMALIZACJA STATYSTYCZNA (Synergia Par/Trójek - Tryb Hot)',
                '8' => '[8] RANKINGOWE PEŁNE POKRYCIE (Synergia Par + Gwarancja 100% Puli)',
            ], '8');
        }

        // Ile kuponow LACZNIE (nie na blok - patrz finding B1 w docs/REVIEW.md).
        $betsTotal = (int) ($input->getOption('bets') ?: 0);
        if ($betsTotal < 1) {
            $default = in_array($mode, ['7', '8'], true)
                ? (count($fullPool) >= 40 ? '100' : '25')
                : '10';
            $betsTotal = (int) ($input->isInteractive()
                ? $this->promptInput('Ile zakladow wygenerowac LACZNIE?', $default)
                : $default);
        }

        $sessions = (int) ($input->getOption('sessions') ?: 50);
        $history = $this->historicalDataProvider->fetch($gameType, $sessions);

        if (!$history['ok']) {
            $io->warning($history['warning']);
            $io->note('Kupony zostana wygenerowane, ale bez warstwy statystycznej: wszystkie liczby sa traktowane jednakowo.');
        } elseif ($history['warning'] !== null) {
            $io->note($history['warning']);
        }

        $gameForPipeline = $game;
        $gameForPipeline['_special_frequencies'] = $history['special_frequencies'] ?? [];
        $gameForPipeline['_special_draws'] = $history['special_draws'] ?? [];

        $askNumbers = function (string $option, string $question) use ($input): array {
            $raw = $input->getOption($option);
            // promptInput to wlasny prompt TUI - sam z siebie nie honoruje
            // --no-interaction, wiec trzeba go pominac jawnie.
            if (($raw === null || $raw === '') && $input->isInteractive()) {
                $raw = $this->promptInput($question, '');
            }
            preg_match_all('/\\d+/', (string) $raw, $m);

            return array_values(array_unique(array_map('intval', $m[0] ?? [])));
        };

        $askInt = function (string $option, string $question, string $default) use ($input): int {
            $raw = $input->getOption($option);
            if ($raw === null || $raw === '') {
                $raw = $input->isInteractive() ? $this->promptInput($question, $default) : $default;
            }

            return (int) $raw;
        };

        $request = new BetPipelineRequest(
            game: $gameForPipeline,
            pool: $fullPool,
            mode: $mode,
            betsTotal: $betsTotal,
            frequencies: $history['frequencies'],
            draws: $history['draws'],
            bankers: in_array($mode, ['4', '6'], true)
                ? $askNumbers('bankers', 'Wpisz liczby BANKIEROW z puli:')
                : [],
            bankersPerBet: $mode === '6' ? $askInt('bankers-per-bet', 'Ilu Bankierow na kazdym kuponie?', '3') : 3,
            l1Size: $mode === '5' ? $askInt('l1-size', 'Rozmiar Bloku L1:', '12') : 12,
            l1Count: $mode === '5' ? $askInt('l1-count', 'Ile Blokow L1:', '4') : 4,
            l2Size: $mode === '5' ? $askInt('l2-size', 'Rozmiar Bloku L2:', '8') : 8,
            l2Count: $mode === '5' ? $askInt('l2-count', 'Ile podblokow L2:', '2') : 2,
            blockSize: $mode === '2' ? $askInt('block-size', 'Rozmiar bloku:', '12') : 12,
            blockCount: $mode === '2' ? $askInt('block-count', 'Ile blokow:', '5') : 5,
            hotNumbers: $mode === '3' ? $askNumbers('hot', 'Wpisz liczby GORACE (wieksza waga):') : [],
            weight: $mode === '3' ? $askInt('weight', 'Waga (2-10):', '5') : 5,
        );

        $io->text('Generowanie pakietu (tryb ' . $mode . ')...');

        try {
            $pipelineResult = $this->betPipeline->run($request);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $allFinalBets = $pipelineResult->bets;
        $statsReport = $pipelineResult->statsReport;
        $extraSets = $pipelineResult->extraSets;
        $extraInfo = $pipelineResult->extraInfo;

        $io->success("Wygenerowano " . count($allFinalBets) . " zakładów!");

        if ($extraInfo !== null) {
            $io->text(sprintf(
                'Liczby dodatkowe: %d z %d — %d różnych zestawów z %d możliwych (źródło: %s).',
                (int) $game['extra'],
                (int) $game['extra_from'],
                $extraInfo['distinct_sets'],
                $extraInfo['total_possible'],
                $extraInfo['source'] === 'historical_co_occurrence'
                    ? 'rzeczywiste współwystępowanie'
                    : 'heurystyka częstotliwościowa'
            ));
        }

        foreach ($pipelineResult->warnings as $warning) {
            $io->warning($warning);
        }

        $this->statsWindowRenderer->renderBets(
            $io,
            $allFinalBets,
            $extraSets,
            $statsReport,
            (int) ($input->getOption('max-rows') ?: StatsWindowRenderer::SHOW_ALL)
        );

        if ($statsReport !== null) {
            $this->statsWindowRenderer->renderStatsWindow($io, $statsReport, count($fullPool));
        }

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
            $io->warning("Pula jest zbyt duża (" . count($fullPool) . " liczb) na pełną symulację kombinatoryczną w czasie rzeczywistym.");
        }

        return Command::SUCCESS;
    }
}