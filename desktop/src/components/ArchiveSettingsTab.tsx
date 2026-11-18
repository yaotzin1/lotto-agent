import { useMemo, useState } from 'react';
import {
  Box,
  Button,
  Card,
  CardContent,
  CardHeader,
  Chip,
  Divider,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import SaveIcon from '@mui/icons-material/Save';
import SyncIcon from '@mui/icons-material/Sync';
import { Gridwright, type GridwrightColumn } from '@apsw/gridwright/react';
import { muiAddons } from '@apsw/gridwright-mui';
import { en, pl } from '@apsw/gridwright/locales';
import type { ArchiveFreshness, SettingsResponse } from '../api';
import type { Lang, Translations } from '../i18n';

interface ArchiveRow {
  game: string;
  total: number;
  lastDate: string;
  expectedDate: string;
  missing: number;
  stale: boolean;
  statusLabel: string;
}

interface ArchiveSettingsTabProps {
  lang: Lang;
  tr: Translations;
  selectedGame: string;
  archives: Record<string, ArchiveFreshness>;
  syncMode: string;
  settings: SettingsResponse | null;
  loading: boolean;
  onChangeSyncMode: (val: string) => void;
  onSyncSelected: () => void;
  onSaveSettings: (payload: {
    ai_provider: string;
    ai_model: string;
    keys: Record<string, string>;
  }) => void;
}

const SECRET_KEYS = [
  'LOTTO_API_KEY',
  'GEMINI_API_KEY',
  'ANTHROPIC_API_KEY',
  'OPENAI_API_KEY',
  'DEEPSEEK_API_KEY',
];

export function ArchiveSettingsTab({
  lang,
  tr,
  selectedGame,
  archives,
  syncMode,
  settings,
  loading,
  onChangeSyncMode,
  onSyncSelected,
  onSaveSettings,
}: ArchiveSettingsTabProps) {
  const [keyInputs, setKeyInputs] = useState<Record<string, string>>({});
  const [aiProvider, setAiProvider] = useState<string>(settings?.ai_provider ?? 'gemini');
  const [aiModel, setAiModel] = useState<string>(settings?.ai_model ?? '');

  const keys = settings?.keys ?? {};

  const rows: ArchiveRow[] = useMemo(
    () =>
      Object.entries(archives).map(([game, a]) => ({
        game,
        total: a.total,
        lastDate: a.last_date ?? '—',
        expectedDate: a.expected_date ?? '—',
        missing: a.missing,
        stale: a.stale,
        statusLabel: a.stale ? tr.statusStale : tr.statusFresh,
      })),
    [archives, tr]
  );

  const columns: GridwrightColumn<ArchiveRow, any>[] = useMemo(
    () => [
      {
        id: 'game',
        header: tr.colGame,
        accessor: 'game',
        sortable: true,
        cell: ({ value }) => <Typography variant="body2" sx={{ fontWeight: 700 }}>{String(value)}</Typography>,
      },
      {
        id: 'total',
        header: tr.colTotalDraws,
        accessor: 'total',
        align: 'end',
        sortable: true,
      },
      {
        id: 'lastDate',
        header: tr.colLastDrawDate,
        accessor: 'lastDate',
        sortable: true,
      },
      {
        id: 'expectedDate',
        header: tr.colExpected,
        accessor: 'expectedDate',
        sortable: true,
      },
      {
        id: 'missing',
        header: tr.colMissing,
        accessor: 'missing',
        align: 'end',
        sortable: true,
      },
      {
        id: 'statusLabel',
        header: tr.colStatus,
        accessor: 'statusLabel',
        align: 'center',
        sortable: true,
        cell: ({ row }) => (
          <Chip
            size="small"
            label={row.statusLabel}
            color={row.stale ? 'warning' : 'success'}
            variant="outlined"
          />
        ),
      },
    ],
    [tr]
  );

  const gridCoreAddons = useMemo(() => muiAddons<ArchiveRow>(), []);

  const handleSave = () => {
    const cleaned: Record<string, string> = {};
    for (const [k, v] of Object.entries(keyInputs)) {
      if (v.trim() !== '') {
        cleaned[k] = v.trim();
      }
    }
    onSaveSettings({
      ai_provider: aiProvider,
      ai_model: aiModel,
      keys: cleaned,
    });
    setKeyInputs({});
  };

  return (
    <Box
      sx={{
        display: 'grid',
        gridTemplateColumns: { xs: '1fr', lg: '460px 1fr' },
        gap: 2.5,
        alignItems: 'start',
      }}
    >
      <Card variant="outlined">
        <CardHeader title={tr.settingsTitle} titleTypographyProps={{ variant: 'h6' }} />
        <Divider />
        <CardContent>
          <Stack spacing={2}>
            <Typography
              variant="body2"
              color="text.secondary"
              dangerouslySetInnerHTML={{ __html: tr.settingsHint }}
            />

            {SECRET_KEYS.map((envKey) => {
              const info = keys[envKey];
              const label = tr.secretLabels[envKey] ?? envKey;
              return (
                <Box key={envKey}>
                  <Stack
                    direction="row"
                    sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 0.75 }}
                  >
                    <Typography variant="caption" sx={{ fontWeight: 600 }}>
                      {label}
                    </Typography>
                    <Chip
                      size="small"
                      label={
                        info?.configured
                          ? tr.configured(info.masked ?? '')
                          : tr.notConfigured
                      }
                      color={info?.configured ? 'success' : 'default'}
                      variant="outlined"
                    />
                  </Stack>
                  <TextField
                    fullWidth
                    size="small"
                    type="password"
                    value={keyInputs[envKey] ?? ''}
                    placeholder={tr.enterNewKey(envKey)}
                    onChange={(e) =>
                      setKeyInputs((prev) => ({ ...prev, [envKey]: e.target.value }))
                    }
                  />
                </Box>
              );
            })}

            <Stack direction="row" spacing={1.5}>
              <FormControl fullWidth size="small">
                <InputLabel>{tr.defaultAiProvider}</InputLabel>
                <Select
                  label={tr.defaultAiProvider}
                  value={aiProvider}
                  onChange={(e) => setAiProvider(e.target.value)}
                >
                  {['gemini', 'claude', 'openai', 'deepseek'].map((p) => (
                    <MenuItem key={p} value={p}>
                      {p}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>

              <TextField
                fullWidth
                size="small"
                label={tr.defaultAiModelOverride}
                value={aiModel}
                placeholder="np. gemini-3.8-flash"
                onChange={(e) => setAiModel(e.target.value)}
              />
            </Stack>

            <Button
              variant="contained"
              startIcon={<SaveIcon />}
              disabled={loading}
              onClick={handleSave}
            >
              {tr.saveSettings}
            </Button>
          </Stack>
        </CardContent>
      </Card>

      <Card variant="outlined">
        <CardHeader
          title={tr.localDrawArchives}
          subheader={tr.syncModeDescriptions[syncMode]}
          titleTypographyProps={{ variant: 'h6' }}
          action={
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
              <Select
                size="small"
                value={syncMode}
                onChange={(e) => onChangeSyncMode(e.target.value)}
              >
                {['refresh', 'backfill', 'repair'].map((m) => (
                  <MenuItem key={m} value={m}>
                    {tr.syncModes[m]}
                  </MenuItem>
                ))}
              </Select>
              <Button
                size="small"
                variant="outlined"
                startIcon={<SyncIcon />}
                disabled={loading}
                onClick={onSyncSelected}
              >
                {tr.syncGame(selectedGame)}
              </Button>
            </Stack>
          }
        />
        <Divider />
        <CardContent>
          <Gridwright<ArchiveRow>
            columns={columns}
            data={rows}
            getRowId={(r) => r.game}
            pageSize={15}
            coreAddons={gridCoreAddons}
            locale={lang === 'pl' ? pl : en}
            selectionMode="none"
            aria-label={tr.localDrawArchives}
          />
        </CardContent>
      </Card>
    </Box>
  );
}
