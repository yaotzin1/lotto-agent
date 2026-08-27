# 🔍 Technical & Analytical Review — Lotto Agent

> Independent review of the codebase (`src/`), the public claims (`README.md`), and the player-facing
> documentation (`docs/HOW_TO_PLAY.md`, `docs/STRATEGY_ANALYSIS.md`, `agent.md`).
>
> **Date:** 2026-08-27 · **Reviewed revision:** working tree as of this date (not a git repo)
> **Method:** full read of all 15 source files + 4 docs, plus direct execution of the services against
> synthetic inputs to confirm each numeric claim. Every finding marked **[verified]** was reproduced
> by running the actual code; findings marked **[read]** come from reading alone.

---

## ✅ Status napraw (2026-08-27)

> 📄 **Runda 2:** pozycje A3, A4, C4 i E1 (a przy okazji E4) zostały domknięte —
> szczegóły w [REVIEW_ROUND2.md](REVIEW_ROUND2.md). Statusy poniżej dotyczą rundy 1.

Wszystkie zmiany wprowadzono w repozytorium `lotto-simulator` i zweryfikowano w Dockerze
(`docker compose run --rm app php vendor/bin/phpunit` — **43 testy, 3041 asercji, zielone**).

| Status | Znaczenie |
|---|---|
| ✅ **NAPRAWIONE** | Poprawka wdrożona i zweryfikowana uruchomieniem |
| 🟨 **CZĘŚCIOWO** | Poprawiono to, co dało się poprawić bez zmiany zakresu |
| ⛔ **ZABLOKOWANE** | Naprawa niemożliwa bez większej zmiany; powód opisany |
| 📝 **SKORYGOWANE** | Ustalenie z pierwotnego przeglądu było błędne — patrz niżej |

### Korekty do pierwotnego przeglądu

Dwa ustalenia z pierwszej wersji tego dokumentu okazały się nieprawidłowe i zostały poprawione:

1. **E2 (wydajność) — zmierzone w złym środowisku.** Podane wcześniej „72,3 s" dla 100 zakładów
   pochodziło z hosta (PHP 8.2.0 ZTS na Windows). W sankcjonowanym środowisku Docker
   (PHP 8.5.6, Linux) ta sama operacja trwa **3,9 s**, a 30 zakładów — 1,3 s.
   Waga ustalenia spada z 🟠 High na 🔵 Low.
2. **D1 — klucz LOTTO był sprawny.** Wstępna sonda `wget` zwracała 401 z powodu źle
   przekazanego nagłówka, nie z powodu nieważnego klucza. Właściwe wywołanie przez
   `HttpClient` zwraca **HTTP 200**. Natomiast sam problem bezpieczeństwa okazał się
   **poważniejszy**, niż zakładano — patrz D1 poniżej.

### 🔴 Eskalacja D1: klucze są w historii Git i na GitHubie

W `lotto-simulator` pliki `.env` i `.env.dev` były **śledzone przez Git i zacommitowane
w sześciu commitach**, a repozytorium ma osiągalny remote
`https://github.com/yaotzin1/lotto-simulator.git`. W `HEAD` znajdował się aktywny klucz
Google (`[USUNIETO]…`), klucz LOTTO oraz `APP_SECRET`.

Wykonano: usunięcie z indeksu (`git rm --cached`), wpis w `.gitignore`, dodanie
`.env.dev.example`.

**Nadal wymaga działania właściciela repozytorium — samo usunięcie plików nie cofa publikacji:**

1. **Zrotuj oba klucze i `APP_SECRET`.** Google aktywnie skanuje GitHub pod kątem `[USUNIETO]`.
2. Rozważ wyczyszczenie historii (`git filter-repo` / BFG) i wymuszony push — to operacja
   przepisująca historię, więc pozostawiona do Twojej decyzji.

---

## Table of Contents

