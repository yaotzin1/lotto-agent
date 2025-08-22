# Lotto Simulator (Symfony + FrankenPHP + Encore + React TS + MUI)

This repository contains a minimal Symfony 7 application packaged for Docker using FrankenPHP, with Webpack Encore bundling a React + TypeScript + Material-UI frontend. It implements two core features from the provided PHP scripts:

- Generator (v4.3) — implemented as a Symfony console command: `bin/console app:generate`
- Verifier (v2.1) — implemented as a Symfony console command: `bin/console app:verify <file> <numbers...>`

## Prerequisites
- Docker and Docker Compose

## Run (development)

```bash
docker-compose up --build
```

This starts:
- app: FrankenPHP serving Symfony at http://localhost:8080
- node: Node 20 watcher running Encore dev build

Open http://localhost:8080 — the React page is minimal and loads via Encore.

## Commands

Inside the container (app service):

```bash
docker compose exec app bash
composer install
php bin/console app:generate
php bin/console app:verify system_Mini_Lotto_12_liczb_YYYY-MM-DD.txt 5 12 18 23 40
```

Notes:
- The generator mirrors the original script logic, including Multi Multi fallback and optional filters.
- The verifier detects game type from file names (including DOWOLNY_XzY) and computes hits, plus handling, and ROI prompts.

## Frontend
- React + TypeScript + MUI scaffolded in `assets/` and built to `public/build/` via Encore.
- Adjust or extend the UI to call backend endpoints if needed (currently minimal page).

## Configuration
- PHP GMP extension is installed in the FrankenPHP image (required for binomial computations).
- Twig and Webpack Encore bundles are enabled.

## License
MIT
