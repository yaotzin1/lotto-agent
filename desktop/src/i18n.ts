export type Lang = 'pl' | 'en';

export interface Translations {
  subtitle: string;
  tabGenerator: string;
  tabAgent: string;
  tabArchive: string;
  tabLogs: string;
  tabManual: string;
  sidecarOnline: (phpVersion: string) => string;
  sidecarOffline: string;
  sidecarConnectionError: (msg: string) => string;

  // Generator tab
  configuration: string;
  selectedCount: (selected: number, max: number) => string;
  lotteryGame: string;
  of: string;
  wheelingMode: string;
  modes: Record<string, string>;
  modeDescriptions: Record<string, string>;
  poolModeLabel: string;
  poolModes: Record<'manual' | 'ai' | 'decades', string>;
  poolModeDescriptions: Record<'manual' | 'ai' | 'decades', string>;
  manualPoolInputLabel: string;
  manualPoolInputPlaceholder: string;
  manualPoolApply: string;
  bankersLabel: (selected: number, maxInfo: string) => string;
  bankersAutoPick: string;
  bankersAiPick: string;
  bankersAutoCountLabel: string;
  bankersManualInputLabel: string;
  bankersClear: string;
  bankersPerBetLabel: string;
  clickModePool: string;
  clickModeBanker: string;
  bankerGridHint: string;
  couponsBets: string;
  coverDecades: string;
  neighbours: string;
  aiCommentary: string;
  candidatePoolLabel: (minPick: number) => string;
  poolAll: (max: number) => string;
  poolRand18: string;
  poolAiPick: string;
  poolClear: string;
  btnGenerate: string;
  btnStatsReport: string;
  aiStrategicCommentary: string;
  statsSummaryTitle: string;
  poolCoverage: string;
  gaussianOptimalWindow: string;
  couponsInGaussianWindow: string;
  uniquePairsCovered: string;
  gaussianAlignment: (pct: number) => string;
  generatedCoupons: (count: number) => string;
  selectedCouponsCount: (selected: number) => string;
  exportCsv: string;
  exportTxt: string;
  exportJson: string;
  emptyCouponsHint: string;

  // Agent tab
  agentTitle: string;
  gameLabel: string;
  strategyLabel: string;
  strategies: Record<string, string>;
  strategyDescriptions: Record<string, string>;
  targetPoolSize: string;
  aiProvider: string;
  modelLabel: string;
  modelDefault: (def: string) => string;
  historicalWindow: string;
  decadeBalance: string;
  btnRunAgentRunning: string;
  btnRunAgent: string;
  agentOutput: string;
  agentOutputHint: string;
  selectedCandidatePool: (count: number) => string;
  usePoolInGenerator: string;
  fallbackPoolAlert: string;
  reactToolTrace: (count: number) => string;
  noToolCalls: string;
  turnLabel: (turn: number) => string;
  argsLabel: string;

  // Archive & Settings tab
  settingsTitle: string;
  settingsHint: string;
  secretLabels: Record<string, string>;
  configured: (masked: string) => string;
  notConfigured: string;
  enterNewKey: (envKey: string) => string;
  defaultAiProvider: string;
  defaultAiModelOverride: string;
  saveSettings: string;
  localDrawArchives: string;
  syncModes: Record<string, string>;
  syncModeDescriptions: Record<string, string>;
  syncGame: (game: string) => string;
  colGame: string;
  colTotalDraws: string;
  colLastDrawDate: string;
  colExpected: string;
  colMissing: string;
  colStatus: string;
  statusStale: string;
  statusFresh: string;

  // Grid columns & Logs tab
  colCouponIndex: string;
  colNumbers: string;
  colSum: string;
  colParity: string;
  colDecades: string;
  colTurn: string;
  colTool: string;
  colArgs: string;
  logsTitle: string;
  logsSubtitle: string;
  logsRefresh: string;
  logsClear: string;
  logsFilterLevel: string;
  logsAllLevels: string;
  logsColTime: string;
  logsColLevel: string;
  logsColSource: string;
  logsColMessage: string;
  themeDark: string;
  themeLight: string;
  manualSidebarTitle: string;
  manualDocDesktopTitle: string;
  manualDocHowToPlayTitle: string;
  manualDocStrategyTitle: string;
  manualDocSpecTitle: string;
}

