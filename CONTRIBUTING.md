# Contributing to Lotto Agent

Thanks for taking an interest in the project. Contributions of all sizes are welcome —
bug reports, documentation fixes, new generator modes, and test coverage alike.

## Ground rules

This project is a **mathematical and AI engineering experiment**, not a system for beating
the lottery. Contributions that claim, imply, or advertise improved winning odds will not be
merged. See the [disclaimer](README.md#-disclaimer--responsible-play) for the reasoning.

## Local setup

```bash
git clone https://github.com/yaotzin1/lotto-agent.git
cd lotto-agent

cp .env.dev.example .env.dev   # then fill in GEMINI_API_KEY and LOTTO_API_KEY
docker compose build
docker compose run --rm app composer install
```

Never commit `.env`, `.env.dev`, or any file containing a real API key — they are gitignored
for a reason.

## Running the test suite

```bash
docker compose run --rm app vendor/bin/phpunit
```

Please add or update tests for any change to the generator, optimizer, or statistics layer.
These parts of the codebase are pure functions over draw data and are cheap to test.

## Coding conventions

- PHP 8.5, Symfony 8.2, PSR-4 autoloading under `App\` → `src/`.
- Follow the existing structure: commands in `src/Command`, business logic in `src/Service`,
  agent tools in `src/Service/AgentTools` (implementing `LottoToolInterface`).
- Respect `.editorconfig` for whitespace and line endings.
- Keep new code consistent with the surrounding file's naming and comment density.

## Pull requests

1. Branch off `main`.
2. Keep each PR focused on a single concern.
3. Make sure `vendor/bin/phpunit` passes before opening the PR.
4. Describe *what* changed and *why* — especially for anything touching the statistical model,
   where the reasoning matters more than the diff.

## Reporting bugs

Open an issue using the bug report template. Include the exact command you ran, the game and
mode, and the output you got. If the AI agent is involved, note that Gemini responses are
non-deterministic and include the run's log output where possible.
