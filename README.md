# Lotto Agent AI & Statistical System Generator

A Symfony 8 CLI & TUI application containerized with Docker, designed as an AI lotto agent utilizing Google Gemini models, ReAct Agent tooling, and advanced combinatorial & statistical optimization engines.

## Key Features

1. **ReAct Agent AI (`app:lotto-agent`)**: Autonomous multi-turn AI agent that queries LOTTO statistics, evaluates co-occurrences, k-clique clusters, and builds high-potential number pools.
2. **Mathematical Generator Suite (`app:lotto-generator`)**: 7 advanced mathematical wheeling & reduction modes (Fractal rolling overlap, Smart Croupier, Floating Bankers, Weighted, Split Bankers, and Statistical Optimizer).
3. **Interactive TUI Suite (`app:lotto-tui`)**: Terminal User Interface with interactive menus, progress indicators, and coverage matrix visualization.
4. **Statistical Dashboard & Dilution Optimizer (`app:lotto-stats` / Mode 7)**:
   - Solves the problem of **heavy dilution (rozwodnienie)** when picking large pools (e.g. 49 numbers into 100 bets or 30 numbers into 25 bets).
   - Assembles combinations around highest historical pair/triplet affinities.
   - Enforces macro-statistical plausibility: Gaussian sum bell curve ($115-185$ for 6/49), parity balance ($3:3, 4:2, 2:4$), and decade spread.
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
  1. **Macro-Plausibility (Stochastic Filtering)**: Over $80\%$ of all real-world lottery winning draws fall within the Gaussian interval $\mu \pm 1.35\sigma$ ($115-185$ in Lotto), have balanced odd/even splits ($3:3$ or $4:2$), and span across at least $3$ decades. The optimizer ensures $100\%$ of generated bets adhere to these proven macro-characteristics.
  2. **Micro-Synergy (Historical Co-occurrence & Affinity)**: Instead of arbitrary pairing, numbers are assembled using a dynamic affinity matrix ($P(A, B)$ co-occurrence + cluster proximity bonuses). Dynamic usage penalties ensure the entire 49-number pool is evenly represented without sacrificing pair synergy.

---

## Requirements
- Docker
- Docker Compose

## Setup and Installation

1. **Environment Setup:**
   Ensure your `.env.dev` or `.env` files are configured with the required API keys (Gemini & Lotto API keys).
   ```bash
   cp .env.dev .env
   ```

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
| **[7]** | **OPTYMALIZACJA STATYSTYCZNA** | Affinity & Co-occurrence Optimizer with Gaussian filtering | Heavy dilution (e.g. 49 nos / 100 bets) |

---

## Testing

Run unit tests via PHPUnit:
```bash
docker compose run --rm app vendor/bin/phpunit
```
