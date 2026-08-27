# 🔧 Runda 2 napraw — pozycje otwarte z Sekcji 10

> Domknięcie trzech pozycji, które po pierwszej rundzie zostały oznaczone jako
> 🟨 CZĘŚCIOWO / ⛔ ZABLOKOWANE: **A3/A4**, **C4**, **E1**.
>
> **Data:** 2026-08-27 · **Weryfikacja:** Docker, PHP 8.5.6 — **55 testów, 3247 asercji, zielone**

---

## Podsumowanie

| # | Było | Jest |
|---|---|---|
| **A3** | `fetch_overdue_stats` zwracał **średni odstęp** (`totalDraws / occurrences`) — ranking tożsamy z listą liczb zimnych | Liczy **rzeczywistą zaległość**: ile losowań minęło od ostatniego wystąpienia (`0` = ostatnie losowanie) |
| **A4** | `fetch_recent_draws` zwracał podsumowanie częstotliwości i **zawsze** `fetched_draws_count = 4` | Zwraca **prawdziwe losowania**, od najnowszego, z `draws_ago` i liczbami dodatkowymi |
| **A1'** | `fetch_pair_co_occurrence` (narzędzie agenta) nadal liczyło `sqrt(f(A)·f(B))` | Liczy **rzeczywiste współwystępowanie** par z historii |
| **C4** | EuroNumbers (2 z 12) **niezaimplementowane** — kupon EuroJackpot był niegrywalny | Pełny kupon: 5 głównych + **2 liczby Euro**, dobierane po realnym współwystępowaniu |
| **E1** | Dyspozytor trybów w **dwóch kopiach** (345 identycznych linii), nietestowalny bez terminala | `BetPipelineService` — jedno źródło prawdy, **12 testów jednostkowych** |

---

## Nieoczekiwane odkrycie: HTTP 429 wymusił trwały cache

Przy przepinaniu narzędzi na historię losowań okazało się, że LOTTO OpenAPI
**nie ma endpointu z zakresem dat** dla wyników. Jedyny działający to
`by-date-per-game` dla **pojedynczej daty**, więc pobranie 100 losowań to
100 zapytań — a to bardzo szybko kończy się odpowiedzią **HTTP 429**:

```
[429] by-date-per-game?gameType=Lotto&drawDate=2026-08-20  -> Too Many Requests
```

Pierwsza wersja próbowała pobrać 60–100 dat naraz i **cała historia wracała pusta**,
co po cichu degradowało analizę do heurystyki. Dlatego E4 (cache/trwałość), wcześniej
oznaczone jako ⛔ ZABLOKOWANE, **przestało być opcjonalne** i zostało zrobione:

