import { invoke } from '@tauri-apps/api/core';

export interface BackendConfig {
  base_url: string;
  token: string;
}

export interface GameConfig {
  name: string;
  pick: number;
  pick_min: number;
  pick_max: number;
  system_max?: number;
  from: number;
  extra: number;
  extra_from: number;
  draw_days: number[];
  variable_pick: boolean;
  pick_range: { min: number; max: number; default: number };
  supports_full_system: boolean;
  system_pick_range: { min: number; max: number; default: number };
}

export interface GenerateResponse {
  game: string;
  mode: string;
  pick: number;
  pool: number[];
  count: number;
  bets: number[][];
  extra_sets: number[][];
  stats_report: Record<string, any> | null;
  extra_info: Record<string, any> | null;
  ai_commentary?: string | null;
  warnings: string[];
}

export interface StatsResponse {
  game: string;
  pick: number;
  max_number: number;
  pool: number[];
  count: number;
  bets: number[][];
  report: Record<string, any>;
  dilution: Record<string, any>;
  gaussian: {
    expected_sum: number;
    std_dev: number;
    optimal_min: number;
    optimal_max: number;
    optimal_range_str: string;
  };
  draws_analyzed: number;
  ai_commentary: string | null;
  warnings: string[];
}

export interface AgentStep {
  turn: number;
  tool: string;
  args: Record<string, any>;
  result: Record<string, any> | null;
}

export interface AgentRunResponse {
  game: string;
  strategy: string;
  pool: number[];
  selected_pool: number[];
  reasoning: string;
  steps: AgentStep[];
  is_fallback: boolean;
}

export interface ArchiveFreshness {
  last_date: string | null;
  expected_date: string | null;
  missing: number;
  stale: boolean;
  total: number;
}

export interface SettingsResponse {
  keys: Record<string, { configured: boolean; masked: string }>;
  ai_provider: string;
  ai_model: string;
  openai_base_url: string;
  providers_catalog: Record<
    string,
    {
      displayName: string;
      defaultModel: string;
      models: Record<string, { name: string; description: string; recommendedFor: string }>;
      fallbacks: string[];
    }
  >;
}

export interface SidecarDiagnostics {
  mode: 'sidecar' | 'dev_external';
  state: 'starting' | 'ready' | 'failed' | 'exited';
  php_path: string | null;
  php_source: string | null;
  php_version: string | null;
  error: {
    kind: string;
    message?: string;
    found?: string;
    required?: string;
    path?: string;
    code?: number | null;
    signal?: number | null;
    hint?: string | null;
    url?: string;
  } | null;
  exit_code: number | null;
  signal: number | null;
  stderr_tail: string[];
  base_url: string;
  data_dir: string | null;
  checked_at: number;
}

export function formatSidecarDiagnostics(diag: SidecarDiagnostics): string {
  const err = diag.error;
  if (!err) {
    return `${diag.state} (${diag.base_url})`;
  }
  switch (err.kind) {
    case 'VersionTooOld':
      return `PHP ${err.found} < ${err.required} (${diag.php_path ?? 'PATH'})`;
    case 'PhpNotFound':
      return err.message || 'PHP binary not found';
    case 'PermissionDenied':
      return err.message || 'Permission denied executing PHP';
    case 'DataDirNotWritable':
      return `App-Data not writable: ${err.path} (${err.message})`;
    case 'DevBackendUnauthorized':
      return `401 Unauthorized on ${err.url} (token mismatch)`;
    case 'HealthTimeout':
      return `${err.message}${diag.stderr_tail.length ? ` | stderr: ${diag.stderr_tail.slice(-2).join(' ; ')}` : ''}`;
    default:
      return err.message || err.kind;
  }
}

export async function getSidecarDiagnostics(): Promise<SidecarDiagnostics | null> {
  try {
    return await invoke<SidecarDiagnostics>('get_sidecar_diagnostics');
  } catch {
    return null;
  }
}

export async function retrySidecar(): Promise<SidecarDiagnostics | null> {
  try {
    resetBackendConfig();
    return await invoke<SidecarDiagnostics>('retry_sidecar');
  } catch {
    return null;
  }
}

let cachedConfig: BackendConfig | null = null;

export function resetBackendConfig(): void {
  cachedConfig = null;
}

async function resolveBackendConfig(): Promise<BackendConfig> {
  if (cachedConfig) {
    return cachedConfig;
  }

  try {
    const cfg = await invoke<BackendConfig>('get_backend_config');
    if (cfg && cfg.base_url) {
      cachedConfig = cfg;
      return cfg;
    }
  } catch {
    // Running outside Tauri shell (e.g., standalone browser Vite dev)
  }

  cachedConfig = {
    base_url: (import.meta as any).env?.VITE_API_BASE_URL || 'http://127.0.0.1:8000',
    token: (import.meta as any).env?.VITE_DESKTOP_TOKEN || '',
  };
  return cachedConfig;
}

