# Lotto Agent - AI Integration Guide

Ten dokument opisuje warstwę AI aplikacji: agenta ReAct opartego o Google Gemini oraz
narzędzia statystyczne, z których agent korzysta.

## Architektura

Agent działa jako pętla ReAct (*Reasoning + Acting*) w `src/Service/ReActAgentService.php`:

1. Model dostaje deklaracje narzędzi (`ToolRegistry::getGeminiFunctionDeclarations()`).
2. Model wywołuje narzędzia; wyniki wracają jako `functionResponse`.
3. Po maksymalnie 8 turach model ma zwrócić JSON z kluczem `selected_pool`.

**Kontrakt wyjścia jest ścisły.** Akceptowany jest wyłącznie poprawny JSON:

```json
{ "reasoning": "...", "selected_pool": [2, 7, 12, 24, 38] }
```

Jeżeli model go nie zwróci, wynik jest oznaczony jako `is_fallback: true`, a komenda
wypisuje wyraźne ostrzeżenie. Pula z trybu awaryjnego **nie jest** rekomendacją
statystyczną i nigdy nie jest tak opisywana.

## Komendy

| Komenda | Plik | Co robi |
|---|---|---|
| `app:lotto-agent` | `src/Command/LottoAgentCommand.php` | Uruchamia pętlę ReAct i zwraca **pulę kandydującą**. Nie generuje kuponów. |
| `app:lotto-stride` | `src/Command/LottoStrideCommand.php` | Generator stroboskopowy: pobiera kotwice co N losowań wstecz (np. N=257) + sąsiadów i generuje zakłady. Świadomy gry (`--game`), przed zbudowaniem puli domyka archiwum z LOTTO OpenAPI (`--no-refresh` wyłącza). |
| `app:lotto-archive` | `src/Command/LottoArchiveCommand.php` | Buduje i pokazuje archiwum losowań. `--status` pokazuje stan bez sieci, `--game=X --days=N` domyka jedną grę, `--all-games` zasila wiele gier jednym zapytaniem na dzień, `--repair` pobiera ponownie daty zapisane niekompletnie. |
| `app:lotto-backtest` | `src/Command/LottoBacktestCommand.php` | Backtest kroczeń (stride sampling N) i sąsiadów na pełnym archiwum wybranej gry (`--game`). Rozkład hipergeometryczny liczony z zakresu liczb i liczby losowanych kul TEJ gry. |
| `app:lotto-generator` | `src/Command/LottoGeneratorCommand.php` | Zamienia pulę (ręczną lub z AI) na kupony w jednym z 8 trybów. |
| `app:lotto-stats` | `src/Command/LottoStatsCommand.php` | Okno statystyczne: rozwodnienie, macierz par, rozkład sum, ranking kuponów. |
| `app:lotto-tui` | `src/Command/LottoTuiCommand.php` | Interaktywny odpowiednik generatora (obsługuje AI, Stride i Manual). |
| `app:gemini-models` | `src/Command/GeminiModelsCommand.php` | Wypisuje modele dostępne dla klucza API. |

## Skąd biorą się losowania

| Warstwa | Plik | Rola |
|---|---|---|
| Oficjalne API | `src/Service/LottoApiClient.php` | LOTTO OpenAPI (`developers.lotto.pl`). Wyniki wyłącznie **dla pojedynczej daty**: `by-date-per-game` (jedna gra) albo `by-date` (wszystkie gry naraz). Endpointu z zakresem dat nie ma — sprawdzone w specyfikacji `swagger/open-api-v1/swagger.json`. |
| Cache API | `src/Service/DrawHistoryProvider.php` | Przyrostowy magazyn kluczowany datą (`var/draw-history/<Gra>.json`). Pobiera **sekwencyjnie**, a HTTP 429 ponawia po wycofaniu. W trybie interaktywnym budżet to 25 nowych dat; backfill podaje własny. |
| Archiwum chronologiczne | `src/Service/DrawArchiveService.php` | Rzutuje cache API na listę uporządkowaną w czasie, scala ją z archiwum na dysku (`data/lotto_draws.json` dla Lotto, `data/draws/<Gra>.json` dla reszty) i dopisuje nowe losowania. Zgłasza też, o ile losowań archiwum jest do tyłu. |

Kroczenie adresuje losowania **pozycją** (T-N, T-2N...), więc brakujące losowanie przesuwa
wszystkie kotwice naraz — dlatego archiwum jest domykane przed zbudowaniem puli, a jego
nieaktualność jest wypisywana jako ostrzeżenie, a nie przemilczana.

