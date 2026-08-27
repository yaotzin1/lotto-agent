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

#[AsCommand(
    name: 'app:lotto-generator',
    description: 'Zaawansowany Generator Systemów Hazardzisty v8.1 połączony z AI',
)]
class LottoGeneratorCommand extends Command
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
        $this->addOption('sessions', 's', InputOption::VALUE_REQUIRED, 'Ilość ostatnich losowań do pobrania statystyk');
        $this->addOption('bets', 'b', InputOption::VALUE_REQUIRED, 'Ile zakładów ma wygenerować system?');
        $this->addOption('strategy', 'st', InputOption::VALUE_REQUIRED, 'Strategia doboru liczb przez AI (balanced/aggressive)');
        $this->addOption('pool-size', 'p', InputOption::VALUE_REQUIRED, 'Rozmiar puli liczb wybieranych przez AI');
        $this->addOption('pool-mode', 'pm', InputOption::VALUE_REQUIRED, 'Metoda doboru puli (AI/Manual)');
        $this->addOption('pool', null, InputOption::VALUE_REQUIRED, 'Pula liczb dla trybu Manual (np. "all" albo "1,5,12,18")');
        $this->addOption('pick', null, InputOption::VALUE_REQUIRED, 'Ile liczb skreślać na kuponie (tylko gry o zmiennej liczbie skreśleń: MultiMulti, Keno)');
        $this->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Ogranicz liczbę wierszy w tabeli wyników (domyślnie: wszystkie)');
        $this->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Tryb pracy generatora (1-8)');
        // Opcje dla trybów 2-6, aby udokumentowane komendy dało się uruchomić
        // nieinteraktywnie (wcześniej każda z nich blokowała na $io->ask()).
        $this->addOption('bankers', null, InputOption::VALUE_REQUIRED, 'Tryb 4/6: liczby bankierów (np. "7,13,24")');
        $this->addOption('bankers-per-bet', null, InputOption::VALUE_REQUIRED, 'Tryb 6: ilu bankierów na jednym kuponie');
        $this->addOption('l1-size', null, InputOption::VALUE_REQUIRED, 'Tryb 5: rozmiar bloku L1');
        $this->addOption('l1-count', null, InputOption::VALUE_REQUIRED, 'Tryb 5: liczba bloków L1');
        $this->addOption('l2-size', null, InputOption::VALUE_REQUIRED, 'Tryb 5: rozmiar bloku L2');
        $this->addOption('l2-count', null, InputOption::VALUE_REQUIRED, 'Tryb 5: liczba podbloków L2');
        $this->addOption('block-size', null, InputOption::VALUE_REQUIRED, 'Tryb 2: rozmiar bloku');
        $this->addOption('block-count', null, InputOption::VALUE_REQUIRED, 'Tryb 2: liczba bloków');
        $this->addOption('hot', null, InputOption::VALUE_REQUIRED, 'Tryb 3: liczby gorące o zwiększonej wadze');
        $this->addOption('weight', null, InputOption::VALUE_REQUIRED, 'Tryb 3: waga liczb gorących (2-10)');
    }

    /**
     * Zwraca wartość opcji CLI albo — dopiero gdy jej nie podano — pyta interaktywnie.
     */
    private function optionOrAsk(InputInterface $input, SymfonyStyle $io, string $option, string $question, string $default): string
    {
        $value = $input->getOption($option);
        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        return (string) $io->ask($question, $default);
    }

    /**
     * @return array<int>
     */
    private function parseNumbers(?string $raw): array
    {
        preg_match_all('/\d+/', (string) $raw, $m);

        return array_values(array_unique(array_map('intval', $m[0] ?? [])));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title("========================================================\n||   GENERATOR v8.1 (FRAKTAL & ROZDZIELNY) + AI       ||\n========================================================");

        $gameType = $input->getOption('game');
        if (!$gameType || !$this->gameRegistryService->isValidGame($gameType)) {
            $gameType = $io->choice(
                'Wybierz grę do analizy',
                $this->gameRegistryService->getGameNames(),
                'Lotto'
            );
        }
        $game = $this->gameRegistryService->getGameConfig($gameType);

        // Gry o zmiennej liczbie skreśleń (Multi Multi, Keno) obsługiwane generycznie
        // przez rejestr, zamiast warunku na konkretną nazwę gry.
        // --pool-size NIE słuzy juz do tego: wcześniej ta sama opcja oznaczała
        // jednocześnie "ile skreśleń" i "jak duża pula", więc --pool-size=6 dawało
        // pulę równą liczbie skreśleń, czyli jeden możliwy kupon.
        if ($this->gameRegistryService->isVariablePick($gameType)) {
            $range = $this->gameRegistryService->getPickRange($gameType);
            $pickOpt = $input->getOption('pick');

            if ($pickOpt !== null && is_numeric($pickOpt)) {
                $game['pick'] = $this->gameRegistryService->resolvePick($gameType, (int) $pickOpt);
            } elseif ($input->isInteractive()) {
                $choices = array_map('strval', range($range['min'], $range['max']));
                $game['pick'] = (int) $io->choice(
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
            $poolMode = $io->choice('Jak chcesz wygenerować pulę wejściową liczb?', [
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
                $aiStrategy = $io->choice('Wybierz strategię doboru liczb przez AI:', [
                    'syndicate' => 'Syndykat Klastrowy (60% sąsiedzi, 20% powtórki wygranych, 20% uśpione)',
                    'balanced' => 'Zbalansowana (Klasyczna Hybryda, równowaga)',
                    'aggressive' => 'Ostra Gra (Łowca Trendów - maks. ryzyko na klastry i liczby gorące)',
                ], 'syndicate');
            }
        }

        $fullPool = [];

        if ($poolMode === 'AI') {
            $sessionsOpt = $input->getOption('sessions');
            $sessions = $sessionsOpt !== null && is_numeric($sessionsOpt) ? (int)$sessionsOpt : null;
            if (!$sessions) {
                $sessions = (int)$io->ask('Z ilu ostatnich losowań pobrać statystyki (np. 50)?', '50');
            }

            $poolSizeOpt = $input->getOption('pool-size');
            if ($poolSizeOpt && is_numeric($poolSizeOpt) && (int)$poolSizeOpt >= $game['pick']) {
                $poolSize = (int)$poolSizeOpt;
            } else {
                $poolSize = (int)$io->ask("Ile liczb ma wybrać AI? (dla gry {$gameType} losujemy {$game['pick']} z {$game['from']})", '20');
            }

            $io->text("Uruchamianie ReAct Agent AI (Gemini + Narzędzia Statystyczne)...");

            $onStepCallback = function (string $type, array $data) use ($io): void {
                if ($type === 'tool_call') {
                    $io->text(sprintf(
                        "🤖 <fg=cyan>[Agent Thought/Action]</fg=cyan> Uruchamiam narzędzie <fg=yellow>%s</fg=yellow> z parametrami: %s",
                        $data['tool'],
                        json_encode($data['args'])
                    ));
                } elseif ($type === 'tool_result') {
                    $io->text(sprintf(
                        "📊 <fg=green>[Observation]</fg=green> Otrzymano wynik z narzędzia %s.",
                        $data['tool']
                    ));
                }
            };

            $result = $this->reactAgentService->runAgentLoop($gameType, $poolSize, $aiStrategy, $onStepCallback, $sessions);
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
            if ($poolOpt !== null && strtolower(trim((string) $poolOpt)) === 'all') {
                $fullPool = range(1, $game['from'] ?? 49);
            } else {
                $fullPool = $this->parseNumbers(
                    $this->optionOrAsk($input, $io, 'pool', 'Podaj pulę liczb oddzielonych spacją lub przecinkiem (np. 1 5 12 18):', '')
                );
            }

            $maxNum = $game['from'] ?? 49;
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
            $mode = $io->choice('Wybierz tryb:', [
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
            $betsTotal = (int) $io->ask('Ile zakladow wygenerowac LACZNIE?', $default);
        }

        // Dane historyczne pobierane raz, niezaleznie od trybu.
        $sessions = (int) ($input->getOption('sessions') ?: 50);
        $history = $this->historicalDataProvider->fetch($gameType, $sessions);

        if (!$history['ok']) {
            $io->warning($history['warning']);
            $io->note('Kupony zostana wygenerowane, ale bez warstwy statystycznej: wszystkie liczby sa traktowane jednakowo.');
        } elseif ($history['warning'] !== null) {
            $io->note($history['warning']);
        }

        // Liczby dodatkowe (EuroJackpot 2/12, EkstraPensja/Premia 1/4)
        // przekazywane sa razem z konfiguracja gry.
        $gameForPipeline = $game;
        $gameForPipeline['_special_frequencies'] = $history['special_frequencies'] ?? [];
        $gameForPipeline['_special_draws'] = $history['special_draws'] ?? [];

        $request = new BetPipelineRequest(
            game: $gameForPipeline,
            pool: $fullPool,
            mode: $mode,
            betsTotal: $betsTotal,
            frequencies: $history['frequencies'],
            draws: $history['draws'],
            bankers: $this->parseNumbers(
                in_array($mode, ['4', '6'], true)
                    ? $this->optionOrAsk($input, $io, 'bankers', 'Wpisz liczby BANKIEROW z puli:', '')
                    : ''
            ),
            bankersPerBet: (int) ($mode === '6'
                ? $this->optionOrAsk($input, $io, 'bankers-per-bet', 'Ilu Bankierow na kazdym kuponie?', '3')
                : 3),
            l1Size: (int) ($mode === '5' ? $this->optionOrAsk($input, $io, 'l1-size', 'Rozmiar Bloku L1:', '12') : 12),
            l1Count: (int) ($mode === '5' ? $this->optionOrAsk($input, $io, 'l1-count', 'Ile Blokow L1:', '4') : 4),
            l2Size: (int) ($mode === '5' ? $this->optionOrAsk($input, $io, 'l2-size', 'Rozmiar Bloku L2:', '8') : 8),
            l2Count: (int) ($mode === '5' ? $this->optionOrAsk($input, $io, 'l2-count', 'Ile podblokow L2:', '2') : 2),
            blockSize: (int) ($mode === '2' ? $this->optionOrAsk($input, $io, 'block-size', 'Rozmiar bloku:', '12') : 12),
            blockCount: (int) ($mode === '2' ? $this->optionOrAsk($input, $io, 'block-count', 'Ile blokow:', '5') : 5),
            hotNumbers: $this->parseNumbers(
                $mode === '3'
                    ? $this->optionOrAsk($input, $io, 'hot', 'Wpisz liczby GORACE (wieksza waga):', '')
                    : ''
            ),
            weight: (int) ($mode === '3' ? $this->optionOrAsk($input, $io, 'weight', 'Waga (2-10):', '5') : 5),
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
        $io->section("ANALIZA POKRYCIA (Gwarancje Matematyczne)");
        $assumeHits = $game['pick'];

        if (count($fullPool) <= 30) {
            $io->text("Symulacja wszystkich możliwych wariantów losowania...");
            $io->text("Założenie: Maszyna wylosowała dokładnie $assumeHits liczb, które znajdują się w Twojej puli.");

            try {
                $coverage = $this->generatorService->calculateCoverage($fullPool, $allFinalBets, $assumeHits);

                $covData = [];
                for ($i = 3; $i <= $assumeHits; $i++) {
                    $percent = $coverage['guarantees'][$i];
                    $format = $percent == 100 ? '<fg=green;options=bold>%s%%</>' : ($percent > 50 ? '<fg=yellow>%s%%</>' : '%s%%');
                    $covData[] = [
                        "Gwarancja $i/" . $game['pick'],
                        sprintf($format, $percent),
                    ];
                }

                $io->table(['Cel', 'Szansa na przynajmniej 1 taki kupon'], $covData);
                $io->text("<fg=gray>Przeanalizowano w ułamku sekundy dokładnie " . number_format($coverage['total_draws'], 0, ',', ' ') . " kombinacji.</>");
            } catch (\Exception $e) {
                $io->warning("Nie udało się przeprowadzić symulacji: " . $e->getMessage());
            }
        } else {
            $io->warning("Pula jest zbyt duża (" . count($fullPool) . " liczb) na pełną symulację kombinatoryczną w czasie rzeczywistym.");
        }

        return Command::SUCCESS;
    }
}