# 🎰 Analiza Zasadności i Matematyki Strategii Loteryjnych

> Dokumentacja analityczna dla projektu **Lotto Agent AI** (`lotto-agent`).  
> Analiza oparta na teorii prawdopodobieństwa, analizie klastrowej danych historycznych oraz kombinatoryce systemów skróconych.

---

## 1. Prawda o Prawdopodobieństwie (Reality Check)

W klasycznej grze Lotto (6 z 49):
* Liczba wszystkich możliwych kombinacji 6-liczbowych wynosi:
  $$\binom{49}{6} = \frac{49 \times 48 \times 47 \times 46 \times 45 \times 44}{6 \times 5 \times 4 \times 3 \times 2 \times 1} = 13\,983\,816$$
* Każda pojedyncza kombinacja 6 liczb ma **dokładnie takie samo prawdopodobieństwo wylosowania** w pojedynczym losowaniu:
  $$P(\text{Trafienie 6/6}) = \frac{1}{13\,983\,816} \approx 0.0000000715$$

### Dlaczego więc stosujemy analitykę AI i systemy redukcyjne?
Loteria to gra losowa o niezależnych losowaniach (*I.I.D. – Independent and Identically Distributed*), ale **sposób obstawiania i selekcji zakładów** ma kluczowe znaczenie dla:
1. **Maksymalizacji pokrycia kombinatorycznego** – zamiast losowych kuponów, które dublują te same podgrupy, systemy skrócone gwarantują matematycznie wygrane niższego stopnia (3/6, 4/6, 5/6), jeśli wygrane liczby znajdą się w wytypowanej puli.
2. **Eksploatacji mechanicznych anomalii i klastrów** – bębny losujące i kule w fizycznych maszynach losujących wykazują w krótkich i średnich okresach zagęszczenia w okolicach określonych sektorów bębna.
3. **Efektu lewara w syndykatach** – przy trafieniu puli wejściowej, zastosowanie bloków i bankierów powoduje **jednoczesne trafienie wielu wygranych na dziesiątkach kuponów**.

---

## 2. Strategia Syndykat Klastrowy (Reguła 60 / 20 / 20)

Strategia `syndicate` wdrożona w `lotto-agent` dzieli selekcję puli kandydującej na trzy ścisłe filary:

```
┌────────────────────────────────────────────────────────────────────────┐
│                   PULA KANDYDUJĄCA (np. 12 - 20 liczb)                 │
├─────────────────────────┬──────────────────────────┬───────────────────┤
│    60% SĄSIEDZI (±1)    │    20% POWTÓRKI (0)      │ 20% UŚPIONE/ZIMNE │
│  Klastry wokół wygranych│ Ostatnie liczby wygrane  │ Odległe sektory   │
└─────────────────────────┴──────────────────────────┴───────────────────┘
```

### Filar 1: Sąsiedzi Matematyczni ($\pm 1$) – 60% puli
* **Zjawisko:** W ponad **50% losowań Lotto** przynajmniej dwie z wylosowanych liczb są bezpośrednimi sąsiadami na bębnie (np. 14 i 15, 27 i 28). Wynika to z dynamiki zderzeń kul w komorze mieszającej.
* **Działanie:** Narzędzie `fetch_neighbours_analysis` lokalizuje liczby wygrane z ostatnich losowań (kotwice) i wyznacza ich sąsiadów ($N-1$ oraz $N+1$). Sąsiedzi przylegający do więcej niż jednej wygranej liczby otrzymują najwyższy *Cluster Score*.

### Filar 2: Bezpośrednie Powtórki (*Repeat Numbers*) – 20% puli
* **Zjawisko:** W statystyce Lotto w około **42–45% losowań** co najmniej jedna liczba z poprzedniego losowania pojawia się ponownie w kolejnym losowaniu.
* **Działanie:** Agent rezerwuje 20% puli na najmocniejsze liczby z bezpośrednio poprzednich sesji, tworząc stałe kotwice (*anchors*).

