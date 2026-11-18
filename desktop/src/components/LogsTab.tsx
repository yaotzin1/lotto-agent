import { useCallback, useEffect, useMemo, useState } from 'react';
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
  Typography,
} from '@mui/material';
import RefreshIcon from '@mui/icons-material/Refresh';
import DeleteOutlinedIcon from '@mui/icons-material/DeleteOutlined';
import { Gridwright, search, type GridwrightColumn } from '@apsw/gridwright/react';
import { muiAddons } from '@apsw/gridwright-mui';
import { en, pl } from '@apsw/gridwright/locales';
import {
  clearClientLogs,
  getClientLogs,
  lottoApi,
  subscribeClientLogs,
  type LogEntry,
} from '../api';
import type { Lang, Translations } from '../i18n';

interface LogsTabProps {
  lang: Lang;
  tr: Translations;
}

export function LogsTab({ lang, tr }: LogsTabProps) {
  const [backendLogs, setBackendLogs] = useState<LogEntry[]>([]);
  const [clientLogs, setClientLogs] = useState<LogEntry[]>(() => getClientLogs());
  const [levelFilter, setLevelFilter] = useState<string>('');
  const [loading, setLoading] = useState(false);

  const fetchBackendLogs = useCallback(async () => {
    setLoading(true);
    try {
      const res = await lottoApi.getLogs(300);
      setBackendLogs(res.logs ?? []);
    } catch {
      // clientLogs already records API errors
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchBackendLogs();
    return subscribeClientLogs(() => {
      setClientLogs(getClientLogs());
    });
  }, [fetchBackendLogs]);

  const handleClear = async () => {
    clearClientLogs();
    try {
      await lottoApi.clearLogs();
      setBackendLogs([]);
    } catch {
      // ignore if backend logs cannot be cleared
    }
  };

  const rows: LogEntry[] = useMemo(() => {
    const merged = [...clientLogs, ...backendLogs];
    const filtered = levelFilter
      ? merged.filter((entry) => entry.level.toUpperCase() === levelFilter)
      : merged;
    return filtered.sort((a, b) => (b.timestamp || '').localeCompare(a.timestamp || ''));
  }, [clientLogs, backendLogs, levelFilter]);

  const columns: GridwrightColumn<LogEntry, any>[] = useMemo(
    () => [
      {
        id: 'timestamp',
        header: tr.logsColTime,
        accessor: 'timestamp',
        width: 210,
        sortable: true,
        cell: ({ value }) => (
          <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>
            {String(value || '—')}
          </Typography>
        ),
      },
      {
        id: 'level',
        header: tr.logsColLevel,
        accessor: 'level',
        width: 110,
        align: 'center',
        sortable: true,
        cell: ({ value }) => {
          const lvl = String(value).toUpperCase();
          const color =
            lvl === 'ERROR' || lvl === 'CRITICAL'
              ? 'error'
              : lvl === 'WARNING'
                ? 'warning'
                : 'info';
          return <Chip size="small" label={lvl} color={color} variant="outlined" />;
        },
      },
      {
        id: 'source',
        header: tr.logsColSource,
        accessor: 'source',
        width: 170,
        sortable: true,
      },
      {
        id: 'message',
        header: tr.logsColMessage,
        accessor: 'message',
        sortable: false,
        cell: ({ value }) => (
          <Box sx={{ fontFamily: 'monospace', fontSize: '0.8rem', wordBreak: 'break-word' }}>
            {String(value)}
          </Box>
        ),
      },
    ],
    [tr]
  );

  const gridCoreAddons = useMemo(() => muiAddons<LogEntry>(), []);
  const gridAddons = useMemo(() => [search<LogEntry>()], []);

  return (
    <Card variant="outlined">
      <CardHeader
        title={tr.logsTitle}
        subheader={tr.logsSubtitle}
        titleTypographyProps={{ variant: 'h6' }}
        action={
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
            <FormControl size="small" sx={{ minWidth: 130 }}>
              <InputLabel>{tr.logsFilterLevel}</InputLabel>
              <Select
                label={tr.logsFilterLevel}
                value={levelFilter}
                onChange={(e) => setLevelFilter(e.target.value)}
              >
                <MenuItem value="">{tr.logsAllLevels}</MenuItem>
                <MenuItem value="INFO">INFO</MenuItem>
                <MenuItem value="WARNING">WARNING</MenuItem>
                <MenuItem value="ERROR">ERROR</MenuItem>
              </Select>
            </FormControl>

            <Button
              size="small"
              variant="outlined"
              startIcon={<RefreshIcon />}
              disabled={loading}
              onClick={fetchBackendLogs}
            >
              {tr.logsRefresh}
            </Button>

            <Button
              size="small"
              variant="outlined"
              color="error"
              startIcon={<DeleteOutlinedIcon />}
              onClick={handleClear}
            >
              {tr.logsClear}
            </Button>
          </Stack>
        }
      />
      <Divider />
      <CardContent>
        <Gridwright<LogEntry>
          columns={columns}
          data={rows}
          getRowId={(r) => r.id}
          pageSize={25}
          coreAddons={gridCoreAddons}
          addons={gridAddons}
          locale={lang === 'pl' ? pl : en}
          selectionMode="none"
          aria-label={tr.logsTitle}
        />
      </CardContent>
    </Card>
  );
}