* **`DrawHistoryProvider`** — trwały, przyrostowy magazyn w `var/draw-history/{gra}.json`.
  Zapisuje też **puste** daty („sprawdzone, brak losowania"), więc nie odpytuje ich ponownie.
* **Limit 25 nowych dat na uruchomienie**, paczki po 8 równoległych zapytań.
* **Reakcja na 429**: przerwanie dociągania, praca na tym, co już jest, i jawne
  `rate_limited: true` w wyniku.
* **Nazwany wolumen `lotto_var`** w `docker-compose.yml` — poprzedni anonimowy wolumen
  `- /app/var` gubił cache przy każdym `docker compose run --rm`.

Zmierzone zachowanie:

```
run 1 (zimny):  draws=24  from_cache=0   fetched=25  missing=5
run 2 (ciepły): draws=29  from_cache=25  fetched=5   missing=0
run 3:          draws=29  from_cache=30  fetched=0   missing=0   (0.0s, zero zapytań)
```

Przy wyczerpanym limicie degradacja jest czysta, bez wyjątku:

```
EuroJackpot  draws=8  from_cache=8  fetched=0  rate_limited=true  missing=22
```

> Po drodze naprawiono realny błąd: niezużyta odpowiedź 4xx rzuca `ClientException`
> **z destruktora** Symfony HttpClient i wywracała proces już po zakończeniu pracy.
> Odpowiedzi pomijane po 429 są teraz jawnie anulowane.

---

## A3 — rzeczywista zaległość

`FetchOverdueStatsTool` liczy teraz indeks pierwszego trafienia w historii
posortowanej od najnowszego losowania:

```
OVERDUE  source=draw_history  estimate=false  draws=59
  most overdue: 2, 3, 13, 35, 30, 32
  sample: nr 2 -> 28 losowan temu
```

Ścieżka awaryjna (brak historii) nadal zwraca średni odstęp, ale pod innymi kluczami
(`average_gap_between_draws`, `coldest_numbers`) i z `is_estimate: true` oraz ostrzeżeniem,
że ranking jest równoważny liście liczb zimnych.

## A4 — prawdziwe losowania

```json
{ "source": "draw_history", "fetched_draws_count": 3,
  "draws": [ { "draws_ago": 0, "numbers": [18,25,28,29,33,41] }, ... ] }
```

Dla gier z drugim bębnem dochodzi `special_numbers`.

## C4 — EuroJackpot ma wreszcie liczby Euro

Nowy `ExtraNumbersGenerator` obsługuje każdą grę z `extra > 0`
(EuroJackpot 2/12, EkstraPensja/Premia 1/4). Zestawy nie powtarzają się, dopóki są
wolne kombinacje, i preferują pary o realnym współwystępowaniu.

```
Liczby dodatkowe: 2 z 12 — 8 różnych zestawów z 66 możliwych (źródło: rzeczywiste współwystępowanie)

 Ranking  Liczby               Dodatkowe  Suma  Parz  Synergy  Profil
 #1        5, 34, 35, 42, 46   5, 7       162   2:3   697.5    [★ TOP SYNERGIA]
 #2        4, 10, 17, 36, 37   5, 6       104   2:3   653.0    [Mocna Synergia]
```

## E1 — jeden dyspozytor trybów zamiast dwóch kopii

`BetPipelineService` + `BetPipelineRequest` + `BetPipelineResult`. Serwis **nie robi I/O
i nie zadaje pytań** — komenda zbiera parametry (opcje albo prompty), serwis zwraca
kupony i ostrzeżenia.

| Plik | Przed | Po |
|---|---|---|
| `LottoGeneratorCommand.php` | 613 linii | **427** |
| `LottoTuiCommand.php` | 753 linie | **509** |
| Wspólne linie między komendami | 345 | 306 |

Wszystkie 8 trybów zweryfikowane w obu komendach:

```
mode 1..8 (generator): 12, 12, 12, 12, 12, 10, 12, 12 kuponów
tui mode 1, 2, 5, 8:   8, 8, 8, 8 kuponów
```

(mode 6 → 10, bo przy 5 bankierach po 3 na kupon $\binom{5}{3}=10$ to twardy sufit —
i jest raportowany jako niedobór, nie ukrywany.)

Przy okazji naprawiono: własny prompt TUI (`promptInput`) **ignorował `--no-interaction`**
i blokował tryby 2 i 5 w skryptach.

---

## Co nadal otwarte

| # | Status | Powód |
|---|---|---|
| **A7** | ⛔ | `evaluate_distribution` jest tautologiczne dla dużych pul. Naprawa to policzenie rozkładu sumy dla **losowego podzbioru**, czyli przeprojektowanie narzędzia. |
| **C9** | 🟨 | Wpisy `Keno` i `MultiMulti` w rejestrze mają stałe `pick`, a obie gry mają zmienną liczbę skreśleń — decyzja produktowa. |
| **D1** | 🟨 | **Rotacja kluczy nadal po Twojej stronie.** Klucze są w historii Git i na GitHubie. |
| **D5** | ⛔ | `phpunit` → `require-dev` niewykonalne: `composer update --lock` nie rozwiązuje drzewa (`symfony/*: 8.2.*` wskazuje na rozjechane gałęzie dev). |
| **E1'** | 🟨 | Renderowanie Okna Statystycznego jest wciąż zduplikowane (~306 wspólnych linii). Kolejny kandydat: `StatsWindowRenderer`. |

### Uwaga o limicie API

Pierwsze uruchomienie dla nowej gry pobiera do 25 dat. Pełne 50–100 losowań
zbierze się po kilku uruchomieniach albo po wywołaniu komendy kilka razy —
to świadomy kompromis wymuszony limitem 429. Cache jest trwały, więc koszt
ponosi się raz.

---

## 🔧 Runda 3 (2026-08-27): A7, C9, StatsWindowRenderer

Domknięcie trzech ostatnich pozycji otwartych. **66 testów, 3415 asercji, zielone.**

### A7 — `evaluate_distribution` przestało być tautologiczne

Stara wersja liczyła sumę podzbioru jako `poolAverage * pick`, czyli **dokładnie
średnią rozkładu**. Dla każdej symetrycznej puli wynik zawsze wypadał „optymalnie".
Margines był arbitralny (`pickCount * 6`) i niezgodny z sigma liczonym w optymalizatorze.

Teraz narzędzie liczy **rozkład sumy losowego podzbioru z podanej puli** (średnia
ORAZ odchylenie, z poprawką na populację skończoną) i raportuje, **jaka część losowych
zakładów z tej puli trafi w prawdopodobny przedział sumy**. Przedział pochodzi
z `calculateGaussianParameters()` — jedno źródło prawdy.

Przypadek, który obnaża starą wersję:

| Pula | Średnia sumy | Sigma | % w normie |
|---|---|---|---|
| pełny bęben 1-49 | 150,0 | 32,79 | **82,0%** |
| skrajna 1-8 + 42-49 | **150,0** | 41,26 | **71,4%** |
| tylko niskie 1-20 | 63,0 | 12,12 | 0% |
| tylko wysokie 30-49 | 237,0 | 12,12 | 0% |

Pula skrajna ma **identyczną średnią** jak pełny bęben, więc stara wersja uznałaby ją
za optymalną. Nowa pokazuje, że jest wyraźnie gorsza. Dochodzi też `verdict`,
`empty_decades` i `decade_limit_blocks_generation`.

### C9 — gry o zmiennej liczbie skreśleń

**Parametry gier zweryfikowano wobec LOTTO OpenAPI** (zakres liczb zwracanych przez
`numbers-frequency`). Wynik: **wszystkie wartości `from` i `extra_from` w rejestrze były
poprawne**, łącznie z `Keno` 70 — wcześniejsza sugestia w tym dokumencie, że wpis Keno
„wygląda na błędny", była nieuzasadniona i zostaje wycofana.

Realny problem był węższy: `pick` był stały dla **Multi Multi** i **Keno**, gdzie gracz
sam wybiera 1-10 liczb. Rejestr ma teraz `pick_min` / `pick_max` oraz
`isVariablePick()`, `getPickRange()`, `resolvePick()`.

Warunek `if ($gameType === 'MultiMulti')` zniknął z trzech komend — obsługa jest
generyczna. Dodano opcję `--pick`, która działa **wyłącznie** tam, gdzie gra na to
pozwala:

```
MultiMulti --pick=6 -> kupon 6 liczb
MultiMulti --pick=3 -> kupon 3 liczb
Keno       --pick=5 -> kupon 5 liczb
Lotto      --pick=3 -> kupon 6 liczb   (zignorowane, zasady gry wygrywają)
```

> Uwaga: `--pool-size` **nie służy już** do ustawiania liczby skreśleń. Wcześniej ta sama
> opcja oznaczała jednocześnie „ile skreśleń" i „jak duża pula", przez co
> `--pool-size=6` dawało pulę równą liczbie skreśleń, czyli jeden możliwy kupon.

> `ZakladySpecjalne` nie zwraca danych z API (brak losowań w oknie) — wpis pozostaje,
> ale nie da się go zweryfikować.

### StatsWindowRenderer — ostatni duplikat wydzielony

Bloki renderujące w `LottoGeneratorCommand` i `LottoTuiCommand` były **identyczne
co do znaku** (79 linii, `diff` = 0 różnic).

| Plik | Runda 2 | Runda 3 |
|---|---|---|
| `LottoGeneratorCommand.php` | 427 | **370** |
| `LottoTuiCommand.php` | 509 | **455** |
| Wspólne linie | 306 | **250** |

Licząc od stanu wyjściowego: 613 → 370 i 753 → 455 linii.

Przy okazji obie komendy **zyskały** to, co wcześniej miał tylko `app:lotto-stats`:
źródło macierzy par (rzeczywiste współwystępowanie vs heurystyka) oraz uwagę
metodologiczną o samozwrotności benchmarku.

### Nadal otwarte

| # | Status | Powód |
|---|---|---|
| **D1** | 🟨 | Rotacja kluczy po stronie właściciela. Historia Git została wyczyszczona (`git filter-repo`, force-push), ale GitHub trzyma stare obiekty do GC. |
| **D5** | ⛔ | `phpunit` → `require-dev` niewykonalne: `composer update --lock` nie rozwiązuje drzewa (`symfony/*: 8.2.*` wskazuje na rozjechane gałęzie dev). |
