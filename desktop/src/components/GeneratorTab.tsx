import { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  CardHeader,
  Checkbox,
  Divider,
  FormControl,
  FormControlLabel,
  FormHelperText,
  InputLabel,
  LinearProgress,
  MenuItem,
  Paper,
  Select,
  Stack,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
} from '@mui/material';
import PlayArrowIcon from '@mui/icons-material/PlayArrow';
import AnalyticsIcon from '@mui/icons-material/Analytics';
import DownloadIcon from '@mui/icons-material/Download';
import { Gridwright, search, type GridwrightColumn } from '@apsw/gridwright/react';
import { muiAddons } from '@apsw/gridwright-mui';
import { en, pl } from '@apsw/gridwright/locales';
import type { GameConfig, GenerateResponse, StatsResponse } from '../api';
import type { Lang, Translations } from '../i18n';
import { NumberBalls } from './NumberBalls';

interface CouponRow {
  id: number;
  index: number;
  numbers: number[];
  extra: number[];
  numbersText: string;
  sum: number;
  parity: string;
  decades: number;
}

export type PoolMode = 'manual' | 'ai' | 'decades';

interface GeneratorTabProps {
  lang: Lang;
  tr: Translations;
  games: Record<string, GameConfig>;
  selectedGame: string;
  selectedPool: number[];
  poolMode: PoolMode;
  mode: string;
  bankers: number[];
  bankersPerBet: number;
  bets: number;
  coverDecades: boolean;
  withNeighbours: boolean;
  includeAiCommentary: boolean;
  loading: boolean;
  genResult: GenerateResponse | null;
  statsResult: StatsResponse | null;
  agentReasoning?: string | null;
  onSelectGame: (game: string) => void;
  onChangePoolMode: (poolMode: PoolMode) => void;
  onSetPool: (pool: number[]) => void;
  onChangeMode: (mode: string) => void;
  onToggleBanker: (num: number) => void;
  onAutoPickBankers: (count?: number) => void;
  onAiPickBankers: (count: number) => void;
  onSetBankers: (bankers: number[]) => void;
  onClearBankers: () => void;
  onChangeBankersPerBet: (val: number) => void;
  onChangeBets: (bets: number) => void;
  onToggleDecades: (val: boolean) => void;
  onToggleNeighbours: (val: boolean) => void;
  onToggleAiCommentary: (val: boolean) => void;
  onToggleNumber: (num: number) => void;
  onPoolAll: () => void;
  onPoolRand18: () => void;
  onPoolAiPick: () => void;
  onPoolClear: () => void;
  onRunGenerate: () => void;
  onRunStats: () => void;
}

