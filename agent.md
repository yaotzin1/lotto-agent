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
| `app:lotto-generator` | `src/Command/LottoGeneratorCommand.php` | Zamienia pulę (ręczną lub z AI) na kupony w jednym z 9 trybów. |
| `app:lotto-stats` | `src/Command/LottoStatsCommand.php` | Okno statystyczne: rozwodnienie, macierz par, rozkład sum, ranking kuponów, opcjonalny komentarz AI (`--ai`). |
| `app:lotto-tui` | `src/Command/LottoTuiCommand.php` | Interaktywny odpowiednik generatora (obsługuje AI, Stride i Manual). |
| `app:ai-models` | `src/Command/AiModelsCommand.php` | Katalog modeli AI dla wszystkich dostawców (Gemini, Claude, OpenAI, DeepSeek) z zaleceniami statystycznymi i trybem `--live`. |
| `app:gemini-models` | `src/Command/GeminiModelsCommand.php` | (Legacy) Wypisuje modele dostępne dla klucza Google Gemini API. |

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

## Konfiguracja środowiska

Aplikacja konfiguruje dostęp do modeli i usług za pośrednictwem zmiennych środowiskowych. Skopiuj `.env.dev.example` do `.env.dev` i uzupełnij klucze:
```bash
cp .env.dev.example .env.dev
```

**Nie commituj `.env` ani `.env.dev`** — oba pliki są w `.gitignore` i chronią Twoje tokeny przed wyciekiem.