export interface LogEntry {
  id: string;
  timestamp: string;
  level: 'INFO' | 'WARNING' | 'ERROR' | string;
  source: string;
  message: string;
}

export interface LogsResponse {
  logs: LogEntry[];
  files: string[];
  log_dir: string;
}

const clientLogs: LogEntry[] = [];
const logListeners = new Set<() => void>();
let clientLogSeq = 1;

export function appendClientLog(level: LogEntry['level'], source: string, message: string): void {
  clientLogs.unshift({
    id: `ui-${clientLogSeq++}`,
    timestamp: new Date().toISOString(),
    level,
    source,
    message,
  });
  if (clientLogs.length > 300) {
    clientLogs.length = 300;
  }
  logListeners.forEach((fn) => fn());
}

export function getClientLogs(): LogEntry[] {
  return [...clientLogs];
}

export function clearClientLogs(): void {
  clientLogs.length = 0;
  logListeners.forEach((fn) => fn());
}

export function subscribeClientLogs(fn: () => void): () => void {
  logListeners.add(fn);
  return () => {
    logListeners.delete(fn);
  };
}

async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const { base_url, token } = await resolveBackendConfig();
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string> | undefined),
  };

  if (options.body && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const method = options.method || 'GET';
  const started = performance.now();

  try {
    const response = await fetch(`${base_url}${path}`, {
      ...options,
      headers,
    });

    const payload = await response.json().catch(() => ({}));
    const ms = Math.round(performance.now() - started);

    if (!response.ok) {
      const errMsg = payload.error || `HTTP ${response.status}`;
      if (!path.startsWith('/api/logs')) {
        appendClientLog('ERROR', `API ${method}`, `${base_url}${path} → ${response.status} (${ms} ms): ${errMsg}`);
      }
      throw new Error(errMsg);
    }

    if (!path.startsWith('/api/logs')) {
      appendClientLog('INFO', `API ${method}`, `${base_url}${path} → 200 OK (${ms} ms)`);
    }

    return payload as T;
  } catch (err: any) {
    const ms = Math.round(performance.now() - started);
    const msg = err?.message || String(err);
    if (!msg.startsWith('HTTP ')) {
      // Network/connection failure: clear cached config so next attempt re-resolves
      resetBackendConfig();

      // In dev mode, if Tauri's spawned sidecar failed (e.g. PHP < 8.5), try Docker dev backend on :8000
      const devFallback = (import.meta as any).env?.DEV ? 'http://127.0.0.1:8000' : '';
      if (devFallback && base_url !== devFallback) {
        try {
          const { Authorization: _auth, ...fallbackHeaders } = headers;
          const fallbackRes = await fetch(`${devFallback}${path}`, {
            ...options,
            headers: fallbackHeaders,
          });
          if (fallbackRes.ok) {
            cachedConfig = { base_url: devFallback, token: '' };
            const fallbackPayload = await fallbackRes.json().catch(() => ({}));
            appendClientLog('INFO', `API ${method}`, `${devFallback}${path} → 200 OK (dev fallback)`);
            return fallbackPayload as T;
          }
        } catch {
          // fallback also unavailable
        }
      }

      if (!path.startsWith('/api/logs')) {
        appendClientLog('ERROR', `API ${method}`, `${base_url}${path} (${ms} ms): ${msg}`);
      }
    }
    throw err;
  }
}

export const lottoApi = {
  health: () => apiRequest<{ status: string; app: string; php_version: string }>('/api/health'),
  games: () => apiRequest<{ games: Record<string, GameConfig> }>('/api/games'),
  generate: (body: Record<string, any>) =>
    apiRequest<GenerateResponse>('/api/generate', {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  stats: (body: Record<string, any>) =>
    apiRequest<StatsResponse>('/api/stats', {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  runAgent: (body: Record<string, any>) =>
    apiRequest<AgentRunResponse>('/api/agent/run', {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  archiveStatus: (game?: string) =>
    apiRequest<{ archives: Record<string, ArchiveFreshness> }>(
      game ? `/api/archive/status?game=${encodeURIComponent(game)}` : '/api/archive/status'
    ),
  archiveSync: (body: { game: string; sessions: number; mode: string }) =>
    apiRequest<{
      game: string;
      mode: string;
      result: Record<string, any>;
      freshness: ArchiveFreshness | Record<string, ArchiveFreshness>;
    }>('/api/archive/sync', {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  getSettings: () => apiRequest<SettingsResponse>('/api/settings'),
  saveSettings: (body: Record<string, any>) =>
    apiRequest<SettingsResponse>('/api/settings', {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  getLogs: (limit = 300, level = '') =>
    apiRequest<LogsResponse>(
      `/api/logs?limit=${limit}${level ? `&level=${encodeURIComponent(level)}` : ''}`
    ),
  clearLogs: () =>
    apiRequest<{ cleared: number }>('/api/logs', {
      method: 'DELETE',
    }),
};
