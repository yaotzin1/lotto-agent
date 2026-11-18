# Instrukcja Aplikacji Desktopowej — Lotto Agent

Przewodnik po funkcjach interfejsu graficznego **Lotto Agent Desktop** (Material UI + `@apsw/gridwright` + sidecar PHP 8.5 / Symfony 8.2).

---

## 1. Zakładki aplikacji

1. **Generator i Optymalizator** — główny panel doboru puli liczb, wyboru trybu systemu (1–9), generowania kuponów oraz analizy rozwodnienia i krzywej Gaussa.
2. **Agent AI ReAct** — autonomiczny agent wieloetapowy (Gemini, Claude, OpenAI, DeepSeek), który korzysta z narzędzi statystycznych (częstotliwości, pary, kliki) i proponuje pulę kandydatów.
3. **Archiwum i Ustawienia** — konfiguracja kluczy API (zapisywanych bezpiecznie w `.env.desktop` w katalogu App-Data systemu) oraz synchronizacja lokalnych archiwów losowań z LOTTO OpenAPI.
4. **Logi** — podgląd zdarzeń UI/API na żywo oraz wpisów z backendu Symfony (`var/log/*.log`) z filtrowaniem po poziomie (`INFO`, `WARNING`, `ERROR`) i wyszukiwarką.
5. **Podręcznik / Jak grać** — wbudowana baza wiedzy z dokumentacją aplikacji, strategiami gry i przewodnikiem matematycznym.

---

## 2. Metody doboru puli (Pool Mode)

- **Manual** — klikasz liczby bezpośrednio na siatce bębna albo wpisujesz je ręcznie po przecinku (np. `1, 5, 12, 18, 25, 33`) lub wpisujesz `all`, aby zaznaczyć cały bęben.
- **AI** — przed wygenerowaniem kuponów uruchamiany jest Agent ReAct, który analizuje historię losowań i automatycznie dobiera pulę liczb.
- **Decades** — automatycznie dobiera pulę równomiernie rozłożoną na wszystkie dekady (1–10, 11–20, 21–30 itd.).

---

## 3. Jak zacząć krok po kroku

1. Przejdź do **Archiwum i Ustawienia** i opcjonalnie wpisz `LOTTO_API_KEY` (do pobierania aktualnych losowań) oraz klucz wybranego dostawcy AI (`GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` lub `DEEPSEEK_API_KEY`).
   - *Wskazówka:* Tryby matematyczne i statystyczne działają w 100% lokalnie bez żadnych kluczy API, korzystając z wbudowanego archiwum `data/lotto_draws.json`.
2. W zakładce **Generator i Optymalizator** wybierz grę (np. *Lotto*, *Mini Lotto*, *EuroJackpot*, *Multi Multi*).
3. Wybierz metodę doboru puli (`Manual`, `AI` lub `Decades`) i zaznacz co najmniej tyle liczb, ile wynosi losowanie w danej grze.
4. Wybierz **Tryb systemu / optymalizacji (1–9)** oraz liczbę kuponów, a następnie kliknij:
   - **Generuj zakłady** — aby wygenerować kupony w wybranym trybie systemu.
   - **Raport rozwodnienia i Gaussa** — aby uruchomić optymalizator pod kątem okna Gaussa ($1.35\sigma$), pokrycia par i pokrycia puli.
5. Jeśli zaznaczysz **Komentarz AI**, model językowy wygeneruje zwięzłą ocenę strategiczną wygenerowanego pakietu.