| Zmienna | Wymagana | Opis i zastosowanie |
|---|---|---|
| `GEMINI_API_KEY` | dla Gemini | Klucz API Google AI Studio ([uzyskaj tutaj](https://aistudio.google.com/app/apikey)). Wysyłany nagłówkiem `x-goog-api-key`. |
| `ANTHROPIC_API_KEY` | dla Claude | Klucz API Anthropic Console ([uzyskaj tutaj](https://console.anthropic.com/)). Wysyłany nagłówkiem `x-api-key`. |
| `OPENAI_API_KEY` | dla OpenAI | Klucz API OpenAI Platform ([uzyskaj tutaj](https://platform.openai.com/api-keys)). Wysyłany nagłówkiem `Authorization: Bearer`. |
| `DEEPSEEK_API_KEY` | dla DeepSeek | Klucz API DeepSeek Platform ([uzyskaj tutaj](https://platform.deepseek.com/)). |
| `DEEPSEEK_BASE_URL` | opcjonalna | Adres bazowy API DeepSeek (domyślnie `https://api.deepseek.com/v1`). |
| `AI_PROVIDER` | opcjonalna | Domyślny dostawca w aplikacji: `gemini` (domyślnie), `claude`, `openai`, `deepseek`. |
| `AI_MODEL` | opcjonalna | Nadpisanie domyślnego modelu (np. `claude-3-opus-20240229`, `gpt-4o`, `deepseek-reasoner`). |
| `LOTTO_API_KEY` | do historii live | Klucz API LOTTO OpenAPI (`developers.lotto.pl`). |
| `APP_SECRET` | tak | Standardowy sekret aplikacji Symfony. |

---

## Architektura Multi-Provider LLM

Warstwa integracji z modelami LLM (`src/Service/Llm/`) została zaprojektowana według wzorca strategii i fabryki z uniwersalnym interfejsem:

```
                          ┌────────────────────────┐
                          │   LlmClientResolver    │  (odczytuje --provider / -pv,
                          └───────────┬────────────┘   --model / -md lub .env)
                                      │
                         ┌────────────┴────────────┐
                         ▼                         ▼
             ┌──────────────────────┐   ┌──────────────────────┐
             │  LlmClientInterface  │   │     SchemaHelper     │  (normalizacja
             └──────────┬───────────┘   └──────────────────────┘   JSON Schema)
                        │
      ┌─────────────────┼──────────────────┬─────────────────┐
      ▼                 ▼                  ▼                 ▼
┌──────────────┐ ┌──────────────┐ ┌────────────────┐ ┌──────────────┐
│GeminiApiClient│ │AnthropicApi  │ │ OpenAiApiClient│ │OpenAiApiClient│
│ (Google API) │ │Client(Claude)│ │    (OpenAI)    │ │  (DeepSeek)  │
└──────────────┘ └──────────────┘ └────────────────┘ └──────────────┘
```

### Kluczowe komponenty:
1. **`LlmClientInterface`**:
   - `chatWithTools(array $messages, array $tools, ?string $model = null): array`: Uniwersalny silnik rozmowy wieloturowej z obsługą Function Calling (używany przez agenta ReAct).
   - `askForPool(string $prompt, ?string $model = null): ?array`: Generowanie puli kandydującej w formacie JSON `{"reasoning": "...", "selected_pool": [...]}`.
   - `askForRecommendation(string $prompt, ?string $model = null): ?string`: Generowanie narracyjnego komentarza i analizy strategicznej dla Okna Statystycznego (`app:lotto-stats --ai`).
   - `getProviderName()` oraz `getDefaultModel()`: Metadane dostawcy.
2. **`SchemaHelper`**:
   - Narzędzia w repozytorium deklarują typy parametrów wielkimi literami (np. `OBJECT`, `ARRAY`, `STRING`).
   - `SchemaHelper::normalize()` automatycznie rekursywnie mapuje je na małe litery (`object`, `array`, `string`), zapewniając 100% zgodności ze specyfikacją JSON Schema wymaganą przez Anthropic Claude oraz OpenAI / DeepSeek.
3. **Pętla ReAct w `ReActAgentService`**:
   - Całkowicie uniezależniona od specyfiki pojedynczego API.
   - Prowadzi dialog z modelem, przekazując wyniki wywołań narzędzi zgodnie z protokołem dostawcy (Claude: bloki `tool_use`/`tool_result`, OpenAI: `tool_calls` i rola `tool`, Gemini: `functionCall` i rola `user` z `functionResponse` oraz zachowywaniem bloków `thought: true`).

---

## Katalog modeli i zalecenia w analizie ilościowej / statystycznej

Modele LLM różnią się architekturą i specyfikacją. W analizie statystycznej, kombinatoryce i weryfikacji hipotez kluczowe znaczenie ma **odporność na halucynacje liczbowe, precyzja wywoływania narzędzi oraz głębokie wnioskowanie logiczne**.

### Zestawienie modeli

| Dostawca (`--provider`) | Model ID (`--model`) | Nazwa handlowa | Rola i siła w analizie statystycznej |
|---|---|---|---|
| **`claude`** | `claude-3-opus-20240229` | **Claude 3 Opus** | **Złoty standard w analizie ilościowej i statystyce.** Bezbłędna synteza złożonych rozkładów, precyzyjne wnioskowanie dedukcyjne, zerowa skłonność do zmyślania metryk. |
| | `claude-3-7-sonnet-20250219` *(domyślny)* | **Claude 3.7 Sonnet** | **Hybrydowe wnioskowanie.** Najwyższa precyzja wywoływania narzędzi (Function Calling), perfekcyjna generacja struktur JSON. |
| | `claude-3-5-sonnet-20241022` | **Claude 3.5 Sonnet v2** | Wzorcowy model inżynierski, doskonały do rygorystycznej weryfikacji kuponów i filtrów kombinatorycznych. |
| | `claude-3-5-haiku-20241022` | **Claude 3.5 Haiku** | Błyskawiczny, lekki model do jednosesyjnych podsumowań i szybkiego generowania puli. |
| **`openai`** | `gpt-4o` *(domyślny)* | **GPT-4o** | Wszechstronny model flagowy z natywnym wsparciem JSON Schema i precyzyjnym Function Calling. |
| | `o3-mini` | **OpenAI o3-mini** | Model STEM z rozszerzonym łańcuchem myślowym (Chain-of-Thought) zoptymalizowany pod kombinatorykę i logikę. |
| | `gpt-4o-mini` | **GPT-4o Mini** | Ekonomiczny model o bardzo niskim opóźnieniu do testów wsadowych. |
| | `gpt-4-turbo` | **GPT-4 Turbo** | Stabilny model o dużej precyzji weryfikacyjnej. |
| **`deepseek`** | `deepseek-reasoner` | **DeepSeek-R1** | **Dedykowany model matematyczny CoT.** Generuje rozbudowane łańcuchy dowodowe przed podjęciem decyzji, rygorystycznie testując hipotezy o trendach. |
| | `deepseek-chat` *(domyślny)* | **DeepSeek-V3** | Wysoce wydajny, zoptymalizowany model do szybkiej analizy wielkich zbiorów danych. |
| **`gemini`** | `gemini-3.7-flash` *(domyślny)* | **Gemini 3.7 Flash** | Najnowszy model Google o doskonałym stosunku szybkości do głębi myślenia, natywne wsparcie w pętli agenta. |
| | `gemini-2.5-pro` | **Gemini 2.5 Pro** | Głębokie wnioskowanie analityczne, idealne dla dużych puli liczb (np. bębna 49 liczb). |
| | `gemini-3.8-flash` | **Gemini 3.8 Flash** | Eksperymentalny model ultra-szybki. |
| | `gemini-2.5-flash` | **Gemini 2.5 Flash** | Stabilny, produkcyjny model ekonomiczny. |

### Automatyczne łańcuchy awaryjne (Fallback Chains)
Wszystkie klienty implementują mechanizm kaskady awaryjnej. W przypadku niedostępności modelu (kod błędu HTTP 404) lub wyczerpania limitu zapytań (HTTP 429), zapytanie jest automatycznie ponawiane z kolejnym modelem z listy fallback:
- **Claude:** `claude-3-7-sonnet-20250219` ➔ `claude-3-5-sonnet-20241022` ➔ `claude-3-opus-20240229` ➔ `claude-3-5-haiku-20241022`
- **OpenAI:** `gpt-4o` ➔ `gpt-4o-mini` ➔ `gpt-4-turbo`
- **DeepSeek:** `deepseek-chat` ➔ `deepseek-reasoner`
- **Gemini:** `gemini-3.7-flash` ➔ `gemini-3.6-flash` ➔ `gemini-3.5-flash` ➔ `gemini-2.5-flash` ➔ `gemini-flash-latest` ➔ `gemini-pro-latest` ➔ `gemini-2.5-pro`

---

## Pełny przewodnik po komendach CLI

Każda komenda korzystająca z AI przyjmuje opcje `--provider` (skrót `-pv`) oraz `--model` (skrót `-md`).

### 1. Przeglądanie katalogu modeli (`app:ai-models`)
Wyświetla tabelę zarejestrowanych modeli, ich architekturę, zastosowanie i domyślne konfiguracje:
```bash
# Wyświetlenie wszystkich zarejestrowanych modeli:
docker compose run --rm app php bin/console app:ai-models

# Filtrowanie według dostawcy:
docker compose run --rm app php bin/console app:ai-models --provider=claude
docker compose run --rm app php bin/console app:ai-models --provider=openai
docker compose run --rm app php bin/console app:ai-models --provider=deepseek
docker compose run --rm app php bin/console app:ai-models --provider=gemini

# Odpytanie na żywo zdalnego API Gemini o listę aktywnych modeli na Twoim koncie:
docker compose run --rm app php bin/console app:ai-models --provider=gemini --live
```

### 2. Autonomiczny ReAct Agent (`app:lotto-agent`)
Agent uruchamia wieloturową pętlę ReAct, analizuje statystyki za pomocą narzędzi i zwraca optymalną pulę liczb:
```bash
# Uruchomienie z zalecanym modelem ilościowym Claude 3 Opus:
docker compose run --rm app php bin/console app:lotto-agent --provider=claude --model=claude-3-opus-20240229 --strategy=syndicate

# Uruchomienie z modelem hybrydowym Claude 3.7 Sonnet:
docker compose run --rm app php bin/console app:lotto-agent --provider=claude --model=claude-3-7-sonnet-20250219

# Uruchomienie z OpenAI GPT-4o:
docker compose run --rm app php bin/console app:lotto-agent --provider=openai --model=gpt-4o

# Uruchomienie z DeepSeek-R1:
docker compose run --rm app php bin/console app:lotto-agent --provider=deepseek --model=deepseek-reasoner

# Użycie flag skróconych (-pv oraz -md):
docker compose run --rm app php bin/console app:lotto-agent -pv claude -md claude-3-opus-20240229
```

### 3. Okno Statystyczne i Komentarz AI (`app:lotto-stats`)
Generuje statystyki rozwodnienia, rozkładu Gaussa i macierzy par, z opcjonalną analizą strategiczną AI (`--ai`):
```bash
# Analiza statystyczna z komentarzem Claude Opus:
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --ai --provider=claude --model=claude-3-opus-20240229

# Analiza statystyczna z modelem matematycznym DeepSeek-R1:
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --ai --provider=deepseek --model=deepseek-reasoner

# Standardowa analiza bez AI (wyłącznie silnik matematyczny):
docker compose run --rm app php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100
```

### 4. Generator Systemowy z pulą AI (`app:lotto-generator`)
Generuje zakłady w jednym z 9 trybów kombinatorycznych, pobierając pulę liczb bezpośrednio od wybranego modelu AI:
```bash
# Generowanie z puli Claude 3.7 Sonnet w trybie fraktalnym (Mode 5):
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --provider=claude --model=claude-3-7-sonnet-20250219 --mode=5

# Generowanie z puli OpenAI GPT-4o w trybie optymalizacji statystycznej (Mode 7):
docker compose run --rm app php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --provider=openai --model=gpt-4o --mode=7 --bets=50
```

### 5. Interfejs Terminalowy TUI (`app:lotto-tui`)
W interfejsie TUI możesz wskazać dostawcę i model flagami startowymi:
```bash
docker compose run --rm app php bin/console app:lotto-tui --provider=claude --model=claude-3-7-sonnet-20250219
```
W menu TUI wybierz `[1] Generuj pulę przez AI` — aplikacja automatycznie użyje wskazanego dostawcy.
