<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiApiClient
{
    private const MODELS_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    
    private const FALLBACK_MODELS = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-2.5-flash',
        'gemini-flash-latest',
        'gemini-pro-latest',
        'gemini-2.5-pro',
        'gemini-3.5-flash-lite',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%gemini_api_key%')]
        private readonly string $geminiApiKey
    ) {
    }

    public function listModels(): array
    {
        $maxRetries = 5;
        $retryDelay = 1;
        $data = [];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->httpClient->request('GET', self::MODELS_API_URL, [
                    'query' => ['key' => trim($this->geminiApiKey)],
                ]);
                $data = $response->toArray();
                break;
            } catch (\Throwable $e) {
                $statusCode = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getStatusCode() : 0;
                if ($attempt < $maxRetries && ($statusCode === 404 || $statusCode === 503 || $statusCode === 429 || $statusCode >= 500)) {
                    if ($statusCode !== 404) {
                        sleep($retryDelay);
                        $retryDelay *= 2;
                    }
                } else {
                    throw $e;
                }
            }
        }

        return $data['models'] ?? [];
    }

    public function generateContent(array $payload, int $timeoutSeconds = 120): string
    {
        $maxRetries = count(self::FALLBACK_MODELS);
        $retryDelay = 2;
        $aiText = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $currentModel = self::FALLBACK_MODELS[$attempt - 1];
            $apiUrl = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $currentModel);

            try {
                $response = $this->httpClient->request('POST', $apiUrl, [
                    'query' => ['key' => trim($this->geminiApiKey)],
                    'json' => $payload,
                    'timeout' => $timeoutSeconds,
                    'verify_peer' => false,
                    'verify_host' => false,
                ]);

                $resArray = $response->toArray();
                $parts = $resArray['candidates'][0]['content']['parts'] ?? [];
                $textParts = [];

                foreach ($parts as $part) {
                    if (isset($part['thought']) && $part['thought'] === true) {
                        continue;
                    }
                    if (isset($part['text']) && is_string($part['text'])) {
                        $textParts[] = $part['text'];
                    }
                }

                $aiText = trim(implode("\n", $textParts));
                if ($aiText === '' && !empty($parts[0]['text'])) {
                    $aiText = trim((string)$parts[0]['text']);
                }

                break;
            } catch (\Throwable $e) {
                $statusCode = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getStatusCode() : 0;
                $errorContent = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getContent(false) : '';

                if ($attempt < $maxRetries && ($statusCode === 404 || $statusCode === 503 || $statusCode === 429 || $statusCode >= 500)) {
                    $nextModel = self::FALLBACK_MODELS[$attempt];
                    $waitSec = $retryDelay;
                    if (preg_match('/"retryDelay":\s*"(\d+)s"/', $errorContent, $match)) {
                        $waitSec = max((int)$match[1], 2);
                    }

                    $this->logger->warning(sprintf(
                        "Gemini API error (%d) with model %s. Switching to %s...",
                        $statusCode,
                        $currentModel,
                        $nextModel
                    ));
                    if ($statusCode !== 404) {
                        sleep($waitSec);
                        $retryDelay = max($retryDelay * 2, $waitSec);
                    }
                } else {
                    $this->logger->error("Gemini API Request Failed ($statusCode): $errorContent", ['payload' => $payload]);
                    throw $e;
                }
            }
        }

        return $aiText;
    }

    public function generateContentWithTools(
        array $contents,
        array $toolsDeclarations,
        ?string $systemInstruction = null,
        int $timeoutSeconds = 120
    ): array {
        $maxRetries = count(self::FALLBACK_MODELS);
        $retryDelay = 2;
        $candidateContent = [];

        $payload = [
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.2],
        ];

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        if (!empty($toolsDeclarations)) {
            $payload['tools'] = [
                ['functionDeclarations' => $toolsDeclarations]
            ];
        }

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $currentModel = self::FALLBACK_MODELS[$attempt - 1];
            $apiUrl = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $currentModel);

            try {
                $response = $this->httpClient->request('POST', $apiUrl, [
                    'query' => ['key' => trim($this->geminiApiKey)],
                    'json' => $payload,
                    'timeout' => $timeoutSeconds,
                    'verify_peer' => false,
                    'verify_host' => false,
                ]);

                $resArray = $response->toArray();
                $candidateContent = $resArray['candidates'][0]['content'] ?? [];
                break;
            } catch (\Throwable $e) {
                $statusCode = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getStatusCode() : 0;
                $errorContent = $e instanceof \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface ? $e->getResponse()->getContent(false) : '';

                if ($attempt < $maxRetries && ($statusCode === 404 || $statusCode === 503 || $statusCode === 429 || $statusCode >= 500)) {
                    $nextModel = self::FALLBACK_MODELS[$attempt];
                    $waitSec = $retryDelay;
                    if (preg_match('/"retryDelay":\s*"(\d+)s"/', $errorContent, $match)) {
                        $waitSec = max((int)$match[1], 2);
                    }

                    $this->logger->warning(sprintf(
                        "Gemini API error (%d) with model %s. Switching to %s...",
                        $statusCode,
                        $currentModel,
                        $nextModel
                    ));
                    if ($statusCode !== 404) {
                        sleep($waitSec);
                        $retryDelay = max($retryDelay * 2, $waitSec);
                    }
                } else {
                    $this->logger->error("Gemini API Request Failed ($statusCode): $errorContent", ['payload' => $payload]);
                    throw new \RuntimeException("Gemini API error ($statusCode): " . mb_strimwidth($errorContent, 0, 300, '...'), 0, $e);
                }
            }

        }

        return $candidateContent;
    }


    public function askForPool(
        string $gameType,
        string $hotStr,
        string $coldStr,
        int $poolSize,
        string $strategy = 'balanced'
    ): array {
        $basePrompt = "Jesteś Głównym Analitykiem Danych w profesjonalnym syndykacie loteryjnym. 
Twoim jedynym zadaniem jest wyselekcjonowanie optymalnej puli wejściowej DOKŁADNIE $poolSize unikalnych liczb dla gry $gameType.

TWARDE DANE HISTORYCZNE (częstotliwość):
- Liczby Gorące (trend rosnący): $hotStr
- Liczby Zimne (potencjał przełamania): $coldStr

REGUŁY SELEKCJI (MUSISZ ICH PRZESTRZEGAĆ):\n";

        if ($strategy === 'aggressive') {
            $strategyRules = "1. Strategia Ostrej Gry (Łowca Trendów): Odrzuć standardowy balans! Wybierz 80-90% liczb z ekstremalnie gorących oraz ich bezpośrednich sąsiadów. Szukamy klastrów i anomalii.
2. Parzystość: Zignoruj.
3. Rozkład w zakresie: Zignoruj równomierne rozłożenie na bębnie. Celuj w silne zagęszczenia w okolicach najczęstszych trafień z historii.
4. Ryzyko: Skup się na maksymalizacji wygranej kosztem wyższego ryzyka. Uderzaj w powtarzające się wzorce z krótkiego dystansu.";
        } else {
            $strategyRules = "1. Strategia Hybrydowa: Zbuduj pulę, biorąc około 60-70% liczb z grupy Gorących oraz 30-40% z grupy Zimnych.
2. Balans Parzystości: Zadbaj, aby ostateczna pula miała jak najbardziej wyrównaną ilość liczb parzystych i nieparzystych.
3. Balans Zakresu: Zadbaj o równomierny rozkład – nie grupuj wszystkich liczb na początku ani na końcu bębna maszyny losującej.
4. Sąsiedztwo: Jeśli wybierasz bardzo gorącą liczbę, rozważ dobranie jednego z jej matematycznych sąsiadów (np. dla 14 rozważ 13 lub 15).";
        }

        $formatPrompt = "\nFORMAT ODPOWIEDZI (KRYTYCZNE):
Zwróć WYŁĄCZNIE same liczby oddzielone przecinkami, posortowane rosnąco. Żadnego tekstu, żadnych wstępów, żadnych znaczników markdown. 
Przykład poprawnej odpowiedzi: 2, 7, 12, 14, 28, 33, 41";

        $systemPrompt = $basePrompt . $strategyRules . $formatPrompt;

        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => "Wytypuj $poolSize liczb."]]]],
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'generationConfig' => ['temperature' => 0.4],
        ];

        $aiText = $this->generateContent($payload, 120);

        preg_match_all('/\d+/', $aiText, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);

        return array_unique(array_slice($numbers, 0, $poolSize));
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
            ? "\n3. Klastrowanie: Zwróć szczególną uwagę na 'liczby sąsiadujące' (np. dla 15 sąsiadami są 14 i 16) przy dobieraniu do liczb gorących."
            : '';

        $systemPrompt = "Jesteś Głównym Analitykiem Danych Gier Liczbowych.
Twoim zadaniem jest wygenerowanie $betsCount zakładów (po DOKŁADNIE $pickCount liczb każdy) dla gry $gameType.

DANE WEJŚCIOWE:
- Gorące liczby: $hotStr
- Zimne liczby: $coldStr

WYTYCZNE STRATEGICZNE:
1. Hybryda: Zbuduj zakłady opierając się na miksie liczb gorących (utrzymanie trendu) i zimnych (powrót do średniej).
2. Wariancja: Unikaj trywialnych ciągów (np. 1, 2, 3, 4, 5).{$neighbourInstruction}
4. Kompresja: Staraj się użyć możliwie najmniejszej puli UNIKALNYCH liczb do zbudowania wszystkich $betsCount zakładów (maksymalizuj powtarzanie się tych samych liczb na różnych kuponach).";

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

        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => "Przygotuj rekomendację $betsCount zakładów (po $pickCount liczb) dla gry $gameType."]]]],
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'generationConfig' => ['temperature' => 0.4],
        ];

        return $this->generateContent($payload, 300);
    }
}