export function GeneratorTab({
  lang,
  tr,
  games,
  selectedGame,
  selectedPool,
  poolMode,
  mode,
  bankers,
  bankersPerBet,
  bets,
  coverDecades,
  withNeighbours,
  includeAiCommentary,
  loading,
  genResult,
  statsResult,
  agentReasoning,
  onSelectGame,
  onChangePoolMode,
  onSetPool,
  onChangeMode,
  onToggleBanker,
  onAutoPickBankers,
  onAiPickBankers,
  onSetBankers,
  onClearBankers,
  onChangeBankersPerBet,
  onChangeBets,
  onToggleDecades,
  onToggleNeighbours,
  onToggleAiCommentary,
  onToggleNumber,
  onPoolAll,
  onPoolRand18,
  onPoolAiPick,
  onPoolClear,
  onRunGenerate,
  onRunStats,
}: GeneratorTabProps) {
  const [manualInput, setManualInput] = useState('');
  const [bankerInput, setBankerInput] = useState('');
  const [autoBankerCount, setAutoBankerCount] = useState(16);
  const [clickMode, setClickMode] = useState<'pool' | 'banker'>('pool');
  const [selectedCouponIds, setSelectedCouponIds] = useState<readonly (string | number)[]>([]);
  const isBankerMode = mode === '4' || mode === '6';
  const game = games[selectedGame];
  const maxNumber = game?.from ?? 49;
  const pick = game?.pick ?? 6;

  const handleApplyBankerInput = () => {
    const parsed = Array.from(
      new Set(
        bankerInput
          .split(/[\s,;]+/)
          .map((s) => Number(s.trim()))
          .filter((n) => Number.isInteger(n) && n >= 1 && n <= maxNumber)
      )
    ).sort((a, b) => a - b);
    if (parsed.length > 0) {
      onSetBankers(parsed);
    }
  };

  const handleApplyManualInput = () => {
    const trimmed = manualInput.trim().toLowerCase();
    if (trimmed === 'all') {
      onPoolAll();
      return;
    }
    const parsed = Array.from(
      new Set(
        manualInput
          .split(/[\s,;]+/)
          .map((s) => Number(s.trim()))
          .filter((n) => Number.isInteger(n) && n >= 1 && n <= maxNumber)
      )
    ).sort((a, b) => a - b);
    if (parsed.length > 0) {
      onSetPool(parsed);
    }
  };

  const aiCommentary = statsResult?.ai_commentary ?? genResult?.ai_commentary ?? null;

  const report = statsResult?.report ?? genResult?.stats_report ?? null;
  const gaussAnalysis = report?.gaussian_analysis ?? null;
  const inOptimalPct = Number(gaussAnalysis?.in_optimal_range_pct ?? 0);
  const poolCoveragePct = Number(report?.pool_coverage?.coverage_pct ?? 0);
  const rawBets = statsResult?.bets ?? genResult?.bets ?? [];
  const extraSets = genResult?.extra_sets ?? [];
  const warnings = [...(genResult?.warnings ?? []), ...(statsResult?.warnings ?? [])];

  const rows: CouponRow[] = useMemo(
    () =>
      rawBets.map((bet, idx) => {
        const extra = extraSets[idx] ?? [];
        const sum = bet.reduce((acc, n) => acc + n, 0);
        const odd = bet.filter((n) => n % 2 !== 0).length;
        const even = bet.length - odd;
        const decades = new Set(bet.map((n) => Math.floor((n - 1) / 10))).size;
        return {
          id: idx + 1,
          index: idx + 1,
          numbers: bet,
          extra,
          numbersText: bet.join(', ') + (extra.length ? ` + ${extra.join(', ')}` : ''),
          sum,
          parity: `${odd}:${even}`,
          decades,
        };
      }),
    [rawBets, extraSets]
  );

  const columns: GridwrightColumn<CouponRow, any>[] = useMemo(
    () => [
      {
        id: 'index',
        header: tr.colCouponIndex,
        accessor: 'index',
        width: 64,
        align: 'end',
        sortable: true,
      },
      {
        id: 'numbersText',
        header: tr.colNumbers,
        accessor: 'numbersText',
        sortable: false,
        cell: ({ row }) => <NumberBalls numbers={row.numbers} extra={row.extra} />,
      },
      {
        id: 'sum',
        header: tr.colSum,
        accessor: 'sum',
        width: 84,
        align: 'end',
        sortable: true,
      },
      {
        id: 'parity',
        header: tr.colParity,
        accessor: 'parity',
        width: 115,
        align: 'center',
        sortable: true,
      },
      {
        id: 'decades',
        header: tr.colDecades,
        accessor: 'decades',
        width: 88,
        align: 'end',
        sortable: true,
      },
    ],
    [tr]
  );

  const gridCoreAddons = useMemo(() => muiAddons<CouponRow>(), []);
  const gridAddons = useMemo(() => [search<CouponRow>()], []);

  const downloadFile = (filename: string, content: string, mime: string) => {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };

  const getRowsToExport = (): CouponRow[] => {
    if (selectedCouponIds.length > 0) {
      const idSet = new Set(selectedCouponIds);
      const filtered = rows.filter((r) => idSet.has(r.id));
      if (filtered.length > 0) return filtered;
    }
    return rows;
  };

  const handleExport = (format: 'csv' | 'txt' | 'json') => {
    const exportRows = getRowsToExport();
    if (exportRows.length === 0) return;
    const slug = selectedGame.toLowerCase().replace(/\s+/g, '-');
    const dateStr = new Date().toISOString().slice(0, 10);

    if (format === 'csv') {
      const header = ['#', tr.colNumbers, tr.colSum, tr.colParity, tr.colDecades].join(';');
      const lines = exportRows.map((r) =>
        [r.index, `"${r.numbersText}"`, r.sum, r.parity, r.decades].join(';')
      );
      downloadFile(
        `lotto-${slug}-${dateStr}.csv`,
        '\uFEFF' + [header, ...lines].join('\n'),
        'text/csv;charset=utf-8'
      );
      return;
    }

    if (format === 'txt') {
      const lines = exportRows.map((r) => {
        const nums = r.numbers.map((n) => String(n).padStart(2, '0')).join(' ');
        const extra = r.extra.length ? ` + ${r.extra.join(' ')}` : '';
        return `${String(r.index).padStart(3, ' ')}) ${nums}${extra}`;
      });
      downloadFile(
        `lotto-${slug}-${dateStr}.txt`,
        lines.join('\n') + '\n',
        'text/plain;charset=utf-8'
      );
      return;
    }

    const jsonPayload = {
      game: selectedGame,
      mode,
      exported_at: new Date().toISOString(),
      count: exportRows.length,
      coupons: exportRows.map((r) => ({
        index: r.index,
        numbers: r.numbers,
        extra: r.extra,
        sum: r.sum,
        parity: r.parity,
        decades: r.decades,
      })),
    };
    downloadFile(
      `lotto-${slug}-${dateStr}.json`,
      JSON.stringify(jsonPayload, null, 2) + '\n',
      'application/json;charset=utf-8'
    );
  };

  return (
    <Box
      sx={{
        display: 'grid',
        gridTemplateColumns: { xs: '1fr', lg: '440px 1fr' },
        gap: 2.5,
        alignItems: 'start',
      }}
    >
      <Card variant="outlined">
        <CardHeader
          title={tr.configuration}
          subheader={tr.selectedCount(selectedPool.length, maxNumber)}
          titleTypographyProps={{ variant: 'h6' }}
        />
        <Divider />
        <CardContent>
          <Stack spacing={2}>
            <FormControl fullWidth size="small">
              <InputLabel>{tr.lotteryGame}</InputLabel>
              <Select
                label={tr.lotteryGame}
                value={selectedGame}
                onChange={(e) => onSelectGame(e.target.value)}
              >
                {Object.values(games).map((g) => (
                  <MenuItem key={g.name} value={g.name}>
                    {g.name} ({g.pick} {tr.of} {g.from}
                    {g.extra ? ` + ${g.extra}/${g.extra_from}` : ''})
                  </MenuItem>
                ))}
              </Select>
            </FormControl>

            <FormControl fullWidth size="small">
              <InputLabel>{tr.poolModeLabel}</InputLabel>
              <Select
                label={tr.poolModeLabel}
                value={poolMode}
                onChange={(e) => onChangePoolMode(e.target.value as PoolMode)}
              >
                {(['manual', 'ai', 'decades'] as const).map((pm) => (
                  <MenuItem key={pm} value={pm}>
                    {tr.poolModes[pm]}
                  </MenuItem>
                ))}
              </Select>
              <FormHelperText>{tr.poolModeDescriptions[poolMode]}</FormHelperText>
            </FormControl>

            <Stack direction="row" spacing={1.5}>
              <FormControl fullWidth size="small">
                <InputLabel>{tr.wheelingMode}</InputLabel>
                <Select
                  label={tr.wheelingMode}
                  value={mode}
                  onChange={(e) => onChangeMode(e.target.value)}
                >
                  {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((m) => (
                    <MenuItem key={m} value={m}>
                      {tr.modes[m]}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>

              <TextField
                size="small"
                type="number"
                label={tr.couponsBets}
                value={bets}
                onChange={(e) => onChangeBets(Math.max(1, Number(e.target.value) || 1))}
                slotProps={{ htmlInput: { min: 1, max: 500 } }}
                sx={{ width: 150, flexShrink: 0 }}
              />
            </Stack>

            <Alert severity="info" variant="outlined" sx={{ py: 0.5 }}>
              <Typography variant="caption">{tr.modeDescriptions[mode]}</Typography>
            </Alert>

            {isBankerMode && (
              <Paper variant="outlined" sx={{ p: 1.5 }}>
                <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 1, flexWrap: 'wrap', gap: 1 }}>
                  <Typography variant="caption" sx={{ fontWeight: 600 }}>
                    {tr.bankersLabel(
                      bankers.filter((b) => selectedPool.includes(b)).length,
                      mode === '4' ? `max ${Math.max(1, pick - 1)}` : `min ${bankersPerBet}`
                    )}
                  </Typography>
                  <Button size="small" variant="text" color="inherit" onClick={onClearBankers}>
                    {tr.bankersClear}
                  </Button>
                </Stack>

                <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap', mb: 1.25 }}>
                  <ToggleButtonGroup
                    size="small"
                    exclusive
                    value={clickMode}
                    onChange={(_, val) => {
                      if (val) setClickMode(val);
                    }}
                  >
                    <ToggleButton value="pool">{tr.clickModePool}</ToggleButton>
                    <ToggleButton value="banker" color="warning">
                      {tr.clickModeBanker}
                    </ToggleButton>
                  </ToggleButtonGroup>

                  {mode === '6' && (
                    <TextField
                      size="small"
                      type="number"
                      label={tr.bankersPerBetLabel}
                      value={bankersPerBet}
                      onChange={(e) =>
                        onChangeBankersPerBet(
                          Math.max(1, Math.min(Math.max(1, pick - 1), Number(e.target.value) || 1))
                        )
                      }
                      slotProps={{ htmlInput: { min: 1, max: Math.max(1, pick - 1) } }}
                      sx={{ width: 155 }}
                    />
                  )}
                </Stack>

                <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1.25 }}>
                  <TextField
                    size="small"
                    type="number"
                    label={tr.bankersAutoCountLabel}
                    value={mode === '4' ? Math.min(autoBankerCount, Math.max(1, pick - 1)) : autoBankerCount}
                    onChange={(e) => setAutoBankerCount(Math.max(1, Number(e.target.value) || 1))}
                    slotProps={{
                      htmlInput: {
                        min: 1,
                        max: mode === '4' ? Math.max(1, pick - 1) : Math.max(1, maxNumber - 1),
                      },
                    }}
                    sx={{ width: 155 }}
                  />
                  <Button
                    size="small"
                    variant="outlined"
                    disabled={loading}
                    onClick={() => onAutoPickBankers(autoBankerCount)}
                  >
                    {tr.bankersAutoPick}
                  </Button>
                  <Button
                    size="small"
                    variant="contained"
                    color="secondary"
                    disabled={loading}
                    onClick={() =>
                      onAiPickBankers(
                        mode === '4'
                          ? Math.min(autoBankerCount, Math.max(1, pick - 1))
                          : autoBankerCount
                      )
                    }
                  >
                    {tr.bankersAiPick}
                  </Button>
                </Stack>

                <Stack direction="row" spacing={1} sx={{ mb: 1 }}>
                  <TextField
                    fullWidth
                    size="small"
                    label={tr.bankersManualInputLabel}
                    placeholder="np. 1, 7, 10, 11, 18, 20, 21..."
                    value={bankerInput}
                    onChange={(e) => setBankerInput(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') handleApplyBankerInput();
                    }}
                  />
                  <Button size="small" variant="outlined" onClick={handleApplyBankerInput}>
                    {tr.manualPoolApply}
                  </Button>
                </Stack>

                <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                  {tr.bankerGridHint}
                </Typography>
              </Paper>
            )}

            <Stack direction="row" sx={{ flexWrap: 'wrap', columnGap: 1 }}>
              <FormControlLabel
                control={
                  <Checkbox
                    size="small"
                    checked={coverDecades}
                    onChange={(e) => onToggleDecades(e.target.checked)}
                  />
                }
                label={<Typography variant="body2">{tr.coverDecades}</Typography>}
              />
              <FormControlLabel
                control={
                  <Checkbox
                    size="small"
                    checked={withNeighbours}
                    onChange={(e) => onToggleNeighbours(e.target.checked)}
                  />
                }
                label={<Typography variant="body2">{tr.neighbours}</Typography>}
              />
              <FormControlLabel
                control={
                  <Checkbox
                    size="small"
                    checked={includeAiCommentary}
                    onChange={(e) => onToggleAiCommentary(e.target.checked)}
                  />
                }
                label={<Typography variant="body2">{tr.aiCommentary}</Typography>}
              />
            </Stack>

            <Box>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                {tr.candidatePoolLabel(pick)}
              </Typography>
              {poolMode === 'manual' && (
                <Stack direction="row" spacing={1} sx={{ mb: 1.5 }}>
                  <TextField
                    fullWidth
                    size="small"
                    label={tr.manualPoolInputLabel}
                    placeholder={tr.manualPoolInputPlaceholder}
                    value={manualInput}
                    onChange={(e) => setManualInput(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') handleApplyManualInput();
                    }}
                  />
                  <Button size="small" variant="outlined" onClick={handleApplyManualInput}>
                    {tr.manualPoolApply}
                  </Button>
                </Stack>
              )}
              <Stack direction="row" spacing={1} useFlexGap sx={{ mb: 1.5, flexWrap: 'wrap' }}>
                <Button size="small" variant="outlined" onClick={onPoolAll}>
                  {tr.poolAll(maxNumber)}
                </Button>
                <Button size="small" variant="outlined" onClick={onPoolRand18}>
                  {tr.poolRand18}
                </Button>
                <Button
                  size="small"
                  variant="outlined"
                  color="secondary"
                  disabled={loading}
                  onClick={onPoolAiPick}
                >
                  {tr.poolAiPick}
                </Button>
                <Button size="small" variant="outlined" color="inherit" onClick={onPoolClear}>
                  {tr.poolClear}
                </Button>
              </Stack>

              <Box
                sx={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
                  gap: 1,
                  justifyItems: 'center',
                }}
              >
                {Array.from({ length: maxNumber }, (_, i) => i + 1).map((n) => {
                  const selected = selectedPool.includes(n);
                  const isBanker = isBankerMode && bankers.includes(n) && selected;
                  return (
                    <Button
                      key={n}
                      size="small"
                      variant={selected || isBanker ? 'contained' : 'outlined'}
                      color={isBanker ? 'warning' : selected ? 'primary' : 'inherit'}
                      onClick={() => {
                        if (isBankerMode && clickMode === 'banker') {
                          onToggleBanker(n);
                        } else {
                          onToggleNumber(n);
                        }
                      }}
                      onContextMenu={(e) => {
                        if (isBankerMode) {
                          e.preventDefault();
                          onToggleBanker(n);
                        }
                      }}
                      sx={{
                        width: 40,
                        height: 40,
                        minWidth: 40,
                        borderRadius: '50%',
                        p: 0,
                        fontSize: isBanker ? '0.78rem' : '0.85rem',
                        fontWeight: selected || isBanker ? 700 : 500,
                        fontVariantNumeric: 'tabular-nums',
                        borderColor: selected || isBanker ? 'transparent' : 'divider',
                        backgroundImage: isBanker
                          ? 'radial-gradient(circle at 32% 28%, #ffe082 0%, #f57c00 72%, #e65100 100%)'
                          : selected
                            ? 'radial-gradient(circle at 32% 28%, #64b5f6 0%, #1976d2 70%, #0d47a1 100%)'
                            : undefined,
                        color: isBanker || selected ? '#ffffff' : undefined,
                        boxShadow: isBanker || selected ? 2 : 0,
                        transition: 'transform 120ms ease, box-shadow 120ms ease',
                        '&:hover': {
                          transform: 'scale(1.06)',
                        },
                      }}
                    >
                      {isBanker ? `${n}★` : n}
                    </Button>
                  );
                })}
              </Box>
            </Box>

            <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
              <Button
                variant="contained"
                startIcon={<PlayArrowIcon />}
                disabled={loading}
                onClick={onRunGenerate}
                sx={{ py: 0.75, px: 2, whiteSpace: 'nowrap' }}
              >
                {tr.btnGenerate}
              </Button>
              <Button
                variant="outlined"
                startIcon={<AnalyticsIcon />}
                disabled={loading}
                onClick={onRunStats}
                sx={{ py: 0.75, px: 2, whiteSpace: 'nowrap' }}
              >
                {tr.btnStatsReport}
              </Button>
            </Stack>
          </Stack>
        </CardContent>
      </Card>

      <Stack spacing={2}>
        {warnings.map((w, idx) => (
          <Alert key={idx} severity="warning">
            {w}
          </Alert>
        ))}

        {(aiCommentary || agentReasoning) && (
          <Card variant="outlined">
            <CardHeader
              title={tr.aiStrategicCommentary}
              titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }}
            />
            <Divider />
            <CardContent>
              <Stack spacing={1}>
                {agentReasoning && (
                  <Typography variant="body2" color="text.secondary">
                    {agentReasoning}
                  </Typography>
                )}
                {aiCommentary && (
                  <Typography variant="body2">{aiCommentary}</Typography>
                )}
              </Stack>
            </CardContent>
          </Card>
        )}

        {report && (
          <Card variant="outlined">
            <CardHeader
              title={tr.statsSummaryTitle}
              titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }}
            />
            <Divider />
            <CardContent>
              <Box
                sx={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
                  gap: 1.5,
                  mb: 2,
                }}
              >
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">
                    {tr.poolCoverage}
                  </Typography>
                  <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>
                    {poolCoveragePct}% ({report.pool_coverage?.used_numbers ?? selectedPool.length}/
                    {selectedPool.length})
                  </Typography>
                </Paper>
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">
                    {tr.gaussianOptimalWindow}
                  </Typography>
                  <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>
                    {gaussAnalysis?.optimal_range ??
                      statsResult?.gaussian?.optimal_range_str ??
                      'N/A'}
                  </Typography>
                </Paper>
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">
                    {tr.couponsInGaussianWindow}
                  </Typography>
                  <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>
                    {inOptimalPct}%
                  </Typography>
                </Paper>
                <Paper variant="outlined" sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">
                    {tr.uniquePairsCovered}
                  </Typography>
                  <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>
                    {report.pair_coverage?.unique_pairs_used ?? '—'} (
                    {report.pair_coverage?.pair_coverage_pct ?? 0}%)
                  </Typography>
                </Paper>
              </Box>

              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.75 }}>
                {tr.gaussianAlignment(inOptimalPct)}
              </Typography>
              <LinearProgress
                variant="determinate"
                value={Math.min(100, Math.max(0, inOptimalPct))}
                sx={{ height: 8, borderRadius: 4 }}
              />
            </CardContent>
          </Card>
        )}

        <Card variant="outlined">
          <CardHeader
            title={tr.generatedCoupons(rows.length)}
            subheader={
              selectedCouponIds.length > 0
                ? tr.selectedCouponsCount(selectedCouponIds.length)
                : undefined
            }
            titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }}
            action={
              rows.length > 0 ? (
                <Stack direction="row" spacing={0.75} useFlexGap sx={{ flexWrap: 'wrap' }}>
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<DownloadIcon />}
                    onClick={() => handleExport('csv')}
                  >
                    {tr.exportCsv}
                  </Button>
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<DownloadIcon />}
                    onClick={() => handleExport('txt')}
                  >
                    {tr.exportTxt}
                  </Button>
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<DownloadIcon />}
                    onClick={() => handleExport('json')}
                  >
                    {tr.exportJson}
                  </Button>
                </Stack>
              ) : undefined
            }
          />
          <Divider />
          <CardContent
            sx={{
              '& [aria-selected="true"]': {
                bgcolor: 'action.selected',
              },
              '& tbody tr:hover': {
                bgcolor: 'action.hover',
              },
            }}
          >
            {rows.length === 0 ? (
              <Typography
                variant="body2"
                color="text.secondary"
                dangerouslySetInnerHTML={{ __html: tr.emptyCouponsHint }}
              />
            ) : (
              <Gridwright<CouponRow>
                columns={columns}
                data={rows}
                getRowId={(r) => r.id}
                pageSize={15}
                coreAddons={gridCoreAddons}
                addons={gridAddons}
                locale={lang === 'pl' ? pl : en}
                selectionMode="multiple"
                onSelectionChange={(ids) => setSelectedCouponIds(ids)}
                aria-label={tr.generatedCoupons(rows.length)}
              />
            )}
          </CardContent>
        </Card>
      </Stack>
    </Box>
  );
}