### Filar 3: Uśpione Liczby Izolowane (*Reversion to the Mean*) – 20% puli
* **Zjawisko:** Prawo wielkich liczb (*Law of Large Numbers*) dąży do wyrównania długoterminowych frekwencji. Jednak włączanie zimnych liczb leżących tuż obok klastra osłabia skupienie.
* **Działanie:** Algorytm selekcjonuje wyłącznie liczby uśpione, które są **przestrzennie odizolowane** (dystans $|N - W| > 2$ od strefy wygranych), co chroni przed skupieniem całej puli w jednym wąskim wycinku bębna.

---

## 3. Współpraca AI z Generatorami `BetGeneratorService`

Pula liczb wygenerowana przez AI nie jest grana "na ślepo". Wykorzystywane są silniki matematyczne:

### A. Silnik Fraktalny (Tryb 5 – Rolling Overlap & Geometric Interleaving)
* Rozkłada pulę liczb według algorytmu kroku względnie pierwszego (*coprime stride*).
* Generuje bloki nadrzędne L1 i podbloki L2 o kontrolowanym nakładaniu się par (*interlocking*).
* Jeśli 6 wygranych liczb wpadnie w pulę 18 liczb, system fraktalny gwarantuje wysokie prawdopodobieństwo skumulowania ich w jednym podbloku.

### B. System Rozdzielny i Bankierzy Rotacyjni (Tryb 6 / Tryb 4)
* Kotwice wygrane (20% puli) można zadeklarować jako **Bankierów**.
* Sąsiedzi (60%) stanowią **Pule Zmiennych**.
* Przy trafieniu bankierów i 2-3 sąsiadów, gracz zgarnia **wielokrotne trafienia 3-ek, 4-ek i 5-tek na całym pakiecie kuponów**.

### C. Silnik Redukcji Strict Bucket Balance
* Optymalizuje funkcję kosztu:
  $$\text{Koszt}(N) = 10 \times \text{LicznikUżyć}(N) + 20 \times \sum_{S \in \text{Kupon}} \text{LicznikPary}(N, S)$$
* Każda para liczb występuje w kuponach z maksymalnie zrównoważoną częstotliwością, eliminując marnowanie zakładów na powtarzające się dublety.

---

## 5. Optymalizacja Statystyczna przy Silnym Rozwodnieniu (Tryb 7 / Okno Statystyczne)

### A. Problem Rozwodnienia Puli (Dilution Breakdown)
Gdy gracz wybiera bardzo duży zbiór liczb (np. $N = 49$ dla Lotto lub $N = 30$) i dysponuje ograniczonym budżetem (np. $100$ lub $25$ zakładów):
* Przestrzeń pełna: $\binom{49}{6} = 13\,983\,816$.
* Pokrycie $100$ zakładami stanowi zaledwie $0.000715\%$ przestrzeni.
* Losowy dobór (Random Baseline) przy takim rozwodnieniu generuje zakłady o **niskiej jakości probabilistycznej** – kupony o sumach rzędu $50$ lub $240$, zaburzonej parzystości ($6:0$), czy parach liczb, które statystycznie nigdy nie występowały razem.

### B. Dwuwarstwowy Mechanizm Optymalizatora Statystycznego:

```
                  ┌──────────────────────────────────────────────┐
                  │    PULA WEJŚCIOWA (np. 49 liczb / 100 gier)   │
                  └──────────────────────┬───────────────────────┘
                                         │
                 ┌───────────────────────┴───────────────────────┐
                 ▼                                               ▼
     [WARSTWA MAKRO: Filtry Stochastyczne]       [WARSTWA MIKRO: Synergia i Powiązania]
     - Krzywa Gaussa sumy (115 - 185)            - Macierz współwystępowania par P(A,B)
     - Balans parzystości (3:3, 4:2, 2:4)        - Klastry sąsiedztwa i k-kliki
     - Rozpiętość min. 3 dekad bębna             - Dynamiczne tłumienie nasycenia puli
                 │                                               │
                 └───────────────────────┬───────────────────────┘
                                         ▼
                 [Zestaw 100 Kuponów o Najwyższej Synergii Historycznej]
                 [Okno Statystyczne: Histogram Gaussa + Benchmark Zysku]
```

