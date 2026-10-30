<?php

declare(strict_types=1);

namespace App\Service\Llm;

trait LlmCommonPromptsTrait
{
    abstract public function generateText(string $prompt, ?string $systemInstruction = null, float $temperature = 0.4): string;

    public function askForPool(
        string $gameType,
        string $hotStr,
        string $coldStr,
        int $poolSize,
        string $strategy = 'balanced',
        int $maxNumber = 0
    ): array {
        $rangeNote = $maxNumber > 0 ? " (wyłącznie z zakresu 1..$maxNumber)" : '';
        $basePrompt = "Jesteś Głównym Analitykiem Danych w profesjonalnym syndykacie loteryjnym. 
Twoim jedynym zadaniem jest wyselekcjonowanie optymalnej puli wejściowej DOKŁADNIE $poolSize unikalnych liczb$rangeNote dla gry $gameType.

TWARDE DANE HISTORYCZNE (częstotliwość):
- Liczby Gorące (trend rosnący): $hotStr
- Liczby Zimne (potencjał przełamania): $coldStr

REGUŁY SELEKCJI (MUSISZ ICH PRZESTRZEGAĆ):\n";

        if ($strategy === 'aggressive') {
            $strategyRules = "1. Strategia Ostrej Gry (Łowca Trendów): Odrzuć standardowy balans! Wybierz 80-90% liczb z ekstremalnie gorących oraz ich bezpośrednich sąsiadów (+1/-1). Szukamy klastrów i anomalii.
2. Parzystość: Drugorzędna wobec siły klastrów.
3. Rozkład w zakresie: Zignoruj równomierne rozłożenie na bębnie. Celuj w silne zagęszczenia w okolicach najczęstszych trafień z historii.
4. Ryzyko: Skup się na maksymalizacji trafień klastrowych z krótkiego dystansu.";
        } elseif ($strategy === 'syndicate') {
            $strategyRules = "1. Strategia Syndykatu Klastrowego: Zbuduj pulę łączącą sąsiadów (+1/-1 wokół gorących kotwic, ok. 50-60%), powtórki liczb gorących (ok. 20-30%) oraz uśpione liczby zimne jako zabezpieczenie (ok. 10-20%).
2. Dywersyfikacja (Anty-Overclustering): Unikaj zbijania całej puli w jeden ciasny ciąg (np. 4-5 kolejnych liczb). Rozłóż klastry na różne dekady bębna.
3. Balans Parzystości i Sumy: Zadbaj o zbliżony udział liczb parzystych i nieparzystych oraz środek ciężkości sumy bliski średniej bębna.";
        } elseif ($strategy === 'decades') {
            $strategyRules = "1. Strategia Równomiernych Dekad: Rozłóż $poolSize liczb równomiernie pomiędzy wszystkie dekady bębna (1-10, 11-20 itd.).
2. Wewnątrz każdej dekady priorytetyzuj liczby o najwyższej częstotliwości (Gorące) oraz ich sąsiadów (+1/-1).
3. Balans Parzystości: Utrzymaj równowagę liczb parzystych i nieparzystych.";
        } else {
            $strategyRules = "1. Strategia Hybrydowa (Zbalansowana): Zbuduj pulę, biorąc około 60-70% liczb z grupy Gorących oraz 30-40% z grupy Zimnych.
2. Balans Parzystości: Zadbaj, aby ostateczna pula miała jak najbardziej wyrównaną ilość liczb parzystych i nieparzystych.
3. Balans Zakresu: Zadbaj o równomierny rozkład – nie grupuj wszystkich liczb na początku ani na końcu bębna maszyny losującej.
4. Sąsiedztwo: Jeśli wybierasz bardzo gorącą liczbę, rozważ dobranie jednego z jej matematycznych sąsiadów (np. dla 14 rozważ 13 lub 15).";
        }

        $formatPrompt = "\nFORMAT ODPOWIEDZI (KRYTYCZNE):
Zwróć WYŁĄCZNIE same liczby oddzielone przecinkami, posortowane rosnąco. Żadnego tekstu, żadnych wstępów, żadnych znaczników markdown. 
Przykład poprawnej odpowiedzi: 2, 7, 12, 14, 28, 33, 41";

        $systemPrompt = $basePrompt . $strategyRules . $formatPrompt;

        $aiText = $this->generateText("Wytypuj $poolSize liczb$rangeNote.", $systemPrompt, 0.4);

        preg_match_all('/\d+/', $aiText, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        if ($maxNumber > 0) {
            $numbers = array_filter($numbers, static fn(int $n): bool => $n >= 1 && $n <= $maxNumber);
        }

        return array_values(array_slice(array_values(array_unique($numbers)), 0, $poolSize));
    }

    public function askForRecommendation(
        string $gameType,
        int $pickCount,
        int $betsCount,
        string $hotStr,
        string $coldStr,
        bool $includeNeighbours,
        bool $isJson
    ): string {
        $neighbourInstruction = $includeNeighbours
            ? "\n3. Klastrowanie sąsiadów (+1/-1): Zwróć szczególną uwagę na liczby sąsiadujące (np. dla 15 sąsiadami są 14 i 16) przy dobieraniu do liczb gorących."
            : '';
        $coverageStepNum = $includeNeighbours ? 4 : 3;

        $systemPrompt = "Jesteś Głównym Analitykiem Danych Gier Liczbowych.
Twoim zadaniem jest wygenerowanie $betsCount zakładów (po DOKŁADNIE $pickCount liczb każdy) dla gry $gameType.

DANE WEJŚCIOWE:
- Gorące liczby: $hotStr
- Zimne liczby: $coldStr

WYTYCZNE STRATEGICZNE:
1. Hybryda: Zbuduj zakłady opierając się na miksie liczb gorących (utrzymanie trendu) i zimnych (powrót do średniej).
2. Wariancja i realizm: Unikaj trywialnych ciągów 3+ kolejnych liczb (np. 1, 2, 3, 4, 5) oraz skrajnych sum.{$neighbourInstruction}
{$coverageStepNum}. Pokrycie i ochrona przed kanibalizacją par: Rozłóż liczby tak, aby pakiet zakładów obejmował możliwie SZEROKI wycinek puli i nie powtarzał tych samych par na wielu kuponach (system skrócony / dywersyfikacja trójek).";

        if ($isJson) {
            $systemPrompt .= "\n\nFORMAT ODPOWIEDZI: WYŁĄCZNIE poprawny obiekt JSON. Zero dodatkowego tekstu.
Struktura JSON:
{
  \"strategy_summary\": \"Krótkie uzasadnienie strategii (max 2 zdania).\",
  \"unique_numbers_used\": <int>,
  \"bets\": [[...], [...]]
}";
        } else {
            $systemPrompt .= "\n\nFORMAT ODPOWIEDZI:
1. Najpierw napisz krótkie uzasadnienie strategii (max 3 zdania).
2. Następnie podaj jawnie: 'Użyto łącznie [X] unikalnych liczb we wszystkich zakładach.'
3. Na samym końcu w nowej linii dodaj pogrubiony napis: **WYTYPOWANE LICZBY:** a pod nim wylistuj zakłady (np. 'Zakład 1: 2, 14, 25...').";
        }

        return $this->generateText(
            "Przygotuj rekomendację $betsCount zakładów (po $pickCount liczb) dla gry $gameType.",
            $systemPrompt,
            0.4
        );
    }
}
