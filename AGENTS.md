# AGENTS.md

Instructions for AI coding agents working in `lotto-agent`.

## Overview

`lotto-agent` is a **PHP 8.5 + Symfony 8.2** CLI & TUI lottery system generator, statistical optimizer, and ReAct AI agent.
See [agent.md](agent.md) for the AI ReAct contract, draw archive mechanics, and LOTTO OpenAPI constraints.

The desktop initiative (orchestrated by [workflow.ai.yml](workflow.ai.yml)) wraps the existing PHP/Symfony core in a **Tauri v2** desktop shell (`desktop/`) with a bundled `static-php-cli` PHP 8.5 sidecar and automated GitHub Releases (`.github/workflows/release.yml`).

## Repository Layout

- `src/Command/` — Symfony console commands (`app:lotto-agent`, `app:lotto-generator`, `app:lotto-stats`, `app:lotto-tui`, `app:lotto-stride`, `app:lotto-archive`, `app:lotto-backtest`, `app:ai-models`)
- `src/Service/` — Core mathematical, statistical, archive, and LLM services (`BetPipelineService`, `StatisticalOptimizerService`, `GameRegistryService`, `DrawHistoryProvider`, `DrawArchiveService`, `ReActAgentService`, `Llm/*`, `AgentTools/*`)
- `src/Controller/` — Localhost JSON HTTP bridge for the desktop UI (authenticated with `LOTTO_DESKTOP_TOKEN`)
- `config/` — Symfony configuration and routing
- `tests/` — PHPUnit 11 unit and mutation test suites (`tests/Unit/Service/`, `tests/Unit/Mutation/MutationTestingSuiteTest.php`)
- `data/` — Local draw archives (`data/lotto_draws.json` is tracked; `data/draws/` is generated/gitignored)
- `docs/` — Documentation and desktop specification (`docs/desktop-spec.md`, `docs/desktop-release.md`)
- `desktop/` — Tauri v2 desktop app (Vite + TypeScript frontend in `desktop/src/`, Rust sidecar host in `desktop/src-tauri/`)
- `.github/workflows/` — CI (`ci.yml`) and multi-OS desktop release (`release.yml`)

## Commands & Quality Gates

Every PHP change must pass the same gates as `.github/workflows/ci.yml`:

```bash
php bin/console lint:yaml config
php bin/console lint:container
vendor/bin/phpunit tests/Unit/Service
vendor/bin/phpunit tests/Unit/Mutation/MutationTestingSuiteTest.php
```

For Indaba workflow validation:

```bash
npx indaba@next validate workflow.ai.yml
npx indaba@next plan workflow.ai.yml
```

## Rules & Boundaries

1. **Preserve existing CLI & TUI behavior.** Do not break `app:lotto-*` commands, their options, or the strict JSON contract documented in `agent.md`.
2. **Never hardcode API keys or secrets.** Keys (`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `DEEPSEEK_API_KEY`, `LOTTO_API_KEY`, `APP_SECRET`) come from environment variables or the OS app-data config directory.
3. **Desktop read-only bundle safety.** Packaged Tauri apps install into read-only locations; user `.env` overrides and mutable `data/draws/` / `var/` caches must resolve to the OS app-data directory when running in desktop mode.
4. **Localhost bridge security.** Bind only to `127.0.0.1` on an ephemeral port and require `Authorization: Bearer <LOTTO_DESKTOP_TOKEN>` on API endpoints when `LOTTO_DESKTOP_TOKEN` is set.
5. **Respect step permissions.** When running inside `workflow.ai.yml`, only modify files permitted by your step's `permissions.fs.write` scope.

## What "Done" Means

- Specification and code match (`docs/desktop-spec.md`).
- All Symfony lint checks and PHPUnit suites (including `MutationTestingSuiteTest.php`) pass with zero failures.
- No secrets, `vendor/`, `node_modules/`, `target/`, or `.indaba/` artifacts are committed.