export const translations: Record<Lang, Translations> = {
  pl: {
    subtitle: 'Silnik kombinatoryczny Symfony 8.2 i strateg ReAct Multi-LLM',
    tabGenerator: 'Generator i Optymalizator',
    tabAgent: 'Agent AI ReAct',
    tabArchive: 'Archiwum i Ustawienia',
    tabLogs: 'Logi',
    tabManual: 'Podręcznik / Jak grać',
    sidecarOnline: (v) => `PHP ${v} Sidecar Online`,
    sidecarOffline: 'Sidecar Offline',
    sidecarConnectionError: (msg) => `Błąd połączenia z backendem PHP: ${msg}`,

    configuration: 'Konfiguracja',
    selectedCount: (sel, max) => `wybrano ${sel} / ${max}`,
    lotteryGame: 'Gra losowa',
    of: 'z',
    wheelingMode: 'Tryb systemu / optymalizacji',
    modes: {
      '1': 'Tryb 1 — Zrównoważony skrócony',
      '2': 'Tryb 2 — Inteligentne unikalne bloki',
      '3': 'Tryb 3 — Ważona pula gorących liczb',
      '4': 'Tryb 4 — Stałe pewniaki (Bankers)',
      '5': 'Tryb 5 — Bloki fraktalne L1/L2',
      '6': 'Tryb 6 — Rotacyjne pewniaki',
      '7': 'Tryb 7 — Koncentracja rozwodnienia',
      '8': 'Tryb 8 — Pełne pokrycie (Zero-Drop)',
      '9': 'Tryb 9 — Kaskada sąsiadów (Tiered)',
    },
    modeDescriptions: {
      '1': 'System skrócony (balanced shorthand): równomiernie rozkłada wybrane liczby na zadaną liczbę kuponów, minimalizując powtórzenia trójek i czwórek.',
      '2': 'Inteligentny krupier (unique blocks): dzieli pulę na rozłączne i częściowo nakładające się bloki, maksymalizując unikalne pokrycie par.',
      '3': 'Ważona pula gorących liczb: nadaje wyższą wagę liczbom o najwyższej częstotliwości historycznej i częściej umieszcza je w kuponach.',
      '4': 'Stałe pewniaki (Fixed Bankers): wybrane liczby bazowe (kotwice) trafiają do każdego kuponu, a pozostałe miejsca są rotowane z reszty puli.',
      '5': 'Bloki fraktalne L1/L2: hierarchiczny podział puli na warstwę główną (L1) i uzupełniającą (L2) z kontrolowanym przenikaniem.',
      '6': 'Rotacyjne pewniaki (Split Bankers): dzieli pewniaki na podgrupy i rotuje je między kuponami, zwiększając odporność na pudło pojedynczego pewniaka.',
      '7': 'Optymalizator rozwodnienia: dobiera kombinacje o najwyższej historycznej synergii par, pilnując krzywej Gaussa (1.35σ), parzystości i rozkładu dekad.',
      '8': 'Pełne pokrycie (Zero-Drop): gwarantuje, że każda liczba z wybranej puli pojawi się na kuponach (brak pominiętych liczb) przy zachowaniu reguł Gaussa.',
      '9': 'Kaskada sąsiadów (Tiered): dzieli bęben na 3 warstwy siły (kotwice + sąsiedzi ±1, bufor ±2 i reszta puli) i szereguje kupony od najsilniejszego.',
    },
    poolModeLabel: 'Metoda doboru puli (Pool Mode)',
    poolModes: {
      manual: 'Manual — Ręczny wybór lub wpisanie liczb',
      ai: 'AI — Automatyczny dobór puli przez Agenta ReAct',
      decades: 'Decades — Automatyczny balans dekad',
    },
    poolModeDescriptions: {
      manual: 'Ręcznie klikasz liczby na siatce lub wpisujesz je po przecinku (albo "all" dla całego bębna). Pełna kontrola nad pulą wejściową.',
      ai: 'Przed wygenerowaniem kuponów Agent AI ReAct analizuje historię losowań, klastry i pary, dobiera optymalną pulę liczb i generuje z niej zakłady.',
      decades: 'Automatycznie wybiera równomierną liczbę kandydatów z każdej dekady (1–10, 11–20, 21–30...), zapewniając szeroki rozkład na bębnie.',
    },
    manualPoolInputLabel: 'Wpisz liczby ręcznie (po przecinku lub "all")',
    manualPoolInputPlaceholder: 'np. 1, 5, 12, 18, 25, 33 lub all',
    manualPoolApply: 'Zastosuj',
    bankersLabel: (sel, maxInfo) => `Pewniaki ★ (wybrano ${sel}, ${maxInfo})`,
    bankersAutoPick: 'Auto-dobierz ★',
    bankersAiPick: 'Dobierz AI ★',
    bankersAutoCountLabel: 'Ile auto-dobrać ★',
    bankersManualInputLabel: 'Wpisz pewniaki po przecinku',
    bankersClear: 'Wyczyść ★',
    bankersPerBetLabel: 'Pewniaków na kupon',
    clickModePool: 'Klik: Pula',
    clickModeBanker: 'Klik: ★ Pewniak',
    bankerGridHint: 'Zaznacz pewniaki bezpośrednio na siatce poniżej (tryb „★ Pewniak” lub prawy przycisk myszy). Pewniak automatycznie trafia do puli.',
    couponsBets: 'Liczba zakładów',
    coverDecades: 'Pokrycie dekad',
    neighbours: 'Sąsiedzi (+1/-1)',
    aiCommentary: 'Komentarz AI',
    candidatePoolLabel: (minPick) => `Pula liczb kandydujących (kliknij, aby przełączyć, min. ${minPick})`,
    poolAll: (max) => `Wszystkie (${max})`,
    poolRand18: 'Losowe 18',
    poolAiPick: 'Dobierz pulę AI',
    poolClear: 'Wyczyść',
    btnGenerate: 'Generuj zakłady',
    btnStatsReport: 'Raport rozwodnienia i Gaussa',
    aiStrategicCommentary: 'Strategiczny komentarz AI',
    statsSummaryTitle: 'Podsumowanie statystyczne i okno Gaussa',
    poolCoverage: 'Pokrycie puli',
    gaussianOptimalWindow: 'Optymalne okno Gaussa',
    couponsInGaussianWindow: 'Zakłady w oknie Gaussa',
    uniquePairsCovered: 'Pokryte unikalne pary',
    gaussianAlignment: (pct) => `Dopasowanie do krzywej Gaussa (${pct}% w przedziale 1.35σ)`,
    generatedCoupons: (count) => `Wygenerowane zakłady (${count})`,
    selectedCouponsCount: (sel) => `zaznaczono ${sel}`,
    exportCsv: 'Eksport CSV',
    exportTxt: 'Eksport TXT',
    exportJson: 'Eksport JSON',
    emptyCouponsHint: 'Wybierz pulę liczb i kliknij <strong>Generuj zakłady</strong> lub <strong>Raport rozwodnienia i Gaussa</strong>.',

    agentTitle: 'Strateg AI ReAct (dobór puli)',
    gameLabel: 'Gra',
    strategyLabel: 'Strategia',
    strategies: {
      syndicate: 'Klaster syndykatu (50/30/20)',
      balanced: 'Zrównoważona hybryda (60/40)',
      aggressive: 'Agresywny łowca trendów',
      decades: 'Ścisłe limity dekad',
    },
    strategyDescriptions: {
      syndicate: 'Łączy 50% najsilniejszych par/klik, 30% liczb w średnim trendzie oraz 20% niedowartościowanych liczb na odbicie (cold-bounce).',
      balanced: 'Zrównoważony miks 60% stabilnych liderów częstotliwości i 40% liczb o wysokiej synergii sąsiedztwa.',
      aggressive: 'Faworyzuje najgorętsze liczby z ostatnich losowań oraz silne klastry krótkoterminowe.',
      decades: 'Wymusza ścisłe kwoty na każdą dekadę (1–10, 11–20, ...), eliminując puste przedziały na kuponie.',
    },
    targetPoolSize: 'Docelowy rozmiar puli',
    aiProvider: 'Dostawca AI',
    modelLabel: 'Model',
    modelDefault: (def) => `Domyślny (${def})`,
    historicalWindow: 'Okno historyczne (losowania)',
    decadeBalance: 'Balans dekad',
    btnRunAgentRunning: 'Trwa pętla ReAct...',
    btnRunAgent: 'Uruchom analizę agenta ReAct',
    agentOutput: 'Wynik agenta',
    agentOutputHint: 'Uruchom 4-fazową pętlę ReAct, aby zobaczyć wywołania narzędzi, autokorektę i wygenerowaną pulę liczb.',
    selectedCandidatePool: (count) => `Wybrana pula liczb (${count} liczb)`,
    usePoolInGenerator: 'Użyj puli w Generatorze →',
    fallbackPoolAlert: 'Użyto puli zapasowej (sprawdź konfigurację klucza API w zakładce Archiwum i Ustawienia).',
    reactToolTrace: (count) => `Ślad narzędzi ReAct (${count} wywołań)`,
    noToolCalls: 'Nie zarejestrowano wywołań narzędzi w tej turze.',
    turnLabel: (turn) => `Tura ${turn}`,
    argsLabel: 'Argumenty',

    settingsTitle: 'Klucze API i konfiguracja App-Data',
    settingsHint: 'Zapisywane w pliku <code>.env.desktop</code> w katalogu danych aplikacji systemu, poza katalogiem instalacyjnym. Pozostaw puste, aby zachować obecną wartość.',
    secretLabels: {
      LOTTO_API_KEY: 'Klucz LOTTO OpenAPI (developers.lotto.pl)',
      GEMINI_API_KEY: 'Klucz API Google Gemini',
      ANTHROPIC_API_KEY: 'Klucz API Anthropic Claude',
      OPENAI_API_KEY: 'Klucz API OpenAI',
      DEEPSEEK_API_KEY: 'Klucz API DeepSeek',
    },
    configured: (masked) => `Skonfigurowano (${masked})`,
    notConfigured: 'Nieskonfigurowano',
    enterNewKey: (envKey) => `Wpisz nowy ${envKey}, aby zaktualizować...`,
    defaultAiProvider: 'Domyślny dostawca AI',
    defaultAiModelOverride: 'Domyślny model AI (opcjonalnie)',
    saveSettings: 'Zapisz ustawienia',
    localDrawArchives: 'Lokalne archiwum losowań',
    syncModes: {
      refresh: 'Odśwież (przyrostowo)',
      backfill: 'Uzupełnij głęboką historię',
      repair: 'Napraw ucięte daty',
    },
    syncModeDescriptions: {
      refresh: 'Pobiera tylko brakujące losowania od ostatniej zapisanej daty do dzisiaj.',
      backfill: 'Pobiera głębszą historię losowań wstecz, aby zwiększyć próbkę statystyczną.',
      repair: 'Wykrywa i uzupełnia luki oraz ucięte daty w lokalnym pliku archiwum.',
    },
    syncGame: (game) => `Synchronizuj ${game}`,
    colGame: 'Gra',
    colTotalDraws: 'Liczba losowań',
    colLastDrawDate: 'Ostatnie losowanie',
    colExpected: 'Oczekiwana data',
    colMissing: 'Brakujące',
    colStatus: 'Status',
    statusStale: 'Nieaktualne',
    statusFresh: 'Aktualne',

    colCouponIndex: '#',
    colNumbers: 'Liczby',
    colSum: 'Suma',
    colParity: 'Parzystość (N:P)',
    colDecades: 'Dekady',
    colTurn: 'Tura',
    colTool: 'Narzędzie',
    colArgs: 'Argumenty',
    logsTitle: 'Logi aplikacji i backendu Symfony',
    logsSubtitle: 'Zdarzenia żądań UI/API oraz wpisy z katalogu var/log',
    logsRefresh: 'Odśwież',
    logsClear: 'Wyczyść logi',
    logsFilterLevel: 'Poziom',
    logsAllLevels: 'Wszystkie',
    logsColTime: 'Czas',
    logsColLevel: 'Poziom',
    logsColSource: 'Źródło',
    logsColMessage: 'Wiadomość',
    themeDark: 'Motyw ciemny',
    themeLight: 'Motyw jasny',
    manualSidebarTitle: 'Dokumentacja',
    manualDocDesktopTitle: 'Instrukcja aplikacji',
    manualDocHowToPlayTitle: 'Jak grać i strategie',
    manualDocStrategyTitle: 'Analiza strategii',
    manualDocSpecTitle: 'Architektura Desktop',
  },

  en: {
    subtitle: 'Symfony 8.2 Combinatorial Engine & Multi-LLM ReAct Strategist',
    tabGenerator: 'Generator & Optimizer',
    tabAgent: 'AI ReAct Agent',
    tabArchive: 'Archive & Settings',
    tabLogs: 'Logs',
    tabManual: 'Manual / How to Play',
    sidecarOnline: (v) => `PHP ${v} Sidecar Online`,
    sidecarOffline: 'Sidecar Offline',
    sidecarConnectionError: (msg) => `Sidecar connection error: ${msg}`,

    configuration: 'Configuration',
    selectedCount: (sel, max) => `${sel} / ${max} selected`,
    lotteryGame: 'Lottery Game',
    of: 'of',
    wheelingMode: 'Wheeling / Optimizer Mode',
    modes: {
      '1': 'Mode 1 — Balanced Shorthand',
      '2': 'Mode 2 — Smart Unique Blocks',
      '3': 'Mode 3 — Weighted Hot Pool',
      '4': 'Mode 4 — Fixed Bankers',
      '5': 'Mode 5 — Fractal Blocks L1/L2',
      '6': 'Mode 6 — Rotating Bankers',
      '7': 'Mode 7 — Dilution Concentration',
      '8': 'Mode 8 — Full Coverage (Zero-Drop)',
      '9': 'Mode 9 — Tiered Neighbour Cascade',
    },
    modeDescriptions: {
      '1': 'Balanced shorthand wheeling: evenly distributes your chosen numbers across the coupon budget while minimizing 3/4-number repeats.',
      '2': 'Smart unique blocks: splits the pool into disjoint and partially overlapping blocks to maximize unique pair coverage.',
      '3': 'Weighted hot pool: gives higher selection weight to historically frequent numbers so they appear in more coupons.',
      '4': 'Fixed bankers: locks chosen anchor numbers into every coupon and rotates the remaining slots from the rest of the pool.',
      '5': 'Fractal blocks L1/L2: hierarchical split into a primary (L1) and secondary (L2) layer with controlled cross-bleed.',
      '6': 'Rotating bankers: splits bankers into subgroups and rotates them across coupons to reduce single-anchor failure risk.',
      '7': 'Dilution optimizer: builds combinations around highest historical pair synergy while enforcing Gaussian sum (1.35σ), parity, and decade spread.',
      '8': 'Full coverage (Zero-Drop): guarantees every number in your selected pool appears in the generated coupons while respecting Gaussian rules.',
      '9': 'Tiered neighbour cascade: decomposes the drum into 3 strength tiers (anchors + ±1 neighbours, ±2 buffer, remaining pool) and ranks coupons strongest-first.',
    },
    poolModeLabel: 'Pool Selection Mode',
    poolModes: {
      manual: 'Manual — Pick or type numbers',
      ai: 'AI — Auto-select pool via ReAct Agent',
      decades: 'Decades — Auto-balance across decades',
    },
    poolModeDescriptions: {
      manual: 'Click numbers on the grid or enter comma-separated numbers (or "all" for the full drum). Full manual control over your candidate pool.',
      ai: 'Before generating coupons, the ReAct AI Agent analyzes draw history, cliques, and pairs to pick an optimal pool automatically.',
      decades: 'Automatically selects an even spread of candidates from every decade (1–10, 11–20, 21–30...) across the drum.',
    },
    manualPoolInputLabel: 'Enter numbers manually (comma-separated or "all")',
    manualPoolInputPlaceholder: 'e.g. 1, 5, 12, 18, 25, 33 or all',
    manualPoolApply: 'Apply',
    bankersLabel: (sel, maxInfo) => `Bankers ★ (${sel} selected, ${maxInfo})`,
    bankersAutoPick: 'Auto-pick ★',
    bankersAiPick: 'Pick with AI ★',
    bankersAutoCountLabel: 'Auto-pick count ★',
    bankersManualInputLabel: 'Enter bankers (comma-separated)',
    bankersClear: 'Clear ★',
    bankersPerBetLabel: 'Bankers per coupon',
    clickModePool: 'Click: Pool',
    clickModeBanker: 'Click: ★ Banker',
    bankerGridHint: 'Mark bankers directly on the grid below (switch to "★ Banker" or right-click any number). Bankers are automatically included in the pool.',
    couponsBets: 'Coupons (Bets)',
    coverDecades: 'Cover Decades',
    neighbours: 'Neighbours (+1/-1)',
    aiCommentary: 'AI Commentary',
    candidatePoolLabel: (minPick) => `Candidate Number Pool (click to toggle, min ${minPick})`,
    poolAll: (max) => `All (${max})`,
    poolRand18: 'Random 18',
    poolAiPick: 'Pick Pool with AI',
    poolClear: 'Clear',
    btnGenerate: 'Generate Coupons',
    btnStatsReport: 'Dilution & Gaussian Report',
    aiStrategicCommentary: 'AI Strategic Commentary',
    statsSummaryTitle: 'Statistical & Gaussian Window Summary',
    poolCoverage: 'Pool Coverage',
    gaussianOptimalWindow: 'Gaussian Optimal Window',
    couponsInGaussianWindow: 'Coupons in Gaussian Window',
    uniquePairsCovered: 'Unique Pairs Covered',
    gaussianAlignment: (pct) => `Gaussian Bell Curve Alignment (${pct}% inside 1.35σ interval)`,
    generatedCoupons: (count) => `Generated Coupons (${count})`,
    selectedCouponsCount: (sel) => `${sel} selected`,
    exportCsv: 'Export CSV',
    exportTxt: 'Export TXT',
    exportJson: 'Export JSON',
    emptyCouponsHint: 'Select your pool and click <strong>Generate Coupons</strong> or <strong>Dilution &amp; Gaussian Report</strong>.',

    agentTitle: 'ReAct AI Pool Strategist',
    gameLabel: 'Game',
    strategyLabel: 'Strategy',
    strategies: {
      syndicate: 'Syndicate Cluster (50/30/20)',
      balanced: 'Balanced Hybrid (60/40)',
      aggressive: 'Aggressive Trend Hunter',
      decades: 'Strict Decade Quotas',
    },
    strategyDescriptions: {
      syndicate: 'Combines 50% strongest pairs/cliques, 30% medium-trend numbers, and 20% overdue cold-bounce candidates.',
      balanced: 'Balanced mix of 60% stable frequency leaders and 40% high-synergy neighbour numbers.',
      aggressive: 'Favours the hottest numbers from recent draws and strong short-term clusters.',
      decades: 'Enforces strict quotas per decade (1–10, 11–20, ...), preventing empty ranges on coupons.',
    },
    targetPoolSize: 'Target Pool Size',
    aiProvider: 'AI Provider',
    modelLabel: 'Model',
    modelDefault: (def) => `Default (${def})`,
    historicalWindow: 'Historical Window (Sessions)',
    decadeBalance: 'Decade Balance',
    btnRunAgentRunning: 'Running ReAct Loop...',
    btnRunAgent: 'Run ReAct Agent Analysis',
    agentOutput: 'Agent Output',
    agentOutputHint: 'Launch the 4-phase ReAct loop to inspect tool calls, self-correction, and the synthesized number pool.',
    selectedCandidatePool: (count) => `Selected Candidate Pool (${count} numbers)`,
    usePoolInGenerator: 'Use Pool in Generator →',
    fallbackPoolAlert: 'Fallback pool used (check API key configuration in Archive & Settings).',
    reactToolTrace: (count) => `ReAct Tool Trace (${count} tool calls)`,
    noToolCalls: 'No tool calls recorded in this turn.',
    turnLabel: (turn) => `Turn ${turn}`,
    argsLabel: 'Args',

    settingsTitle: 'API Keys & App-Data Configuration',
    settingsHint: 'Stored in your OS App-Data <code>.env.desktop</code> file outside the read-only application bundle. Leave an input blank to keep its existing value.',
    secretLabels: {
      LOTTO_API_KEY: 'LOTTO OpenAPI Secret Key (developers.lotto.pl)',
      GEMINI_API_KEY: 'Google Gemini API Key',
      ANTHROPIC_API_KEY: 'Anthropic Claude API Key',
      OPENAI_API_KEY: 'OpenAI API Key',
      DEEPSEEK_API_KEY: 'DeepSeek API Key',
    },
    configured: (masked) => `Configured (${masked})`,
    notConfigured: 'Not configured',
    enterNewKey: (envKey) => `Enter new ${envKey} to update...`,
    defaultAiProvider: 'Default AI Provider',
    defaultAiModelOverride: 'Default AI Model Override',
    saveSettings: 'Save Settings',
    localDrawArchives: 'Local Draw Archives',
    syncModes: {
      refresh: 'Refresh (incremental)',
      backfill: 'Backfill deep history',
      repair: 'Repair truncated dates',
    },
    syncModeDescriptions: {
      refresh: 'Fetches only missing draws from the last saved date up to today.',
      backfill: 'Downloads deeper historical draws to expand the statistical sample.',
      repair: 'Detects and repairs gaps or truncated dates in the local archive file.',
    },
    syncGame: (game) => `Sync ${game}`,
    colGame: 'Game',
    colTotalDraws: 'Total Draws',
    colLastDrawDate: 'Last Draw Date',
    colExpected: 'Expected',
    colMissing: 'Missing',
    colStatus: 'Status',
    statusStale: 'Stale',
    statusFresh: 'Fresh',

    colCouponIndex: '#',
    colNumbers: 'Numbers',
    colSum: 'Sum',
    colParity: 'Parity (O:E)',
    colDecades: 'Decades',
    colTurn: 'Turn',
    colTool: 'Tool',
    colArgs: 'Arguments',
    logsTitle: 'Application & Symfony Backend Logs',
    logsSubtitle: 'Live UI/API request events and entries from var/log',
    logsRefresh: 'Refresh',
    logsClear: 'Clear Logs',
    logsFilterLevel: 'Level',
    logsAllLevels: 'All',
    logsColTime: 'Timestamp',
    logsColLevel: 'Level',
    logsColSource: 'Source',
    logsColMessage: 'Message',
    themeDark: 'Dark mode',
    themeLight: 'Light mode',
    manualSidebarTitle: 'Documentation',
    manualDocDesktopTitle: 'Application Manual',
    manualDocHowToPlayTitle: 'How to Play & Strategies',
    manualDocStrategyTitle: 'Strategy Analysis',
    manualDocSpecTitle: 'Desktop Architecture',
  },
};
