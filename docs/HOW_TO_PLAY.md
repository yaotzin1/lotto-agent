# 📖 Kompletny Podręcznik Gracza: Strategie i Komendy CLI

> Praktyczny przewodnik po wszystkich trybach generatora, strategiach ataku na jackpot, analizie AI oraz komendach w aplikacji **Lotto Agent & Simulator**.

---

## 🎯 Spis Treści
1. [Szybka Ściąga: Kiedy stosować który tryb?](#-1-szybka-ściąga-kiedy-stosować-który-tryb)
2. [Strategie Ataku na JACKPOT (Główna Wygrana I Stopnia)](#-2-strategie-ataku-na-jackpot-i-stopień)
   - [Tryb [4]: Snajper Hybrydowy (Stali Bankierzy)](#tryb-4-snajper-hybrydowy-stali-bankierzy)
   - [Tryb [5]: System Fraktalny (Kaskady Klastrów)](#tryb-5-system-fraktalny-kaskady-klastrów)
   - [Tryb [6]: Bankierzy Rotacyjni (System Rozdzielny)](#tryb-6-bankierzy-rotacyjni-system-rozdzielny)
3. [Strategie z Pełnego Bębna (Bez Odrzucania Liczb)](#-3-strategie-z-pełnego-bębna-bez-odrzucania-liczb)
   - [Tryb [8]: Rankingowe Pełne Pokrycie (Zero-Drop Synergy)](#tryb-8-rankingowe-pełne-pokrycie-zero-drop-synergy)
   - [Tryb [7]: Łowca Synergii Hot (Koncentracja Statystyczna)](#tryb-7-łowca-synergii-hot-koncentracja-statystyczna)
4. [Strategie Defensywne (Częste Wygrane Niższego Stopnia)](#-4-strategie-defensywne-częste-wygrane-niższego-stopnia)
   - [Tryb [2]: Inteligentny Krupier (Kreator Bloków)](#tryb-2-inteligentny-krupier-kreator-bloków)
   - [Tryb [1]: Klasyczna Redukcja Zbalansowana](#tryb-1-klasyczna-redukcja-zbalansowana)
   - [Tryb [3]: Generator Ważony (Urn Model)](#tryb-3-generator-ważony-urn-model)
5. [Narzędzia Analityczne i AI](#-5-narzędzia-analityczne-i-ai)
   - [Okno Statystyczne (`app:lotto-stats`)](#okno-statystyczne-applotto-stats)
   - [ReAct Agent AI (`app:lotto-agent`)](#react-agent-ai-applotto-agent)
   - [Interaktywne Terminal UI (`app:lotto-tui`)](#interaktywne-terminal-ui-applotto-tui)

---

## ⚡ 1. Szybka Ściąga: Kiedy stosować który tryb?

| Cel Gracza | Rekomendowany Tryb | Zalecana Pula | Dlaczego? |
|---|---|---|---|
| 👑 **Maksymalny atak na JACKPOT** | **Tryb [4] (Hybrydowy)** | 10–14 liczb (2 bankierów) | Zawężona przestrzeń daje nawet **1:8** szansy na 5/5 przy trafieniu bazy. |
| 🧬 **Syndykat / Kaskada wygranych** | **Tryb [5] (Fraktalny)** | 12–18 liczb | Rolling overlap wymusza zderzenie liczb w podblokach kuponów. |
| 🎯 **Jackpot z CAŁEGO bębna** | **Tryb [8] (Rankingowy)** | Pełna (42 / 49 liczb) | Gwarancja 100% puli + odrzucenie kombinacji martwych statystycznie. |
| 🔥 **Gra na najgorętsze trendy** | **Tryb [7] (Synergia Hot)** | Pełna lub 25–30 liczb | Maksymalizuje sumaryczny wskaźnik historycznych par i trójek. |
| 🛡️ **Zwrot kosztów (gwarancje 3/5, 4/6)** | **Tryb [2] (Krupier)** | Dowolna pula | Równomierne rozłożenie bez faworyzowania klastrów. |

---

## 👑 2. Strategie Ataku na JACKPOT (I Stopień)

### Tryb [4]: Snajper Hybrydowy (Stali Bankierzy)
* **Zasada:** Typujesz **2–3 liczby pewne (Bankierów)**, które pojawiają się na **każdym kuponie**. Resztę miejsc wypełniają kombinacje z puli zmiennych.
* **Dlaczego to najlepszy atak na jackpot?** W Mini Lotto, jeśli trafisz 2 Bankierów i 3 z 10 liczb zmiennych, szansa na trafienie 5/5 w 15 kuponach rośnie do poziomu **ok. 1 do 8**!

```bash
# Mini Lotto: 2 Bankierów (np. 7, 24) + Pula 10 zmiennych -> 15 zakładów
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=4 --bets=15
# W promptach wpisujesz:
# Stałych bankierów: 7 24
# Pulę liczb zmiennych: 2 11 15 18 21 28 30 35 37 41

# Wersja Docker:
docker compose run --rm app php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=4 --bets=15
```

---

### Tryb [5]: System Fraktalny (Kaskady Klastrów)
* **Zasada:** Wielopoziomowy podział puli na bloki nadrzędne (L1) i podbloki (L2) z krokiem względnie pierwszym (*Coprime Stride Geometric Interleaving*).
* **Zastosowanie:** Gra zespołowa (syndykat) lub atak na kumulację z puli 15–20 liczb.

```bash
# Lotto (6/49) z puli 18 liczb AI wygenerowanej klastrowo:
php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --strategy=syndicate --pool-size=18 --mode=5 --bets=20

# Mini Lotto (5/42) z puli ręcznej:
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=5 --bets=15
# W promptach podajesz parametry bloków, np. L1=10, L1_count=3, L2=6, L2_count=2
```

---

### Tryb [6]: Bankierzy Rotacyjni (System Rozdzielny)
* **Zasada:** Zamiast stałych bankierów, masz grupę **Bankierów Pływających** (np. 6 liczb, z których na każdy kupon losowane są 2 lub 3) oraz pulę zmiennych.
* **Zastosowanie:** Gdy masz grupę faworytów, ale nie masz 100% pewności do konkretnych 2 liczb.

```bash
# Lotto: 6 bankierów rotacyjnych (po 3 na kuponie) + zmienne
php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --mode=6 --bets=25
```

---

## 🎯 3. Strategie z Pełnego Bębna (Bez Odrzucania Liczb)

### Tryb [8]: Rankingowe Pełne Pokrycie (Zero-Drop Synergy)
* **Zasada:** Wszystkie 42 liczby (Mini Lotto) lub 49 liczb (Lotto) zostają w 100% włączone do zestawu (występują min. 1 raz).
* **Jak działa algorytm:**
  1. Dzieli całą pulę na $\lceil N / k \rceil$ zakładów bazowych (9 dla Mini Lotto), łącząc liczby o najwyższym wzajemnym powinowactwie (*Pair Affinity*) i optymalnym dzwonie Gaussa.
  2. Pozostałe zakłady dopełnia najgorętszymi klastrami.
  3. Sortuje kupony malejąco według *Fitness Score* (Kupon `#1 [★ TOP SYNERGIA]` to najmocniejszy zestaw).

```bash
# Mini Lotto: 42 liczby w 15 zakładach (gwarantowane 100% pokrycia + ranking)
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=8 --bets=15

# Lotto: Wszystkie 49 liczb w 25 zakładach
php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --mode=8 --bets=25
```

---

### Tryb [7]: Łowca Synergii Hot (Koncentracja Statystyczna)
* **Zasada:** Czysta optymalizacja pod liczby gorące i najsilniejsze historycznie pary. Nie gwarantuje udziału liczb zimnych, faworyzuje najsilniejsze klastry.

```bash
# Lotto: 100 zakładów z optymalizacją rozwodnienia
php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --mode=7 --bets=100
```

---

## 🛡️ 4. Strategie Defensywne (Częste Wygrane Niższego Stopnia)

### Tryb [2]: Inteligentny Krupier (Kreator Bloków)
* **Zasada:** Backtrackingowy algorytm matematycznego krupiera. Rozkłada liczby ze ściśle równą frekwencją bez faworyzowania statystyk historycznych.
* **Cel:** Gwarancja regularnych trafień 3/5 i 4/6 amortyzujących koszty gry.

```bash
# Mini Lotto: 15 zakładów z idealnym podziałem puli
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=2 --bets=15
```

---

### Tryb [1]: Klasyczna Redukcja Zbalansowana
* **Zasada:** Algorytm minimalizacji funkcji kosztu użycia par i liczb (*Strict Bucket Balance*).

```bash
# Zredukowanie puli 15 liczb do 10 kuponów:
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=1 --bets=10
```

---

### Tryb [3]: Generator Ważony (Urn Model)
* **Zasada:** Model urnowy ze zwiększoną wagą prawdopodobieństwa (2x–10x) dla wskazanych liczb gorących.

```bash
php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --mode=3 --bets=15
```

---

## 📊 5. Narzędzia Analityczne i AI

### Okno Statystyczne (`app:lotto-stats`)
Analizuje przestrzeń kombinatoryczną, stopień rozwodnienia, buduje macierz współwystępowania par, wykres słupkowy ASCII dzwonu Gaussa oraz generuje pakiet z rankingiem synergii.

```bash
# Domyślnie z pełnym pokryciem puli (15 zakładów Mini Lotto):
php bin/console app:lotto-stats --game=MiniLotto --pool=all --bets=15

# Lotto 49 liczb / 100 zakładów z analizą AI Gemini:
php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --ai

# Własna pula liczb (np. 25 liczb w 30 zakładach):
php bin/console app:lotto-stats --game=Lotto --pool="1,5,7,12,14,18,21,24,27,28,30,31,34,35,37,40,41,42,44,45,46,47,48,49" --bets=30

# Eksport danych do formatu JSON:
php bin/console app:lotto-stats --game=MiniLotto --pool=all --bets=15 --json-output
```

---

### ReAct Agent AI (`app:lotto-agent`)
Autonomiczny agent AI (Google Gemini + zestaw narzędzi LOTTO OpenAPI). Analizuje klastry, powtórki, sąsiadów i wyznacza optymalną pulę kandydującą.

```bash
# Strategia Syndykat Klastrowy (60% sąsiedzi, 20% powtórki, 20% uśpione):
php bin/console app:lotto-agent --game=MiniLotto --strategy=syndicate --sessions=20

# Strategia Agresywna (Łowca Trendów - maks. ryzyko):
php bin/console app:lotto-agent --game=Lotto --strategy=aggressive --sessions=50
```

---

### Interaktywne Terminal UI (`app:lotto-tui`)
Pełny interfejs konsolowy (TUI) z interaktywnymi listami wyboru, widgetami wejściowymi i prezentacją wyników:

```bash
php bin/console app:lotto-tui
```
