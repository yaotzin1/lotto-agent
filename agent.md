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
| `app:lotto-stride` | `src/Command/LottoStrideCommand.php` | Generator stroboskopowy: pobiera kotwice co N losowań wstecz (np. N=257) + sąsiadów i generuje zakłady. |
| `app:lotto-backtest` | `src/Command/LottoBacktestCommand.php` | Backtest kroczeń (stride sampling N) i sąsiadów na pełnej historii 7,399 losowań. |
| `app:lotto-generator` | `src/Command/LottoGeneratorCommand.php` | Zamienia pulę (ręczną lub z AI) na kupony w jednym z 8 trybów. |
| `app:lotto-stats` | `src/Command/LottoStatsCommand.php` | Okno statystyczne: rozwodnienie, macierz par, rozkład sum, ranking kuponów. |
| `app:lotto-tui` | `src/Command/LottoTuiCommand.php` | Interaktywny odpowiednik generatora (obsługuje AI, Stride i Manual). |
| `app:gemini-models` | `src/Command/GeminiModelsCommand.php` | Wypisuje modele dostępne dla klucza API. |

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
