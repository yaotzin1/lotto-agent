# 📖 Kompletny Podręcznik Gracza: Strategie, Skalowanie i Komendy CLI

> Praktyczny przewodnik po matematyce loterii, logarytmicznym skalowaniu budżetu i puli, horyzontach czasowych oraz wszystkich trybach generatora w aplikacji **Lotto Agent & Simulator**.

---

## 🎯 Spis Treści
1. [Logarytmiczna Matryca Skalowania Budżetu (Od 5 do 500+ zakładów)](#-1-logarytmiczna-matryca-skalowania-budżetu)
2. [Przewodnik po Grach: Mini Lotto, Lotto, EuroJackpot, Multi Multi](#-2-przewodnik-po-grach)
   - [Mini Lotto (5/42)](#mini-lotto-542)
   - [Lotto (6/49)](#lotto-649)
   - [EuroJackpot (5/50 + 2/12)](#eurojackpot-550--212)
   - [Multi Multi (1–10/80)](#multi-multi-11080)
3. [Horyzonty Czasowe w Statystyce (Mikro vs Średnie vs Makro)](#-3-horyzonty-czasowe-w-statystyce)
4. [Katalog Trybów Generatora (Od [1] do [8])](#-4-katalog-trybów-generatora)
   - [Tryb [4]: Snajper Hybrydowy (Stali Bankierzy)](#tryb-4-snajper-hybrydowy-stali-bankierzy)
   - [Tryb [6]: Bankierzy Rotacyjni (System Rozdzielny)](#tryb-6-bankierzy-rotacyjni-system-rozdzielny)
   - [Tryb [8]: Rankingowe Pełne Pokrycie (Zero-Drop Synergy)](#tryb-8-rankingowe-pełne-pokrycie-zero-drop-synergy)
   - [Tryb [5]: System Fraktalny (Kaskady Klastrów)](#tryb-5-system-fraktalny-kaskady-klastrów)
   - [Tryb [7]: Łowca Synergii Hot (Koncentracja)](#tryb-7-łowca-synergii-hot-koncentracja)
   - [Tryb [2]: Inteligentny Krupier (Zbalansowany)](#tryb-2-inteligentny-krupier-zbalansowany)
   - [Tryb [1] & [3]: Redukcja Klasyczna i Model Urnowy](#tryb-1--3-redukcja-klasyczna-i-model-urnowy)
5. [Narzędzia Analityczne i AI (Agent, Stats, TUI)](#-5-narzędzia-analityczne-i-ai)

---

## 📈 1. Logarytmiczna Matryca Skalowania Budżetu

W grach liczbowych wraz ze wzrostem budżetu zmienia się **optymalna architektura kombinatoryczna**. Granie 200 zakładami w trybie dla 10 zakładów prowadzi do katastrofalnego rozwodnienia lub nadmiarowości.

```
       [ 5–15 Zakładów ]      ──►  Wąska Pula AI (10–12 liczb) + Stali Bankierzy (Tryb 4)
             │                     Kondensacja: 1:8 szansy na Jackpot po wejściu bazy.
             ▼
       [ 30–50 Zakładów ]     ──►  Średnia Pula (16–20 liczb) / Fraktal (Tryb 5) LUB Pełny Bęben (Tryb 8)
             │                     Eliminacja martwych kul, wysoka amortyzacja 3/5 i 4/5.
             ▼
       [ 100–200 Zakładów ]   ──►  Bankierzy Rotacyjni (Tryb 6: 8 faworytów) LUB Pełna Synergia Par (Tryb 8)
             │                     Wielokrotne pokrycie par (7-8x na parę), 1:157 na Jackpot.
             ▼
       [ 500+ Zakładów ]      ──►  Zaawansowane Kaskady Fraktalne L1/L2/L3 lub Syndykat Pełnej Redukcji
```

---

## 🎰 2. Przewodnik po Grach

---

### Mini Lotto (5/42)
* **Przestrzeń kombinacji:** $\binom{42}{5} = \mathbf{850\ 668}$
* **Koszt pojedynczego zakładu:** 1,25 zł *(zweryfikuj w aktualnym cenniku Totalizatora przed grą)*
* **Optymalna suma Gaussa:** 73 – 142 ($\mu = 107{,}5$, $\sigma = 25{,}75$) — wartości generowane przez `calculateGaussianParameters()`

| Skala Budżetu | Liczba Zakładów | Koszt | Rekomendowany Tryb | Rozmiar Puli | Matematyka i Prawdopodobieństwo |
|---|---|---|---|---|---|
| 🟢 **Micro** | **5 – 15** | 6,25 – 18,75 zł | **Tryb [4]** (Stali Bankierzy) | **10–12 liczb (AI)** | **Bezwarunkowo: 1 do 56 711** przy 15 zakładach. Liczba „1 do 8" jest **warunkowa** — zakłada, że 2 bankiery już trafiły *i* pozostałe 3 liczby są w puli. Sam ten warunek ma szansę ok. **1 do 1 062** ($inom{12}{5}/inom{42}{5}$). |
| 🟡 **Mid** | **30 – 50** | 45 – 75 zł | **Tryb [8]** (Pełne Pokrycie) *lub* **Tryb [5]** (Fraktal) | **Wszystkie 42 liczby** *(Tryb 8)* lub **16–18 liczb** *(Tryb 5)* | **100% obecności bębna** (każda liczba $\approx$ 6 razy). 9 kuponów bazowych + 41 kuponów synergii par Hot. |
| 🟠 **Semi-Pro** | **100 – 200** | 125 – 250 zł | **Tryb [6]** (Bankierzy Rotacyjni) | **8 Bankierów + 34 zmienne** | **Bezwarunkowo: 1 do 4 253** przy 200 zakładach. Liczba „1 do 157" jest **warunkowa** (3 z 8 bankierów trafione). ⚠️ Przy 8 bankierach po 3 na kupon istnieje tylko $inom{8}{3}=56$ kombinacji bankierów, więc **200 zakładów nie jest osiągalne** — generator zgłosi niedobór. |
| 🔴 **Syndicate** | **500+** | 625+ zł | **Tryb [8]** + **Tryb [5]** | **42 liczby** | **Bezwarunkowo: 1 do 1 701** przy 500 zakładach ($850\,668/500$). To jedyna liczba w tej tabeli, która jest bezwarunkowa w oryginale. |

#### Komendy CLI dla Mini Lotto:
```bash
# Micro (15 zakładów, pula AI 12 liczb, 2 stałych bankierów):
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=AI --strategy=syndicate --pool-size=12 --mode=4 --bets=15

# Mid (50 zakładów z całego bębna 42 liczb z rankingiem synergii):
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=8 --bets=50

# Semi-Pro (200 zakładów, 8 bankierów rotacyjnych z całego bębna):
php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=6 --bets=200
```

---

### Lotto (6/49)
* **Przestrzeń kombinacji:** $\binom{49}{6} = \mathbf{13\ 983\ 816}$
* **Koszt pojedynczego zakładu:** 3,00 zł
* **Optymalna suma Gaussa:** 106 – 194 ($\mu = 150$, $\sigma = 32{,}79$) — wartości generowane przez `calculateGaussianParameters()`

| Skala Budżetu | Liczba Zakładów | Koszt | Rekomendowany Tryb | Rozmiar Puli | Matematyka i Prawdopodobieństwo |
|---|---|---|---|---|---|
| 🟢 **Micro** | **10 – 25** | 30 – 75 zł | **Tryb [4]** (Stali Bankierzy) | **12–14 liczb (AI)** | **Bezwarunkowo: 1 do 559 353** przy 25 zakładach. Warunkowo (3 bankiery trafione *i* pozostałe 3 liczby w puli): $inom{11}{3}=165$, więc 25 zakładów pokrywa **15,1%** tego przypadku — ale sam warunek ma szansę ok. **1 do 4 657**. |
| 🟡 **Mid** | **50 – 100** | 150 – 300 zł | **Tryb [5]** (Fraktalny) *lub* **Tryb [8]** | **18–24 liczby** *(Tryb 5)* lub **49 liczb** *(Tryb 8)* | Podział puli na bloki L1=12 i L2=8 z krokiem względnie pierwszym (*Coprime Stride*) zapobiega pustym kuponom. |
| 🟠 **Semi-Pro** | **200 – 500** | 600 – 1 500 zł | **Tryb [7]** (Łowca Synergii Hot) | **49 liczb** | Koncentracja na historycznych trójkach i czwórkach, optymalizacja pod kumulacje I i II stopnia. |
| 🔴 **Syndicate** | **1 000+** | 3 000+ zł | **Tryb [8]** + Redukcja blokowa | **49 liczb** | Zagęszczenie par $>10\times$, stabilna amortyzacja wygranymi 4/6 i 5/6. |

#### Komendy CLI dla Lotto:
```bash
# Micro (20 zakładów, pula AI 14 liczb, tryb stałych bankierów):
php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --strategy=syndicate --pool-size=14 --mode=4 --bets=20

# Mid (50 zakładów, fraktal L1=12/4, L2=8/2 z puli AI 18 liczb):
php bin/console app:lotto-generator --game=Lotto --pool-mode=AI --strategy=syndicate --pool-size=18 --mode=5 --bets=50

# Semi-Pro (100 zakładów, rankingowe pełne pokrycie 49 liczb):
php bin/console app:lotto-generator --game=Lotto --pool-mode=Manual --mode=8 --bets=100
```

---

### EuroJackpot (5/50 + 2/12)

> ⚠️ **Ograniczenie implementacji:** aplikacja generuje wyłącznie **5 liczb głównych z 50**.
> Bęben EuroNumbers (2 z 12) **nie jest obsługiwany** przez żaden generator — pola `extra`
> i `extra_from` w `GameRegistryService` są zdefiniowane, ale nieużywane. Liczby E1/E2
> musisz wybrać samodzielnie. To samo dotyczy gier EkstraPensja i EkstraPremia.
* **Przestrzeń kombinacji:** $\binom{50}{5} \times \binom{12}{2} = 2\ 118\ 760 \times 66 = \mathbf{139\ 838\ 160}$
* **Koszt pojedynczego zakładu:** 12,50 zł
* **Specyfika:** Potężna przestrzeń główna + bęben EuroNumbers (E1, E2).

| Skala Budżetu | Liczba Zakładów | Koszt | Rekomendowany Tryb | Strategia EuroNumbers (E1, E2) |
|---|---|---|---|---|
| 🟢 **Micro** | **5 – 10** | 62,50 – 125 zł | **Tryb [4]** (2 Bankierów) | Stała para EuroNumbers (np. 2 najgorętsze E-liczby na wszystkich kuponach). |
| 🟡 **Mid** | **20 – 50** | 250 – 625 zł | **Tryb [8]** (Pokrycie puli 50) | Rotacja EuroNumbers: z 12 E-liczb powstaje 66 par $\to$ obsadzenie 30–50 najczęstszych par E-liczb. |
| 🟠 **Semi-Pro / Syndykat** | **100+** | 1 250+ zł | **Tryb [5]** (Fraktalny) | Pełne pokrycie 12 EuroNumbers (wszystkie 66 par obstawione min. 1 raz). |

```bash
# EuroJackpot (20 zakładów z analizą statystyczną):
php bin/console app:lotto-generator --game=EuroJackpot --pool-mode=AI --strategy=balanced --pool-size=20 --mode=8 --bets=20
```

---

### Multi Multi (1–10/80)
* **Przestrzeń kombinacji:** Losowanych jest 20 kul z 80. Gracz wybiera skreślenie od 1 do 10 liczb.
* **Strategia Optymalna (Kombinatoryczna):**
  * **Gra na 4–6 liczb (Wysoka wygrywalność):** Najwyższe matematyczne ROI przy regularnych trafieniach 4/4 i 5/5.
  * **Gra na 10 liczb (Atak na Jackpot):** Wymaga strategii klastrowej w oparciu o [Tryb 7] lub [Tryb 8].

```bash
# Multi Multi (Skreślanie 6 liczb, 30 zakładów, pełna analiza):
php bin/console app:lotto-generator --game=MultiMulti --pool-size=6 --mode=8 --bets=30
```

---

## 💰 2b. Podział Puli Nagród (jedyna realna dźwignia EV)

> Ta sekcja jest ważniejsza niż wszystkie optymalizacje w tym dokumencie razem wzięte.

W polskim Lotto nagrody I, II i III stopnia są **pari-mutuel**: to ustalony procent puli
wpłat, **dzielony między wszystkich zwycięzców danego stopnia**. Wynika z tego rzecz,
którą łatwo przeoczyć:

* **Nie da się zwiększyć szansy na trafienie.** Każda kombinacja ma identyczne $P$.
* **Da się zwiększyć oczekiwaną WYPŁATĘ**, grając kombinacje, które wybiera mało innych
  graczy — bo wtedy rzadziej dzielisz się nagrodą.

Co robi większość graczy (a więc czego **warto unikać**, jeśli celujesz w I stopień):

| Wzorzec | Dlaczego jest popularny |
|---|---|
| Liczby $\le 31$ | Daty urodzenia i rocznice |
| „Ładne" układy na kuponie | Przekątne, kolumny, symetrie |
| Liczby „gorące" z ostatnich losowań | Publikowane statystyki, systemy typu tego |
| Zrównoważona parzystość i rozrzut | Intuicja „tak wygląda losowe" |

⚠️ **Świadome ograniczenie tej aplikacji:** funkcja oceny (`calculateBetFitness`) premiuje
liczby gorące (`freqTotal * 2.5`) oraz wymusza „naturalnie wyglądające" wzorce parzystości
i rozrzutu dekadowego. To są dokładnie te wzorce, które wybiera najwięcej graczy. Z punktu
widzenia **oczekiwanej wypłaty** taka optymalizacja działa więc raczej **na Twoją niekorzyść**
niż korzyść.

Jeśli celujesz w I stopień, rozważ strategię odwrotną: liczby $> 31$, układy asymetryczne,
sumy poza szczytem dzwonu. Trafisz tak samo często — ale rzadziej z kimś się podzielisz.

---

## ⏱️ 3. Horyzonty Czasowe w Statystyce

```
[ 1–5 losowań ]        [ 30–50 losowań ]            [ 500+ losowań ]
      │                        │                           │
  MIKRO-KLASTRY            SWEET SPOT                 PŁASKA ŚREDNIA
(Powtórki i Sąsiedzi)    (Fale Pędu i Pary)        (Prawo Wielkich Liczb)
```

1. **Mikro (3–5 losowań) – *Anchors i Sąsiedzi*:**
   * W Mini Lotto w ~45% losowań powtarza się min. 1 liczba z poprzedniego dnia, a w ~70% wpada sąsiad ($\pm 1$).
   * Idealne do wyznaczenia **1–2 Stałych Bankierów**.
2. **Średnie (30–50 losowań) – *Domyślny Sweet Spot w aplikacji*:**
   * Wykrywa fale pędu (*Momentum/Hot*), liczby skrajnie uśpione (*Overdue / Reversion to the mean*) oraz macierz współwystępowania par.
   * Daje próbę 250–500 wylosowanych kul, co odróżnia szum od trendu.
3. **Makro (200–1000+ losowań) – *Prawo Wielkich Liczb*:**
   * Wszystkie liczby dążą do identycznej frekwencji. **Brak wartości prognostycznej dla bieżących klastrów**.

---

## 🛠️ 4. Katalog Trybów Generatora

### Tryb [4]: Snajper Hybrydowy (Stali Bankierzy)
* **Zasada:** Typujesz **2–3 stałych Bankierów**, obecnych na **każdym kuponie**. Resztę wypełniają kombinacje z puli zmiennych.
* **Kiedy stosować:** Budżet 5–25 zakładów + silne przekonanie do 2 liczb (np. Anchors z AI).
* **Szansa:** **1 do 8** — ale to liczba **warunkowa**, liczona już PO trafieniu bazy. Bezwarunkowa szansa to `liczba_zakładów / C(M, k)`.

---

### Tryb [6]: Bankierzy Rotacyjni (System Rozdzielny)
* **Zasada:** Grupa np. 6–8 faworytów, z których generator dobiera po 2 lub 3 na każdy kupon.
* **Kiedy stosować:** Budżet 50–200 zakładów. Zabezpiecza przed chybieniem jednego bankiera.
* **Szansa:** **1 do 157** — liczba **warunkowa** (3 z 8 bankierów trafione). Bezwarunkowo przy 200 zakładach w Mini Lotto: **1 do 4 253**.

---

### Tryb [8]: Rankingowe Pełne Pokrycie (Zero-Drop Synergy)
* **Zasada:** 100% liczb bębna (wszystkie 42 lub 49) znajduje się w zestawie kuponów.
* **Jak działa:**
  1. Pierwsze $\lceil N / k \rceil$ kuponów (9 dla Mini Lotto) wyczerpuje cały bęben.
  2. Pozostałe kupony łączą najsilniejsze statystycznie pary (*Pair Affinity*) i dzwon Gaussa.
  3. Kupony posortowane według *Fitness Score* (od Kuponu `#1 ★ TOP SYNERGIA`).
* **Kiedy stosować:** Zawsze, gdy chcesz grać z całego bębna bez odrzucania żadnej liczby (30–500 zakładów).

---

### Tryb [5]: System Fraktalny (Kaskady Klastrów)
* **Zasada:** Wielopoziomowy podział puli na bloki nadrzędne (L1) i podbloki (L2) z krokiem względnie pierwszym (*Coprime Stride*).
* **Kiedy stosować:** Gry syndykatowe z puli 16–24 liczb (50–150 zakładów).

---

### Tryb [7]: Łowca Synergii Hot (Koncentracja)
* **Zasada:** Czysta optymalizacja pod najgorętsze liczby i najsilniejsze historycznie trójki/czwórki.
* **Kiedy stosować:** Gra agresywna pod kątem kumulacji (100–300 zakładów).

---

### Tryb [2]: Inteligentny Krupier (Zbalansowany)
* **Zasada:** Rygorystyczny algorytm backtrackingowy. Każda liczba występuje na kuponach dokładnie tyle samo razy.
* **Kiedy stosować:** Maksymalna amortyzacja kosztów gry bez faworyzowania jakichkolwiek liczb.

---

### Tryb [1] & [3]: Redukcja Klasyczna i Model Urnowy
* **Tryb [1]:** Minimalizacja funkcji kosztu par (*Strict Bucket Balance*).
* **Tryb [3]:** Model urnowy z wagami prawdopodobieństwa (2x–10x) dla liczb faworyzowanych.

---

## 🤖 5. Narzędzia Analityczne i AI

### ReAct Agent AI (`app:lotto-agent`)
Autonomiczny analityk oparty o Google Gemini i LOTTO OpenAPI. Stosuje formułę syndykatu klastrowego: **60% sąsiedzi, 20% powtórki (Anchors), 20% uśpione (przełamanie serii)**.

```bash
# Analiza Mini Lotto z ostatnich 50 losowań (Strategia Syndykat):
php bin/console app:lotto-agent --game=MiniLotto --strategy=syndicate --sessions=50

# Strategia Ostra / Agresywna (Maks. ryzyko na klastry Hot):
php bin/console app:lotto-agent --game=Lotto --strategy=aggressive --sessions=50
```

### Okno Statystyczne (`app:lotto-stats`)
Analiza przestrzeni kombinatorycznej, współczynnika rozwodnienia, wykres słupkowy ASCII dzwonu Gaussa oraz ranking par:

```bash
# Analiza 50 zakładów z całego bębna Mini Lotto:
php bin/console app:lotto-stats --game=MiniLotto --pool=all --bets=50

# Lotto 49 liczb / 100 zakładów z komentarzem AI:
php bin/console app:lotto-stats --game=Lotto --pool=all --bets=100 --ai
```

### Interaktywne Terminal UI (`app:lotto-tui`)
Pełny interfejs konsolowy (TUI) z interaktywnymi promptami i generatorem:

```bash
php bin/console app:lotto-tui
```
