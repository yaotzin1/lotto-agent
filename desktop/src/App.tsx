import { useCallback, useEffect, useMemo, useState } from 'react';
import { listen } from '@tauri-apps/api/event';
import {
  Alert,
  AppBar,
  Avatar,
  Box,
  Button,
  Chip,
  Container,
  CssBaseline,
  IconButton,
  LinearProgress,
  Stack,
  Tab,
  Tabs,
  ThemeProvider,
  Toolbar,
  Tooltip,
  Typography,
  createTheme,
} from '@mui/material';
import DarkModeIcon from '@mui/icons-material/DarkMode';
import LightModeIcon from '@mui/icons-material/LightMode';
import {
  formatSidecarDiagnostics,
  getSidecarDiagnostics,
  lottoApi,
  retrySidecar,
  type AgentRunResponse,
  type ArchiveFreshness,
  type GameConfig,
  type GenerateResponse,
  type SettingsResponse,
  type SidecarDiagnostics,
  type StatsResponse,
} from './api';
import { translations, type Lang } from './i18n';
import { GeneratorTab, type PoolMode } from './components/GeneratorTab';
import { AgentTab } from './components/AgentTab';
import { ArchiveSettingsTab } from './components/ArchiveSettingsTab';
import { LogsTab } from './components/LogsTab';
import { ManualTab } from './components/ManualTab';

type ActiveTab = 'generator' | 'agent' | 'archive' | 'logs' | 'manual';

function loadInitialLang(): Lang {
  try {
    const saved = localStorage.getItem('lotto_desktop_lang');
    if (saved === 'en' || saved === 'pl') return saved;
  } catch {
    // ignore storage errors
  }
  return 'pl';
}

function loadInitialMode(): 'light' | 'dark' {
  try {
    const saved = localStorage.getItem('lotto_desktop_theme');
    if (saved === 'dark' || saved === 'light') return saved;
  } catch {
    // ignore storage errors
  }
  return 'light';
}

function selectDefaultPoolForGame(game: GameConfig): number[] {
  const target = Math.min(game.from, Math.max(game.pick * 3, 18));
  const nums: number[] = [];
  const step = Math.max(1, Math.floor(game.from / target));
  for (let n = 1; n <= game.from && nums.length < target; n += step) {
    nums.push(n);
  }
  return nums;
}

