# Lotto Agent AI & Statistical System Generator

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.2-000000.svg?logo=symfony&logoColor=white)](https://symfony.com/)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-Support-FF5E5B.svg?logo=ko-fi&logoColor=white)](https://ko-fi.com/yaotzin1)
[![Sponsor](https://img.shields.io/badge/GitHub-Sponsor-EA4AAA.svg?logo=githubsponsors&logoColor=white)](https://github.com/sponsors/yaotzin1)

A Symfony 8 CLI & TUI application containerized with Docker, designed as an AI lotto agent utilizing Google Gemini models, ReAct Agent tooling, and advanced combinatorial & statistical optimization engines.

## Key Features

1. **ReAct Agent AI (`app:lotto-agent`)**: Autonomous multi-turn AI agent that queries LOTTO statistics, evaluates co-occurrences, k-clique clusters, and builds high-potential number pools.
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
   | `GEMINI_API_KEY` | for `app:lotto-agent` | Google Gemini access for the ReAct agent ([get one](https://aistudio.google.com/app/apikey)) |
   | `LOTTO_API_KEY` | for live draw history | Draw-history API access. Without it the optimizer falls back to a frequency heuristic that carries no pair information. |
   | `APP_SECRET` | yes | Standard Symfony secret; any random string works locally. |

   The purely mathematical modes (`app:lotto-generator`, `app:lotto-stats`) run without a
   Gemini key — only the AI agent and live statistics need credentials.

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
```

### 2. Interactive Terminal UI (`app:lotto-tui`)
```bash
docker compose run --rm app php bin/console app:lotto-tui
```

### 3. Generator Suite (`app:lotto-generator`)
```bash
# Run generator with Statistical Optimization mode (Mode 7):
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --mode=7 --bets=100

# Run with AI candidate pool and Fractal rolling overlap (Mode 5):
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --strategy=syndicate --mode=5
```

### 4. ReAct Agent AI (`app:lotto-agent`)
```bash
docker compose run --rm app php bin/console app:lotto-agent --game=Lotto --strategy=syndicate --sessions=15
```

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
[![GitHub Sponsors](https://img.shields.io/badge/GitHub-Sponsor-EA4AAA?style=for-the-badge&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/yaotzin1)

Starring the repository and reporting bugs helps just as much, and costs nothing.

**Please fund this project rather than the lottery** — the expected return is better here.

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
