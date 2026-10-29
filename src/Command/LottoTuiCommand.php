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
use App\Service\DecadeDistributionService;
use App\Service\ExtraNumbersGenerator;
use App\Service\HistoricalDataProvider;
use App\Service\StatisticalOptimizerService;
use App\Service\StatsWindowRenderer;
use App\Service\StrideBacktestService;
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
        private readonly StrideBacktestService $strideService,
        private readonly DecadeDistributionService $decadeDistributionService,
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
        $this->addOption('pool-mode', 'pm', InputOption::VALUE_REQUIRED, 'Metoda doboru puli (AI/Stride/Decades/Manual)');
        $this->addOption('cover-decades', 'cd', InputOption::VALUE_NONE, 'Wymuś równomierne pokrycie wszystkich dekad');
        $this->addOption('decade-strategy', null, InputOption::VALUE_REQUIRED, 'Strategia doboru wewnątrz dekad (hot/balanced/random)', 'hot');
        $this->addOption('pool', null, InputOption::VALUE_REQUIRED, 'Pula liczb dla trybu Manual (np. "all" albo "1,5,12,18")');
        $this->addOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Ogranicz liczbę wierszy w tabeli wyników (domyślnie: wszystkie)');
        $this->addOption('bankers-per-bet', null, InputOption::VALUE_REQUIRED, 'Tryb 6: ilu bankierów na jednym kuponie');
        $this->addOption('l1-size', null, InputOption::VALUE_REQUIRED, 'Tryb 5: rozmiar bloku L1');
        $this->addOption('l1-count', null, InputOption::VALUE_REQUIRED, 'Tryb 5: liczba bloków L1');
        $this->addOption('l2-size', null, InputOption::VALUE_REQUIRED, 'Tryb 5: rozmiar bloku L2');
        $this->addOption('l2-count', null, InputOption::VALUE_REQUIRED, 'Tryb 5: liczba podbloków L2');
        $this->addOption('block-size', null, InputOption::VALUE_REQUIRED, 'Tryb 2: rozmiar bloku');
        $this->addOption('block-count', null, InputOption::VALUE_REQUIRED, 'Tryb 2: liczba bloków');
        $this->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Tryb pracy generatora (1-9)');
        $this->addOption('latest-draw', 'ld', InputOption::VALUE_REQUIRED, 'Liczby ostatniego losowania dla Trybu 9 (np. "2,3,19,23,42,49")');
        $this->addOption('neighbours', 'nb', InputOption::VALUE_NONE, 'Czy uwzględniać w analizie liczby sąsiadujące (+1/-1)?');
        $this->addOption('with-neighbours', 'wn', InputOption::VALUE_NONE, 'W trybie Decades lub AI: uwzględniaj sąsiadów (±1) ostatnich losowań');
        $this->addOption('neighbours-ratio', 'nr', InputOption::VALUE_REQUIRED, 'Docelowy procentowy udział sąsiadów w puli (np. 60, 60%, 0.6 - domyślnie: 60%)', '60%');
        $this->addOption('bankers', 'bk', InputOption::VALUE_REQUIRED, 'Liczby bankierów oddzielone przecinkami/spacją (dla trybów 4 i 6)');
        $this->addOption('weight', 'w', InputOption::VALUE_REQUIRED, 'Waga dla gorących liczb w generatorze ważonym (dla trybu 3)');
        $this->addOption('stride', null, InputOption::VALUE_REQUIRED, 'Krok kroczenia wstecz dla trybu Stride (np. 257 lub 127)');
        $this->addOption('stride-strategy', null, InputOption::VALUE_REQUIRED, 'Strategia w trybie Stride (anchor_neighbours lub multi_anchor)');
        $this->addOption('anchors', 'a', InputOption::VALUE_REQUIRED, 'Liczba losowań kotwicznych wstecz w trybie Stride (np. 2, 3, 4, domyślnie: auto)');
        $this->addOption('provider', 'pv', InputOption::VALUE_REQUIRED, 'Wybór dostawcy AI: gemini, claude, openai, deepseek');
        $this->addOption('model', 'md', InputOption::VALUE_REQUIRED, 'Model AI (np. gemini-3.8-flash, gemini-2.5-pro, claude-3-7-sonnet-20250219, gpt-4o)');
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

    private function promptConfirm(string $question, bool $default = false): bool
    {
        $answer = $this->promptSelect($question, [
            'y' => 'Tak',
            'n' => 'Nie',
        ], $default ? 'y' : 'n');

        return $answer === 'y';
    }

    /**
     * @return array<int>
     */
    private function parseNumbers(?string $raw): array
    {
        preg_match_all('/\d+/', (string) $raw, $m);

        return array_values(array_unique(array_map('intval', $m[0] ?? [])));
    }

    private function parseRatio(?string $raw, float $default = 0.6): float
    {
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $clean = trim(str_replace('%', '', $raw));
        if (!is_numeric($clean)) {
            return $default;
        }
        $val = (float) $clean;
        if ($val > 1.0) {
            $val /= 100.0;
        }

        return max(0.1, min(0.9, $val));
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

        $coverDecades = (bool) $input->getOption('cover-decades');
        $poolModeOpt = $input->getOption('pool-mode');
        $modeOpt = $input->getOption('mode');
        $sessions = (int) ($input->getOption('sessions') ?: 50);

        // Pobieramy historię losowań na samym starcie, aby wyświetlić pasek statusu
        // oraz uniknąć dwukrotnego odpytywania API/dysku w dalszych krokach.
        $history = $this->historicalDataProvider->fetch($gameType, $sessions);
        $latestDraw = $this->parseNumbers($input->getOption('latest-draw'));
        if ($latestDraw === [] && !empty($history['draws'])) {
            $latestDraw = $history['draws'][0] ?? [];
        }

        // --- BANER STATUSU GRY I OSTATNIEGO LOSOWANIA ---
        $maxNum = (int) ($game['from'] ?? 49);
        $extraLabel = (($game['extra'] ?? 0) > 0)
            ? sprintf(' + %d z %d', (int) $game['extra'], (int) $game['extra_from'])
            : '';
        $latestDrawStr = $latestDraw !== []
            ? implode(', ', $latestDraw)
            : 'Brak w lokalnym archiwum (uruchom app:lotto-archive)';
        $drawsLoadedCount = count($history['draws'] ?? []);

        $io->table(
            ['Parametr Gry', 'Status'],
            [
                ['Wybrana gra', sprintf('<fg=yellow;options=bold>%s</> (%d z %d%s)', $gameType, (int) $game['pick'], $maxNum, $extraLabel)],
                ['Ostatnie losowanie (Kotwice)', sprintf('<fg=cyan;options=bold>[%s]</>', $latestDrawStr)],
                ['Załadowana historia losowań', sprintf('%d sesji %s', $drawsLoadedCount, $drawsLoadedCount >= 20 ? '<fg=green>[OK - Aktywna Synergia EMA Par i Trójek]</>' : '<fg=yellow>[Mało danych - Tryb Heurystyczny]</>')],
            ]
        );

        // --- 1-CLICK SZYBKIE PROFILE GRY (QUICK-START PRESETS) ---
        $selectedPreset = 'custom';
        if (!$poolModeOpt && !$modeOpt && !$coverDecades && $input->isInteractive()) {
            $selectedPreset = $this->promptSelect('Wybierz Szybki Profil Gry (Quick-Start) lub Konfigurację Zaawansowaną:', [
                'preset_syndicate_ai' => '🔥 Szybki Syndykat AI + Kaskada Sąsiadów (Gemini 3.8 + Tryb [9] — Rekomendowany)',
                'preset_zero_drop' => '🎯 Pełny Bęben Zero-Drop (100% liczb bębna + Tryb [9] Kaskada Sąsiadów)',
                'preset_wheeling' => '📐 System Skrócony Wheeling (t-Design Set-Cover — Zawężona pula + Gwarancja 3/k i 4/k)',
                'custom' => '⚙️  Konfiguracja Zaawansowana (Kreator krok po kroku — pełna kontrola wszystkich opcji)',
            ], 'preset_syndicate_ai');
        }

        $presetPoolSize = null;
        $presetMode = null;

        if ($selectedPreset === 'preset_syndicate_ai') {
            $poolMode = 'AI';
            $aiStrategy = 'syndicate';
            $includeNeighbours = true;
            $presetPoolSize = min($maxNum, max((int) $game['pick'] * 3, 20));
            $presetMode = '9';
        } elseif ($selectedPreset === 'preset_zero_drop') {
            $poolMode = 'Manual';
            $includeNeighbours = true;
            $presetMode = '9';
        } elseif ($selectedPreset === 'preset_wheeling') {
            $poolMode = 'Decades';
            $includeNeighbours = true;
            $presetPoolSize = min($maxNum, max((int) $game['pick'] + 4, (int) $game['pick'] * 2 + 2));
            $presetMode = '1';
        } else {
            if ($poolModeOpt && in_array(strtolower($poolModeOpt), ['ai', 'manual', 'stride', 'decades', 'decade'], true)) {
                $poolMode = match (strtolower($poolModeOpt)) {
                    'ai' => 'AI',
                    'stride' => 'Stride',
                    'decades', 'decade' => 'Decades',
                    default => 'Manual',
                };
            } elseif ($coverDecades && !$poolModeOpt) {
                $poolMode = 'Decades';
            } else {
                $poolMode = $this->promptSelect('Jak chcesz wygenerować pulę wejściową liczb?', [
                    'AI' => 'AI (Na podstawie statystyk LOTTO API i ReAct Agent)',
                    'Stride' => 'Stride / Kroczenie N (Losowania wstecz co N kroków + sąsiedzi)',
                    'Decades' => 'Dekady (Równomierne pokrycie wszystkich dekad bębna)',
                    'Manual' => 'Ręcznie (Wpisz własne liczby lub wybierz cały bęben "all")',
                ], $coverDecades ? 'Decades' : 'AI');
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

            $includeNeighbours = (bool) ($input->getOption('with-neighbours') || $input->getOption('neighbours'));
        }

        $neighboursRatio = $this->parseRatio((string) $input->getOption('neighbours-ratio'));

        $fullPool = [];

        if ($poolMode === 'AI') {
            $poolSizeOpt = $input->getOption('pool-size');
            if ($poolSizeOpt && is_numeric($poolSizeOpt) && (int)$poolSizeOpt >= $game['pick']) {
                $poolSize = (int)$poolSizeOpt;
            } elseif ($presetPoolSize !== null) {
                $poolSize = (int) $this->promptInput(
                    "Ile liczb ma wybrać AI do puli? (dla gry {$gameType} losujemy {$game['pick']} z {$game['from']})",
                    (string) $presetPoolSize
                );
            } else {
                $poolSize = (int)$this->promptInput("Ile liczb ma wybrać AI? (dla gry {$gameType} losujemy {$game['pick']} z {$game['from']})", '20');
            }

            $providerOpt = $input->getOption('provider');
            $modelOpt = $input->getOption('model');

            if (!$providerOpt && !$modelOpt && $input->isInteractive()) {
                $aiChoice = $this->promptSelect('Wybierz silnik i model AI do analizy ReAct:', [
                    'gemini:gemini-3.8-flash' => '⚡ Google Gemini 3.8 Flash (Domyślny — szybka pętla narzędziowa ReAct)',
                    'gemini:gemini-3.7-flash' => '🚀 Google Gemini 3.7 Flash (Alternatywny szybki model ReAct)',
                    'gemini:gemini-2.5-pro' => '🧠 Google Gemini 2.5 Pro (Głębokie wnioskowanie matematyczne i kombinatoryczne)',
                    'gemini:gemini-3.1-pro-preview' => '🔬 Google Gemini 3.1 Pro Preview (Eksperymentalny model Pro)',
                    'claude:claude-3-7-sonnet-20250219' => '🤖 Anthropic Claude 3.7 Sonnet (Wymaga ANTHROPIC_API_KEY)',
                    'openai:gpt-4o' => '🌐 OpenAI GPT-4o (Wymaga OPENAI_API_KEY)',
                    'deepseek:deepseek-reasoner' => '📐 DeepSeek-R1 Reasoner CoT (Wymaga DEEPSEEK_API_KEY)',
                ], 'gemini:gemini-3.8-flash');

                [$providerOpt, $modelOpt] = explode(':', $aiChoice, 2);
            }

            if ($selectedPreset === 'custom' && !$input->hasParameterOption('--with-neighbours') && !$input->hasParameterOption('--neighbours') && !$input->hasParameterOption('-wn') && !$input->hasParameterOption('-nb') && $input->isInteractive()) {
                $includeNeighbours = $this->promptConfirm('Czy włączyć analizę sąsiadów (±1 wokół ostatnich kotwic) w pętli AI?', true);
            }

            $io->text(sprintf(
                'Uruchamianie ReAct Agent AI (<fg=cyan>%s / %s</> + Narzędzia Statystyczne)...',
                $providerOpt ?: 'gemini',
                $modelOpt ?: 'gemini-3.8-flash'
            ));

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
                        "🧠 <fg=gray>[Agent Thinking]</fg=gray> Tura %d: Model AI przetwarza obserwacje...",
                        $data['turn']
                    ));
                }
            };

            $sessionsOpt = $input->getOption('sessions');
            $aiSessions = $sessionsOpt !== null && is_numeric($sessionsOpt) ? (int)$sessionsOpt : null;

            $monthsOpt = $input->getOption('months');
            $months = $monthsOpt !== null && is_numeric($monthsOpt) ? (int)$monthsOpt : null;

            $result = $this->reactAgentService->runAgentLoop($gameType, $poolSize, $aiStrategy, $onStepCallback, $aiSessions, $months, $includeNeighbours, $coverDecades, $providerOpt, $modelOpt);
            $fullPool = $result['pool'] ?? $result['selected_pool'] ?? [];
            sort($fullPool);

            if ($result['is_fallback'] ?? false) {
                $io->warning(
                    "Agent AI nie zwrócił wyniku analizy — pula pochodzi z trybu awaryjnego.\n"
                    . "Nie traktuj jej jako rekomendacji statystycznej."
                );
            }

            if (!empty($result['reasoning'])) {
                $io->text("<comment>Uzasadnienie ReAct Agent:</comment> " . $result['reasoning']);
            }
            $io->success("Pula AI (" . count($fullPool) . " liczb): " . implode(', ', $fullPool));
        } elseif ($poolMode === 'Stride') {
            $strideOpt = $input->getOption('stride');
            $stride = ($strideOpt !== null && is_numeric($strideOpt))
                ? max(1, (int) $strideOpt)
                : (int) $this->promptInput('Podaj krok kroczenia N wstecz w historii (np. 257 lub 127):', '257');

            $strideStratOpt = $input->getOption('stride-strategy');
            if ($strideStratOpt && in_array($strideStratOpt, ['anchor_neighbours', 'multi_anchor'], true)) {
                $strideStrategy = (string) $strideStratOpt;
            } else {
                $strideStrategy = $this->promptSelect('Wybierz wariant strategii kroczenia:', [
                    'anchor_neighbours' => 'Kotwica (losowanie T-N) + sąsiedzi (±1)',
                    'multi_anchor' => 'Wielokrotne kotwice (losowania T-N, T-2N...) + sąsiedzi',
                ], 'anchor_neighbours');
            }

            $poolSizeOpt = $input->getOption('pool-size');
            if ($poolSizeOpt && is_numeric($poolSizeOpt) && (int) $poolSizeOpt >= $game['pick']) {
                $poolSize = (int) $poolSizeOpt;
            } else {
                $poolSize = (int) $this->promptInput(sprintf(
                    'Ile liczb ma zawierać pula Stride? (gra %s: %d z %d, rekomendowane: %d)',
                    $gameType,
                    $game['pick'],
                    $game['from'],
                    min((int) $game['from'], 2 * (int) $game['pick'])
                ), (string) min((int) $game['from'], 2 * (int) $game['pick']));
            }
            $poolSize = max((int) $game['pick'], min((int) $game['from'], $poolSize));

            $anchorsOpt = $input->getOption('anchors');
            $anchorCount = ($anchorsOpt !== null && is_numeric($anchorsOpt))
                ? max(1, (int) $anchorsOpt)
                : null;

            if ($strideStrategy === 'multi_anchor' && $anchorCount === null && $input->isInteractive()) {
                $autoAnchors = $this->strideService->autoAnchorCount($poolSize, $gameType);
                $ans = $this->promptInput(sprintf('Ile losowań kotwicznych wstecz (kroków N) próbkować? [Enter dla auto: %d]', $autoAnchors), (string) $autoAnchors);
                if (is_numeric($ans) && (int) $ans > 0) {
                    $anchorCount = (int) $ans;
                }
            }

            $refresh = $this->strideService->refreshArchive($gameType);
            if ($refresh !== null) {
                if ($refresh['added'] > 0) {
                    $io->text(sprintf('↻ Archiwum %s uzupełnione o %d nowych losowań z LOTTO OpenAPI.', $gameType, $refresh['added']));
                }
                if ($refresh['warning'] !== null) {
                    $io->warning($refresh['warning']);
                }
            }

            try {
                $strideInfo = $this->strideService->getStridePoolInfo($stride, $poolSize, $strideStrategy, null, $anchorCount, $gameType);
                $fullPool = $strideInfo['pool'];
                sort($fullPool);

                $strideFreshness = $strideInfo['freshness'];
                if ($strideFreshness !== null && $strideFreshness['stale']) {
                    $io->warning(sprintf(
                        "Archiwum losowań %s jest nieaktualne: ostatnie losowanie w bazie to %s, a od tego czasu "
                        . "odbyło się jeszcze %d losowań (ostatnie oczekiwane: %s).\n"
                        . 'Kotwice T-N liczone są od ostatniego wiersza archiwum, więc wszystkie są przesunięte o tyle samo losowań.',
                        $gameType,
                        $strideFreshness['last_date'] ?? 'brak',
                        $strideFreshness['missing'],
                        $strideFreshness['expected_date'] ?? 'brak'
                    ));
                }

                $io->section(sprintf(
                    'Wykorzystane losowania kotwiczne (gra %s: %d z %d, krok N=%d, %d losowań):',
                    $gameType,
                    $game['pick'],
                    $game['from'],
                    $stride,
                    count($strideInfo['anchor_draws'])
                ));
                foreach ($strideInfo['anchor_draws'] as $ad) {
                    $io->text(sprintf(' - Losowanie #%d (%s, %d wstecz): %s', $ad['index'], $ad['date'], $ad['stride_back'], implode(', ', $ad['numbers'])));
                }

                $io->text(sprintf('★ Kotwice (%d): %s', count($strideInfo['anchors']), implode(', ', $strideInfo['anchors'])));
                $io->text(sprintf('⚡ Sąsiedzi ±1 (%d): %s', count($strideInfo['neighbours']), implode(', ', $strideInfo['neighbours'])));
                $io->success('Pula Stride (' . count($fullPool) . ' liczb): ' . implode(', ', $fullPool));
            } catch (\Throwable $e) {
                $io->error('Błąd pobierania puli Stride: ' . $e->getMessage());
                return Command::FAILURE;
            }
        } elseif ($poolMode === 'Decades') {
            $decadesCount = (int) ceil($maxNum / 10);
            $defaultPoolSize = (string) ($presetPoolSize ?? min($maxNum, max($game['pick'], 3 * $decadesCount)));

            $poolSizeOpt = $input->getOption('pool-size');
            if ($poolSizeOpt && is_numeric($poolSizeOpt) && (int) $poolSizeOpt >= $game['pick']) {
                $poolSize = (int) $poolSizeOpt;
            } else {
                $poolSize = (int) $this->promptInput(
                    sprintf("Ile liczb ma zawierać pula dekadowa? (gra %s: %d dekad, rekomendowane: %s)", $gameType, $decadesCount, $defaultPoolSize),
                    $defaultPoolSize
                );
            }
            $poolSize = max($game['pick'], min($maxNum, $poolSize));

            $frequencies = $history['frequencies'] ?? [];

            $stratOpt = $input->getOption('decade-strategy');
            if ($stratOpt && in_array($stratOpt, ['hot', 'balanced', 'random'], true)) {
                $decadeStrategy = (string) $stratOpt;
            } elseif ($selectedPreset === 'custom' && $input->isInteractive()) {
                $decadeStrategy = $this->promptSelect('Wybierz strategię wyboru liczb wewnątrz dekad:', [
                    'hot' => 'Gorące (najczęstsze liczby w historii dla danej dekady)',
                    'balanced' => 'Zbalansowana (60% gorące, 40% zimne)',
                    'random' => 'Losowa (równomierny wybór losowy w dekadzie)',
                ], 'hot');
            } else {
                $decadeStrategy = 'hot';
            }

            if ($selectedPreset === 'custom' && !$input->hasParameterOption('--with-neighbours') && !$input->hasParameterOption('--neighbours') && !$input->hasParameterOption('-wn') && !$input->hasParameterOption('-nb') && $input->isInteractive()) {
                $includeNeighbours = $this->promptSelect('Czy uwzględnić w dekadach sąsiadów (±1) z ostatniego losowania?', [
                    'yes' => 'Tak - priorytetyzuj sąsiadów ±1 w dekadach',
                    'no' => 'Nie - czysta strategia częstotliwościowa',
                ], 'no') === 'yes';
            }

            $decadeResult = $this->decadeDistributionService->generateDecadePool(
                $maxNum,
                $poolSize,
                $frequencies,
                $decadeStrategy,
                $latestDraw,
                $includeNeighbours,
                $neighboursRatio
            );
            $fullPool = $decadeResult['pool'];

            $io->section(sprintf(
                'Rozkład dekadowy puli (%d liczb z %d)%s:',
                count($fullPool),
                $maxNum,
                $includeNeighbours ? sprintf(' [z sąsiadami ±1, limit: %d%%]', (int) round($neighboursRatio * 100)) : ''
            ));
            if ($includeNeighbours && !empty($decadeResult['anchors_used'])) {
                $io->text(sprintf(' Kotwice wygranych (baza sąsiadów): [%s]', implode(', ', $decadeResult['anchors_used'])));
            }
            foreach ($decadeResult['breakdown'] as $b) {
                $nbrInfo = '';
                if ($includeNeighbours && !empty($b['neighbours_selected'])) {
                    $nbrInfo = sprintf(' (w tym sąsiedzi ±1: %s)', implode(', ', $b['neighbours_selected']));
                }
                $io->text(sprintf(
                    ' • Dekada %-7s: %2d liczb -> [%s]%s',
                    $b['label'],
                    $b['quota'],
                    implode(', ', $b['selected']),
                    $nbrInfo
                ));
            }

            $io->success(sprintf(
                'Pula dekadowa (%d liczb)%s: %s',
                count($fullPool),
                $includeNeighbours ? sprintf(' [%d sąsiadów]', $decadeResult['neighbours_count'] ?? 0) : '',
                implode(', ', $fullPool)
            ));
        } else {
            $poolOpt = $input->getOption('pool');

            if ($selectedPreset === 'preset_zero_drop' || ($poolOpt !== null && strtolower(trim((string) $poolOpt)) === 'all')) {
                $fullPool = range(1, $maxNum);
            } else {
                $poolStr = ($poolOpt !== null && $poolOpt !== '')
                    ? (string) $poolOpt
                    : (string) $this->promptInput('Podaj pulę liczb oddzielonych spacją/przecinkiem lub wpisz "all" dla całego bębna:', 'all');
                if (strtolower(trim($poolStr)) === 'all') {
                    $fullPool = range(1, $maxNum);
                } else {
                    preg_match_all('/\d+/', $poolStr, $matches);
                    $fullPool = array_values(array_unique(array_map('intval', $matches[0] ?? [])));
                }
            }

            $fullPool = array_values(array_filter($fullPool, static fn(int $n): bool => $n >= 1 && $n <= $maxNum));
            sort($fullPool);
            $io->success(sprintf('Pula wejściowa (%d liczb): %s', count($fullPool), implode(', ', $fullPool)));
        }

        if (count($fullPool) < $game['pick']) {
            $io->error("Pula (" . count($fullPool) . ") jest mniejsza niż wymagana ilość do skreślenia (" . $game['pick'] . ")!");
            return Command::FAILURE;
        }

        if ($modeOpt && in_array((string)$modeOpt, ['1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            $mode = (string)$modeOpt;
        } elseif ($presetMode !== null) {
            $mode = $presetMode;
        } else {
            $io->section("METODA PRACY (Generator)");
            $mode = $this->promptSelect('Wybierz tryb:', [
                '1' => '[1] SYSTEM SKRÓCONY WHEELING (t-Design Set-Cover + Bucket Balance)',
                '2' => '[2] KREATOR BLOKÓW (Inteligentny Krupier)',
                '3' => '[3] GENERATOR WAŻONY (Statystyczny)',
                '4' => '[4] SYSTEM HYBRYDOWY (Stali Bankierzy + Zmienne z Puli)',
                '5' => '[5] SYSTEM FRAKTALNY (Zaawansowane bloki)',
                '6' => '[6] SYSTEM ROZDZIELNY (Bankierzy Rotacyjni)',
                '7' => '[7] OPTYMALIZACJA STATYSTYCZNA (Synergia Par/Trójek EMA - Tryb Hot)',
                '8' => '[8] RANKINGOWE PEŁNE POKRYCIE (Synergia Par/Trójek + Gwarancja 100% Puli)',
                '9' => '[9] KASKADOWY SYSTEM SĄSIADÓW (Warstwy Siły: Sąsiedzi -> Bufor -> Pełne Pokrycie)',
            ], '9');
        }

        // Ile kuponow LACZNIE (nie na blok - patrz finding B1 w docs/REVIEW.md).
        $betsTotal = (int) ($input->getOption('bets') ?: 0);
        if ($betsTotal < 1) {
            $baseZeroDropBets = (int) ceil(count($fullPool) / max(1, (int) $game['pick']));
            $default = in_array($mode, ['7', '8', '9'], true)
                ? (string) max($baseZeroDropBets * 2, (count($fullPool) >= 40 ? 25 : 15))
                : '12';
            $betsTotal = (int) ($input->isInteractive()
                ? $this->promptInput(sprintf('Ile zakładów wygenerować ŁĄCZNIE? (min. %d dla 100%% pokrycia puli)', $baseZeroDropBets), $default)
                : $default);
        }

        if (!$history['ok']) {
            $io->warning($history['warning']);
            $io->note('Kupony zostaną wygenerowane, ale bez warstwy statystycznej: wszystkie liczby są traktowane jednakowo.');
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

        while (true) {
            $request = new BetPipelineRequest(
                game: $gameForPipeline,
                pool: $fullPool,
                mode: $mode,
                betsTotal: $betsTotal,
                frequencies: $history['frequencies'],
                draws: $history['draws'],
                bankers: in_array($mode, ['4', '6'], true)
                    ? $askNumbers('bankers', 'Wpisz liczby BANKIERÓW z puli:')
                    : [],
                bankersPerBet: $mode === '6' ? $askInt('bankers-per-bet', 'Ilu Bankierów na każdym kuponie?', '3') : 3,
                l1Size: $mode === '5' ? $askInt('l1-size', 'Rozmiar Bloku L1:', '12') : 12,
                l1Count: $mode === '5' ? $askInt('l1-count', 'Ile Bloków L1:', '4') : 4,
                l2Size: $mode === '5' ? $askInt('l2-size', 'Rozmiar Bloku L2:', '8') : 8,
                l2Count: $mode === '5' ? $askInt('l2-count', 'Ile podbloków L2:', '2') : 2,
                blockSize: $mode === '2' ? $askInt('block-size', 'Rozmiar bloku:', '12') : 12,
                blockCount: $mode === '2' ? $askInt('block-count', 'Ile bloków:', '5') : 5,
                hotNumbers: $mode === '3' ? $askNumbers('hot', 'Wpisz liczby GORĄCE (większa waga):') : [],
                weight: $mode === '3' ? $askInt('weight', 'Waga (2-10):', '5') : 5,
                coverDecades: $coverDecades || $poolMode === 'Decades',
                withNeighbours: $includeNeighbours,
                neighboursRatio: $neighboursRatio,
                latestDraw: $latestDraw,
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

            // --- WIZUALNA MAPA BĘBNA (HEATMAPA 1..N) ---
            $this->statsWindowRenderer->renderDrumHeatmap(
                $io,
                $allFinalBets,
                $fullPool,
                $maxNum,
                $latestDraw
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

            if (!$input->isInteractive()) {
                break;
            }

            // --- INTERAKTYWNE MENU PO WYGENEROWANIU KUPONÓW ---
            while (true) {
                $postAction = $this->promptSelect('Co chcesz zrobić dalej z wygenerowanym pakietem?', [
                    'finish' => '🚪 Zakończ pracę generatora',
                    'export' => '💾 Zapisz kupony do pliku (TXT / CSV / JSON w var/exports/)',
                    'deep_stats' => '📈 Pokaż szczegółowy rozkład Gaussa, Top Pary, Trójki (3-Cliques) i pokrycie t-Design',
                    'rerun_mode' => '🔄 Przelicz tę samą pulę w innym trybie (np. porównaj Tryb 9, 8, 7, 1)',
                ], 'finish');

                if ($postAction === 'finish') {
                    return Command::SUCCESS;
                }

                if ($postAction === 'export') {
                    $format = $this->promptSelect('Wybierz format eksportu kuponów:', [
                        'txt' => '📄 TXT (Czytelny arkusz do przepisania na blankiety)',
                        'csv' => '📊 CSV (Arkusz kalkulacyjny Excel / LibreOffice)',
                        'json' => '🧩 JSON (Pełny zrzut danych ze statystykami)',
                    ], 'txt');

                    $exportPath = $this->exportGeneratedBets($gameType, $mode, $fullPool, $allFinalBets, $extraSets, $statsReport, $format);
                    $io->success(sprintf('Zapisano %d zakładów do pliku: %s', count($allFinalBets), $exportPath));
                    continue;
                }

                if ($postAction === 'deep_stats') {
                    $subsetsCov = $this->generatorService->calculateSubsetsCoverage($fullPool, $allFinalBets);
                    $this->statsWindowRenderer->renderDeepSynergyAndGaussianDetails($io, $statsReport, $subsetsCov);
                    continue;
                }

                if ($postAction === 'rerun_mode') {
                    $mode = $this->promptSelect('Wybierz nowy tryb generatora dla tej samej puli (' . count($fullPool) . ' liczb):', [
                        '9' => '[9] KASKADOWY SYSTEM SĄSIADÓW (Warstwy Siły: Sąsiedzi -> Bufor -> Pełne Pokrycie)',
                        '8' => '[8] RANKINGOWE PEŁNE POKRYCIE (Synergia Par/Trójek + Gwarancja 100% Puli)',
                        '7' => '[7] OPTYMALIZACJA STATYSTYCZNA (Synergia Par/Trójek EMA - Tryb Hot)',
                        '1' => '[1] SYSTEM SKRÓCONY WHEELING (t-Design Set-Cover + Bucket Balance)',
                        '2' => '[2] KREATOR BLOKÓW (Inteligentny Krupier)',
                        '3' => '[3] GENERATOR WAŻONY (Statystyczny)',
                        '4' => '[4] SYSTEM HYBRYDOWY (Stali Bankierzy + Zmienne z Puli)',
                        '5' => '[5] SYSTEM FRAKTALNY (Zaawansowane bloki)',
                        '6' => '[6] SYSTEM ROZDZIELNY (Bankierzy Rotacyjni)',
                    ], $mode);

                    $betsTotal = (int) $this->promptInput('Ile zakładów wygenerować w nowym trybie?', (string) $betsTotal);
                    break; // Wróć do pętli głównej generowania z nowym trybem!
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Eksportuje wygenerowany pakiet kuponów do pliku w katalogu var/exports/.
     *
     * @param array<int> $pool
     * @param array<int, array<int>> $bets
     * @param array<int, array<int>> $extraSets
     * @param array<string, mixed>|null $statsReport
     */
    private function exportGeneratedBets(
        string $gameType,
        string $mode,
        array $pool,
        array $bets,
        array $extraSets,
        ?array $statsReport,
        string $format
    ): string {
        $exportDir = dirname(__DIR__, 2) . '/var/exports';
        if (!is_dir($exportDir)) {
            @mkdir($exportDir, 0775, true);
        }

        $timestamp = date('Y-m-d_His');
        $safeGame = preg_replace('/[^a-zA-Z0-9_-]/', '', $gameType) ?: 'Lotto';
        $filename = sprintf('%s/kupony_%s_tryb%s_%s.%s', $exportDir, $safeGame, $mode, $timestamp, $format);

        if ($format === 'json') {
            $payload = [
                'generated_at' => date(DATE_ATOM),
                'game' => $gameType,
                'mode' => $mode,
                'pool_size' => count($pool),
                'pool' => $pool,
                'bets_count' => count($bets),
                'bets' => $bets,
                'extra_sets' => $extraSets,
                'stats_summary' => $statsReport !== null ? [
                    'unique_numbers_used' => $statsReport['unique_numbers_used'] ?? count($pool),
                    'pool_coverage_pct' => $statsReport['pool_coverage_pct'] ?? 100.0,
                    'pairs_coverage_pct' => $statsReport['pairs_coverage_pct'] ?? 0.0,
                    'top_pairs' => $statsReport['top_pairs'] ?? [],
                    'top_triplets' => $statsReport['top_triplets'] ?? [],
                ] : null,
            ];
            file_put_contents($filename, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $filename;
        }

        if ($format === 'csv') {
            $lines = ['Nr;Liczby_Glowne;Liczby_Dodatkowe;Suma'];
            foreach ($bets as $idx => $bet) {
                $extraStr = isset($extraSets[$idx]) && $extraSets[$idx] !== []
                    ? implode(' ', $extraSets[$idx])
                    : '';
                $lines[] = sprintf(
                    '%d;"%s";"%s";%d',
                    $idx + 1,
                    implode(', ', $bet),
                    $extraStr,
                    array_sum($bet)
                );
            }
            file_put_contents($filename, implode(PHP_EOL, $lines) . PHP_EOL);

            return $filename;
        }

        // Domyślny format TXT
        $lines = [
            '==============================================================',
            sprintf(' PAKIET KUPONÓW LOTTO AGENT — GRA: %s (TRYB %s)', strtoupper($gameType), $mode),
            sprintf(' Data wygenerowania: %s', date('Y-m-d H:i:s')),
            sprintf(' Pula wejściowa (%d liczb): %s', count($pool), implode(', ', $pool)),
            sprintf(' Liczba zakładów: %d', count($bets)),
            '==============================================================',
            '',
        ];

        foreach ($bets as $idx => $bet) {
            $numsFormatted = implode(', ', array_map(static fn(int $n): string => sprintf('%2d', $n), $bet));
            $extraStr = isset($extraSets[$idx]) && $extraSets[$idx] !== []
                ? sprintf('  + [%s]', implode(', ', $extraSets[$idx]))
                : '';
            $lines[] = sprintf('Zakład #%03d:  %s%s   (Suma: %d)', $idx + 1, $numsFormatted, $extraStr, array_sum($bet));
        }

        $lines[] = '';
        $lines[] = '==============================================================';
        file_put_contents($filename, implode(PHP_EOL, $lines) . PHP_EOL);

        return $filename;
    }
}