1. **Warstwa Makro-Prawdopodobieństwa (Filtry Stochastyczne):**
   * **Dzwon Gaussa Sumy:** Teoretyczna średnia sumy 6 liczb w Lotto wynosi $\mu = 150$, a odchylenie $\sigma \approx 32.8$. Ponad $80\%$ historycznych losowań mieści się w przedziale $[115, 185]$. Optymalizator odrzuca zakłady leżące w ogonach rozkładu.
   * **Balans Parzystości:** $82\%$ losowań Lotto to układy $3:3$, $4:2$ lub $2:4$.
   * **Rozpiętość Dekadowa:** Wymuszenie pokrycia co najmniej 3 różnych dekad zapobiega nienaturalnym skupiskom.

2. **Warstwa Mikro-Powiązań (Affinity Matrix & Anti-Cannibalization):**
   * Tworzy symetryczną macierz powiązań $P(A, B)$ dla każdej pary z puli.
   * Dynamiczny algorytm marginalnego spadku użyteczności:
     $$\text{Score}(c) = 2 \cdot \text{Freq}(c) + 1.5 \sum_{s \in \text{Kupon}} \text{Affinity}(c, s) - 18 \cdot \text{Usage}(c) - 40 \sum_{s} \text{PairUsage}(c, s)$$
   * Zapewnia to równomierne zużycie wszystkich 49 liczb (średnio $\approx 12.24$ razy na liczbę w 100 kuponach), ale **każda liczba dobierana jest zawsze ze swoimi historycznie najmocniejszymi partnerami**.

---

## 6. Podsumowanie i Rekomendowany Workflow

1. **Analiza Dużej Puli i Rozwodnienia (np. 49 liczb / 100 gier lub 42 liczby / 15 gier):**
   ```bash
   # Gwarantowane pełne pokrycie 100% puli i ranking synergii:
   docker compose run --rm app php bin/console app:lotto-stats --game=MiniLotto --pool=all --bets=15
   ```
2. **Uruchomienie Generatora z Pełnym Pokryciem i Rankingiem (Tryb 8):**
   ```bash
   docker compose run --rm app php bin/console app:lotto-generator --game=MiniLotto --pool-mode=Manual --mode=8 --bets=15
   ```
3. **Interaktywny Tryb TUI:**
   ```bash
   docker compose run --rm app php bin/console app:lotto-tui
   ```
4. **Uruchomienie ReAct Agent AI:**
   ```bash
   docker compose run --rm app php bin/console app:lotto-agent --game=Lotto --strategy=syndicate --sessions=15
   ```

---

## 7. Tryb [8] Rankingowe Pełne Pokrycie Synergiczne (Zero-Drop Guarantee)

### Zasada działania dwufazowego:
1. **Faza 1 – Partytywne Pokrycie Puli (Zero-Drop):**
   * Dla puli $N$ liczb (np. 42 w Mini Lotto) i formatu $k$ (5 liczb na zakład), algorytm wyznacza liczbę zakładów bazowych $B_{base} = \lceil N / k \rceil = 9$.
   * Algorytm heurystycznego klastrowania rozkłada 100% liczb na 9 zakładów tak, by połączyć liczby o najwyższym wzajemnym współwystępowaniu (Pair Affinity) i zgodności z dzwonem Gaussa.
2. **Faza 2 – Doładowanie Top-Synergii:**
   * Pozostałe sloty (np. zakłady 10–15) wypełniane są kombinacjami najsilniejszych liczb gorących i klastrów sąsiedzkich.
3. **Faza 3 – Sortowanie Rankingowe (Fitness Score):**
   * Wszystkie wygenerowane zakłady są sortowane malejąco według wskaźnika *Fitness Score*, prezentując graczowi przejrzysty ranking od kuponu #1 [★ Top Synergia] po zakłady domykające pulę.

