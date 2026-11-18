# Lotto Agent Desktop Specification

## 1. Goal & Architecture

Wrap the existing **PHP 8.5 + Symfony 8.2** `lotto-agent` application ([composer.json](../composer.json)) as a cross-platform desktop application using **Tauri v2** (`desktop/`) and a bundled **PHP 8.5 CLI sidecar** (`static-php-cli`), without rewriting the mathematical, statistical, or ReAct AI core in `src/Service/`.

```text
+-------------------------------------------------------+
| Tauri v2 Window (Vite + TypeScript UI in desktop/src) |
+---------------------------+---------------------------+
                            | HTTP (127.0.0.1:<port>)
                            | Authorization: Bearer <token>
+---------------------------v---------------------------+
| Bundled PHP 8.5 Sidecar (php -S 127.0.0.1:<port>)     |
| -> Symfony Kernel (public/index.php + src/Controller) |
| -> Existing Services (src/Service/*)                  |
+---------------------------+---------------------------+
                            |
+---------------------------v---------------------------+
| OS App-Data Directory (%APPDATA% / ~/.config / ~/Lib) |
| -> .env.desktop (API keys)                            |
| -> data/draws/ (mutable draw cache)                   |
| -> var/ (Symfony cache & logs)                        |
+-------------------------------------------------------+
```

Existing CLI and TUI commands (`app:lotto-agent`, `app:lotto-generator`, `app:lotto-stats`, `app:lotto-tui`, `app:lotto-stride`, `app:lotto-archive`, `app:lotto-backtest`) remain unchanged.

---

## 2. Symfony JSON HTTP Bridge (`src/Controller/`)

All desktop endpoints live under `/api/*` in `src/Controller/` and reuse existing services:

| Endpoint | Method | Backing Service(s) | Purpose |
| :--- | :--- | :--- | :--- |
| `/api/health` | `GET` | — | Readiness probe for Tauri startup |
| `/api/games` | `GET` | `GameRegistryService` | List supported games, ranges, and draw sizes |
| `/api/generate` | `POST` | `BetPipelineService`, `BetPipelineRequest` | Run mathematical wheeling / generation modes |
| `/api/stats` | `POST` | `StatisticalOptimizerService`, `DrawHistoryProvider` | Run dilution optimizer, Gaussian stats, & optional AI commentary |
| `/api/agent/run` | `POST` | `ReActAgentService`, `LlmClientResolver` | Run ReAct agent pool selection (returns strict JSON `selected_pool`) |
| `/api/archive/status` | `GET` | `DrawArchiveService` | Check local archive freshness per game |
| `/api/archive/sync` | `POST` | `DrawArchiveService`, `LottoApiClient` | Sequentially fetch missing draws from LOTTO OpenAPI |
| `/api/settings` | `GET`, `POST` | App-data `.env.desktop` | Read/update masked API keys & default `AI_PROVIDER` / `AI_MODEL` |

### Validation & Error Format
- Invalid JSON or missing/invalid parameters return `400 Bad Request` with `{ "error": "<message>" }`.
- Unhandled service errors return `500` with `{ "error": "<message>" }` without leaking stack traces or API keys.

### Localhost Security
- Tauri generates a cryptographically random `LOTTO_DESKTOP_TOKEN` per launch and passes it to both the PHP sidecar environment and the frontend (via a Tauri command).
- When `LOTTO_DESKTOP_TOKEN` is set, a Symfony request listener rejects any `/api/*` request missing `Authorization: Bearer <LOTTO_DESKTOP_TOKEN>` with `401 Unauthorized`.
- The PHP sidecar binds strictly to `127.0.0.1` on an OS-assigned ephemeral port.

---

## 3. Runtime Storage & Bundled PHP Requirements

### Required PHP 8.5 Extensions
The bundled `static-php-cli` (`spc`) binary must include:
- `ctype`, `iconv`, `zip` (required by [composer.json](../composer.json) and [Dockerfile](../Dockerfile))
- `curl`, `openssl`, `mbstring` (required by `symfony/http-client` for LLM and LOTTO OpenAPI calls)

### Writable Paths Outside the App Bundle
Packaged `.msi`, `.dmg`, and `.AppImage` installs are read-only. Tauri resolves the OS app-data directory (`lotto-agent`) and passes:
- `LOTTO_APP_DATA_DIR` — base writable directory
- `LOTTO_ENV_FILE` — path to user-editable `.env.desktop` (API keys)
- `APP_CACHE_DIR` / `APP_LOG_DIR` — writable `var/` subdirectories

Read-only seed data (`data/lotto_draws.json`) stays in the bundle; incremental updates (`data/draws/*.json`, `var/draw-history/*.json`) read from and write to `LOTTO_APP_DATA_DIR`.

---

## 4. Tauri v2 Shell (`desktop/`)

- **Frontend (`desktop/src/`)**: Vite + TypeScript UI with tabs for:
  1. **Generator & Stats**: Game selector, number pool picker, mode selector, coupon output, Gaussian sum & coverage summary.
  2. **AI ReAct Agent**: Prompt/objective input, provider/model selector, tool trace & `selected_pool` result.
  3. **Archive & Settings**: Archive sync status and API key management (`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `DEEPSEEK_API_KEY`, `LOTTO_API_KEY`).
- **Rust Host (`desktop/src-tauri/`)**:
  - Picks a free `127.0.0.1` port, generates `LOTTO_DESKTOP_TOKEN`, spawns the PHP sidecar (`php -S 127.0.0.1:<port> -t <resource_dir>/public`), polls `/api/health`, and terminates the child process on window close (`RunEvent::Exit`).

---

## 5. GitHub Releases (`.github/workflows/release.yml`)

- Triggered on git tags `v*`.
- Matrix: `windows-latest`, `macos-latest` (`aarch64` / `x86_64`), `ubuntu-latest`.
- Steps per OS:
  1. Install PHP 8.5 & run `composer install --no-dev --optimize-autoloader`.
  2. Download/cache `static-php-cli` PHP 8.5 binary into `desktop/src-tauri/binaries/php-<target-triple>[.exe]`.
  3. Run `tauri-apps/tauri-action` to build installers and attach them to the GitHub Release.