1. [Executive summary](#1-executive-summary)
2. [Severity legend](#2-severity-legend)
3. [Group A — Analytical validity (what the code actually computes)](#group-a--analytical-validity)
4. [Group B — Correctness bugs that cost money](#group-b--correctness-bugs-that-cost-money)
5. [Group C — Documentation vs. behaviour](#group-c--documentation-vs-behaviour)
6. [Group D — Security & configuration](#group-d--security--configuration)
7. [Group E — Architecture, tests, performance](#group-e--architecture-tests-performance)
8. [What is genuinely sound](#8-what-is-genuinely-sound)
9. [Prioritised remediation plan](#9-prioritised-remediation-plan)

---

## 1. Executive summary

The project is well structured as *software* — clean Symfony service layering, a tagged-iterator tool
registry, a real ReAct loop, decent CLI ergonomics. The combinatorics helper
(`calculateCombinationsCount`) and the Gaussian variance formula are mathematically correct.

The weak points cluster in three places, in descending order of seriousness:

**1. The analytical core does not do what the documentation says it does.** Three headline
features — the *pair co-occurrence matrix*, the *overdue statistics*, and the *recent draws* feed —
are all derived from a single API endpoint that returns **only per-number marginal frequencies**.
No pairwise data, and no per-draw data, is ever fetched or computed. The "affinity matrix" is
`sqrt(freq(A) × freq(B)) × 8` plus a fixed distance bonus, which carries exactly zero information
about whether A and B ever appeared together. Everything downstream that is sold as "historical
synergy" is a relabelled hot-number ranking.

**2. The quality benchmark is circular.** `benchmarkAgainstRandom()` scores the random baseline with
`calculateBetFitness()` — the very objective function the optimizer maximises. The headline output
*"Zysk synergii: +16.3% względem losowego doboru"* is a tautology: it measures only that the
optimizer optimised its own scoring function. It says nothing about hit rates, expected return, or
win probability, yet it is the last line the user reads.

**3. Several generator paths silently produce the wrong number of coupons — always more or worse
than the user asked for.** Verified examples: the documented Mode 5 recipe emits **224 coupons
(672 zł) when `--bets=50` (150 zł) was requested**; the documented Mode 6 recipe caps at 56 coupons
when 200 were requested; the documented Multi Multi recipe produces **30 identical coupons** while
the report declares "100% pokrycia puli (Zero Drop)".

There is also one strategic omission worth more than every micro-optimisation in the codebase: Polish
Lotto's top prize tiers are **pari-mutuel** (the pool is split among winners). The only lever that
genuinely moves expected value is *avoiding combinations other people play*. The fitness function
does the exact opposite — it rewards hot, popular numbers (`freqTotal * 2.5`) and enforces the
"balanced" patterns most players already pick. If it has any EV effect at all, it is negative.

---

## 2. Severity legend

| | Meaning |
|---|---|
| 🔴 **Critical** | Produces materially wrong output, loses money, or leaks secrets |
| 🟠 **High** | A documented feature does not work as described |
| 🟡 **Medium** | Real defect, contained blast radius |
| 🔵 **Low** | Cosmetic, maintainability, or hygiene |

---

## Group A — Analytical validity

### A1 🔴 The "pair co-occurrence / affinity matrix" contains no pair information **[verified]**

> ✅ **NAPRAWIONE**

`StatisticalOptimizerService::buildPairAffinityMatrix()` (`src/Service/StatisticalOptimizerService.php:88-121`)
and `FetchPairCoOccurrenceTool::execute()` (`src/Service/AgentTools/FetchPairCoOccurrenceTool.php:75-82`)
both compute affinity as a **geometric mean of the two numbers' individual frequencies**:

```php
$coScore = (int) round(sqrt($f1 * $f2));            // FetchPairCoOccurrenceTool
$baseScore = (int) round(sqrt($f1 * $f2) * 8);      // buildPairAffinityMatrix
```

Because `sqrt(f1·f2)` is strictly monotone in both arguments, the "top historical pairs" are, by
construction, *just the pairs of the hottest numbers*. Verified:

```
freq: 1=>20 2=>20 3=>5 4=>5   affinity(1,2)=168   affinity(3,4)=48
```

This directly contradicts:

* `README.md` — *"a dynamic affinity matrix (P(A, B) co-occurrence + cluster proximity bonuses)"*
* `docs/STRATEGY_ANALYSIS.md` §5 — *"Tworzy symetryczną macierz powiązań P(A, B) dla każdej pary z puli"*
* `docs/HOW_TO_PLAY.md` Tryb [8] — *"kupony łączą najsilniejsze statystycznie pary (Pair Affinity)"*
* The tool description shown to Gemini — *"Oblicza współwystępowanie par liczb (które liczby w historii losowań najczęściej pojawiają się razem)"*

**Root cause:** `LottoApiClient` only ever calls
`/lotteries/draw-statistics/numbers-frequency` (`src/Service/LottoApiClient.php:23-30`), which returns
marginal counts. **No endpoint returning individual draws is used anywhere in the codebase**, so
pairwise history is simply not available to compute from.

**Impact:** the entire "Micro-Synergy" layer, the `Wskaźnik Synergii (Affinity)` column in the stats
window, and the `[★ TOP SYNERGIA]` ranking are all cosmetic relabelings of hot-number frequency.

**Fix:** either (a) fetch actual draw results, store them, and compute true co-occurrence counts, or
(b) rename every "affinity/synergy/co-occurrence" label to "frequency-weighted heuristic score" and
delete the P(A,B) claims from the docs. Option (b) is honest and costs an afternoon; option (a) is
the only one that makes the feature real.

---

### A2 🔴 The synergy benchmark is circular — it cannot show what it claims **[verified]**

> ✅ **NAPRAWIONE**

`benchmarkAgainstRandom()` (`src/Service/StatisticalOptimizerService.php:874-947`) generates random
bets and scores them with `calculateBetFitness()` — the same function
`optimizeBetsWithFullCoverage()` maximises when selecting bets. The result is surfaced as the closing
line of the flagship command:

```php
// src/Command/LottoStatsCommand.php:375
"...Zysk synergii: +%.1f%% względem losowego doboru."
```

Verified run (49 numbers → 100 bets): `+16.3%`. That number would appear no matter how meaningless
the fitness function was; it is a self-grade. It is *not* evidence of a higher chance of winning, and
nothing in the output says so.

There is a second, smaller problem: the "random baseline" samples from `$pool`, not from
`1..maxNumber`, so it is not a baseline for random *play* either.

**Fix:** either delete the benchmark, or replace it with something falsifiable — e.g. backtest both
bet sets against the last N real draws and report the actual distribution of 3/6, 4/6, 5/6 hits. If
the benchmark is kept in its current form, label it explicitly: *"self-consistency score of the
optimizer's own objective — not a measure of winning probability."*

---

### A3 🔴 `fetch_overdue_stats` reports average gap, not overdue **[read]**

> ✅ **NAPRAWIONE** (runda 2)

`FetchOverdueStatsTool` (`src/Service/AgentTools/FetchOverdueStatsTool.php:62-72`) computes:

```php
$estimatedDrawGap = $occurrences > 0 ? (int) round($totalDraws / $occurrences) : $totalDraws;
```

That is the *mean* interval between appearances — not "liczba losowań od ostatniego wystąpienia" as
the tool description promises. Since it is a monotone transform of frequency, `most_overdue_numbers`
is identical to `cold_numbers` from `fetch_hot_cold_stats`. Two of the eight agent tools return the
same ranking under different names, and the agent is told they are different analyses.

The concept the doc describes (`docs/HOW_TO_PLAY.md` §3.2 *"liczby skrajnie uśpione — Overdue"*)
requires per-draw data, which the app does not fetch (see A1).

---

### A4 🟠 `fetch_recent_draws` returns no draws **[read]**

> ✅ **NAPRAWIONE** (runda 2)

`LottoApiClient::getDrawResults()` (`src/Service/LottoApiClient.php:113-131`) calls the *frequency*
endpoint and returns the top-N frequency rows under the key `sample_draw_numbers_summary`. The tool
wrapping it is described to Gemini as *"Pobiera wykaz ostatnich wyników losowań (numery wygrane)"*.

Additionally `FetchRecentDrawsTool:60` reports `'fetched_draws_count' => count($draws)` where
`$draws` is the four-key associative array `[game, date_from, date_to, sample_draw_numbers_summary]`
— so the agent is always told **exactly 4 draws were fetched**, regardless of the `count` argument.

**Consequence for the whole `syndicate` strategy:** `FetchNeighboursAnalysisTool:80` builds
`$winningAnchors` from `array_slice($freqDesc, 0, ...)` — the *most frequent numbers over the whole
window*, not the numbers from the last draw. The 60/20/20 formula in `STRATEGY_ANALYSIS.md` §2 is
defined entirely in terms of "ostatnie liczby wygrane" and "bezpośrednie powtórki z poprzedniego
losowania". Neither is computable from the data the app fetches, so the implemented strategy is
*neighbours-of-hot-numbers*, which is a different thing with a different rationale.

---

### A5 🟠 The optimizer fights the strategy it is fed **[verified]**

> ✅ **NAPRAWIONE**

`STRATEGY_ANALYSIS.md` §2 builds a pool where 60% of numbers are ±1 neighbours, on the stated basis
that *"w ponad 50% losowań Lotto przynajmniej dwie z wylosowanych liczb są bezpośrednimi sąsiadami"*.

The optimizer that consumes that pool then:

* **rejects any bet with more than one adjacent pair** — `$adjacentPairs > 1` → `continue`
  (`StatisticalOptimizerService.php:479-481`, and again at `:625`), and penalises it `-100` in fitness
  (`:807-809`);
* scores a direct neighbour **lower** than a number 3–12 away. Verified with all frequencies equal:

  ```
  affinity(15,14) gap=1 => 88     <-- direct neighbour, +8 bonus
  affinity(15,18) gap=3 => 92     <-- "harmonic spread", +12 bonus
  ```

So the pool is built to be neighbour-dense and the generator is built to break neighbour clusters
apart. Verified end to end: of 100 generated Lotto coupons, only **46** contain even one adjacent
pair — below the ~50% base rate the strategy document cites as its central edge.

**Fix:** pick one thesis. If neighbour clustering is the edge, raise the `diff === 1` bonus above the
`3..12` bonus and lift the `adjacentPairs > 1` rejection. If it is not, remove the 60% neighbour quota
from the strategy docs and the `syndicate` prompt.

---

### A6 🟠 The one real EV lever is inverted: pari-mutuel prize splitting is never mentioned **[read]**

> ✅ **NAPRAWIONE**

In Polish Lotto the I, II and III tier prizes are **pari-mutuel** — a fixed share of the stake pool
divided among that tier's winners. Ticket-selection strategy cannot change your probability of
winning, but it *can* change your expected payout conditional on winning, by avoiding combinations
many other players choose. This is the only mathematically defensible edge in a fair lottery, and it
is well documented in the literature.

The app optimises in the opposite direction:

* `calculateBetFitness()` weights `freqTotal * 2.5` — hot numbers are rewarded
  (`StatisticalOptimizerService.php:855`);
* the parity filter enforces 3:3 / 4:2 / 2:4, the decade filter enforces spread, and the consecutive
  filter forbids runs — these are precisely the "looks-random" patterns most human players pick;
* `GeminiApiClient::askForRecommendation()` instructs the model to *"maksymalizuj powtarzanie się tych
  samych liczb na różnych kuponach"* (`src/Service/GeminiApiClient.php:274`), which shrinks coverage
  rather than growing it — and contradicts Mode 8's Zero-Drop philosophy in the same repo.

Nothing here is fraudulent — the README's *Reality Check* section is honest that P is uniform. But a
document that spends 145 lines on strategy without a single sentence on prize sharing is missing the
only part that is actually actionable.

**Fix:** add a "Prize splitting" section to `STRATEGY_ANALYSIS.md`; add an inverse-popularity term
(or at least a `--contrarian` flag that flips the sign of the frequency weight) to the fitness
function.

---

### A7 🟡 `evaluate_distribution` is near-tautological on large pools **[read]**

> ⛔ **ZABLOKOWANE**

`EvaluateDistributionTool:74-84` estimates a subset's sum as `poolAverage × pick`. For any symmetric
pool — including the `--pool=all` case the README leads with — that is exactly the distribution mean,
so `is_sum_within_gaussian_bell` is always `true`. The margin is also arbitrary
(`$sumMargin = $pickCount * 6`, i.e. 36 for Lotto) and disagrees with the correct σ = 32.79 computed
elsewhere in the same codebase.

---

## Group B — Correctness bugs that cost money

### B1 🔴 Mode 5 (Fraktal) emits 4.5× the requested coupons **[verified]**

> ✅ **NAPRAWIONE**

`LottoGeneratorCommand.php:247-256` builds `l1Count × l2Count` working blocks, then
`:317` applies `$betsLimit` **per block**:

```php
$betsLimit = (int)($input->getOption('bets') ?: $io->ask('Ile zakładów wygenerować na JEDEN blok?', '5'));
```

Running exactly the recipe printed in `docs/HOW_TO_PLAY.md`
(`--mode=5 --bets=50`, pool 18, L1=12×4, L2=8×2):

```
working blocks produced : 8
coupons actually emitted: 224      (docs and --bets say 50)
cost at 3.00 zł/coupon  : 672.00 zł instead of 150.00 zł
duplicate coupons across blocks: 2
```

`--bets` means "total" in modes 7 and 8 and "per block" in modes 2 and 5, with no indication which.
The same defect exists in `LottoTuiCommand.php:417`.

**Fix:** make `--bets` mean the total everywhere; divide across blocks and de-duplicate
`$allFinalBets` before printing.

---

### B2 🔴 Mode 8 will happily sell you the same coupon 30 times **[verified]**

> ✅ **NAPRAWIONE**

`docs/HOW_TO_PLAY.md` Multi Multi recipe:
`app:lotto-generator --game=MultiMulti --pool-size=6 --mode=8 --bets=30`

`--pool-size` is consumed **twice** in `LottoGeneratorCommand`: once as the number of marks per
coupon (`:66-72`, MultiMulti branch) and again as the AI pool size (`:105-110`). Both become 6, so
the pool equals the pick and only one combination exists. Verified:

```
bets returned: 30 | distinct coupons: 1
every coupon: 3-17-28-44-55-71
report says is_full_coverage_guaranteed=true, pairs_coverage=100%
```

The user pays for 30 coupons, gets one combination, and the report congratulates them on 100%
coverage. `optimizeBetsWithFullCoverage()` has no guard for `C(poolSize, pick) < numBets`; when every
candidate collides, `$bestCandidateBet` stays `null` and the random-backup branch
(`StatisticalOptimizerService.php:723-727`) appends the same duplicate without a uniqueness check.

**Fix:** validate `C(|pool|, pick) >= numBets` up front and fail loudly; separate `--pool-size` from
`--pick`; add a duplicate check to the backup branch.

---

### B3 🟠 Mode 6 cannot reach the bet counts its own documentation recommends **[verified]**

> ✅ **NAPRAWIONE**

`HOW_TO_PLAY.md` Semi-Pro row: *"100 – 200 zakładów, Tryb [6] Bankierzy Rotacyjni, 8 Bankierów"*, with
a quoted 1:157 jackpot chance "przy 200 zakładach".

`LottoGeneratorCommand.php:325` builds banker sub-bets via
`generateBalancedShorthand($bankersPool, $bankersQty, $betsLimit)`; with 8 bankers taken 3 at a time
there are only C(8,3) = 56 distinct triples. `:330` then does
`$limit = min(count($bankerBets), count($varBets))`. Verified:

```
8 bankers, 3 per coupon, --bets=200  ->  got 56 coupons (C(8,3)=56 is the hard ceiling)
```

No warning is printed. The user asked for 200 and silently receives 56.

---

### B4 🟠 `generateBalancedShorthand` returns an invalid coupon when the pool is too small **[verified]**

> ✅ **NAPRAWIONE**

`BetGeneratorService.php:143-146`:

```php
if (count($pool) <= $pick) {
    return [$pool];
}
```

For `pool=[4,9,17], pick=6` this returns `[[4,9,17]]` — a 3-number coupon for a 6-number game, which
cannot be played. It should throw. The same method also silently gives up after 20 000 attempts
(`:158`) and returns fewer bets than requested, with no signal to the caller.

---

### B5 🟠 `generateOverlappingBlocks` can emit a block containing repeated numbers **[verified]**

> ✅ **NAPRAWIONE**

`BetGeneratorService.php:74-77` indexes with `% $poolCount`. When `blockSize > poolCount` the block
wraps onto itself:

```
generateOverlappingBlocks(pool of 5, blockSize=8) -> [1,1,2,2,3,4,4,5]
```

Mode 5 prompts for L1/L2 sizes with no validation against the pool size, so this is reachable from
the UI. Additionally `$step = $poolCount / $numBlocks` (`:81`) collapses to identical start indices
when `numBlocks > poolCount`, producing duplicate blocks.

The "Coprime Stride" the docs make much of is also largely decorative: the linear-probe fallback at
`:64-67` skips visited indices regardless, so the traversal degenerates to an arbitrary permutation
whether or not the stride is coprime.

---

### B6 🟠 Fitness thresholds are hardcoded for `pick ∈ {5,6}` and break other games **[verified]**

> ✅ **NAPRAWIONE**

`calculateBetFitness()` (`StatisticalOptimizerService.php:832,850`) hardcodes:

```php
$isParityBalanced = ($odds >= 2 && $evens >= 1 && $odds <= 4);
$decadeBonus = ... $decadeSpread >= ($pick >= 6 ? 4 : 3) ...
if ($maxInSingleDecade >= 3) $decadeBonus = -150;
```

Verified against the registry's own game definitions:

| Game | Symptom |
|---|---|
| **MultiMulti** (pick 10) | A 5:5 odd/even split — the modal outcome — is scored `is_parity_balanced=false` and penalised −40, while 4:6 is rewarded +30 |
| **Kaskada** (12 from 24) | Only 3 decades exist, so the "≥4 decades" bonus is unreachable; and 12 numbers over 3 decades forces ≥3 in one decade, so **every possible Kaskada coupon** takes the −150 penalty |
| **Kaskada** | The construction filter `$decCount >= 2 → continue` (`:497`) can reject every candidate, dropping generation into the `array_shift` fallback path for the whole run |

`GameRegistryService` advertises 11 games; the fitness function was written for 2.

---

### B7 🟡 `askForPool` slices before de-duplicating **[read]**

> ✅ **NAPRAWIONE**

`GeminiApiClient.php:262-265`:

```php
$numbers = array_map('intval', $matches[0] ?? []);
return array_unique(array_slice($numbers, 0, $poolSize));
```

Slicing first means any duplicate in the model's output shrinks the returned pool below `$poolSize`.
There is also no range filter, so a hallucinated `55` is accepted for a 6/49 game, and `array_unique`
preserves keys — the return value is not a list, which will surprise `json_encode` and any indexed
access downstream.

---

### B8 🟡 ReAct history is corrupted when Gemini returns parallel function calls **[read]**

> ✅ **NAPRAWIONE**

`ReActAgentService.php:171-190` appends the **entire** `$parts` array as the model turn, then appends
exactly **one** `functionResponse`, then `break`s out of the parts loop. If Gemini emits two function
calls in one turn, the conversation history contains two calls and one response — which the v1beta
API rejects or mis-handles. Only the first call is ever executed.

---

### B9 🟡 The ReAct fallback fabricates a pool and calls it analysis **[read]**

> ✅ **NAPRAWIONE**

`ReActAgentService.php:274-280`:

```php
$poolRange = range(1, $maxNumber);
$merged = array_values(array_unique(array_merge($lastEvaluatedPool, $poolRange)));
$finalPool = array_slice($merged, 0, $poolSize);
$finalReasoning = "Wytypowano pulę na podstawie przeprowadzonej analizy statystyk i ewaluacji narzędziami ReAct (...)";
```

This pads whatever the agent last happened to pass to a tool with the sequence `1, 2, 3, 4, …` and
then tells the user it is the product of statistical analysis. The user has no way to distinguish this
from a real result. (The second fallback at `:281-285` shuffles and *does* say "pula rezerwowa" — that
one is honest.)

Worse, the primary extraction path is a bare integer regex over the model's prose
(`ReActAgentService.php:222-228`):

```php
preg_match_all('/\d+/', $text, $numMatches);
```

Prose such as *"Analiza 50 losowań: 60% sąsiedzi, 20% powtórki"* yields the pool `[50, 60, 20]`, and
`count >= $pickCount` is the only gate. Any narration containing enough in-range integers is accepted
as a typing.

**Fix:** require the JSON contract (use Gemini's `responseMimeType: application/json` /
`responseSchema`), drop the regex fallback, and never label a fallback as analysis.

---

### B10 🟡 Mode 4 accepts more bankers than the game has marks **[read]**

> ✅ **NAPRAWIONE**

`LottoGeneratorCommand.php:429`: `$needed = $game['pick'] - count($bankers);` — no validation that
bankers ⊆ pool, are in range, or number fewer than `pick`. With 8 bankers in a 6/49 game `$needed`
is `-2`, `generateBalancedShorthand` spins to its 20 000-attempt cap, and the merged "coupon" comes
back with 8 numbers.

---

### B11 🔵 `app:lotto-tui --mode=8` silently ignores the flag **[verified]**

> ✅ **NAPRAWIONE**

`LottoTuiCommand.php:238` whitelists `['1'..'7']` while the interactive menu at `:242-250` and the
handler at `:258` both support `'8'`. Passing `--mode=8` drops the user into the interactive prompt.
`LottoGeneratorCommand.php:150` has the correct `['1'..'8']` list — the two copies have drifted.

---

### B12 🔵 Assorted display defects **[read]**

> ✅ **NAPRAWIONE**

* `sprintf('+%.1f%%', ...)` hardcodes the plus sign in three places
  (`LottoStatsCommand.php:329,375`, `LottoGeneratorCommand.php:406`); a negative advantage renders as
  `+-3.2%`.
* `LottoStatsCommand.php:308` divides parity counts by the **requested** `$betsCount` rather than
  `count($bets)`; when generation falls short the percentages do not sum to 100.
* `generateAsciiGaussianHistogram()` (`StatisticalOptimizerService.php:952`) calls `min()`/`max()` and
  divides by `count($sums)` with no empty guard — an empty bet set throws `ValueError` in PHP 8.
* `generateCombinations()` (`BetGeneratorService.php:301`) has no `$length <= 0` guard and recurses
  unboundedly if ever called with 0.
* `LottoApiClient::getHotAndColdNumbers()` returns overlapping hot/cold sets when
  `count($frequencies) < 2 * $limit`.
* Typo `numberFrequrency` is load-bearing (`LottoApiClient.php:63`) — worth a comment noting it
  mirrors the upstream API's own typo, or it will get "fixed" and break silently.
* `LottoTuiCommand.php:247` — `"Bankierzy RotACYJNI"`.

---

## Group C — Documentation vs. behaviour

### C1 🔴 The Gaussian range is stated three different ways, none matching the code **[verified]**

> ✅ **NAPRAWIONE**

`calculateGaussianParameters()` is **correct** — variance `k(M+1)(M−k)/12` is the right
finite-population formula. But every document disagrees with its output:

| Source | Lotto 6/49 σ | Lotto optimal range | MiniLotto 5/42 σ | MiniLotto range |
|---|---|---|---|---|
| **The code (verified)** | **32.79** | **106 – 194** | **25.75** | **73 – 142** |
| `README.md` | 32.8 implied | 115 – 185 | — | — |
| `docs/STRATEGY_ANALYSIS.md` §5 | 32.8 ✓ | 115 – 185 | — | — |
| `docs/HOW_TO_PLAY.md` | **24.1** ✗ | **125 – 175** | **16.5** ✗ | **85 – 130** |

Full verified output:

```
Lotto         6/49  mu=150  sigma= 32.79  optimal_range=106 - 194
MiniLotto     5/42  mu=108  sigma= 25.75  optimal_range=73 - 142
EuroJackpot   5/50  mu=128  sigma= 30.92  optimal_range=86 - 169
Kaskada      12/24  mu=150  sigma= 17.32  optimal_range=127 - 173
MultiMulti   10/80  mu=405  sigma= 68.74  optimal_range=312 - 498
```

The `HOW_TO_PLAY.md` σ values (24.1, 16.5) are not the standard deviation of anything in this problem.

Two secondary errors follow from the same confusion:
* `README.md` claims *"Over 80% of all real-world lottery winning draws fall within μ ± 1.35σ
  (115–185)"*. 115–185 is μ ± 1.07σ ≈ 71.5% under a normal approximation, not 80%. The **code's**
  ±1.35σ band is ~82% — so the coefficient is right and the interval printed next to it is wrong.
* `LottoStatsCommand.php:290` prints the literal string *"Przedział optymalny (80% masy)"* — an
  asserted figure that is never measured against real draw data.

**Fix:** delete all hardcoded ranges from the prose and generate the table from
`calculateGaussianParameters()`. Single source of truth.

---

### C2 🟠 Modes 2–6 are documented as one-shot CLI commands but block on interactive prompts **[read]**

> ✅ **NAPRAWIONE**

`HOW_TO_PLAY.md` presents e.g.:

```bash
php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --pool-size=14 --mode=4 --bets=20
```

Mode 4 then hits `$io->ask('Wpisz stałych Bankierów...')` (`LottoGeneratorCommand.php:265`). There
are no `--bankers`, `--bankers-per-bet`, `--l1-size`, `--l1-count`, `--l2-size`, `--l2-count`, `--hot`
or `--weight` options, so **none of the mode 2–6 recipes in the manual can run non-interactively** —
which also means they cannot be scripted, tested, or CI-verified. (`LottoTuiCommand` does define a
`--bankers` option; `LottoGeneratorCommand` does not — another divergence between the two copies.)

---

### C3 🟠 Mode 7 and Mode 8 are the same code path **[read]**

> ✅ **NAPRAWIONE**

`LottoGeneratorCommand.php:206` calls `optimizeBetsForDilution(...)` **without** an `$options`
argument. `StatisticalOptimizerService.php:142` then does:

```php
$fullCoverageRequested = $options['full_coverage'] ?? true;
if ($fullCoverageRequested) {
    return $this->optimizeBetsWithFullCoverage($pool, $pick, $numBets, $frequencies, $maxNumber, $options);
}
```

So Mode 7 immediately delegates to Mode 8's implementation with identical arguments. The only
difference between them is the default value in the "how many bets?" prompt (100 vs. 10/15). The
~270-line dilution branch below that early return (`:146-412`) is **dead code** on every documented
invocation — the only way to reach it is `app:lotto-stats --full-coverage=false`.

Meanwhile the docs describe them as strategically distinct:

* `README.md` — Mode 7 *"Heavy dilution (Hot numbers concentration)"* vs Mode 8 *"Full pool partitioning"*
* `HOW_TO_PLAY.md` — Mode 7 *"Łowca Synergii Hot (Koncentracja) — 100–300 zakładów"* vs Mode 8
  *"Rankingowe Pełne Pokrycie"*, recommended for different budget tiers
* The Lotto budget table routes Semi-Pro to Mode 7 and Syndicate to Mode 8 — a distinction the code
  does not make.

The same duplication exists in `LottoTuiCommand.php:290`.

---

### C4 🟠 EuroJackpot's Euro-numbers are documented but not implemented **[verified]**

> ✅ **NAPRAWIONE** (runda 2)

`GameRegistryService` defines `'extra' => 2, 'extra_from' => 12` for EuroJackpot. A repo-wide grep
shows **`extra` and `extra_from` are read nowhere outside the registry**. No generator, optimizer, or
tool ever produces the 2-from-12 Euro-numbers, and `EkstraPensja` / `EkstraPremia` (`extra => 1`) are
in the same position.

`HOW_TO_PLAY.md` nonetheless devotes a full column to *Strategia EuroNumbers (E1, E2)* with
recommendations like *"Rotacja EuroNumbers: z 12 E-liczb powstaje 66 par → obsadzenie 30–50
najczęstszych par"*, and `--game=EuroJackpot` runs without warning — producing coupons that cannot
actually be played.

---

### C5 🟠 Conditional odds are presented alongside unconditional odds without marking **[read]**

> ✅ **NAPRAWIONE**

`HOW_TO_PLAY.md` budget tables mix two incompatible kinds of probability in adjacent rows:

| Claim | Kind | Check |
|---|---|---|
| Mini Lotto Syndicate: *"1 do 1 700 w pojedynczym losowaniu"* | unconditional | ✓ 850 668 / 500 = 1 701 |
| Mini Lotto Semi-Pro: *"1 do 157 (0,64%) na Jackpot"* @ 200 bets | **conditional** on 3-of-8 bankers hitting | unconditional is 850 668 / 200 = **1 : 4 253** |
| Lotto Micro: *"1 do 6,6 (15,1%) na 6/6"* @ 25 bets | **conditional** on the base entering | unconditional is 13 983 816 / 25 = **1 : 559 353** |

The Lotto Micro figure is internally consistent (25 / C(11,3) = 15.15%) and the row does say *"po
wejściu bazy"* — but the probability of the condition itself (all 6 winning numbers inside a
14-number pool ≈ 1 : 4 657, then the 3 chosen bankers all being among them) is never stated anywhere.
A reader comparing "1 do 8" against "1 do 1 700" in the same table will draw exactly the wrong
conclusion about which is better.

**Fix:** add a `P(warunek)` column, or render every conditional figure as
`P(jackpot) = P(baza) × P(trafienie | baza)` with both factors shown.

---

### C6 🟡 `app:lotto-agent --pick` and `--bets` do nothing **[read]**

> ✅ **NAPRAWIONE**

`LottoAgentCommand.php:40,43` register both options and prompt for them interactively
(`:61-70`), but `$betsCount` is never read again and `$pickCount` is used only in
`$poolSize = max($pickCount + 4, 12)` (`:134`). The command produces a candidate pool and stops — it
never generates a single bet. `README.md` §4 and `HOW_TO_PLAY.md` §5 both present it as the
headline AI feature without saying it produces no coupons.

---

### C7 🟡 Mode 3 silently truncates the pool to 15 numbers **[read]**

> ✅ **NAPRAWIONE**

`LottoGeneratorCommand.php:290`: `$targetSize = min(count($fullPool), 15);`. A user who asked the AI
for a 24-number pool and picks Mode 3 gets 15, with no message. The selection loop
(`:291-298`) also rejection-samples one number at a time from a weighted urn — with a ×10 weight on
many numbers, the tail of the pool is reached slowly.

---

### C8 🔵 `agent.md` documents a command that does not exist **[verified]**

> ✅ **NAPRAWIONE**

`agent.md` §4 describes `LottoCommand (src/Command/LottoCommand.php)`. No such file exists. The file
also omits `LottoStatsCommand` and `LottoTuiCommand` and hedges with *"It likely handles..."* — it
reads as generated placeholder text rather than documentation.

---

### C9 🔵 Ticket prices and game definitions should be re-verified **[read]**

> 🟨 **CZĘŚCIOWO**

`HOW_TO_PLAY.md` quotes **1,50 zł** for Mini Lotto; the current price is 1,25 zł (worth confirming
against Totalizator's tariff before the next release, since every cost figure in the budget matrix is
derived from it). Lotto at 3,00 zł and EuroJackpot at 12,50 zł are correct. Separately,
`GameRegistryService` gives `Keno` and `MultiMulti` a fixed `pick`, while both are variable-pick
games (the player chooses 1-10 numbers).

> **KOREKTA (runda 3):** twierdzenie, że wpis `Keno` (`from => 70`) jest błędny, było
> **nieuzasadnione**. Weryfikacja wobec LOTTO OpenAPI potwierdziła, że wszystkie wartości
> `from` i `extra_from` w rejestrze są poprawne, łącznie z Keno = 70. Realnym problemem
> była wyłącznie stała liczba skreśleń — naprawione, patrz REVIEW_ROUND2.md, runda 3.

---

## Group D — Security & configuration

### D1 🔴 Live API keys sit in files that `.gitignore` does not cover **[verified]**

> 🟨 **CZĘŚCIOWO**

`.env` and `.env.dev` both contain real `GEMINI_API_KEY` and `LOTTO_API_KEY` values, plus a
hardcoded `APP_SECRET`. `.gitignore` covers only:

```
/.env.local
/.env.local.php
/.env.*.local
```

`.env` and `.env.dev` are **not** ignored. `docker-compose.yml` loads `.env.dev` via `env_file`, and
`README.md` instructs `cp .env.dev .env` — so the committed file is the one carrying the secrets by
design. This directory is not currently a git repo; the moment it becomes one, both keys are in
history.

**Fix now, before `git init`:**
1. Rotate both API keys and `APP_SECRET` — assume they are compromised.
2. Add `/.env` and `/.env.dev` to `.gitignore`; commit `.env.dev.example` with empty values instead.
3. Point `docker-compose.yml` at `.env.local`.

---

### D2 🔴 TLS certificate verification is disabled on every outbound call **[read]**

> ✅ **NAPRAWIONE**

```php
'verify_peer' => false,
'verify_host' => false,
```

Present in `LottoApiClient.php:41-42` and twice in `GeminiApiClient.php:78-79, 166-167`. Both
requests carry a secret — the Lotto key in a `secret:` header, the Gemini key in the query string.
Disabling verification makes both trivially interceptable on a hostile network. This is almost
certainly a workaround for a local CA-bundle problem; the correct fix is to set `openssl.cafile` /
ship `ca-certificates` in the Docker image (`apk add --no-cache ca-certificates`), not to turn
verification off.

---

### D3 🟡 The Gemini API key travels in the URL query string **[read]**

> ✅ **NAPRAWIONE**

`GeminiApiClient.php:45,76,164` all use `'query' => ['key' => ...]`. Query strings are logged by
proxies, CDNs, and browser/server access logs. Google supports the `x-goog-api-key` request header —
use it.

---

### D4 🟡 The fallback model list is unvalidated, and 404s are treated as retryable **[read]**

> 🟨 **CZĘŚCIOWO**

`GeminiApiClient::FALLBACK_MODELS` (`:15-24`) is a hardcoded list beginning
`gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.5-flash`. These do not correspond to published
Google model identifiers — worth checking against the `listModels()` method this same class already
implements but never calls. Because `$statusCode === 404` is in the retry condition (`:107`), each
non-existent model costs one wasted HTTP round-trip on *every* generation call, silently, before a
working model is reached.

Two consequences beyond the wasted latency:
* **The model actually used is never reported.** Two runs of the same analysis can be served by
  different models with no record of which — the analyses are not reproducible or comparable.
* A genuine 404 (bad key, wrong project) is indistinguishable from "model doesn't exist" and gets
  swallowed by the fallback chain.

**Fix:** call `listModels()` once at boot (cache it), intersect with a preference list, fail fast if
the intersection is empty, and log the resolved model id into every report.

---

### D5 🔵 `phpunit` is a production dependency; Docker image is dev-only **[read]**

> ⛔ **ZABLOKOWANE**

* `composer.json:9` puts `phpunit/phpunit` in `require`, not `require-dev`.
* `"minimum-stability": "dev"` at `:5` accepts unstable upstream releases.
* `Dockerfile` has a single `dev` stage — no `composer install`, no `--no-dev`, no non-root user, no
  `ca-certificates` (see D2), and `docker-compose.yml` pins `target: dev`. There is no path to a
  production image.
* `README.md` says "Symfony 8"; `composer.json` pins `8.2.*` and `php >= 8.5`.

---

## Group E — Architecture, tests, performance

### E1 🟠 `LottoTuiCommand` is a 558-line fork of `LottoGeneratorCommand` **[verified]**

> ✅ **NAPRAWIONE** (runda 2)

345 lines are identical between the two files. Modes 2–8, the whole "Okno Statystyczne" rendering
block, and the coverage verifier are duplicated verbatim. The copies have **already drifted**:

* mode `'8'` is accepted via `--mode` in one and not the other (B11);
* `--bankers` exists in the TUI and not the generator (C2);
* the mode-6 label is misspelled in one copy only (B12).

Every bug in Group B has to be fixed twice, and a fixer will find only one.

**Fix:** extract the shared pipeline into a `BetPipelineService` (mode dispatch → `array $bets`) and a
`StatsWindowRenderer`; leave the two commands as thin I/O adapters.

---

### E2 🟠 The flagship command takes 72 seconds **[verified]**

> 📝 **SKORYGOWANE**

Timed on the exact README example (49 numbers → 100 bets, `optimizeBetsWithFullCoverage`):

```
100 bets: 72.3 s, peak mem 4.0 MB
 30 bets: 16.8 s
```

Roughly O(numBets × attempts × pick × poolSize × pick), with several avoidable multipliers:

* `usort($availablePool, ...)` runs inside the 300/400-attempt loop
  (`StatisticalOptimizerService.php:180`, `:598`) but the comparator depends only on state that does
  not change between attempts — hoist it out of the loop.
* the consecutive-run check re-sorts `$tempNums` and rescans it for **every candidate**
  (`:205-224`, `:474-489`, `:621-631`) — the adjacency test only needs to look at the candidate's two
  neighbours.
* `calculateBetFitness()` is recomputed for every bet at least three times (selection, Phase 3
  ranking, `benchmarkAgainstRandom`) and a fourth time in `LottoStatsCommand.php:349`.
* `benchmarkAgainstRandom` alone runs `50 × numBets` = 5 000 extra fitness evaluations for a 100-bet
  packet — for a metric that (see A2) measures nothing.

Fixing just the first two should be a large constant-factor win with no behaviour change.

---

### E3 🟠 The test suite cannot run, and asserts stale invariants **[verified]**

> ✅ **NAPRAWIONE**

```
$ php vendor/bin/phpunit
PHP Fatal error: Composer detected issues in your platform:
Your Composer dependencies require a PHP version ">= 8.5.0". You are running 8.2.0.
```

The suite is unrunnable outside the container, so it will not be run. Beyond that:

* **5 test files for 15 source files.** `ReActAgentService`, `LottoApiClient`, `ToolRegistry`, all
  four commands, and 7 of 8 agent tools have zero coverage.
* `StatisticalOptimizerServiceTest::testBuildPairAffinityMatrixSymmetryAndClusterBonus` asserts
  `$matrix[5][6] > $matrix[5][12]` with the comment *"5 and 6 are direct neighbours -> +35 cluster
  bonus"*. There is no +35 bonus in the code — it is +8 for `diff === 1` and **+12** for
  `diff ∈ [3,12]`. The test passes only because `freq(6)=15 > freq(12)=8` carries it; with equal
  frequencies the assertion inverts (verified in A5). The test gives false confidence in exactly the
  behaviour that is broken.
* `testCalculateGaussianParameters` asserts `optimal_min <= 115` and `optimal_max >= 185` — loose
  enough to pass while the actual output is 106–194, which is why C1 went unnoticed.
* No test asserts bet **count** (B1, B3), bet **uniqueness across the packet** (B2), or behaviour for
  any game other than Lotto/MiniLotto (B6).

**Fix:** add `"platform": {"php": "8.5.0"}` under `config` in `composer.json` so the suite runs
locally, then add regression tests for B1–B6 first — they are all cheap, deterministic assertions on
counts and set sizes.

---

### E4 🟡 No caching, no persistence, no rate limiting **[read]**

> ✅ **NAPRAWIONE** (runda 2)

`config/packages/cache.yaml` exists but no service uses the cache pool. Every command run re-fetches
from the Lotto API, and `calculateDateFromForSessions()` uses `new \DateTime()` (i.e. *now*), so
consecutive runs produce slightly different windows and non-reproducible reports. A local SQLite/JSON
store of draw history would fix reproducibility *and* unlock the real co-occurrence computation that
A1 needs.

---

### E5 🟡 `calculateDateFromForSessions` has an unbounded loop **[read]**

> ✅ **NAPRAWIONE**

`GameRegistryService.php:127-134` walks backwards one day at a time until it has counted `$sessions`
draw days. `$sessions` is unvalidated at the `LottoStatsCommand` / `LottoGeneratorCommand` call sites
(`FetchOverdueStatsTool` caps at 200, but the commands do not), so `--sessions=1000000` spins for
millions of iterations. If a game config ever had an empty `draw_days`, it would never terminate.

---

### E6 🔵 The coverage guarantee loses its caveat when handed to the LLM **[read]**

> ✅ **NAPRAWIONE**

`BetGeneratorService::calculateCoverage()` enumerates draws drawn **entirely from within the pool**.
`LottoGeneratorCommand.php:415` states this honestly to the human:

> *"Założenie: Maszyna wylosowała dokładnie 6 liczb, które znajdują się w Twojej puli."*

`SimulateCoverageTool::execute()` (`src/Service/AgentTools/SimulateCoverageTool.php:65-70`) returns
the same numbers to Gemini under the bare key `'guarantees'` with no such note. The model then
reasons about — and reports — conditional guarantees as if they were unconditional. The tool's
`pool < 6` check is also hardcoded to 6 rather than the game's `pick`.

---

## 8. What is genuinely sound

Worth stating plainly, because the list above is long:

* **`calculateGaussianParameters()` is correct.** `Var = k(M+1)(M−k)/12` is the right
  finite-population-without-replacement variance, and the derivation in the comment is right too. The
  code is more accurate than three of the four documents describing it.
* **`calculateCombinationsCount()` is well written** — multiplicative, symmetry-reduced (`k > n/2`),
  no factorial overflow.
* **`generateCombinations()` is a proper generator** — genuinely O(1) memory as the comment claims.
* **`calculateCoverage()`'s cumulative guarantee loop is correct**, and it is honestly labelled in the
  CLI (just not in the tool wrapper — E6).
* **`README.md`'s "Reality Check" section is intellectually honest** about uniform probability. Most
  projects in this space are not. The problem is that `HOW_TO_PLAY.md` then walks it back.
* **The service architecture is clean** — constructor injection throughout, a tagged-iterator tool
  registry that makes adding a tool a one-file change, `LottoToolInterface` well factored, `declare(strict_types=1)` everywhere.
* **The ReAct loop is a real ReAct loop** — proper `functionCall`/`functionResponse` turn structure,
  `thought` parts filtered, and `thought_signature` preservation attempted (the parallel-call bug in
  B8 notwithstanding).

---

## 9. Prioritised remediation plan

### Do first — hours, high value

| # | Action | Refs |
|---|---|---|
| 1 | Rotate `GEMINI_API_KEY`, `LOTTO_API_KEY`, `APP_SECRET`; add `/.env`, `/.env.dev` to `.gitignore`; commit `.env.dev.example`. Do this **before** `git init`. | D1 |
| 2 | Remove `verify_peer`/`verify_host` overrides; add `ca-certificates` to the Dockerfile. | D2 |
| 3 | Regenerate the game tables in `HOW_TO_PLAY.md` from `calculateGaussianParameters()` output. Delete every hardcoded σ and sum range from prose. | C1 |
| 4 | Add `"platform": {"php": "8.5.0"}` to `composer.json` so the test suite runs; move `phpunit` to `require-dev`. | D5, E3 |
| 5 | Make `--bets` mean "total coupons" in every mode; de-duplicate `$allFinalBets` before output. | B1 |
| 6 | Validate `C(|pool|, pick) >= numBets` and fail loudly; add a uniqueness check to the random-backup branch. | B2 |
| 7 | Warn when a mode cannot reach the requested bet count (Mode 6 / `generateBalancedShorthand` cap). | B3, B4 |

### Do next — days, restores credibility

| # | Action | Refs |
|---|---|---|
| 8 | **Decide what the pair matrix is.** Either fetch and store real draw history and compute true co-occurrence, or rename every "affinity/synergy/P(A,B)" label to "frequency heuristic" and correct the four documents. | A1, A3, A4, E4 |
| 9 | Replace or clearly label the synergy benchmark. A backtest against the last N real draws is the honest version. | A2 |
| 10 | Make Mode 7 and Mode 8 actually differ, or merge them and update the docs and both mode menus. | C3 |
| 11 | Add `--bankers`, `--l1-size`, `--l1-count`, `--l2-size`, `--l2-count`, `--hot`, `--weight` so the documented recipes are runnable; add validation (bankers ⊆ pool, `count(bankers) < pick`, block size ≤ pool size). | C2, B5, B10 |
| 12 | Derive parity and decade thresholds from `pick` and `maxNumber` instead of hardcoding, or gate unsupported games behind a clear error. | B6 |
| 13 | Extract the shared pipeline from `LottoGeneratorCommand` / `LottoTuiCommand` into a service. | E1 |
| 14 | Replace the ReAct integer-regex fallback with a JSON schema response; never label a fallback as analysis. Handle parallel function calls. | B8, B9 |
| 15 | Either implement EuroJackpot's 2/12 Euro-numbers or reject those games with a clear message and remove the section from the manual. | C4 |

### Then — strategy and honesty

| # | Action | Refs |
|---|---|---|
| 16 | Add a **prize-splitting** section to `STRATEGY_ANALYSIS.md`; consider a `--contrarian` mode that inverts the frequency weight. This is the only genuine EV lever available. | A6 |
| 17 | Mark every conditional probability in `HOW_TO_PLAY.md` with `P(warunek)` alongside it. | C5 |
| 18 | Resolve the neighbour contradiction: pick either "cluster neighbours" or "spread numbers out", and align the affinity bonuses, the `adjacentPairs > 1` rejection, and the strategy prompt with the choice. | A5 |
| 19 | Hoist the loop-invariant `usort`, narrow the adjacency check, and memoise fitness — the 72 s run should drop substantially with no behaviour change. | E2 |
| 20 | Regression-test bet counts, packet uniqueness, and non-Lotto games; fix the stale `+35 cluster bonus` assertion and tighten the loose Gaussian bounds. | E3 |
| 21 | Rewrite `agent.md` (it documents a nonexistent `LottoCommand`); document that `app:lotto-agent` returns a pool, not coupons, and remove its dead `--pick`/`--bets` options. | C6, C8 |
| 22 | Re-verify ticket prices and the `Keno` / `MultiMulti` registry entries against the official tariff. | C9 |

---

## 10. Szczegóły napraw i pozycje otwarte

### Co zostało naprawione i jak to zweryfikowano

| # | Naprawa | Dowód |
|---|---|---|
| **A1** | `buildPairAffinityMatrix()` liczy teraz **rzeczywiste współwystępowanie** par z historii losowań. Dodano `LottoApiClient::fetchDrawHistory()`, które pobiera wyniki równolegle (endpoint `by-date-per-game`, jedno zapytanie na datę losowania). Poniżej 20 losowań macierz degraduje się do starej heurystyki i **jest tak oznaczona** (`affinity_source`). | 59 losowań Lotto pobranych w 0,9 s; okno statystyczne pokazuje „Źródło: RZECZYWISTE współwystępowanie par z 49 losowań". Testy: `PairCoOccurrenceTest`. |
| **A2** | Benchmark zwraca `metric_is_self_referential: true` + `disclaimer`. CLI drukuje uwagę metodologiczną, a końcowy komunikat nie reklamuje już „zysku synergii". | `testBenchmarkDeclaresItselfSelfReferential` |
| **A5** | Premia sąsiedztwa podniesiona do **+14**, premia rozstawu 3–12 obniżona do **+8** — sąsiad już nie przegrywa z liczbą oddaloną. | `testNeighbourBonusOutranksSpreadBonus` |
| **A6** | Nowa sekcja *„Podział Puli Nagród"* w `HOW_TO_PLAY.md` opisuje pari-mutuel i wprost mówi, że funkcja oceny premiuje wzorce popularne wśród graczy, co działa **na niekorzyść** oczekiwanej wypłaty. | `docs/HOW_TO_PLAY.md` §2b |
| **B1** | `--bets` znaczy **łączną** liczbę kuponów w każdym trybie; limit dzielony na bloki, deduplikacja między blokami. | Tryb 5 `--bets=50` → dokładnie **50** kuponów (było 224 / 672 zł) |
| **B2** | `assertBetCountIsAchievable()` odrzuca zamówienie większe niż `C(pula, skreślenia)`; ścieżka awaryjna nie dokłada duplikatów. | `--pool-size=6 --bets=30` → czysty błąd zamiast 30 identycznych kuponów |
| **B3** | Niedobór jest raportowany ostrzeżeniem z podaną przyczyną. | Tryb 6, 200 zamówionych → „Zamówiono 200, wygenerowano 56" |
| **B4/B5** | `generateBalancedShorthand()` i `generateOverlappingBlocks()` rzucają wyjątek zamiast zwracać niegrywalne kupony; `coprimeStride()` liczy krok naprawdę względnie pierwszy. | `BetIntegrityTest` |
| **B6** | `isParityBalanced()`, `maxPerDecade()`, `targetDecadeSpread()` skalują się do `pick`/`maxNumber`. Dla Lotto zachowanie **bez zmian**. | 5:5 w Multi Multi znów legalne; Kaskada ma legalne kupony |
| **B8/B9** | Wszystkie równoległe `functionCall` dostają odpowiedź; usunięto regex po gołych liczbach; tryb awaryjny oznaczony `is_fallback` i nigdy nie opisywany jako analiza. | `ReActAgentService` |
| **C1** | Wszystkie zakresy sum wyliczane z `calculateGaussianParameters()`; dokumentacja doprowadzona do zgodności (Lotto **106–194**, σ = 32,79). | `testGaussianRangeMatchesFinitePopulationFormula` |
| **C2** | Dodano `--pool`, `--bankers`, `--bankers-per-bet`, `--l1-size/count`, `--l2-size/count`, `--block-size/count`, `--hot`, `--weight`. | Wszystkie tryby 2–6 uruchamiane z `--no-interaction` |
| **C3** | `optimizeBetsForDilution()` ma teraz `full_coverage` domyślnie **false** → Tryb 7 to realna koncentracja, Tryb 8 to pełne pokrycie. Odzyskane ~270 linii martwego kodu. | 9 zakładów z puli 49: Tryb 7 → 67,3% pokrycia / 23 sloty gorące; Tryb 8 → 100% / 6 slotów |
| **E5** | Historia losowań ma twardy limit `MAX_DRAW_HISTORY_REQUESTS = 120`. | `LottoApiClient` |
| **E6** | `test_system_coverage` zwraca teraz warunek wprost w polu `assumption`. | `SimulateCoverageTool` |

### Pozycje otwarte i powody

| # | Status | Powód |
|---|---|---|
| **A3, A4** | 🟨 CZĘŚCIOWO | `fetch_overdue_stats` i `fetch_recent_draws` nadal opierają się na endpoincie częstotliwości. Rozbieżność jest teraz **udokumentowana** w `agent.md` i w opisach narzędzi, ale pełna naprawa wymaga przepięcia obu narzędzi na `fetchDrawHistory()` — to zmiana zachowania agenta, którą lepiej zrobić świadomie, osobno. |
| **A7** | ⛔ ZABLOKOWANE | `evaluate_distribution` jest tautologiczne dla dużych pul. Sensowna naprawa to policzenie rozkładu sumy dla **losowego podzbioru**, a nie dla średniej puli — to przeprojektowanie narzędzia, nie poprawka. |
| **C4** | 🟨 CZĘŚCIOWO | EuroNumbers (2 z 12) nadal niezaimplementowane. Dodano wyraźne ostrzeżenie w `HOW_TO_PLAY.md`; pełne wsparcie wymaga rozszerzenia modelu kuponu o liczby dodatkowe w całym łańcuchu (generator → optymalizator → raport). |
| **C9** | 🟨 CZĘŚCIOWO | Cenę Mini Lotto skorygowano na 1,25 zł z adnotacją „zweryfikuj w cenniku". Wpisy `Keno` i `MultiMulti` w `GameRegistryService` (stałe `pick`) wymagają decyzji produktowej — obie gry mają zmienną liczbę skreśleń. |
| **D1** | 🟨 CZĘŚCIOWO | Strona repozytorium zrobiona (untrack + `.gitignore` + `.example`). **Rotacja kluczy i ewentualne przepisanie historii pozostają po stronie właściciela** — patrz eskalacja na początku dokumentu. |
| **D4** | 🟨 CZĘŚCIOWO | Lista `FALLBACK_MODELS` udokumentowana wraz z kosztem nieaktualnych wpisów. Automatyczne rozwiązywanie modelu przez `listModels()` z cache'em nie zostało wdrożone — wymaga skonfigurowania puli cache, której projekt jeszcze nie używa (powiązane z E4). |
| **D5** | ⛔ ZABLOKOWANE | Przeniesienie `phpunit` do `require-dev` **nie jest możliwe**: `composer update --lock` nie potrafi rozwiązać drzewa zależności, bo `symfony/*: 8.2.*` wskazuje na gałęzie dev, które się rozjechały (`symfony/framework-bundle 8.2.x-dev requires symfony/cache ^8.2 … not loaded`). Każda zmiana naruszająca hash locka jest niewdrażalna bez pełnego przejścia na nowszy Symfony. `composer.json` przywrócono do stanu z HEAD. Z tego samego powodu **nie usunięto** `minimum-stability: dev` — to ograniczenie jest tu konieczne. |
| **E1** | 🟨 CZĘŚCIOWO | Wspólne pobieranie danych wydzielono do `HistoricalDataProvider`, a wszystkie poprawki z grupy B zastosowano w obu komendach. Pełna ekstrakcja `BetPipelineService` **nie została zrobiona** — 550-linijkowe komendy są mocno splecione z promptami interaktywnymi, a bez testów integracyjnych ryzyko regresji przewyższa zysk. To najlepszy kandydat na następny krok. |
| **E4** | ⛔ ZABLOKOWANE | Brak cache'owania i trwałego składu losowań. `HistoricalDataProvider` jest właściwym miejscem na cache, ale wymaga skonfigurowania puli Symfony Cache i decyzji o TTL — świadomie poza zakresem tej sesji. |

### Zmiany, które NIE są poprawkami błędów

Trzy rzeczy zmieniono świadomie, choć nie były błędami w sensie ścisłym — warto o nich wiedzieć,
bo zmieniają wyniki:

1. **Premie strukturalne w macierzy** (+14 sąsiad / +8 rozstaw zamiast +8 / +12) — zmienia ranking kuponów.
2. **Tryb 7 przestał być aliasem Trybu 8** — kto polegał na starym zachowaniu, dostanie teraz inne kupony.
3. **Instrukcja „Kompresja"** w prompcie `askForRecommendation()` została zastąpiona instrukcją
   *„Pokrycie"*. Poprzednia kazała modelowi **minimalizować** liczbę użytych liczb, co jest
   sprzeczne z celem gry i z filozofią Trybu 8.

---

## Appendix — Reproducing the verified findings

The numeric evidence above was produced by loading the services directly (bypassing the Symfony
container) and exercising them against synthetic inputs. To reproduce inside the container:

```bash
docker compose run --rm app php -r '
  require "vendor/autoload.php";
  $s = new App\Service\StatisticalOptimizerService(
      new App\Service\GameRegistryService(), new Psr\Log\NullLogger());
  print_r($s->calculateGaussianParameters(49, 6));   // -> 106 - 194, not 115-185 or 125-175
  $m = $s->buildPairAffinityMatrix(range(10,24), array_fill_keys(range(10,24), 10));
  echo "neighbour(15,14)=", $m[15][14], "  gap-3(15,18)=", $m[15][18], PHP_EOL;
'
```

Timing and duplicate-coupon checks were run the same way against
`optimizeBetsWithFullCoverage()` and `BetGeneratorService::generateBalancedShorthand()`.
