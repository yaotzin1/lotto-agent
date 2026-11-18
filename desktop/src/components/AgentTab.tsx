import { useMemo } from 'react';
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
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import { Gridwright, type GridwrightColumn } from '@apsw/gridwright/react';
import { muiAddons } from '@apsw/gridwright-mui';
import { en, pl } from '@apsw/gridwright/locales';
import type { AgentRunResponse, GameConfig, SettingsResponse } from '../api';
import type { Lang, Translations } from '../i18n';
import { NumberBalls } from './NumberBalls';

interface ToolStepRow {
  id: number;
  turn: number;
  tool: string;
  argsJson: string;
}

interface AgentTabProps {
  lang: Lang;
  tr: Translations;
  games: Record<string, GameConfig>;
  selectedGame: string;
  agentStrategy: string;
  agentPoolSize: number;
  agentSessions: number;
  agentProvider: string;
  agentModel: string;
  coverDecades: boolean;
  withNeighbours: boolean;
  loading: boolean;
  settings: SettingsResponse | null;
  agentResult: AgentRunResponse | null;
  onSelectGame: (game: string) => void;
  onChangeStrategy: (val: string) => void;
  onChangePoolSize: (val: number) => void;
  onChangeProvider: (val: string) => void;
  onChangeModel: (val: string) => void;
  onChangeSessions: (val: number) => void;
  onToggleDecades: (val: boolean) => void;
  onToggleNeighbours: (val: boolean) => void;
  onRunAgent: () => void;
  onApplyPool: () => void;
}