export function App() {
  const [lang, setLang] = useState<Lang>(loadInitialLang);
  const [themeMode, setThemeMode] = useState<'light' | 'dark'>(loadInitialMode);
  const [tab, setTab] = useState<ActiveTab>('generator');

  const [backendHealthy, setBackendHealthy] = useState(false);
  const [phpVersion, setPhpVersion] = useState('');
  const [games, setGames] = useState<Record<string, GameConfig>>({});
  const [selectedGame, setSelectedGame] = useState('Lotto');
  const [selectedPool, setSelectedPool] = useState<number[]>([
    1, 3, 5, 7, 11, 14, 18, 21, 24, 28, 32, 35, 39, 42, 45, 49,
  ]);
  const [poolMode, setPoolMode] = useState<PoolMode>('manual');
  const [mode, setMode] = useState('8');
  const [bankers, setBankers] = useState<number[]>([1, 3]);
  const [bankersPerBet, setBankersPerBet] = useState(3);
  const [bets, setBets] = useState(12);
  const [coverDecades, setCoverDecades] = useState(true);
  const [withNeighbours, setWithNeighbours] = useState(true);
  const [includeAiCommentary, setIncludeAiCommentary] = useState(false);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [genResult, setGenResult] = useState<GenerateResponse | null>(null);
  const [statsResult, setStatsResult] = useState<StatsResponse | null>(null);

  const [agentStrategy, setAgentStrategy] = useState('syndicate');
  const [agentPoolSize, setAgentPoolSize] = useState(18);
  const [agentSessions, setAgentSessions] = useState(50);
  const [agentProvider, setAgentProvider] = useState('gemini');
  const [agentModel, setAgentModel] = useState('');
  const [agentResult, setAgentResult] = useState<AgentRunResponse | null>(null);

  const [archives, setArchives] = useState<Record<string, ArchiveFreshness>>({});
  const [syncMode, setSyncMode] = useState('refresh');
  const [settings, setSettings] = useState<SettingsResponse | null>(null);

  const tr = translations[lang];

  const theme = useMemo(
    () =>
      createTheme({
        cssVariables: true,
        palette: {
          mode: themeMode,
          primary: {
            main: themeMode === 'light' ? '#1565c0' : '#90caf9',
          },
          secondary: {
            main: themeMode === 'light' ? '#00796b' : '#80cbc4',
          },
          background: {
            default: themeMode === 'light' ? '#f5f7fa' : '#111418',
            paper: themeMode === 'light' ? '#ffffff' : '#181c22',
          },
        },
        shape: {
          borderRadius: 8,
        },
      }),
    [themeMode]
  );

  useEffect(() => {
    document.documentElement.lang = lang;
    try {
      localStorage.setItem('lotto_desktop_lang', lang);
    } catch {
      // ignore
    }
  }, [lang]);

  useEffect(() => {
    try {
      localStorage.setItem('lotto_desktop_theme', themeMode);
    } catch {
      // ignore
    }
  }, [themeMode]);

  const connectBackend = useCallback(async (silent = false) => {
    try {
      const health = await lottoApi.health();
      setBackendHealthy(health.status === 'ok');
      setPhpVersion(health.php_version);
      setError((prev) =>
        prev && (prev.includes('Sidecar') || prev.includes('backendem PHP')) ? null : prev
      );

      const [gamesRes, settingsRes, archiveRes] = await Promise.all([
        lottoApi.games(),
        lottoApi.getSettings(),
        lottoApi.archiveStatus(),
      ]);

      setGames(gamesRes.games);
      setSettings(settingsRes);
      setAgentProvider(settingsRes.ai_provider || 'gemini');
      setAgentModel(settingsRes.ai_model || '');
      setArchives(archiveRes.archives);

      if (gamesRes.games['Lotto']) {
        setSelectedPool((prev) =>
          prev.length ? prev : selectDefaultPoolForGame(gamesRes.games['Lotto'])
        );
      }
    } catch (err: any) {
      setBackendHealthy(false);
      const diag = await getSidecarDiagnostics();
      if (diag?.state === 'starting' && !diag.error) {
        return;
      }
      const detail = diag?.error ? formatSidecarDiagnostics(diag) : err?.message || String(err);
      if (!silent || diag?.error) {
        setError(translations[lang].sidecarConnectionError(detail));
      }
    }
  }, [lang]);

  useEffect(() => {
    connectBackend(false);
  }, [connectBackend]);

  useEffect(() => {
    let unlisten: (() => void) | undefined;
    listen<SidecarDiagnostics>('sidecar-status', (event) => {
      const diag = event.payload;
      if (diag.state === 'ready') {
        connectBackend(false);
      } else if (diag.state === 'failed' || diag.state === 'exited') {
        setBackendHealthy(false);
        setError(translations[lang].sidecarConnectionError(formatSidecarDiagnostics(diag)));
      }
    })
      .then((fn) => {
        unlisten = fn;
      })
      .catch(() => {
        // Running outside Tauri shell (e.g. Vite browser preview)
      });

    return () => {
      if (unlisten) unlisten();
    };
  }, [connectBackend, lang]);

  useEffect(() => {
    if (backendHealthy) return;
    const timer = window.setInterval(async () => {
      const diag = await getSidecarDiagnostics();
      if (diag?.state === 'failed' || diag?.state === 'exited') {
        await retrySidecar();
        return;
      }
      await connectBackend(true);
    }, 3000);
    return () => window.clearInterval(timer);
  }, [backendHealthy, connectBackend]);

  const handleSelectGame = (gameName: string) => {
    setSelectedGame(gameName);
    const g = games[gameName];
    if (g) {
      setSelectedPool(selectDefaultPoolForGame(g));
    }
    setGenResult(null);
    setStatsResult(null);
  };

  const handleToggleNumber = (num: number) => {
    const exists = selectedPool.includes(num);
    if (exists) {
      setSelectedPool((prev) => prev.filter((x) => x !== num));
      setBankers((prev) => prev.filter((x) => x !== num));
    } else {
      setSelectedPool((prev) => [...prev, num].sort((a, b) => a - b));
    }
  };

  const handlePoolAll = () => {
    const max = games[selectedGame]?.from ?? 49;
    setSelectedPool(Array.from({ length: max }, (_, i) => i + 1));
  };

  const handlePoolRand18 = () => {
    const max = games[selectedGame]?.from ?? 49;
    const all = Array.from({ length: max }, (_, i) => i + 1).sort(() => Math.random() - 0.5);
    setSelectedPool(all.slice(0, Math.min(18, max)).sort((a, b) => a - b));
  };

  const autoPickBankersFor = (
    pool: number[],
    targetMode: string,
    gameName: string,
    requestedCount?: number
  ) => {
    const pick = games[gameName]?.pick ?? 6;
    const maxNumber = games[gameName]?.from ?? 49;
    const sourcePool =
      pool.length > 0 ? pool : Array.from({ length: maxNumber }, (_, i) => i + 1);
    const shuffled = [...sourcePool];
    for (let i = shuffled.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }

    if (targetMode === '4') {
      const maxFixed = Math.max(1, pick - 1);
      const count = Math.min(maxFixed, Math.max(1, requestedCount ?? Math.min(2, maxFixed)));
      return shuffled.slice(0, count).sort((a, b) => a - b);
    }

    if (targetMode === '6') {
      const slotsForVars = Math.max(1, pick - bankersPerBet);
      const maxRotating = Math.max(bankersPerBet, sourcePool.length - slotsForVars);
      const defaultCount = Math.min(maxRotating, Math.max(bankersPerBet, 16));
      const count = Math.min(maxRotating, Math.max(bankersPerBet, requestedCount ?? defaultCount));
      return shuffled.slice(0, count).sort((a, b) => a - b);
    }

    return [];
  };

  const handleSetBankers = (nextBankers: number[]) => {
    const uniqueSorted = Array.from(new Set(nextBankers)).sort((a, b) => a - b);
    setBankers(uniqueSorted);
    setSelectedPool((prev) => {
      const merged = new Set([...prev, ...uniqueSorted]);
      return Array.from(merged).sort((a, b) => a - b);
    });
  };

  const handleAiPickBankers = async (requestedCount: number) => {
    const pick = games[selectedGame]?.pick ?? 6;
    const maxNumber = games[selectedGame]?.from ?? 49;
    const targetCount =
      mode === '4'
        ? Math.min(Math.max(1, pick - 1), Math.max(1, requestedCount))
        : Math.min(
            Math.max(bankersPerBet, maxNumber - (pick - bankersPerBet)),
            Math.max(bankersPerBet, requestedCount)
          );
    const agentPoolSizeReq =
      selectedPool.length < pick
        ? Math.min(maxNumber, Math.max(pick * 2, targetCount, 18))
        : Math.min(maxNumber, Math.max(pick, targetCount));

    setLoading(true);
    setError(null);
    try {
      const agentRes = await lottoApi.runAgent({
        game: selectedGame,
        pool_size: agentPoolSizeReq,
        strategy: agentStrategy,
        sessions: agentSessions,
        cover_decades: coverDecades,
        include_neighbours: withNeighbours,
        provider: agentProvider,
        model: agentModel || undefined,
      });
      setAgentResult(agentRes);
      if (agentRes.selected_pool?.length) {
        const pickedBankers = agentRes.selected_pool.slice(0, targetCount);
        setBankers(Array.from(new Set(pickedBankers)).sort((a, b) => a - b));
        setSelectedPool((prev) => {
          const base = prev.length < pick ? agentRes.selected_pool : prev;
          const merged = new Set([...base, ...pickedBankers]);
          return Array.from(merged).sort((a, b) => a - b);
        });
      }
    } catch (err: any) {
      setError(err?.message || String(err));
    } finally {
      setLoading(false);
    }
  };

  const handleAiPickPool = async () => {
    const pick = games[selectedGame]?.pick ?? 6;
    const maxNumber = games[selectedGame]?.from ?? 49;
    const targetPoolSize = Math.min(maxNumber, Math.max(pick, agentPoolSize));
    setLoading(true);
    setError(null);
    try {
      const agentRes = await lottoApi.runAgent({
        game: selectedGame,
        pool_size: targetPoolSize,
        strategy: agentStrategy,
        sessions: agentSessions,
        cover_decades: coverDecades,
        include_neighbours: withNeighbours,
        provider: agentProvider,
        model: agentModel || undefined,
      });
      setAgentResult(agentRes);
      if (agentRes.selected_pool?.length) {
        setSelectedPool([...agentRes.selected_pool].sort((a, b) => a - b));
      }
    } catch (err: any) {
      setError(err?.message || String(err));
    } finally {
      setLoading(false);
    }
  };

  const handleChangeMode = (nextMode: string) => {
    setMode(nextMode);
    if (nextMode === '4' || nextMode === '6') {
      const valid = bankers.filter((b) => selectedPool.includes(b));
      const pick = games[selectedGame]?.pick ?? 6;
      if (
        valid.length === 0 ||
        (nextMode === '4' && valid.length >= pick) ||
        (nextMode === '6' && valid.length < bankersPerBet)
      ) {
        setBankers(autoPickBankersFor(selectedPool, nextMode, selectedGame));
      }
    }
  };

  const handleToggleBanker = (num: number) => {
    const exists = bankers.includes(num);
    if (exists) {
      setBankers((prev) => prev.filter((x) => x !== num));
    } else {
      setBankers((prev) => [...prev, num].sort((a, b) => a - b));
      setSelectedPool((prev) =>
        prev.includes(num) ? prev : [...prev, num].sort((a, b) => a - b)
      );
    }
  };

  const handleChangePoolMode = (nextMode: PoolMode) => {
    setPoolMode(nextMode);
    if (nextMode === 'decades') {
      const g = games[selectedGame];
      if (g) {
        setSelectedPool(selectDefaultPoolForGame(g));
      }
      setCoverDecades(true);
    }
  };

  const resolvePoolForRun = async (): Promise<number[]> => {
    if (poolMode !== 'ai') {
      return selectedPool;
    }
    const agentRes = await lottoApi.runAgent({
      game: selectedGame,
      pool_size: agentPoolSize,
      strategy: agentStrategy,
      sessions: agentSessions,
      cover_decades: coverDecades,
      include_neighbours: withNeighbours,
      provider: agentProvider,
      model: agentModel || undefined,
    });
    setAgentResult(agentRes);
    if (agentRes.selected_pool?.length) {
      setSelectedPool(agentRes.selected_pool);
      return agentRes.selected_pool;
    }
    return selectedPool;
  };

  const handleRunGenerate = async () => {
    setLoading(true);
    setError(null);
    setStatsResult(null);
    try {
      const poolToUse = await resolvePoolForRun();
      let effectiveBankers = bankers.filter((b) => poolToUse.includes(b));
      if ((mode === '4' || mode === '6') && effectiveBankers.length === 0) {
        effectiveBankers = autoPickBankersFor(poolToUse, mode, selectedGame);
        setBankers(effectiveBankers);
      }
      const res = await lottoApi.generate({
        game: selectedGame,
        pool: poolToUse,
        mode,
        bets,
        bankers: mode === '4' || mode === '6' ? effectiveBankers : undefined,
        bankers_per_bet: mode === '6' ? bankersPerBet : undefined,
        cover_decades: coverDecades,
        with_neighbours: withNeighbours,
        ai: includeAiCommentary,
        provider: agentProvider,
        model: agentModel || undefined,
      });
      setGenResult(res);
    } catch (err: any) {
      setError(err?.message || String(err));
    } finally {
      setLoading(false);
    }
  };

  const handleRunStats = async () => {
    setLoading(true);
    setError(null);
    setGenResult(null);
    try {
      const poolToUse = await resolvePoolForRun();
      const res = await lottoApi.stats({
        game: selectedGame,
        pool: poolToUse,
        bets,
        full_coverage: mode === '8',
        cover_decades: coverDecades,
        with_neighbours: withNeighbours,
        ai: includeAiCommentary,
        provider: agentProvider,
        model: agentModel || undefined,
      });
      setStatsResult(res);
    } catch (err: any) {
      setError(err?.message || String(err));
    } finally {
      setLoading(false);
    }
  };

  const handleRunAgent = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await lottoApi.runAgent({
        game: selectedGame,
        pool_size: agentPoolSize,
        strategy: agentStrategy,
        sessions: agentSessions,
        cover_decades: coverDecades,
        include_neighbours: withNeighbours,
        provider: agentProvider,
        model: agentModel || undefined,
      });
      setAgentResult(res);
    } catch (err: any) {
      setError(err?.message || String(err));
    } finally {
      setLoading(false);
    }
  };

  const handleApplyAgentPool = () => {
    if (agentResult?.selected_pool?.length) {
      setSelectedPool([...agentResult.selected_pool]);
      setTab('generator');
    }
  };

  const handleSaveSettings = async (payload: {
    ai_provider: string;
    ai_model: string;
    keys: Record<string, string>;
  }) => {
    setLoading(true);
    setError(null);
    try {
      const res = await lottoApi.saveSettings(payload);
      setSettings(res);
    } catch (err: any) {
      setError(err?.message || String(err));
    } finally {
      setLoading(false);
    }
  };

  const handleSyncSelected = async () => {
    setLoading(true);
    setError(null);
    try {
      await lottoApi.archiveSync({
        game: selectedGame,
        sessions: 60,
        mode: syncMode,
      });
      const updated = await lottoApi.archiveStatus();
      setArchives(updated.archives);
    } catch (err: any) {
      setError(err?.message || String(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
        <AppBar position="sticky" color="default" elevation={0} sx={{ borderBottom: 1, borderColor: 'divider' }}>
          <Toolbar sx={{ gap: 2, flexWrap: 'wrap', py: 0.5 }}>
            <Stack direction="row" spacing={1.5} sx={{ mr: 'auto', alignItems: 'center' }}>
              <Avatar sx={{ bgcolor: 'primary.main', width: 34, height: 34, fontSize: 14, fontWeight: 700 }}>
                49
              </Avatar>
              <Box>
                <Typography variant="subtitle1" sx={{ fontWeight: 700, lineHeight: 1.2 }}>
                  Lotto Agent Desktop
                </Typography>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                  {tr.subtitle}
                </Typography>
              </Box>
            </Stack>

            <Tabs
              value={tab}
              onChange={(_, v: ActiveTab) => {
                setTab(v);
                setError(null);
              }}
              textColor="primary"
              indicatorColor="primary"
            >
              <Tab value="generator" label={tr.tabGenerator} />
              <Tab value="agent" label={tr.tabAgent} />
              <Tab value="archive" label={tr.tabArchive} />
              <Tab value="logs" label={tr.tabLogs} />
              <Tab value="manual" label={tr.tabManual} />
            </Tabs>

            <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
              <Button
                size="small"
                variant="outlined"
                onClick={() => setLang((prev) => (prev === 'pl' ? 'en' : 'pl'))}
                sx={{ minWidth: 64 }}
              >
                {lang === 'pl' ? 'PL / en' : 'pl / EN'}
              </Button>

              <Tooltip title={themeMode === 'light' ? tr.themeDark : tr.themeLight}>
                <IconButton
                  size="small"
                  onClick={() => setThemeMode((prev) => (prev === 'light' ? 'dark' : 'light'))}
                >
                  {themeMode === 'light' ? <DarkModeIcon fontSize="small" /> : <LightModeIcon fontSize="small" />}
                </IconButton>
              </Tooltip>

              <Chip
                size="small"
                label={backendHealthy ? tr.sidecarOnline(phpVersion) : tr.sidecarOffline}
                color={backendHealthy ? 'success' : 'error'}
                variant="outlined"
              />
            </Stack>
          </Toolbar>
          {loading && <LinearProgress />}
        </AppBar>

        <Container maxWidth="xl" sx={{ py: 3, flex: 1 }}>
          {error && (
            <Alert severity="error" onClose={() => setError(null)} sx={{ mb: 2 }}>
              {error}
            </Alert>
          )}

          {tab === 'generator' && (
            <GeneratorTab
              lang={lang}
              tr={tr}
              games={games}
              selectedGame={selectedGame}
              selectedPool={selectedPool}
              poolMode={poolMode}
              mode={mode}
              bankers={bankers}
              bankersPerBet={bankersPerBet}
              bets={bets}
              coverDecades={coverDecades}
              withNeighbours={withNeighbours}
              includeAiCommentary={includeAiCommentary}
              loading={loading}
              genResult={genResult}
              statsResult={statsResult}
              agentReasoning={agentResult?.reasoning ?? null}
              onSelectGame={handleSelectGame}
              onChangePoolMode={handleChangePoolMode}
              onSetPool={setSelectedPool}
              onChangeMode={handleChangeMode}
              onToggleBanker={handleToggleBanker}
              onAutoPickBankers={(count?: number) => {
                const picked = autoPickBankersFor(selectedPool, mode, selectedGame, count);
                handleSetBankers(picked);
              }}
              onAiPickBankers={handleAiPickBankers}
              onPoolAiPick={handleAiPickPool}
              onSetBankers={handleSetBankers}
              onClearBankers={() => setBankers([])}
              onChangeBankersPerBet={setBankersPerBet}
              onChangeBets={setBets}
              onToggleDecades={setCoverDecades}
              onToggleNeighbours={setWithNeighbours}
              onToggleAiCommentary={setIncludeAiCommentary}
              onToggleNumber={handleToggleNumber}
              onPoolAll={handlePoolAll}
              onPoolRand18={handlePoolRand18}
              onPoolClear={() => setSelectedPool([])}
              onRunGenerate={handleRunGenerate}
              onRunStats={handleRunStats}
            />
          )}

          {tab === 'agent' && (
            <AgentTab
              lang={lang}
              tr={tr}
              games={games}
              selectedGame={selectedGame}
              agentStrategy={agentStrategy}
              agentPoolSize={agentPoolSize}
              agentSessions={agentSessions}
              agentProvider={agentProvider}
              agentModel={agentModel}
              coverDecades={coverDecades}
              withNeighbours={withNeighbours}
              loading={loading}
              settings={settings}
              agentResult={agentResult}
              onSelectGame={handleSelectGame}
              onChangeStrategy={setAgentStrategy}
              onChangePoolSize={setAgentPoolSize}
              onChangeProvider={(p) => {
                setAgentProvider(p);
                setAgentModel('');
              }}
              onChangeModel={setAgentModel}
              onChangeSessions={setAgentSessions}
              onToggleDecades={setCoverDecades}
              onToggleNeighbours={setWithNeighbours}
              onRunAgent={handleRunAgent}
              onApplyPool={handleApplyAgentPool}
            />
          )}

          {tab === 'archive' && (
            <ArchiveSettingsTab
              lang={lang}
              tr={tr}
              selectedGame={selectedGame}
              archives={archives}
              syncMode={syncMode}
              settings={settings}
              loading={loading}
              onChangeSyncMode={setSyncMode}
              onSyncSelected={handleSyncSelected}
              onSaveSettings={handleSaveSettings}
            />
          )}

          {tab === 'logs' && <LogsTab lang={lang} tr={tr} />}

          {tab === 'manual' && <ManualTab tr={tr} />}
        </Container>
      </Box>
    </ThemeProvider>
  );
}
