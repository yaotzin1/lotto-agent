# Lotto Agent Desktop — Local Development & Release Guide

## Overview

The desktop application wraps the existing **PHP 8.5 + Symfony 8.2** `lotto-agent` core in a **Tauri v2** shell (`desktop/`) communicating over a local authenticated HTTP bridge (`127.0.0.1:<ephemeral-port>` with `Authorization: Bearer <LOTTO_DESKTOP_TOKEN>`).

See [docs/desktop-spec.md](desktop-spec.md) for the full architecture specification.

---

## Local Development Setup

### Prerequisites
- **PHP >= 8.5** with `ctype`, `iconv`, `zip`, `curl`, `openssl`, `mbstring`
- **Composer 2.x**
- **Node.js >= 20** & `npm`
- **Rust stable toolchain** (`rustup`) and Tauri v2 OS prerequisites

### 1. Install PHP & Frontend Dependencies

```bash
composer install
cd desktop
npm install
```

### 2. Place or Symlink a Local PHP 8.5 Sidecar Binary

Tauri v2 resolves `externalBin: ["binaries/php"]` using your host's Rust target triple (`rustc -vV | grep host`):

- **Windows (`x86_64-pc-windows-msvc`)**:
  `desktop/src-tauri/binaries/php-x86_64-pc-windows-msvc.exe`
- **macOS Apple Silicon (`aarch64-apple-darwin`)**:
  `desktop/src-tauri/binaries/php-aarch64-apple-darwin`
- **macOS Intel (`x86_64-apple-darwin`)**:
  `desktop/src-tauri/binaries/php-x86_64-apple-darwin`
- **Linux (`x86_64-unknown-linux-gnu`)**:
  `desktop/src-tauri/binaries/php-x86_64-unknown-linux-gnu`

In development mode (`npm run tauri dev`), if no bundled sidecar binary is present in `desktop/src-tauri/binaries/`, the Rust host automatically falls back to `php` on your system `PATH`.

### 3. Run in Development Mode

```bash
cd desktop
npm run tauri dev
```

Or run the Symfony HTTP bridge and Vite dev server separately:

```bash
# Terminal 1 (from repo root):
php -S 127.0.0.1:8000 -t public public/index.php

# Terminal 2 (from desktop/):
npm run dev
```

---

## App-Data Storage & Security

When launched via Tauri:
1. Tauri picks a free ephemeral port on `127.0.0.1` and generates a random 32-byte hex `LOTTO_DESKTOP_TOKEN`.
2. User API keys (`LOTTO_API_KEY`, `GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `DEEPSEEK_API_KEY`) are stored in the OS App-Data directory (`<app_data_dir>/.env.desktop`), never inside the read-only application bundle.
3. Mutable draw caches (`data/draws/*.json`, `var/draw-history/*.json`) and Symfony `var/cache` + `var/log` directories also resolve inside `<app_data_dir>`.

---

## Cutting a GitHub Release

Releases are built and attached automatically by [`.github/workflows/release.yml`](../.github/workflows/release.yml) whenever a `v*` tag is pushed:

```bash
git tag v1.0.0
git push origin v1.0.0
```

The workflow builds installers for:
- **Windows**: `.msi` / NSIS `.exe` (`x86_64-pc-windows-msvc`)
- **macOS**: `.dmg` / `.app` (`aarch64-apple-darwin` and `x86_64-apple-darwin`)
- **Linux**: `.deb` / `.AppImage` (`x86_64-unknown-linux-gnu`)

> **Windows SmartScreen Note:** Releases are currently unsigned. When running the Windows installer for the first time, click **More info** (*Więcej informacji*) → **Run anyway** (*Uruchom mimo to*).
