# Lotto Agent AI & Statistical System Generator

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.2-000000.svg?logo=symfony&logoColor=white)](https://symfony.com/)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-Support-FF5E5B.svg?logo=ko-fi&logoColor=white)](https://ko-fi.com/yaotzin1)
<!-- Re-add once GitHub Sponsors is enrolled:
[![Sponsor](https://img.shields.io/badge/GitHub-Sponsor-EA4AAA.svg?logo=githubsponsors&logoColor=white)](https://github.com/sponsors/yaotzin1)
-->

A Symfony 8 CLI & TUI application containerized with Docker, designed as an AI lotto agent utilizing multi-provider LLMs (Google Gemini, Anthropic Claude, OpenAI, DeepSeek), ReAct Agent tooling, and advanced combinatorial & statistical optimization engines.

## Key Features

1. **ReAct Agent AI (`app:lotto-agent`)**: Autonomous multi-turn AI agent that queries LOTTO statistics, evaluates co-occurrences, k-clique clusters, and builds high-potential number pools using your preferred AI model (Gemini, Claude Opus/Sonnet, GPT-4o/5, DeepSeek-V3/R1).
2. **Mathematical Generator Suite (`app:lotto-generator`)**: 8 advanced mathematical wheeling & reduction modes (Fractal rolling overlap, Smart Croupier, Floating Bankers, Weighted, Split Bankers, and Statistical Optimizer).
3. **Interactive TUI Suite (`app:lotto-tui`)**: Terminal User Interface with interactive menus, progress indicators, and coverage matrix visualization.
4. **Statistical Dashboard & Dilution Optimizer (`app:lotto-stats` / Mode 7)**:
   - Solves the problem of **heavy dilution (rozwodnienie)** when picking large pools (e.g. 49 numbers into 100 bets or 30 numbers into 25 bets).
   - Assembles combinations around highest historical pair/triplet affinities.
   - Enforces macro-statistical plausibility: Gaussian sum bell curve ($106-194$ for 6/49, i.e. $\mu \pm 1.35\sigma$ with $\sigma = 32.79$), parity balance ($3:3, 4:2, 2:4$), and decade spread.
   - Dynamic marginal decay (anti-cannibalization) ensures even pool coverage while maximizing internal coupon synergy.
   - Generates interactive **Okno Statystyczne** (ASCII Gaussian distribution chart, co-occurrence matrix, dilution metrics, and Monte Carlo quality benchmark).

---

## Logical & Mathematical Rationale (Reality Check)

In an unbiased lottery ($6$ out of $49$), every single combination of 6 numbers has an identical probability:
$$P = \frac{1}{\binom{49}{6}} = \frac{1}{13\,983\,816} \approx 7.15 \times 10^{-8}$$

### Why use Statistical Optimization under Heavy Dilution?
When selecting a large pool (e.g. all 49 numbers) and generating a limited budget of bets (e.g. 100 bets = $0.000715\%$ of total space):
* **Random selection / naive reduction** generates arbitrary combinations with strange sum distributions (e.g. sum $< 90$ or $> 220$), poor parity, and random pairs that historically had near-zero synergy.
* **The Statistical Optimizer operates on two levels:**
  1. **Macro-Plausibility (Stochastic Filtering)**: Roughly $82\%$ of the probability mass of the sum distribution lies within $\mu \pm 1.35\sigma$ ($106-194$ in Lotto). The interval is computed by `calculateGaussianParameters()` and is never hardcoded, have balanced odd/even splits ($3:3$ or $4:2$), and span across at least $3$ decades. The optimizer ensures $100\%$ of generated bets adhere to these proven macro-characteristics.
  2. **Micro-Synergy (Historical Co-occurrence & Affinity)**: numbers are assembled using a pair matrix built from **actual draw history** - how often $A$ and $B$ were genuinely drawn together - fetched via `LottoApiClient::fetchDrawHistory()`. When fewer than 20 draws are available the matrix falls back to a frequency heuristic that carries **no pair information at all**, and every report states which of the two modes produced it (`affinity_source`). Dynamic usage penalties ensure the entire 49-number pool is evenly represented without sacrificing pair synergy.

---

## Requirements
- Docker
- Docker Compose

## Setup and Installation

1. **Environment Setup:**
   Copy the example file and fill in your own API keys. Both `.env` and `.env.dev` are
   gitignored, so your keys never leave your machine.
   ```bash
   cp .env.dev.example .env.dev
   ```

   | Variable | Required | Purpose |
   |---|---|---|
   | `GEMINI_API_KEY` | for Gemini | Google Gemini access ([get one](https://aistudio.google.com/app/apikey)) |
   | `ANTHROPIC_API_KEY`| for Claude | Anthropic Claude access (Claude 3.7 Sonnet, Claude Opus) |
   | `OPENAI_API_KEY`   | for OpenAI | OpenAI GPT models (GPT-4o, GPT-5) |
   | `DEEPSEEK_API_KEY` | for DeepSeek| DeepSeek-V3 / DeepSeek-R1 access |
   | `AI_PROVIDER`      | optional | Default AI provider (`gemini`, `claude`, `openai`, `deepseek`) |
   | `AI_MODEL`         | optional | Default model override (e.g. `claude-3-opus-20240229`, `gpt-4o`) |
   | `LOTTO_API_KEY`    | for live draw history | Draw-history API access. Without it the optimizer falls back to a frequency heuristic that carries no pair information. |
   | `APP_SECRET`       | yes | Standard Symfony secret; any random string works locally. |

   The purely mathematical modes (`app:lotto-generator`, `app:lotto-stats`) run without an
   AI key — only the AI agent (`app:lotto-agent`) and AI strategic commentary (`app:lotto-stats --ai`) require model credentials.

2. **Build the Docker Container:**
   ```bash
   docker compose build
   ```

3. **Install Dependencies (Composer):**
   ```bash
   docker compose run --rm app composer install
   ```

---

## Usage

### 1. Statistical Dashboard & Dilution Optimizer (`app:lotto-stats`)
Open the interactive statistical window and optimizer for large pools:
```bash
# Full 49 numbers pool for Lotto (100 bets):
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100

# Custom 30 numbers pool (25 bets):
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool="1,2,5,7,11,14,15,18,21,22,25,28,30,31,33,35,37,38,40,41,42,43,44,45,46,47,48,49" --bets=25

# Output as JSON:
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --json-output

# With AI strategic commentary using default provider (Gemini):
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --ai

# With Claude 3 Opus (top quantitative data analysis model):
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --ai --provider=claude --model=claude-3-opus-20240229

# With DeepSeek-R1 (dedicated mathematical reasoning & verification):
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --ai --provider=deepseek --model=deepseek-reasoner
```

### 2. Interactive Terminal UI (`app:lotto-tui`)
```bash
# Launch interactive TUI (supports AI, Stride, and Manual pool selection):
docker compose run --rm app php bin/console app:lotto-tui

# Launch TUI pre-configured with Claude or OpenAI:
docker compose run --rm app php bin/console app:lotto-tui --provider=claude --model=claude-3-7-sonnet-20250219
docker compose run --rm app php bin/console app:lotto-tui --provider=openai --model=gpt-4o
```

### 3. Tiered Neighbour Cascade Generator (`app:lotto-generator --mode=9`)
Dekompozycja pełnego bębna (np. 49 liczb w Lotto, 42 w Mini Lotto) na 3 pule siły (Tier 1: Kotwice + Sąsiedzi $\pm 1$, Tier 2: Bufor $\pm 2$ & Synergia, Tier 3: Zero-Drop) z rankingiem zakładów od najsilniejszego do najsłabszego:
```bash
# Lotto: 25 zakładów z całego bębna 49 liczb w kaskadzie sąsiadów:
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --pool=all --mode=9 --bets=25

# Z ręcznie zdefiniowaną bazą kotwic ostatniego losowania:
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --pool=all --mode=9 --bets=25 --latest-draw="2,3,19,23,42,49"
```

### 4. Stroboscopic / Stride Sampler (`app:lotto-stride`)
Generates bets using historical draws spaced by a fixed stride $N$ (e.g. 257 or 127) and their $\pm 1$ neighbours:
```bash
# Stride 257, 12 numbers pool, 6 bets with Mode 8 Zero-Drop Full Coverage:
docker compose run --rm app php bin/console app:lotto-stride --stride=257 --pool-size=12 --bets=6

# Stride 127 with multi-anchor history (T-127, T-254):
docker compose run --rm app php bin/console app:lotto-stride --stride=127 --strategy=multi_anchor --bets=10

# Any registered game - the pool is built from THAT game's draws and number range:
docker compose run --rm app php bin/console app:lotto-stride --game=EuroJackpot --stride=50 --pool-size=14

# Skip the LOTTO OpenAPI top-up and use the on-disk archive as-is:
docker compose run --rm app php bin/console app:lotto-stride --stride=257 --no-refresh
```

**Where the draws come from.** Stride addresses draws by *position* (T-N, T-2N...), so it needs an
unbroken chronological index. The official API has no date-range results endpoint - only `by-date-per-game`
(one date, one game) and `by-date` (one date, every game) - so history is built one request per date and
cached. `DrawArchiveService` keeps a per-game archive on disk, projects the API cache
(`var/draw-history/<Game>.json`) onto it on every run and appends anything new. The archive lives in
`data/lotto_draws.json` for Lotto and `data/draws/<Game>.json` for every other game.

Because a missing draw shifts *every* anchor by the same amount, the command reports how many draws
the archive is behind the draw calendar and warns instead of silently sampling the wrong rows.

### 5. Draw Archive Builder (`app:lotto-archive`)
The generator only tops up the last few dates so it never keeps you waiting. Building history hundreds
of draws deep - which is what a stride of 127 or 257 actually needs - is this command's job:
```bash
# Show what each archive holds, without touching the network:
docker compose run --rm app php bin/console app:lotto-archive --status --all-games

# Build ~300 days of Mini Lotto history (one request per date, resumable):
docker compose run --rm app php bin/console app:lotto-archive --game=MiniLotto --days=300

# Fill several games at once - `by-date` returns every game, so it is one request per DAY, not per game:
docker compose run --rm app php bin/console app:lotto-archive --all-games --days=400
docker compose run --rm app php bin/console app:lotto-archive --all-games --games=Lotto,MiniLotto --days=400
```

Verified against the live API for every registered game: Mini Lotto, Lotto, Lotto Plus, EuroJackpot,
Multi Multi, Ekstra Pensja, Ekstra Premia, Kaskada, Keno and Szybkie 600 all return draws whose count
and number range match the registry. Zaklady Specjalne is a valid game type but has not been drawn
since 2024-10-05, so a recent window finds nothing for it.

```bash
# Refetch dates that were stored incomplete (see below):
docker compose run --rm app php bin/console app:lotto-archive --game=Keno --days=35 --repair
```

**Games with many draws per day.** Keno and Szybkie 600 run about 261 draws *per day*, not one.
Requests used to ask for 50 results per date, so 211 of those 261 were dropped and the date was then
marked as fetched and never revisited. The page size now covers a full day, the archive treats the API
as authoritative for any date it knows, and `--repair` refetches dates that were stored short.

The API's rate limit applies to **concurrency, not volume**: measured against the live API, 80 sequential
requests pass in 66 s without a single HTTP 429, while 8 parallel ones are throttled at the eighth.
Fetching is therefore sequential, and a 429 means back off and retry that date rather than abandon the
run. Every fetched date is cached, so an interrupted backfill simply resumes where it stopped.

### 6. Historical Stride Backtester (`app:lotto-backtest`)
Empirical backtesting of stride sampling across the full archive of a chosen game:
```bash
docker compose run --rm app php bin/console app:lotto-backtest --pool-size=12 --strides="1,2,7,30,50,127,257,500"

# Other games use their own number range and drawn-count for the hypergeometric baseline:
docker compose run --rm app php bin/console app:lotto-backtest --game=EuroJackpot --pool-size=12 --strides="1,50"
```

### 7. Generator Suite (`app:lotto-generator`)
```bash
# Run generator with Statistical Optimization mode (Mode 7):
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --mode=7 --bets=100

# Run with AI candidate pool and Fractal rolling overlap (Mode 5) using default AI provider:
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --strategy=syndicate --mode=5

# Run with AI candidate pool using Claude 3.7 Sonnet:
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --provider=claude --model=claude-3-7-sonnet-20250219 --mode=5

# Run with Decade-Balanced pool prioritizing ±1 neighbours of the latest draw (Mode 8):
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=Decades --with-neighbours --pool-size=15 --mode=8 --bets=15
```

### 8. ReAct Agent AI (`app:lotto-agent`)
Autonomous multi-turn AI agent that queries LOTTO statistics, evaluates co-occurrences, k-clique clusters, and builds high-potential number pools using Function Calling across your chosen provider:
```bash
# Default provider (configured in .env or Google Gemini 3.7 Flash):
docker compose run --rm app php bin/console app:lotto-agent --game=Lotto --strategy=syndicate --sessions=15

# Using Anthropic Claude 3 Opus (Top model for quantitative & statistical reasoning):
docker compose run --rm app php bin/console app:lotto-agent --provider=claude --model=claude-3-opus-20240229 --game=Lotto --strategy=syndicate

# Using Anthropic Claude 3.7 Sonnet (Hybrid reasoning, supreme tool precision):
docker compose run --rm app php bin/console app:lotto-agent --provider=claude --model=claude-3-7-sonnet-20250219 --game=Lotto

# Using OpenAI GPT-4o:
docker compose run --rm app php bin/console app:lotto-agent --provider=openai --model=gpt-4o --game=Lotto

# Using DeepSeek-R1 (Specialized chain-of-thought mathematical reasoning):
docker compose run --rm app php bin/console app:lotto-agent --provider=deepseek --model=deepseek-reasoner --game=Lotto

# Using short CLI flag aliases (-pv for --provider, -md for --model):
docker compose run --rm app php bin/console app:lotto-agent -pv claude -md claude-3-opus-20240229
```

### 9. Multi-Provider AI & Model Catalog (`app:ai-models`)
Inspect supported models, architectures, recommended statistical roles, and fallback chains:
```bash
# View all supported models across all providers:
docker compose run --rm app php bin/console app:ai-models

# Filter models for a specific provider:
docker compose run --rm app php bin/console app:ai-models --provider=claude
docker compose run --rm app php bin/console app:ai-models --provider=openai
docker compose run --rm app php bin/console app:ai-models --provider=deepseek
docker compose run --rm app php bin/console app:ai-models --provider=gemini

# Query live remote Gemini API for available models on your account:
docker compose run --rm app php bin/console app:ai-models --provider=gemini --live
```

#### Supported AI Providers & Models Overview

| Provider (`--provider`) | Model ID (`--model`) | Name | Description & Architecture | Recommended Role in Statistics |
|---|---|---|---|---|
| **`claude`** (Anthropic) | `claude-3-opus-20240229` | **Claude 3 Opus** | Deep analytical reasoning & complex mathematical formulation | **Top quantitative analysis & risk synthesis** |
| | `claude-3-7-sonnet-20250219` *(default)* | **Claude 3.7 Sonnet** | Hybrid reasoning with leading function calling & code precision | Best balance of statistical rigor and speed |
| | `claude-3-5-sonnet-20241022` | **Claude 3.5 Sonnet v2** | Structured coding & deterministic JSON execution | Reliable JSON outputs & verification |
| | `claude-3-5-haiku-20241022` | **Claude 3.5 Haiku** | Lightweight ultra-fast reasoning model | Quick single-turn commentary |
| **`openai`** (OpenAI) | `gpt-4o` *(default)* | **GPT-4o** | Flagship multimodal model with native JSON and function calling | All-around lottery strategy & balance evaluation |
| | `o3-mini` | **OpenAI o3-mini** | Specialized chain-of-thought STEM reasoning model | Complex logic and combinatorial calculations |
| | `gpt-4o-mini` | **GPT-4o Mini** | High speed, lightweight model | Low latency batch analyses |
| | `gpt-4-turbo` | **GPT-4 Turbo** | High context accuracy fallback | Historical stability |
| **`deepseek`** (DeepSeek) | `deepseek-chat` *(default)* | **DeepSeek-V3** | Open-weight state-of-the-art model for logic and text | Budget-friendly high-performance data processing |
| | `deepseek-reasoner` | **DeepSeek-R1** | Dedicated mathematical reasoning and verification model | Rigorous probability evaluation & trend hypothesis |
| **`gemini`** (Google) | `gemini-3.7-flash` *(default)* | **Gemini 3.7 Flash** | Fast, high-reasoning multimodal engine with function calling | Default daily usage & ReAct agent loops |
| | `gemini-2.5-pro` | **Gemini 2.5 Pro** | High-capacity analytical reasoning | Deep combinatorial analysis & large pools |
| | `gemini-3.8-flash` | **Gemini 3.8 Flash** | High-throughput next-generation Flash model | Fastest live tool executions |
| | `gemini-2.5-flash` | **Gemini 2.5 Flash** | Stable production flash model | Cost-effective analysis |

> **Automated Fallback Mechanism**: If a provider API endpoint returns a 404 (model deprecated or not available in region) or 429 (rate-limit quota exceeded), the client automatically cascades down the provider's fallback chain to ensure uninterrupted execution.

---

## Generator Modes Summary

| Mode | Name | Description | Best For |
|---|---|---|---|
| **[1]** | **RĘCZNY** | Balanced shorthand reduction on selected pool | General reduction (small pools) |
| **[2]** | **KREATOR BLOKÓW** | Smart Croupier backtracking block generator | Equal distribution of numbers |
| **[3]** | **GENERATOR WAŻONY** | Weighted probabilistic urn selector | Highlighting hot trends |
| **[4]** | **SYSTEM HYBRYDOWY** | Fixed Bankers + variable pool reduction | High confidence core numbers |
| **[5]** | **SYSTEM FRAKTALNY** | Multi-level rolling overlap with geometric interleaving | Syndicates & cluster cascades |
| **[6]** | **SYSTEM ROZDZIELNY** | Rotational floating bankers with variable subsets | Multi-win leverage effect |
| **[7]** | **OPTYMALIZACJA STATYSTYCZNA** | Affinity & Co-occurrence Optimizer with Gaussian filtering | Heavy dilution (Hot numbers concentration) |
| **[8]** | **RANKINGOWE PEŁNE POKRYCIE** | Zero-Drop Guarantee (100% pool coverage) + Pair Affinity + Ranked Output | Full pool partitioning (e.g. 42 nos in 15 bets) |
| **[9]** | **KASKADA SĄSIADÓW** | Tiered Neighbour Cascade (Tier 1: Anchors $\pm 1$, Tier 2: Buffer $\pm 2$, Tier 3: Zero-Drop) | Full drum decomposition (49 or 42 numbers) with ranked bets |

👉 **Szczegółowy podręcznik gracza i komendy dla każdego trybu znajdziesz w: [docs/HOW_TO_PLAY.md](docs/HOW_TO_PLAY.md)**

---

## Testing

Run unit tests via PHPUnit:
```bash
docker compose run --rm app vendor/bin/phpunit
```

---

## ⚠️ Disclaimer & Responsible Play

**This project does not improve your chances of winning the lottery. Nothing can.**

In a fair draw every combination is equally likely, and each draw is independent of every
draw before it. A number being "hot", "overdue", or historically paired with another carries
**no predictive power** — that belief is the [gambler's fallacy](https://en.wikipedia.org/wiki/Gambler%27s_fallacy).
The historical co-occurrence matrices in this codebase describe the past; they do not forecast
the future.

What the software actually does is **combinatorial coverage optimization**: given a pool of
numbers and a fixed budget of bets, it distributes those bets so the pool is covered evenly,
sums fall in a plausible range, and parity and decade spread stay balanced. That changes the
*shape* of your coverage and the distribution of possible payouts — it does not change the
expected value, which remains negative for every lottery.

Treat this repository as what it is: an exercise in Symfony architecture, combinatorial
mathematics, and LLM agent tooling. Play only with money you can afford to lose, and if
gambling has stopped being entertainment, seek help — in Poland,
[Uzależnienia behawioralne](https://www.uzaleznieniabehawioralne.pl/) and the helpline
**801 889 880**; elsewhere, your national problem-gambling service.

---

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, testing,
and coding conventions.

---

## Support the Project

This is a spare-time project maintained for free. If it is useful to you, or you simply enjoy
reading the mathematics behind it, you can support its development:

[![Ko-fi](https://img.shields.io/badge/Ko--fi-Buy%20me%20a%20coffee-FF5E5B?style=for-the-badge&logo=ko-fi&logoColor=white)](https://ko-fi.com/yaotzin1)
<!-- Re-add once GitHub Sponsors is enrolled:
[![GitHub Sponsors](https://img.shields.io/badge/GitHub-Sponsor-EA4AAA?style=for-the-badge&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/yaotzin1)
-->

Starring the repository and reporting bugs helps just as much, and costs nothing.

**Please fund this project rather than the lottery** — the expected return is better here.

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