export function AgentTab({
  lang,
  tr,
  games,
  selectedGame,
  agentStrategy,
  agentPoolSize,
  agentSessions,
  agentProvider,
  agentModel,
  coverDecades,
  withNeighbours,
  loading,
  settings,
  agentResult,
  onSelectGame,
  onChangeStrategy,
  onChangePoolSize,
  onChangeProvider,
  onChangeModel,
  onChangeSessions,
  onToggleDecades,
  onToggleNeighbours,
  onRunAgent,
  onApplyPool,
}: AgentTabProps) {
  const catalog = settings?.providers_catalog ?? {};
  const providerModels = catalog[agentProvider]?.models ?? {};

  const stepRows: ToolStepRow[] = useMemo(
    () =>
      (agentResult?.steps ?? []).map((s, idx) => ({
        id: idx + 1,
        turn: s.turn,
        tool: s.tool,
        argsJson: JSON.stringify(s.args),
      })),
    [agentResult]
  );

  const columns: GridwrightColumn<ToolStepRow, any>[] = useMemo(
    () => [
      {
        id: 'turn',
        header: tr.colTurn,
        accessor: 'turn',
        width: 80,
        align: 'end',
        sortable: true,
      },
      {
        id: 'tool',
        header: tr.colTool,
        accessor: 'tool',
        width: 210,
        sortable: true,
        cell: ({ value }) => (
          <Box component="code" sx={{ fontSize: '0.82rem', fontWeight: 600 }}>
            {String(value)}
          </Box>
        ),
      },
      {
        id: 'argsJson',
        header: tr.colArgs,
        accessor: 'argsJson',
        sortable: false,
        cell: ({ value }) => (
          <Box
            component="code"
            sx={{ fontSize: '0.78rem', wordBreak: 'break-all', whiteSpace: 'pre-wrap' }}
          >
            {String(value)}
          </Box>
        ),
      },
    ],
    [tr]
  );

  const gridCoreAddons = useMemo(() => muiAddons<ToolStepRow>(), []);

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
        <CardHeader title={tr.agentTitle} titleTypographyProps={{ variant: 'h6' }} />
        <Divider />
        <CardContent>
          <Stack spacing={2}>
            <FormControl fullWidth size="small">
              <InputLabel>{tr.gameLabel}</InputLabel>
              <Select
                label={tr.gameLabel}
                value={selectedGame}
                onChange={(e) => onSelectGame(e.target.value)}
              >
                {Object.values(games).map((g) => (
                  <MenuItem key={g.name} value={g.name}>
                    {g.name} ({g.pick}/{g.from})
                  </MenuItem>
                ))}
              </Select>
            </FormControl>

            <Stack direction="row" spacing={1.5}>
              <FormControl fullWidth size="small">
                <InputLabel>{tr.strategyLabel}</InputLabel>
                <Select
                  label={tr.strategyLabel}
                  value={agentStrategy}
                  onChange={(e) => onChangeStrategy(e.target.value)}
                >
                  {['syndicate', 'balanced', 'aggressive', 'decades'].map((s) => (
                    <MenuItem key={s} value={s}>
                      {tr.strategies[s]}
                    </MenuItem>
                  ))}
                </Select>
                <FormHelperText>{tr.strategyDescriptions[agentStrategy]}</FormHelperText>
              </FormControl>

              <TextField
                size="small"
                type="number"
                label={tr.targetPoolSize}
                value={agentPoolSize}
                onChange={(e) => onChangePoolSize(Math.max(5, Number(e.target.value) || 18))}
                slotProps={{ htmlInput: { min: 5, max: 49 } }}
                sx={{ width: 150, flexShrink: 0 }}
              />
            </Stack>

            <Stack direction="row" spacing={1.5}>
              <FormControl fullWidth size="small">
                <InputLabel>{tr.aiProvider}</InputLabel>
                <Select
                  label={tr.aiProvider}
                  value={agentProvider}
                  onChange={(e) => onChangeProvider(e.target.value)}
                >
                  <MenuItem value="gemini">Google Gemini</MenuItem>
                  <MenuItem value="claude">Anthropic Claude</MenuItem>
                  <MenuItem value="openai">OpenAI</MenuItem>
                  <MenuItem value="deepseek">DeepSeek</MenuItem>
                </Select>
              </FormControl>

              <FormControl fullWidth size="small">
                <InputLabel>{tr.modelLabel}</InputLabel>
                <Select
                  label={tr.modelLabel}
                  value={agentModel}
                  onChange={(e) => onChangeModel(e.target.value)}
                >
                  <MenuItem value="">
                    {tr.modelDefault(catalog[agentProvider]?.defaultModel ?? 'auto')}
                  </MenuItem>
                  {Object.entries(providerModels).map(([id, m]) => (
                    <MenuItem key={id} value={id}>
                      {m.name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Stack>

            <TextField
              fullWidth
              size="small"
              type="number"
              label={tr.historicalWindow}
              value={agentSessions}
              onChange={(e) => onChangeSessions(Math.max(10, Number(e.target.value) || 50))}
              slotProps={{ htmlInput: { min: 10, max: 500 } }}
            />

            <Stack direction="row" spacing={1}>
              <FormControlLabel
                control={
                  <Checkbox
                    size="small"
                    checked={coverDecades}
                    onChange={(e) => onToggleDecades(e.target.checked)}
                  />
                }
                label={<Typography variant="body2">{tr.decadeBalance}</Typography>}
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
            </Stack>

            <Button
              fullWidth
              variant="contained"
              startIcon={<SmartToyIcon />}
              disabled={loading}
              onClick={onRunAgent}
            >
              {loading ? tr.btnRunAgentRunning : tr.btnRunAgent}
            </Button>
          </Stack>
        </CardContent>
      </Card>

      <Stack spacing={2}>
        {!agentResult ? (
          <Card variant="outlined">
            <CardHeader
              title={tr.agentOutput}
              titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }}
            />
            <Divider />
            <CardContent>
              <Typography variant="body2" color="text.secondary">
                {tr.agentOutputHint}
              </Typography>
            </CardContent>
          </Card>
        ) : (
          <>
            <Card variant="outlined">
              <CardHeader
                title={tr.selectedCandidatePool(agentResult.selected_pool.length)}
                titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }}
                action={
                  <Button
                    size="small"
                    variant="outlined"
                    endIcon={<ArrowForwardIcon />}
                    onClick={onApplyPool}
                  >
                    {tr.usePoolInGenerator.replace(/\s*→$/, '')}
                  </Button>
                }
              />
              <Divider />
              <CardContent>
                <Stack spacing={1.5}>
                  {agentResult.is_fallback && (
                    <Alert severity="warning">{tr.fallbackPoolAlert}</Alert>
                  )}
                  <NumberBalls numbers={agentResult.selected_pool} size="medium" />
                  <Typography variant="body2" color="text.secondary">
                    {agentResult.reasoning}
                  </Typography>
                </Stack>
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardHeader
                title={tr.reactToolTrace(stepRows.length)}
                titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }}
              />
              <Divider />
              <CardContent>
                {stepRows.length === 0 ? (
                  <Typography variant="body2" color="text.secondary">
                    {tr.noToolCalls}
                  </Typography>
                ) : (
                  <Gridwright<ToolStepRow>
                    columns={columns}
                    data={stepRows}
                    getRowId={(r) => r.id}
                    pageSize={10}
                    coreAddons={gridCoreAddons}
                    locale={lang === 'pl' ? pl : en}
                    selectionMode="none"
                    aria-label={tr.reactToolTrace(stepRows.length)}
                  />
                )}
              </CardContent>
            </Card>
          </>
        )}
      </Stack>
    </Box>
  );
}