Limit API dotyczy **współbieżności, nie liczby zapytań** — pomiar na żywym API: 80 zapytań
po kolei przechodzi w 66 s bez jednego 429, 8 równoległych dostaje 429 przy ósmym, a po
wejściu w limit API wraca po ok. 22 s. Wcześniejsza wersja strzelała ósemkami równolegle
i przerywała przebieg po pierwszym 429, przez co historia głębsza niż kilkadziesiąt losowań
nigdy nie powstawała. Głęboką historię dowolnej gry buduje dziś `app:lotto-archive`.

Nie każda gra ma jedno losowanie dziennie: Keno i Szybkie 600 mają ich po ok. 261. Zapytanie
szło z `size=50`, więc 211 z 261 losowań Keno przepadało, a data i tak zostawała oznaczona
jako sprawdzona. Rozmiar strony obejmuje dziś pełny dzień, dla znanych sobie dat API jest
źródłem prawdy przy scalaniu, a `--repair` pobiera ponownie daty zapisane niekompletnie.

Głęboką historię Lotto od 1957 zasiewa dodatkowo `scripts/parse_history.php` z ręcznie
pobranego pliku tekstowego. Skrypt **scala**, a nie nadpisuje: nie kasuje losowań z API.

> Nie istnieje `LottoCommand` — wcześniejsza wersja tego pliku opisywała klasę,
> której nigdy nie było w repozytorium.

## Narzędzia agenta (`src/Service/AgentTools/`)

| Narzędzie | Źródło danych | Uwagi |
|---|---|---|
| `fetch_hot_cold_stats` | `numbers-frequency` | Częstotliwości pojedynczych liczb. |
| `fetch_neighbours_analysis` | `numbers-frequency` | ⚠️ „Kotwice" to liczby **najczęstsze w oknie**, a nie liczby z ostatniego losowania. |
| `fetch_overdue_stats` | `numbers-frequency` | ⚠️ Zwraca **średni odstęp** (`totalDraws / occurrences`), a nie czas od ostatniego wystąpienia. Ranking jest równoważny liście liczb zimnych. |
| `fetch_pair_co_occurrence` | `numbers-frequency` | ⚠️ Heurystyka `sqrt(f(A)*f(B))` — **nie** zawiera informacji o parach. Prawdziwe współwystępowanie liczy `StatisticalOptimizerService::buildPairAffinityMatrix()` na podstawie `fetchDrawHistory()`. |
| `fetch_recent_draws` | `numbers-frequency` | ⚠️ Zwraca podsumowanie częstotliwości, nie listę losowań. |
| `evaluate_candidate_pool` | lokalnie | Parzystość, suma, rozkład niska/wysoka. |
| `evaluate_distribution` | lokalnie | Rozkład dekadowy i pozycja sumy na krzywej. |
| `test_system_coverage` | lokalnie | ⚠️ Gwarancje są **warunkowe**: zakładają, że wszystkie wylosowane liczby leżą w podanej puli. |

Pozycje oznaczone ⚠️ to znane rozbieżności między opisem narzędzia a tym, co faktycznie
liczy — szczegóły i status naprawy w [docs/REVIEW.md](docs/REVIEW.md), grupa A.

## Konfiguracja

```env
GEMINI_API_KEY=...
LOTTO_API_KEY=...
```

Skopiuj `.env.dev.example` do `.env.dev` i uzupełnij klucze.
**Nie commituj `.env` ani `.env.dev`** — oba są w `.gitignore`.

Klucz Gemini jest wysyłany nagłówkiem `x-goog-api-key` (nie w query stringu),
a weryfikacja certyfikatów TLS jest włączona dla wszystkich połączeń wychodzących.

## Uruchamianie

```bash
docker compose run --rm app php bin/console app:lotto-agent --game=Lotto --strategy=syndicate --sessions=50
docker compose run --rm app sh          # powłoka w kontenerze
docker compose run --rm app php vendor/bin/phpunit
```

## Wybór modelu

`GeminiApiClient::FALLBACK_MODELS` to statyczna lista modeli próbowanych po kolei.
Odpowiedź 404 (model nie istnieje) jest traktowana jak błąd przejściowy i powoduje
przejście do kolejnej pozycji, co przy nieaktualnej liście oznacza zmarnowane zapytania.
Aktualną listę modeli dla danego klucza sprawdzisz komendą:

```bash
docker compose run --rm app php bin/console app:gemini-models
```
